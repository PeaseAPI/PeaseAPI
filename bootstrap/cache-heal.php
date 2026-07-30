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
 *   5. services.php 存在但缺少 ViewServiceProvider 绑定
 *      （services.php 从其他环境复制而来，不包含 view 服务注册）
 *   6. services.php 中包含指向不存在的类文件的路径
 *
 * 这些情况都会导致 Laravel 启动时抛出
 * ReflectionException: Class "view" does not exist。
 *
 * bootstrap/cache/*.php 被 .gitignore 排除，git pull 不会自动清理，
 * 因此在此做运行时检测兜底。
 *
 * 此外，本脚本还会：
 *   - 清除 OPcache 中缓存的旧文件（如果 OPcache 可用）
 *   - 确保 storage/framework 下的关键运行时目录存在，
 *     避免 ViewServiceProvider、Session 等服务启动失败。
 */

$cacheDir = __DIR__.'/cache';
$stale = false;
$staleReason = '';

peaseEnsureAppKey(dirname(__DIR__));

function peaseEnsureAppKey(string $projectRoot): void
{
    $envPath = $projectRoot.'/.env';
    $exampleEnvPath = $projectRoot.'/.env.example';

    if (!is_file($envPath) && is_file($exampleEnvPath)) {
        @copy($exampleEnvPath, $envPath);
    }

    if (!is_file($envPath)) {
        @file_put_contents($envPath, "APP_KEY=\n");
    }

    if (!is_file($envPath)) {
        return;
    }

    $envContent = @file_get_contents($envPath);
    if ($envContent === false) {
        return;
    }

    $currentKey = null;
    if (preg_match('/^APP_KEY=(.*)$/m', $envContent, $matches)) {
        $currentKey = trim($matches[1]);
    }

    if (!empty($currentKey) && $currentKey !== 'SomeRandomStringSomeRandomString' && $currentKey !== '') {
        putenv('APP_KEY=' . $currentKey);
        $_ENV['APP_KEY'] = $currentKey;
        $_SERVER['APP_KEY'] = $currentKey;
        return;
    }

    $newKey = 'base64:' . base64_encode(random_bytes(32));
    $newEnvContent = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $newKey, $envContent);

    if ($newEnvContent === null || $newEnvContent === $envContent) {
        $newEnvContent = rtrim($envContent) . PHP_EOL . 'APP_KEY=' . $newKey . PHP_EOL;
    }

    @file_put_contents($envPath, $newEnvContent);

    putenv('APP_KEY=' . $newKey);
    $_ENV['APP_KEY'] = $newKey;
    $_SERVER['APP_KEY'] = $newKey;
}

function peaseClearBootstrapCacheFiles(string $cacheDir): void
{
    if (!is_dir($cacheDir)) {
        return;
    }

    $entries = glob($cacheDir.'/*');
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if (is_file($entry) || is_link($entry)) {
            @unlink($entry);
        } elseif (is_dir($entry)) {
            @rmdir($entry);
        }
    }
}

// ============================================================
// 先强制清空 bootstrap/cache，避免任意陈旧缓存文件导致容器启动失败
// 这比只做条件检测更稳妥，尤其是从其他环境迁移过来的缓存文件。
// ============================================================
if (is_dir($cacheDir)) {
    $entries = @glob($cacheDir.'/*');
    if ($entries !== false && count($entries) > 0) {
        peaseClearBootstrapCacheFiles($cacheDir);
        $stale = true;
        $staleReason = 'bootstrap/cache 目录存在陈旧缓存，已强制清理';
    }
}

// ============================================================
// 检测 config.php 是否陈旧
// ============================================================
$configCachePath = $cacheDir.'/config.php';

