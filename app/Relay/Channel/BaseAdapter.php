<?php

declare(strict_types=1);

namespace App\Relay\Channel;

use App\Relay\Common\RelayInfo;

/**
 * 基础适配器 - 提供通用方法
 */
abstract class BaseAdapter implements ChannelAdapterInterface
{
    protected string $name = 'base';

    protected int $apiType = 0;

    /** @var array<int, string> */
    protected array $supportedActions = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function getApiType(): int
    {
        return $this->apiType;
    }

    public function getSupportedActions(): array
    {
        return $this->supportedActions;
    }

    public function formatRequest(RelayInfo $info): void
    {
        // 默认实现
    }

    public function formatResponse(RelayInfo $info): void
    {
        // 默认实现
    }

    public function doRequest(RelayInfo $info): void
    {
        // 默认实现
    }

    public function doResponse(RelayInfo $info): void
    {
        // 默认实现
    }

    public function streamHandler(RelayInfo $info, ?callable $callback = null): void
    {
        // 默认不支持流式：宁可让客户端收到明确错误事件（handleStream 捕获后退款），
        // 也不能静默返回空流——旧默认行为会把流式黑洞伪装成 200 成功且计费恒 0
        throw new \RuntimeException(sprintf('适配器 [%s] 不支持流式请求', $this->name));
    }

    public function errorHandler(RelayInfo $info): void
    {
        // 默认实现
    }

    /**
     * 流式传输轮询循环（curl_multi），带客户端断连探活
     *
     * 阻塞式 curl_exec 无法感知客户端断连：php-fpm 下 connection_aborted() 仅在
     * 输出写失败时置位，上游静默期（如思考模型无 chunk）无输出可写 → 无法感知 →
     * 脚本存活至被 FPM request_terminate_timeout 硬杀，计费/退款全部跳过。
     *
     * 改为 curl_multi 轮询：每秒执行一次 $onKeepalive() 探活回调
     * （写一条 SSE 注释行 `: keepalive`，SSE 规范要求客户端忽略冒号开头的行），
     * 写失败（客户端已断开）即返回 false，调用方立即中止上游读取并结算。
     *
     * @param  \CurlMultiHandle  $mh  curl_multi 句柄（已 add_handle $ch）
     * @param  \CurlHandle  $ch  待轮询的传输句柄
     * @param  callable():bool  $onKeepalive  探活回调，返回 false 表示客户端已断开
     * @return bool true=上游传输自然结束；false=客户端断连中止
     */
    protected function pollStreamTransfer($mh, $ch, callable $onKeepalive): bool
    {
        $lastKeepalive = microtime(true);

        do {
            $mstatus = curl_multi_exec($mh, $active);
            if ($active) {
                if (curl_multi_select($mh, 0.2) === -1) {
                    usleep(50_000); // select 立即返回 -1 时避免空转
                }
            }

            if (microtime(true) - $lastKeepalive >= 1.0) {
                $lastKeepalive = microtime(true);

                if (! $onKeepalive()) {
                    return false;
                }
            }
        } while ($active && $mstatus === CURLM_OK);

        return true;
    }

    protected function buildHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }

        return $result;
    }
}
