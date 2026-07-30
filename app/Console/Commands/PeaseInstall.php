<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * PeaseAPI 一键安装命令
 *
 * 在禁用了 proc_open / putenv 的环境（如宝塔面板默认配置）下，
 * Composer 的 post-autoload-dump 等 scripts 无法执行（因为它们需要 proc_open
 * 来启动子进程运行 `php artisan package:discover`）。
 *
 * 本项目的 composer.json 已移除所有依赖 proc_open 的自动脚本，因此直接
 * `composer install` 即可在宝塔环境下正常完成依赖安装，无需解禁任何函数。
 *
 * 依赖安装完成后，运行本命令完成项目初始化：
 *   composer install
 *   php artisan pease:install
 *
 * 本命令内部直接调用 Artisan，不会触发任何子进程，因此无需 proc_open。
 */
class PeaseInstall extends Command
{
    /**
     * 命令名称与签名
     */
    protected $signature = 'pease:install
                            {--force : 强制执行，跳过已安装检查}
                            {--skip-migrate : 跳过数据库迁移}
                            {--skip-key : 跳过 APP_KEY 生成}';

    /**
     * 命令描述
     */
    protected $description = 'PeaseAPI 一键安装初始化（兼容禁用 proc_open/putenv 的环境，替代 composer scripts）';

    /**
     * 执行命令
     */
    public function handle(): int
    {
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║          PeaseAPI 安装初始化程序                ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        // 1. 环境检测
        $this->info('【1/6】检测运行环境...');
        $this->checkEnvironment();
        $this->newLine();

        // 2. 创建 .env 文件（如果不存在）
        $this->info('【2/6】检查 .env 配置文件...');
        $this->ensureEnvFile();
        $this->newLine();

        // 3. 生成 APP_KEY
        if (!$this->option('skip-key')) {
            $this->info('【3/6】生成应用密钥 (APP_KEY)...');
            $this->generateAppKey();
        } else {
            $this->info('【3/6】已跳过 APP_KEY 生成');
        }
        $this->newLine();

        // 4. 缓存清理与包发现（替代被移除的 composer post-autoload-dump 脚本）
        $this->info('【4/6】执行包发现与缓存清理...');
        $this->clearAllCaches();
        $this->runPackageDiscover();
        $this->newLine();

        // 5. 发布 Laravel 资源（替代被移除的 composer post-update-cmd 脚本）
        $this->info('【5/6】发布 Laravel 资源文件...');
        $this->publishLaravelAssets();
        $this->newLine();

        // 6. 数据库迁移
        if (!$this->option('skip-migrate')) {
            $this->info('【6/6】执行数据库迁移...');
            $this->runMigration();
        } else {
            $this->info('【6/6】已跳过数据库迁移');
        }
        $this->newLine();

        // 创建 storage 软链接
        $this->info('创建 storage 软链接...');
        $this->ensureStorageLink();

        $this->newLine();
        $this->info('✅ PeaseAPI 初始化完成！');
        $this->newLine();
        $this->info('后续步骤：');
        $this->line('  1. 编辑 .env 配置数据库与 Redis 连接信息');
        $this->line('  2. 访问 http://你的域名/install 进入 Web 安装向导');
        $this->line('  3. 或直接运行 php artisan serve 启动开发服务器');
        $this->newLine();
        $this->comment('提示：生产环境建议执行 php artisan config:cache && php artisan route:cache');

        return Command::SUCCESS;
    }