if (is_file($configCachePath)) {
    $cacheContent = @file_get_contents($configCachePath);

    if ($cacheContent !== false) {
        // 检测 1：缺少 view 配置键
        if (strpos($cacheContent, "'view'") === false && strpos($cacheContent, '"view"') === false) {
            $stale = true;
            $staleReason = 'config.php 缺少 view 配置键';
        }

        // 检测 2：view.paths 中的路径指向不存在的目录
        if (!$stale && preg_match_all("#['\"](/[^'\"]+/resources/views)['\"]#", $cacheContent, $matches)) {
            foreach ($matches[1] as $viewPath) {
                if (!is_dir($viewPath)) {
                    $stale = true;
                    $staleReason = "config.php 中 view.paths 指向不存在的目录: {$viewPath}";
                    break;
                }
            }
        }

        // 检测 3：compiled 视图路径指向不存在的目录
        if (!$stale && preg_match_all("#['\"](/[^'\"]+/storage/framework/views)['\"]#", $cacheContent, $matches)) {
            foreach ($matches[1] as $compiledPath) {
                if (!is_dir($compiledPath)) {
                    $stale = true;
                    $staleReason = "config.php 中 compiled 视图路径指向不存在的目录: {$compiledPath}";
                    break;
                }
            }
        }

        // 检测 4：compiled 视图路径为 false（realpath() 失败时生成）
        if (!$stale && preg_match("#['\"]compiled['\"]\\s*=>\\s*false#", $cacheContent)) {
            $stale = true;
            $staleReason = 'config.php 中 compiled 视图路径为 false';
        }

        // 兜底：如果缓存内容里仍然包含本机开发路径，直接判定为陈旧缓存
        if (!$stale && preg_match('#/(Users|home)/[^\'\"]+(resources/views|storage/framework/views)#', $cacheContent)) {
            $stale = true;
            $staleReason = 'config.php 包含本机开发路径';
        }
    }
}

// ============================================================
// 检测 services.php 是否陈旧
// ============================================================
$servicesCachePath = $cacheDir.'/services.php';

if (!$stale && is_file($servicesCachePath)) {
    $servicesContent = @file_get_contents($servicesCachePath);

    if ($servicesContent !== false) {
        // 检测 5：services.php 缺少 ViewServiceProvider
        // ViewServiceProvider 是 Laravel 框架核心服务提供者，
        // 如果 services.php 中没有它，容器解析 'view' 时会抛出
        // ReflectionException: Class "view" does not exist
        if (strpos($servicesContent, 'ViewServiceProvider') === false) {
            $stale = true;
            $staleReason = 'services.php 缺少 ViewServiceProvider 绑定';
        }

        // 检测 6：services.php 中包含本地开发机路径
        // （从其他环境复制而来的缓存文件可能包含无效路径）
        if (!$stale && preg_match_all("#['\"](/[^'\"]+/PeaseAPI[^'\"]*)['\"]#", $servicesContent, $matches)) {
            foreach ($matches[1] as $path) {
                // 检查路径是否指向当前项目目录之外
                $projectRoot = dirname(__DIR__);
                if (strpos($path, $projectRoot) !== 0 && !file_exists($path)) {
                    $stale = true;
                    $staleReason = "services.php 包含不存在的路径: {$path}";
                    break;
                }
            }
        }

        // 兜底：如果 services.php 里仍然是旧环境的供应商列表或缺少 view 关联，直接清除
        if (!$stale && preg_match('#/(Users|home)/#', $servicesContent)) {
            $stale = true;
            $staleReason = 'services.php 包含本机开发路径';
        }
    }
}

// ============================================================
// 如果检测到陈旧缓存，删除所有 bootstrap/cache 缓存文件
// ============================================================
if ($stale) {
    peaseClearBootstrapCacheFiles($cacheDir);

    // 清除 OPcache 中可能缓存的旧文件内容
    // PHP-FPM 环境下 OPcache 可能缓存了已删除的文件，
    // 导致即使文件已删除，require 仍然加载旧内容
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

// ============================================================
// 确保 storage/framework 下的关键运行时目录存在
// 这些目录缺失会导致 ViewServiceProvider、Session 等服务启动失败
// ============================================================
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