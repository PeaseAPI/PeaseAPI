<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/**
 * 前置检查：依赖未安装时给出友好提示，避免 PHP fatal error 导致 Nginx 502。
 * 服务器拉取代码后必须先执行 `composer install`。
 */
if (!file_exists(__DIR__.'/../vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $composerInstall = htmlspecialchars('composer install', ENT_QUOTES);
    $peaseInstall = htmlspecialchars('php artisan pease:install', ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PeaseAPI · 依赖未安装</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:0;display:flex;align-items:center;justify-content:center;min-height:100vh}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);max-width:560px;padding:40px;text-align:center}
.logo{font-size:28px;font-weight:700;color:#4f46e5;margin-bottom:8px}
h1{font-size:20px;margin:0 0 12px}
p{color:#64748b;line-height:1.6;margin:8px 0}
code{background:#f1f5f9;color:#db2777;padding:2px 8px;border-radius:6px;font-size:14px;font-family:"SFMono-Regular",Menlo,Monaco,Consolas,monospace}
.steps{text-align:left;background:#f8fafc;border-radius:8px;padding:20px;margin:20px 0}
.steps ol{margin:0;padding-left:20px}
.steps li{margin:8px 0;line-height:1.6;color:#475569}
.steps li code{font-size:13px}
.hint{font-size:13px;color:#94a3b8;margin-top:16px}
</style>
</head>
<body>
<div class="card">
<div class="logo">PeaseAPI</div>
<h1>⚠️ 依赖尚未安装</h1>
<p>检测到 <code>vendor/</code> 目录不存在，PHP 无法启动 Laravel 框架。</p>
<p>请在服务器上完成以下操作后重试：</p>
<div class="steps">
<ol>
<li>进入项目根目录</li>
<li>安装 PHP 依赖：<br><code>{$composerInstall}</code></li>
<li>执行安装命令完成初始化：<br><code>{$peaseInstall}</code></li>
<li>刷新本页面</li>
</ol>
</div>
<p class="hint">如果你已执行上述操作，请检查 <code>vendor/autoload.php</code> 是否存在且 PHP 进程有读取权限。</p>
</div>
</body>
</html>
HTML;
    exit;
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

/**
 * 陈旧配置缓存自愈：如果 bootstrap/cache/config.php 存在但缺少 view 配置
 * （在 config/view.php 缺失时生成的旧缓存），则自动删除，避免 Laravel
 * 启动时因加载不含 view 配置的缓存而抛出 ReflectionException: Class "view" does not exist。
 * bootstrap/cache/*.php 被 .gitignore 排除，git pull 不会自动清理，
 * 因此在此做运行时检测兜底。
 */
$configCachePath = __DIR__.'/../bootstrap/cache/config.php';
if (is_file($configCachePath)) {
    $cacheContent = @file_get_contents($configCachePath);
    if ($cacheContent !== false && strpos($cacheContent, "'view'") === false && strpos($cacheContent, '"view"') === false) {
        @unlink($configCachePath);
    }
}

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