    /**
     * 检测运行环境
     */
    protected function checkEnvironment(): void
    {
        // 必须通过的检查（缺失会导致框架无法运行）
        $checks = [
            'PHP 版本 >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'PDO 扩展' => extension_loaded('pdo'),
            'PDO MySQL 驱动' => extension_loaded('pdo_mysql'),
            'MBString 扩展' => extension_loaded('mbstring'),
            'GMP 扩展' => extension_loaded('gmp'),
            'OpenSSL 扩展' => extension_loaded('openssl'),
            'Tokenizer 扩展' => extension_loaded('tokenizer'),
            'CType 扩展' => extension_loaded('ctype'),
            'JSON 扩展' => extension_loaded('json'),
            'Fileinfo 扩展' => extension_loaded('fileinfo'),
            'storage 目录可写' => is_writable(base_path('storage')),
            'bootstrap/cache 目录可写' => is_writable(base_path('bootstrap/cache')),
        ];

        // 可选扩展（缺失不阻断安装，仅警告）
        $optionalChecks = [
            'Redis 扩展' => extension_loaded('redis'),
        ];

        $allPassed = true;
        foreach ($checks as $name => $passed) {
            if ($passed) {
                $this->line("  <fg=green>✓</> {$name}");
            } else {
                $this->line("  <fg=red>✗</> {$name}");
                $allPassed = false;
            }
        }

        foreach ($optionalChecks as $name => $passed) {
            if ($passed) {
                $this->line("  <fg=green>✓</> {$name}");
            } else {
                $this->line("  <fg=yellow>⚠</> {$name}（可选，未安装可通过 predis 包使用 Redis）");
            }
        }

        // 检查禁用的函数（提示性，不阻断）
        $disabledFunctions = $this->getDisabledFunctions();
        $dangerousFunctions = array_intersect(
            ['proc_open', 'putenv', 'shell_exec', 'exec', 'system', 'passthru'],
            $disabledFunctions
        );

        if (!empty($dangerousFunctions)) {
            $this->newLine();
            $this->warn('  ⚠ 检测到以下函数被禁用：' . implode(', ', $dangerousFunctions));
            $this->line('  <fg=gray>PeaseAPI 运行时不需要这些函数，本安装命令也不依赖它们。</fg>');
            $this->line('  <fg=gray>本项目的 composer.json 已移除依赖这些函数的自动脚本，</fg>');
            $this->line('  <fg=gray>直接 `composer install` + `php artisan pease:install` 即可，无需解禁。</fg>');
        }

        if (!$allPassed) {
            $this->newLine();
            $this->error('环境检测未通过，请修复上述问题后重试。');
            exit(1);
        }
    }

    /**
     * 确保存在 .env 文件
     */
    protected function ensureEnvFile(): void
    {
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');

        if (file_exists($envPath)) {
            $this->line('  <fg=green>✓</> .env 文件已存在');
            return;
        }

        if (file_exists($examplePath)) {
            copy($examplePath, $envPath);
            $this->line('  <fg=green>✓</> 已从 .env.example 创建 .env 文件');
        } else {
            $this->line('  <fg=red>✗</> .env.example 不存在，请手动创建 .env 文件');
        }
    }

    /**
     * 生成 APP_KEY
     */
    protected function generateAppKey(): void
    {
        $key = config('app.key');

        if (!empty($key) && !$this->option('force')) {
            $this->line('  <fg=gray>• APP_KEY 已设置，跳过生成（使用 --force 可强制重新生成）</>');
            return;
        }

        $this->call('key:generate', ['--force' => true]);
    }

    /**
     * 清除所有缓存
     *
     * 删除 bootstrap/cache 下的所有缓存文件，确保不会因为
     * 陈旧缓存导致 ReflectionException: Class "view" does not exist。
     * 然后执行 optimize:clear 清除其他缓存。
     */
    protected function clearAllCaches(): void
    {
        // 物理删除 bootstrap/cache 下的所有缓存文件
        $cacheDir = base_path('bootstrap/cache');

        $removed = 0;
        if (is_dir($cacheDir)) {
            $entries = glob($cacheDir.'/*');
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if (is_file($entry) || is_link($entry)) {
                        @unlink($entry);
                    } elseif (is_dir($entry)) {
                        @rmdir($entry);
                    }
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->line("  <fg=green>✓</> 已清除 {$removed} 个 bootstrap/cache 缓存文件");
        }

        // 清除 OPcache（PHP-FPM 环境下可能缓存了旧文件）
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // 通过 Artisan 清除其他缓存（视图、事件等）
        try {
            $output = new BufferedOutput();
            Artisan::call('optimize:clear', [], $output);
            $this->line('  <fg=green>✓</> artisan optimize:clear 完成');
        } catch (\Exception $e) {
            $this->line('  <fg=gray>• optimize:clear 跳过（可能数据库未配置）</>');
        }
    }

