<?php

declare(strict_types=1);

/**
 * Relay 适配器自检（QA-19~QA-23，非 PHPUnit，独立脚本跑完即退出）
 * 覆盖：VolcengineAdapter 死代码移除 / getUpstreamUrl 版本段去重 / selectAdapter 分发 /
 * Gemini-Vertex SSE→OpenAI 转换与 usageMetadata 计费 / 本地 mock 流式 E2E / 不支持流式显式报错
 */
require __DIR__.'/../vendor/autoload.php';

use App\Models\Channel;
use App\Relay\Channel\AWS\AWSAdapter;
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

    // 等 mock bind 完成就绪行（避免 fsockopen 预检吃掉单次 accept）。
    // 循环跳过 stderr 上的 ini 噪音（如部分环境 CLI 启动即报 mbstring 重复加载警告），
    // 直到出现 READY；mock 启动失败（如端口占用）则读到此进程输出/EOF，作失败处理
    $ready = false;
    while (($line = fgets($pipes[2])) !== false) {
        if (str_contains($line, 'READY')) {
            $ready = true;
            break;
        }
    }
    if (! $ready) {
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

echo "== QA-24 AWSAdapter Converse 转换与计费 ==\n";
$ap = new class extends AWSAdapter
{
    public function probeBody(array $body): array
    {
        return $this->buildConverseBody($body);
    }

    public function probeUsage(RelayInfo $info, array $body): void
    {
        $this->extractUsage($info, $body);
    }

    public function probeStop(string $reason): string
    {
        return $this->mapStopReason($reason);
    }
};
$ab = $ap->probeBody([
    'messages' => [
        ['role' => 'system', 'content' => 'Be brief'],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi'], ['type' => 'image_url', 'url' => 'x']]],
        ['role' => 'assistant', 'content' => 'Prev'],
    ],
    'max_tokens' => 128,
]);
check('system → system 字段', ($ab['system'][0]['text'] ?? '') === 'Be brief');
check('user content → [{text}] 聚合', ($ab['messages'][0]['content'][0]['text'] ?? '') === 'Hi' && ($ab['messages'][0]['role'] ?? '') === 'user');
check('assistant 角色保留', ($ab['messages'][1]['role'] ?? '') === 'assistant');
check('inferenceConfig.maxTokens', ($ab['inferenceConfig']['maxTokens'] ?? 0) === 128);

$au = new RelayInfo;
$ap->probeUsage($au, ['usage' => ['inputTokens' => 12, 'outputTokens' => 34, 'cacheReadInputTokens' => 5, 'cacheWriteInputTokens' => 2, 'totalTokens' => 46]]);
check('usage: prompt=12', $au->promptTokens === 12);
check('usage: completion=34', $au->completionTokens === 34);
check('usage: cached=5（cacheRead）', $au->cachedTokens === 5);

$ac = new RelayInfo;
$ap->probeUsage($ac, ['usage' => ['inputTokens' => 3, 'outputTokens' => 1, 'cacheReadInputTokens' => 9]]);
check('usage: cached 截断到 prompt', $ac->cachedTokens === 3);
check('stopReason 映射', $ap->probeStop('max_tokens') === 'length' && $ap->probeStop('content_filtered') === 'content_filter' && $ap->probeStop('end_turn') === 'stop');

$ar = new RelayInfo;
$ar->upstreamModelName = 'anthropic.claude-sonnet-4-20250514-v1:0';
$ar->responseBody = json_encode([
    'output' => ['message' => ['content' => [['text' => 'A'], ['text' => 'B']]]],
    'stopReason' => 'max_tokens',
    'usage' => ['inputTokens' => 7, 'outputTokens' => 9],
]);
$ap->formatResponse($ar);
$ao = json_decode($ar->responseBody, true);
check('非流式: 文本聚合', ($ao['choices'][0]['message']['content'] ?? '') === 'AB');
check('非流式: max_tokens → length', ($ao['choices'][0]['finish_reason'] ?? '') === 'length');
check('非流式: usage 计费', $ar->promptTokens === 7 && $ar->completionTokens === 9);
check('非流式: model 取 upstream', ($ao['model'] ?? '') === 'anthropic.claude-sonnet-4-20250514-v1:0');

$ae = new RelayInfo;
$ae->responseStatus = 403;
$ae->responseBody = json_encode(['__type' => 'AccessDeniedException', 'message' => 'The security token included in the request is invalid.']);
$ap->errorHandler($ae);
$aeo = json_decode($ae->responseBody, true);
check('错误转 OpenAI 格式', ($aeo['error']['message'] ?? '') === 'The security token included in the request is invalid.' && $ae->responseStatus === 403);

$aurl = new RelayInfo;
$aurl->upstreamModelName = 'anthropic.claude-3-5-haiku-20241022-v1:0';
$aurl->channelBaseUrl = 'https://bedrock-gw.example.com';
$aurl->requestBody = ['model' => 'anthropic.claude-3-5-haiku-20241022-v1:0', 'messages' => [['role' => 'user', 'content' => 'Hi']]];
(new AWSAdapter)->formatRequest($aurl);
check('URL: /model/{id}/converse', $aurl->upstreamUrl === 'https://bedrock-gw.example.com/model/anthropic.claude-3-5-haiku-20241022-v1%3A0/converse');
check('body: 无 modelId 顶层字段', ! isset(json_decode((string) $aurl->upstreamBody, true)['modelId']));
$dflt = new ReflectionMethod(RelayInfo::class, 'getDefaultBaseUrl');
$dflt->setAccessible(true);
check('默认 base: bedrock-runtime.us-east-1', $dflt->invoke(new RelayInfo, 22) === 'https://bedrock-runtime.us-east-1.amazonaws.com');

$athrew = false;
try {
    (new AWSAdapter)->streamHandler(new RelayInfo);
} catch (RuntimeException $e) {
    $athrew = str_contains($e->getMessage(), '不支持流式');
}
check('流式显式报错（converse-stream 未实现）', $athrew);

$aempty = new RelayInfo;
$aempty->upstreamUrl = '';
$athrew = false;
try {
    (new AWSAdapter)->doRequest($aempty);
} catch (RuntimeException $e) {
    $athrew = str_contains($e->getMessage(), 'URL 为空');
}
check('上游 URL 为空 → 显式抛错（非 200 空响应）', $athrew);

echo "\n".($fail === 0 ? 'ALL PASS' : "FAILED: {$fail}")."\n";
exit($fail === 0 ? 0 : 1);
