<?php

declare(strict_types=1);

/**
 * 单连接 Gemini SSE mock 服务器（relay-adapters-verify 用，非 PHPUnit）
 * 用法: php mock-gemini-sse-server.php <port>
 * 收到完整请求后：向 stderr 输出一行 JSON（请求行 + Authorization 头 + 请求体），
 * 回一段 Gemini SSE（两个内容 chunk + usageMetadata）后关闭连接退出。
 */
$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, 'mock server bind failed: '.$errstr."\n");
    exit(1);
}
fwrite(STDERR, "READY\n");
fflush(STDERR);

$conn = stream_socket_accept($server, 15);
if ($conn === false) {
    exit(1);
}

// 读到 \r\n\r\n（请求头结束），再按 Content-Length 读完 body
$raw = '';
$headEnd = false;
while (! $headEnd) {
    $part = fread($conn, 8192);
    if ($part === false || $part === '') {
        break;
    }
    $raw .= $part;
    $headEnd = str_contains($raw, "\r\n\r\n");
}

$body = '';
if ($headEnd && preg_match('/Content-Length: (\d+)/i', $raw, $m) === 1) {
    $need = (int) $m[1];
    $body = substr($raw, strpos($raw, "\r\n\r\n") + 4);
    $have = strlen($body);
    while ($have < $need) {
        $part = fread($conn, 8192);
        if ($part === false || $part === '') {
            break;
        }
        $body .= $part;
        $have += strlen($part);
    }
}

$requestLine = strtok($raw, "\r\n") ?: '';
$auth = '';
if (preg_match('/^Authorization: (.+)$/mi', $raw, $am) === 1) {
    $auth = trim($am[1]);
}
fwrite(STDERR, json_encode([
    'request_line' => $requestLine,
    'authorization' => $auth,
    'body' => $body,
])."\n");

$chunk1 = json_encode([
    'candidates' => [['content' => ['parts' => [['text' => 'Hello']], 'role' => 'model'], 'index' => 0]],
    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 1, 'totalTokenCount' => 11],
], JSON_UNESCAPED_UNICODE);

$chunk2 = json_encode([
    'candidates' => [['content' => ['parts' => [['text' => ' world']], 'role' => 'model'], 'finishReason' => 'STOP', 'index' => 0]],
    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => 5, 'cachedContentTokenCount' => 3, 'totalTokenCount' => 17],
], JSON_UNESCAPED_UNICODE);

fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n"
    ."data: {$chunk1}\n\n"
    ."data: {$chunk2}\n\n");
fclose($conn);
fclose($server);
