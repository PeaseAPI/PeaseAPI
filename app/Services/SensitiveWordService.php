<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * 敏感词检测服务（Round 17：CheckSensitiveEnabled / CheckSensitiveOnPromptEnabled /
 * StopOnSensitiveEnabled / SensitiveWords 后端消费，对标 new-api 敏感词检查）
 *
 * 语义：
 * - 主开关 `SensitiveWordEnabled`（别名 CheckSensitiveEnabled）关闭 → 全部放行（仅一次缓存读）
 * - `CheckSensitiveOnPromptEnabled` 开 → 检查用户请求内容，命中即拒绝（400，发生在预扣之前 →
 *   不扣费、不写消费日志、不调用上游）
 * - `StopOnSensitiveEnabled` 开 → 检查上游响应内容：非流式命中替换为 400（上游成本已产生，计费照常）；
 *   流式命中在输出管道截断并下发错误事件
 * - 词表 `SensitiveWords`：换行 / 中英文逗号 / 分号分隔，大小写不敏感；
 *   词表读取走 OptionService 运行时 memo + 单键缓存，改词表即时生效
 */
class SensitiveWordService
{
    /** 参与扫描的文本字段键（其余键——如 b64_json / image_url / data——天然跳过，避免 base64 误报） */
    protected const TEXT_KEYS = ['text', 'content', 'prompt', 'input', 'system'];

    /** 流式输出扫描的滚动窗口大小（字符） */
    public const STREAM_WINDOW = 65536;

    public function __construct(protected OptionService $options) {}

    public function isEnabled(): bool
    {
        return $this->options->get('SensitiveWordEnabled') === true;
    }

    public function shouldCheckPrompt(): bool
    {
        return $this->options->get('CheckSensitiveOnPromptEnabled') === true;
    }

    public function shouldCheckResponse(): bool
    {
        return $this->options->get('StopOnSensitiveEnabled') === true;
    }

    /**
     * 归一化词表（读取随 OptionService 运行时 memo / 单键缓存，写入即时失效）
     *
     * @return string[]
     */
    public function words(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $raw = (string) $this->options->get('SensitiveWords');
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,，;；]+/u', $raw) ?: [];
        $words = [];
        foreach ($parts as $part) {
            $word = trim($part);
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * 文本命中检查（大小写不敏感）；命中返回词表中的词，未命中返回 null
     */
    public function findIn(string $text): ?string
    {
        foreach ($this->words() as $word) {
            if (mb_stripos($text, $word) !== false) {
                return $word;
            }
        }

        return null;
    }

    /**
     * 在已解码的 JSON payload 中扫描已知文本字段，命中返回词表中的词
     */
    public function findInPayload(mixed $decoded): ?string
    {
        if (! is_array($decoded)) {
            return null;
        }

        $texts = [];
        $this->collectTexts($decoded, $texts);
        foreach ($texts as $text) {
            if (($hit = $this->findIn($text)) !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * 上游响应体检查（仅 JSON 响应体；二进制/非 JSON 透传不扫描，避免 base64 误报）
     */
    public function checkResponse(?string $body): ?string
    {
        if (! $this->isEnabled() || ! $this->shouldCheckResponse()) {
            return null;
        }

        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $this->findInPayload($decoded) : null;
    }

    /**
     * 递归采集文本字段下的直接字符串值（不在文本键内部向下继承，防止嵌套媒体字段误报）
     *
     * @param  array<int, string>  $out
     */
    protected function collectTexts(mixed $node, array &$out): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::TEXT_KEYS, true) && is_string($value) && $value !== '') {
                $out[] = $value;
            }

            $this->collectTexts($value, $out);
        }
    }

    /**
     * 命中告警日志（服务端记录命中词；客户端错误不回显命中词，避免词表枚举探针）
     */
    public function logHit(string $scene, string $word, ?int $userId, ?string $model = null): void
    {
        Log::warning('敏感词命中', [
            'scene' => $scene,
            'word' => $word,
            'user_id' => $userId,
            'model' => $model,
        ]);
    }

    /**
     * 客户端错误负载（OpenAI 风格；消息不含命中词）
     *
     * @return array<string, mixed>
     */
    public static function errorPayload(): array
    {
        return [
            'error' => [
                'message' => 'your request contains sensitive words',
                'type' => 'invalid_request_error',
                'code' => 'sensitive_word_detected',
            ],
        ];
    }
}