    /**
     * 执行包发现（替代 composer post-autoload-dump 中的 package:discover）
     *
     * 直接调用 Artisan，不通过 Composer 的 proc_open
     */
    protected function runPackageDiscover(): void
    {
        $output = new BufferedOutput();
        Artisan::call('package:discover', ['--ansi' => true], $output);
        $result = $output->fetch();
        $this->line('  <fg=green>✓</> 包发现完成');
        if (trim($result)) {
            $this->line('  <fg=gray>' . trim($result) . '</>');
        }
    }

    /**
     * 发布 Laravel 资源文件（替代被移除的 composer post-update-cmd 中的
     * `@php artisan vendor:publish --tag=laravel-assets --ansi --force`）
     *
     * 直接调用 Artisan，不通过 Composer 的 proc_open
     */
    protected function publishLaravelAssets(): void
    {
        $output = new BufferedOutput();
        try {
            Artisan::call('vendor:publish', [
                '--tag' => 'laravel-assets',
                '--force' => true,
                '--ansi' => true,
            ], $output);
            $this->line('  <fg=green>✓</> Laravel 资源发布完成');
        } catch (\Exception $e) {
            $this->warn('  Laravel 资源发布失败（可稍后手动执行 php artisan vendor:publish --tag=laravel-assets --force）');
        }
    }

    /**
     * 执行数据库迁移
     */
    protected function runMigration(): void
    {
        if (!$this->confirm('是否现在执行数据库迁移？（请确保已配置 .env 中的数据库连接信息）', true)) {
            $this->line('  <fg=gray>• 已跳过数据库迁移，可稍后手动执行 php artisan migrate</>');
            return;
        }

        try {
            $this->call('migrate', ['--force' => true]);
            $this->line('  <fg=green>✓</> 数据库迁移完成');
        } catch (\Exception $e) {
            $this->error('  数据库迁移失败：' . $e->getMessage());
            $this->line('  <fg=gray>请检查 .env 中的数据库配置后手动执行：php artisan migrate</>');
        }
    }

    /**
     * 获取被禁用的函数列表
     */
    protected function getDisabledFunctions(): array
    {
        $disabled = ini_get('disable_functions');
        if (empty($disabled)) {
            return [];
        }

        return array_map('trim', explode(',', $disabled));
    }

    /**
     * 确保 public/storage 指向 storage/app/public 的正确软链接。
     */
    protected function ensureStorageLink(): void
    {
        $link = public_path('storage');
        $target = '../storage/app/public';

        if (is_link($link)) {
            $current = readlink($link);
            if ($current === $target) {
                $this->line('  <fg=green>✓</> public/storage 已存在且链接正确');
                return;
            }

            @unlink($link);
        }

        if (file_exists($link) || is_dir($link)) {
            $this->warn('  public/storage 已存在且不是软链接，请手动检查该路径');
            return;
        }

        if (!is_dir(public_path())) {
            @mkdir(public_path(), 0755, true);
        }

        if (@symlink($target, $link)) {
            $this->line('  <fg=green>✓</> 已创建 public/storage 软链接');
            return;
        }

        try {
            $output = new BufferedOutput();
            Artisan::call('storage:link', [], $output);
            $this->line('  <fg=green>✓</> 已通过 artisan storage:link 创建链接');
        } catch (\Throwable $e) {
            $this->warn('  未能自动创建 public/storage 软链接：' . $e->getMessage());
            $this->warn('  请手动在项目根目录执行 `php artisan storage:link` 或创建软链接');
        }
    }
}