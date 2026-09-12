<?php

declare(strict_types=1);

/**
 * Relay 适配器自检（QA-19~QA-23，非 PHPUnit，独立脚本跑完即退出）
 * 覆盖：VolcengineAdapter 死代码移除 / getUpstreamUrl 版本段去重 / selectAdapter 分发 /
 * Gemini-Vertex SSE→OpenAI 转换与 usageMetadata 计费 / 本地 mock 流式 E2E / 不支持流式显式报错
 */
require __DIR__.'/../vendor/autoload.php';

use App\Models\Channel;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\Gemini\GeminiAdapter;
use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Channel\Vertex\VertexAdapter;
use App\Relay\Common\RelayHandler;
use App\Relay\Common\RelayInfo;

$fail = 0;
function check(string $name, bool $cond): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ').$name."\n";
    if (! $cond) {
        $fail++;
    }
}

final class GeminiProbe extends GeminiAdapter
{
    public function probeBody(array $body): array
    {
        return $this->buildGeminiChatBody($body);
    }

    public function probeUsage(RelayInfo $info, array $chunk): void
    {
        $this->extractGeminiUsage($info, $chunk);
    }

    public function probeChunk(RelayInfo $info, array $chunk): ?string
    {
        return $this->convertGeminiChunkToOpenAI($info, $chunk);
    }
}

final class VertexProbe extends VertexAdapter {}

echo "== QA-19 VolcengineAdapter 死代码 ==\n";
check('类不存在', ! class_exists('App\Relay\Channel\Volcengine\VolcengineAdapter', false));
check('文件已删除', ! file_exists(__DIR__.'/../app/Relay/Channel/Volcengine/VolcengineAdapter.php'));

