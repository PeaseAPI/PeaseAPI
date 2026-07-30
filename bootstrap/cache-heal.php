<?php

/**
 * PeaseAPI 陈旧配置缓存自愈逻辑
 *
 * 此文件被 public/index.php 和 artisan 共同 require，
 * 确保无论是 HTTP 请求还是 CLI 命令，都能在 Laravel 启动前
 * 检测并清除包含无效路径的 bootstrap/cache 缓存文件。
 *
 * 触发删除的情况：
 *   1. config.php 缺少 view 配置键（config/view.php 缺失时生成的旧缓存）
 *   2. config.php 中 view.paths 指向不存在的目录
 *      （本地开发机执行 optimize 后将缓存上传到生产服务器，
 *       路径如 /Users/.../resources/views 在服务器上不存在）
 *   3. config.php 中 compiled 视图路径指向不存在的目录
 *   4. config.php 中 compiled 视图路径为 false
 *      （config/view.php 使用 realpath() 时，若目录不存在则返回 false）
 *
 * 这些情况都会导致 Laravel 启动时抛出
 * ReflectionException: Class "view" does not exist。
 *
 * bootstrap/cache/*.php 被 .gitignore 排除，git pull 不会自动清理，
 * 因此在此做运行时检测兜底。
 *
 * 此外，本脚本还会确保 storage/framework 下的关键运行时目录存在，
 * 避免 ViewServiceProvider 因缺少 compiled 视图目录而启动失败。
 */

$configCachePath = __DIR__.'/cache/config.php';

if (is_file($configCachePath)) {
    $cacheContent = @file_get_contents($configCachePath);

    if ($cacheContent !== false) {
        $stale = false;

        // 检测 1：缺少 view 配置键
        if (strpos($cacheContent, "'view'") === false && strpos($cacheContent, '"view"') === false) {
            $stale = true;
        }

        // 检测 2：view.paths 中的路径指向不存在的目录
        // 匹配缓存中形如 '/xxx/resources/views' 的绝对路径（单引号或双引号）
        if (!$stale && preg_match_all("#['\"](/[^'\"]+/resources/views)['\"]#", $cacheContent, $matches)) {
            foreach ($matches[1] as $viewPath) {
                if (!is_dir($viewPath)) {
                    $stale = true;
                    break;
                }
            }
        }

        // 检测 3：compiled 视图路径指向不存在的目录
        if (!$stale && preg_match_all("#['\"](/[^'\"]+/storage/framework/views)['\"]#", $cacheContent, $matches)) {
            foreach ($matches[1] as $compiledPath) {
                if (!is_dir($compiledPath)) {
                    $stale = true;
                    break;
                }
            }
        }

        // 检测 4：compiled 视图路径为 false（realpath() 失败时生成）
        // 匹配 'compiled' => false 或 "compiled" => false
        if (!$stale && preg_match("#['\"]compiled['\"]\\s*=>\\s*false#", $cacheContent)) {
            $stale = true;
        }

        if ($stale) {
            // 删除所有 bootstrap/cache 缓存文件（config/services/packages/routes/events）
            $cacheDir = __DIR__.'/cache';
            foreach (['config.php', 'services.php', 'packages.php', 'routes.php', 'routes-v7.php', 'events.php', 'compiled.php'] as $cacheFile) {
                @unlink($cacheDir.'/'.$cacheFile);
            }
        }
    }
}

// 确保 storage/framework 下的关键运行时目录存在
// 这些目录缺失会导致 ViewServiceProvider、Session 等服务启动失败
$storageDirs = [
    __DIR__.'/../storage/framework/views',
    __DIR__.'/../storage/framework/cache',
    __DIR__.'/../storage/framework/cache/data',
    __DIR__.'/../storage/framework/sessions',
    __DIR__.'/../storage/logs',
];

foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}