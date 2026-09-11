<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Option;
use App\Services\OptionService;
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
                            {--skip-key : 跳过 APP_KEY 生成}
                            {--skip-cron : 跳过定时任务（crontab）自动配置}';

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
        $this->info('【1/7】检测运行环境...');
        $this->checkEnvironment();
        $this->newLine();

        // 2. 创建 .env 文件（如果不存在）
        $this->info('【2/7】检查 .env 配置文件...');
        $this->ensureEnvFile();
        $this->newLine();

        // 3. 生成 APP_KEY
        if (! $this->option('skip-key')) {
            $this->info('【3/7】生成应用密钥 (APP_KEY)...');
            $this->generateAppKey();
        } else {
            $this->info('【3/7】已跳过 APP_KEY 生成');
        }
        $this->newLine();

        // 4. 缓存清理与包发现（替代被移除的 composer post-autoload-dump 脚本）
        $this->info('【4/7】执行包发现与缓存清理...');
        $this->clearAllCaches();
        $this->runPackageDiscover();
        $this->newLine();

        // 5. 发布 Laravel 资源（替代被移除的 composer post-update-cmd 脚本）
        $this->info('【5/7】发布 Laravel 资源文件...');
        $this->publishLaravelAssets();
        $this->newLine();

        // 6. 数据库迁移
        if (! $this->option('skip-migrate')) {
            $this->info('【6/7】执行数据库迁移...');
            $this->runMigration();
        } else {
            $this->info('【6/7】已跳过数据库迁移');
        }
        $this->newLine();

        // 【7/7】写入默认系统配置
        $this->info('【7/7】写入默认系统配置...');
        $this->seedDefaultOptions();
        $this->newLine();

        // 清理头像历史脏数据（重装后 public/avatars 可能被清空，
        // 数据库仍指向旧文件名会导致 404；data: URL 则会导致解码失败）
        $this->info('清理头像历史脏数据...');
        $this->cleanAvatarData();

        // 创建 storage 软链接
        $this->info('创建 storage 软链接...');
        $this->ensureStorageLink();

        // 【8/8】自动创建计划任务（系统 crontab 注册 schedule:run，幂等可重跑）
        $this->info('【8/8】配置定时任务（价格校对/额度重置/订单过期等计划任务）...');
        $this->ensureSchedulerCron();

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

        if (! empty($dangerousFunctions)) {
            $this->newLine();
            $this->warn('  ⚠ 检测到以下函数被禁用：'.implode(', ', $dangerousFunctions));
            $this->line('  <fg=gray>PeaseAPI 运行时不需要这些函数，本安装命令也不依赖它们。</fg>');
            $this->line('  <fg=gray>本项目的 composer.json 已移除依赖这些函数的自动脚本，</fg>');
            $this->line('  <fg=gray>直接 `composer install` + `php artisan pease:install` 即可，无需解禁。</fg>');
        }

        if (! $allPassed) {
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

        if (! empty($key) && ! $this->option('force')) {
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
            $output = new BufferedOutput;
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
        $output = new BufferedOutput;
        Artisan::call('package:discover', ['--ansi' => true], $output);
        $result = $output->fetch();
        $this->line('  <fg=green>✓</> 包发现完成');
        if (trim($result)) {
            $this->line('  <fg=gray>'.trim($result).'</>');
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
        $output = new BufferedOutput;
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
        if (! $this->confirm('是否现在执行数据库迁移？（请确保已配置 .env 中的数据库连接信息）', true)) {
            $this->line('  <fg=gray>• 已跳过数据库迁移，可稍后手动执行 php artisan migrate</>');

            return;
        }

        try {
            $this->call('migrate', ['--force' => true]);
            $this->line('  <fg=green>✓</> 数据库迁移完成');
        } catch (\Exception $e) {
            $this->error('  数据库迁移失败：'.$e->getMessage());
            $this->line('  <fg=gray>请检查 .env 中的数据库配置后手动执行：php artisan migrate</>');
        }
    }

    /**
     * Seed default option values into the database so that the
     * /api/status endpoint returns a complete response even on a
     * fresh installation (no manual admin-panel toggling needed).
     */
    protected function seedDefaultOptions(): void
    {
        try {
            $defaults = OptionService::DEFAULTS;
            $seeded = 0;

            foreach ($defaults as $key => $value) {
                // Only insert if the key does not already exist
                if (! Option::where('key', $key)->exists()) {
                    $stored = is_array($value)
                        ? json_encode($value, JSON_UNESCAPED_UNICODE)
                        : (string) $value;
                    Option::create(['key' => $key, 'value' => $stored]);
                    $seeded++;
                }
            }

            if ($seeded > 0) {
                $this->line("  <fg=green>✓</> 已写入 {$seeded} 条默认配置");
            } else {
                $this->line('  <fg=gray>• 所有默认配置已存在，无需写入</>');
            }

            // Clear option cache so the new values are visible immediately
            Option::clearCache();
        } catch (\Exception $e) {
            $this->warn('  默认配置写入失败：'.$e->getMessage());
            $this->line('  <fg=gray>可稍后在管理面板中手动配置</>');
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
     * 清理头像历史脏数据
     *
     * 重装/重新部署后 public/avatars 可能被清空，但数据库 users.avatar 仍指向
     * 旧文件名（导致 404），或残留 data: URL（导致 "Data URL decoding failed"）。
     * 通过调用 pease:clean-avatar 命令一次性清空这些无效记录。
     */
    protected function cleanAvatarData(): void
    {
        try {
            $output = new BufferedOutput;
            Artisan::call('pease:clean-avatar', [], $output);
            $result = trim($output->fetch());
            if ($result !== '') {
                // 逐行输出，保持安装日志整洁
                foreach (explode("\n", $result) as $line) {
                    $this->line('  <fg=gray>'.$line.'</>');
                }
            }
            $this->line('  <fg=green>✓</> 头像脏数据清理完成');
        } catch (\Throwable $e) {
            // 清理失败不阻断安装流程
            $this->line('  <fg=gray>• 头像脏数据清理跳过（'.$e->getMessage().'）</>');
        }
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

        if (! is_dir(public_path())) {
            @mkdir(public_path(), 0755, true);
        }

        if (@symlink($target, $link)) {
            $this->line('  <fg=green>✓</> 已创建 public/storage 软链接');

            return;
        }

        try {
            $output = new BufferedOutput;
            Artisan::call('storage:link', [], $output);
            $this->line('  <fg=green>✓</> 已通过 artisan storage:link 创建链接');
        } catch (\Throwable $e) {
            $this->warn('  未能自动创建 public/storage 软链接：'.$e->getMessage());
            $this->warn('  请手动在项目根目录执行 `php artisan storage:link` 或创建软链接');
        }
    }

    /**
     * 【8/8】自动创建计划任务：把 Laravel 调度器（schedule:run）注册进系统 crontab。
     *
     * 覆盖所有计划任务（coding-plan:verify-ratios 价格校对、refresh-pricing、
     * 额度重置、订单过期等 routes/console.php 注册项）。幂等设计：
     *  - 以「# PeaseAPI scheduler」注释行为标记，已存在且内容一致则跳过；
     *  - 已存在但项目路径/PHP 路径变化则原位替换；
     *  - 通过临时文件 + `crontab <file>` 写回，不破坏既有任务行。
     *
     * 在禁用 proc_open/exec 的环境（宝塔默认配置）下自动降级为提示手动配置。
     */
    protected function ensureSchedulerCron(): void
    {
        if ($this->option('skip-cron')) {
            $this->line('  <fg=gray>• 已按 --skip-cron 跳过，请手动配置：* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1</>');

            return;
        }

        // macOS 开发机不注册系统 crontab（crontab 写入可能阻塞，且部署目标为 Linux）；
        // 本机调试请改用 `php artisan schedule:work`
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->line('  <fg=gray>• macOS 开发环境跳过系统 crontab 注册，本机调试请运行 php artisan schedule:work</>');

            return;
        }

        // 仅 Linux/Unix 生产环境自动写入（Windows 无 crontab，提示手动）
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->warn('  当前系统（'.PHP_OS_FAMILY.'）无法自动注册计划任务，请手动配置：');
            $this->line('  <fg=gray>* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1</>');

            return;
        }

        $cronLine = sprintf(
            '* * * * * cd %s && %s artisan schedule:run >> /dev/null 2>&1 # PeaseAPI scheduler',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php')
        );

        // 读取现有 crontab（无 crontab 时 exit 1 + 空输出 = 正常新建场景；timeout 防御 crond 异常时阻塞）
        $existing = [];
        $exitCode = 0;
        exec('timeout 15 crontab -l 2>/dev/null', $existing, $exitCode);
        if (in_array($exitCode, [124, 127], true)) {
            $this->warn('  crontab 不可用（'.($exitCode === 124 ? '读取超时' : '命令缺失').'），无法自动注册计划任务');
            $this->line('  <fg=gray>请手动执行 crontab -e 添加：'.$cronLine.'</>');

            return;
        }

        // 过滤旧标记行（可能存在但路径已变）
        $kept = [];
        $markedLine = null;
        foreach ($existing as $line) {
            if (str_contains($line, '# PeaseAPI scheduler')) {
                $markedLine = $line;

                continue;
            }
            $kept[] = $line;
        }

        if ($markedLine !== null && trim($markedLine) === $cronLine) {
            $this->line('  <fg=green>✓</> 计划任务已存在且配置一致（每分钟 schedule:run）');

            return;
        }

        $kept[] = $cronLine;
        $tmp = tempnam(sys_get_temp_dir(), 'pease-cron-');
        if ($tmp === false) {
            $this->warn('  无法创建临时文件，请手动配置 crontab');

            return;
        }
        file_put_contents($tmp, implode("\n", $kept)."\n");

        $writeOutput = [];
        $writeCode = 0;
        exec('timeout 15 crontab '.escapeshellarg($tmp).' 2>&1', $writeOutput, $writeCode);
        unlink($tmp);

        if ($writeCode !== 0) {
            $this->warn('  crontab 写入失败（'.($writeCode === 124 ? '超时' : implode(' ', $writeOutput)).'），请手动配置：');
            $this->line('  <fg=gray>'.$cronLine.'</>');

            return;
        }

        $this->line('  <fg=green>✓</> 已'.($markedLine !== null ? '更新' : '创建').'系统计划任务（每分钟 schedule:run，覆盖价格校对/额度重置等）');
    }
}