echo "== QA-23 getUpstreamUrl 版本段去重 ==\n";
$cases = [
    ['https://ark.cn-beijing.volces.com/api/v3', '/v1/chat/completions', 'https://ark.cn-beijing.volces.com/api/v3/chat/completions'],
    ['https://api.moonshot.cn/v1', '/v1/chat/completions', 'https://api.moonshot.cn/v1/chat/completions'],
    ['https://ark.cn-beijing.volces.com/api/coding', '/v1/messages', 'https://ark.cn-beijing.volces.com/api/coding/v1/messages'],
    ['https://ark.cn-beijing.volces.com/api/coding/v1', '/v1/messages', 'https://ark.cn-beijing.volces.com/api/coding/v1/messages'],
    ['https://open.bigmodel.cn/api/paas/v4', '/v1/chat/completions', 'https://open.bigmodel.cn/api/paas/v4/chat/completions'],
    ['https://api.anthropic.com', '/v1/messages', 'https://api.anthropic.com/v1/messages'],
    ['https://dashscope.aliyuncs.com/compatible-mode/v1', '/v1/chat/completions', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions'],
];
foreach ($cases as $i => [$base, $path, $expect]) {
    $info = new RelayInfo;
    $info->channelBaseUrl = $base;
    check(sprintf('#%d %s%s', $i + 1, $base, $path), $info->getUpstreamUrl($path) === $expect);
}

echo "== QA-20 selectAdapter 类型分发 ==\n";
$probe = new class extends RelayHandler
{
    public function selectFor(RelayInfo $info): string
    {
        $this->info = $info;
        $this->selectAdapter();

        return get_class($this->adapter);
    }
};
$map = [
    4 => 'App\Relay\Channel\Claude\ClaudeAdapter',
    50 => 'App\Relay\Channel\Gemini\GeminiAdapter',
    21 => 'App\Relay\Channel\Vertex\VertexAdapter',
    22 => 'App\Relay\Channel\AWS\AWSAdapter',
    56 => 'App\Relay\Channel\OpenAI\OpenAIAdapter',
    51 => 'App\Relay\Channel\OpenAI\OpenAIAdapter',
];
foreach ($map as $type => $expectClass) {
    $info = new RelayInfo;
    $info->channelType = $type;
    check("type={$type}", $probe->selectFor($info) === $expectClass);
}

echo "== QA-20 Gemini 协议转换与计费（单元） ==\n";
$gp = new GeminiProbe;
$body = $gp->probeBody([
    'messages' => [
        ['role' => 'system', 'content' => 'Be brief'],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi'], ['type' => 'image_url', 'url' => 'x']]],
        ['role' => 'assistant', 'content' => 'Prev'],
    ],
    'max_tokens' => 128,
]);
check('system → systemInstruction', ($body['systemInstruction']['parts'][0]['text'] ?? '') === 'Be brief');
check('user 聚合 text 段', ($body['contents'][0]['parts'][0]['text'] ?? '') === 'Hi');
check('assistant → model 角色', ($body['contents'][1]['role'] ?? '') === 'model');
check('generationConfig.maxOutputTokens', ($body['generationConfig']['maxOutputTokens'] ?? 0) === 128);

$ui = new RelayInfo;
$gp->probeUsage($ui, ['usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => 5, 'cachedContentTokenCount' => 3]]);
check('usage: prompt=10', $ui->promptTokens === 10);
check('usage: completion=candidates+thoughts=7', $ui->completionTokens === 7);
check('usage: cached=3', $ui->cachedTokens === 3);

$ci = new RelayInfo;
$ci->promptTokens = 10;
$ci->completionTokens = 7;
$chunk = $gp->probeChunk($ci, ['candidates' => [['content' => ['parts' => [['text' => 'Hello']]]]]]);
check('内容 chunk → delta.content', $chunk !== null && str_contains($chunk, '"content":"Hello"') && str_contains($chunk, '"finish_reason":null'));
$fin = $gp->probeChunk($ci, ['candidates' => [['finishReason' => 'MAX_TOKENS']]]);
check('MAX_TOKENS → length + usage', $fin !== null && str_contains($fin, '"finish_reason":"length"') && str_contains($fin, '"completion_tokens":7'));
check('纯 usage chunk 不下发', $gp->probeChunk($ci, ['usageMetadata' => ['promptTokenCount' => 1]]) === null);

$ri = new RelayInfo;
$ri->upstreamModelName = 'gemini-2.5-pro';
$ri->responseBody = json_encode([
    'candidates' => [['content' => ['parts' => [['text' => 'A'], ['text' => 'B']], 'finishReason' => 'STOP']]],
    'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 4],
]);
$gp->formatResponse($ri);
$openai = json_decode($ri->responseBody, true);
check('非流式: 文本聚合', ($openai['choices'][0]['message']['content'] ?? '') === 'AB');
check('非流式: finish stop', ($openai['choices'][0]['finish_reason'] ?? '') === 'stop');
check('非流式: usage 计费', $ri->promptTokens === 11 && $ri->completionTokens === 4);

$ei = new RelayInfo;
$ei->responseStatus = 429;
$ei->responseBody = json_encode(['error' => ['code' => 429, 'message' => 'Resource exhausted', 'status' => 'RESOURCE_EXHAUSTED']]);
$gp->errorHandler($ei);
$err = json_decode($ei->responseBody, true);
check('错误转 OpenAI 格式', ($err['error']['code'] ?? '') === 'RESOURCE_EXHAUSTED' && $ei->responseStatus === 429);

echo "== QA-20/21 Gemini/Vertex 流式 E2E（本地 mock SSE） ==\n";

/**
 * 起一个 mock SSE 子进程，跑 adapter->formatRequest + streamHandler，
 * 返回 [请求元信息(请求行/Authorization/body), 收集到的输出]
 *
 * @return array{0: array<string, mixed>|null, 1: string}
 */
function runStreamE2E(int $port, BaseAdapter $adapter, RelayInfo $info): array
{
    $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $proc = proc_open(PHP_BINARY.' '.escapeshellarg(__DIR__.'/mock-gemini-sse-server.php').' '.(string) $port, $desc, $pipes);
    if (! is_resource($proc)) {
        return [null, ''];
    }
    fclose($pipes[0]);

    // 等 mock bind 完成就绪行（避免 fsockopen 预检吃掉单次 accept）
    $readyLine = fgets($pipes[2]);
    if ($readyLine === false || ! str_contains($readyLine, 'READY')) {
        proc_terminate($proc);

        return [null, ''];
    }

    // CLI 下 header() 会因先前输出告警（headers_sent 已置位，OB 压不住），
    // 本地 E2E 局部吞掉警告；真实请求中 SSE 头先于任何输出发出，无此问题
    $out = '';
    set_error_handler(static fn (): bool => true);
    $adapter->formatRequest($info);
    $adapter->streamHandler($info, function ($chunk) use (&$out): void {
        $out .= (string) $chunk;
    });
    restore_error_handler();

    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $lines = array_values(array_filter(explode("\n", trim($stderr))));
    $meta = $lines === [] ? null : json_decode((string) end($lines), true);

    return [is_array($meta) ? $meta : null, $out];
}

$channel = new Channel(['key' => 'gkey']);
$channel->id = 771;
$gi = new RelayInfo;
$gi->channel = $channel;
$gi->channelId = 771;
$gi->apiKey = 'gkey';
$gi->channelType = 50;
$gi->channelBaseUrl = 'http://127.0.0.1:18971';
$gi->requestBody = [
    'model' => 'gemini-2.5-pro',
    'messages' => [['role' => 'system', 'content' => 'Be brief'], ['role' => 'user', 'content' => 'Hi']],
    'stream' => true,
];
$gi->startTime = microtime(true);
[$geminiMeta, $geminiOut] = runStreamE2E(18971, new GeminiAdapter, $gi);
check('E2E URL（:streamGenerateContent?alt=sse&key）', str_contains((string) ($geminiMeta['request_line'] ?? ''), '/v1beta/models/gemini-2.5-pro:streamGenerateContent?alt=sse&key=gkey'));
check('E2E 请求体含 systemInstruction', str_contains((string) ($geminiMeta['body'] ?? ''), 'systemInstruction') && str_contains((string) ($geminiMeta['body'] ?? ''), 'Be brief'));
check('E2E chunk1', str_contains($geminiOut, '"content":"Hello"'));
check('E2E chunk2', str_contains($geminiOut, '"content":" world"'));
check('E2E finish_reason=stop', str_contains($geminiOut, '"finish_reason":"stop"'));
check('E2E [DONE]', str_contains($geminiOut, 'data: [DONE]'));
check('E2E usage 10/7', str_contains($geminiOut, '"prompt_tokens":10') && str_contains($geminiOut, '"completion_tokens":7'));
check('E2E info 计费 10/7/3', $gi->promptTokens === 10 && $gi->completionTokens === 7 && $gi->cachedTokens === 3);
check('E2E status=200 未中断', $gi->responseStatus === 200 && ! $gi->clientAborted);

$vChannel = new Channel(['key' => 'test-token', 'other' => 'us-central1|proj-x']);
$vChannel->id = 772;
$vi = new RelayInfo;
$vi->channel = $vChannel;
$vi->channelId = 772;
$vi->channelType = 21;
$vi->channelBaseUrl = 'http://127.0.0.1:18972';
$vi->requestBody = ['model' => 'gemini-2.5-pro', 'messages' => [['role' => 'user', 'content' => 'Hi']], 'stream' => true];
$vi->startTime = microtime(true);
[$vMeta, $vOut] = runStreamE2E(18972, new VertexAdapter, $vi);
check('Vertex URL（project/region/model:streamGenerateContent）', str_contains((string) ($vMeta['request_line'] ?? ''), '/v1/projects/proj-x/locations/us-central1/publishers/google/models/gemini-2.5-pro:streamGenerateContent'));
check('Vertex Bearer 认证', ($vMeta['authorization'] ?? '') === 'Bearer test-token');
check('Vertex 输出与计费', str_contains($vOut, '"content":"Hello"') && $vi->promptTokens === 10 && $vi->completionTokens === 7);
check('Vertex [DONE] + status=200', str_contains($vOut, 'data: [DONE]') && $vi->responseStatus === 200);

echo "== QA-22 不支持流式 → 显式报错（非静默空流） ==\n";
$plain = new class extends BaseAdapter {};
$threw = false;
try {
    $plain->streamHandler(new RelayInfo);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), '不支持流式');
}
check('BaseAdapter 默认抛出', $threw);

$task = new class extends TaskAdapter
{
    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        return '';
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        return '';
    }

    protected function buildRequestBody(RelayInfo $info): array
    {
        return [];
    }
};
$threw = false;
try {
    $task->streamHandler(new RelayInfo);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), '不支持流式');
}
check('TaskAdapter 继承抛出', $threw);

echo "\n".($fail === 0 ? 'ALL PASS' : "FAILED: {$fail}")."\n";
exit($fail === 0 ? 0 : 1);
