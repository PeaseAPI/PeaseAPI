<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;

class InstallController extends Controller
{
    /**
     * Check if the application is already installed.
     */
    public static function isInstalled(): bool
    {
        return File::exists(storage_path('installed'));
    }

    /**
     * Show the install wizard.
     */
    public function index()
    {
        if (self::isInstalled()) {
            return redirect('/')->with('error', 'Application is already installed.');
        }

        // Check if migration is done (file marker survives server restart)
        if (File::exists(storage_path('install_step3'))) {
            $marker = File::get(storage_path('install_step3'));
            if ($marker === 'done') {
                return view('install', ['step' => 3]);
            }
            // Migration pending or failed - show migrating page
            return view('install', ['step' => 'migrating']);
        }

        return view('install', [
            'step' => 1,
            'envChecks' => $this->getEnvironmentChecks(),
            'dirChecks' => $this->getDirectoryChecks(),
        ]);
    }

    /**
     * Process each step of the installer.
     */
    public function process(Request $request)
    {
        if (self::isInstalled()) {
            return redirect('/')->with('error', 'Application is already installed.');
        }

        $step = (int) $request->input('step', 1);

        return match ($step) {
            1 => $this->processStep1($request),
            2 => $this->processStep2($request),
            3 => $this->processStep3($request),
            default => redirect()->route('install.index'),
        };
    }

    /**
     * Step 1: Environment check - proceed to step 2.
     */
    protected function processStep1(Request $request)
    {
        $envChecks = $this->getEnvironmentChecks();
        $dirChecks = $this->getDirectoryChecks();

        // Check only required (non-optional) items
        $requiredEnvPassed = collect($envChecks)
            ->filter(fn($c) => !isset($c['optional']) || !$c['optional'])
            ->every(fn($c) => $c['passed']);
        $requiredDirPassed = collect($dirChecks)->every(fn($c) => $c['passed']);

        if (!$requiredEnvPassed || !$requiredDirPassed) {
            return view('install', [
                'step' => 1,
                'envChecks' => $envChecks,
                'dirChecks' => $dirChecks,
                'error' => '请修复必需的環境问题后重试（可选项目可以跳过）。',
            ]);
        }

        return view('install', [
            'step' => 2,
            'dbDefaults' => [
                'db_host' => env('DB_HOST', '127.0.0.1'),
                'db_port' => env('DB_PORT', '3306'),
                'db_database' => env('DB_DATABASE', 'pease_api'),
                'db_username' => env('DB_USERNAME', 'root'),
                'db_password' => '',
            ],
        ]);
    }

    /**
     * Step 2: Test database connection and save config.
     */
    protected function processStep2(Request $request)
    {
        $validated = $request->validate([
            'db_host' => 'required|string',
            'db_port' => 'required|numeric',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
        ]);

        // Test database connection
        try {
            $dsn = "mysql:host={$validated['db_host']};port={$validated['db_port']}";
            $pdo = new \PDO($dsn, $validated['db_username'], $validated['db_password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);

            // Try to create database if not exists
            $dbName = $validated['db_database'];
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
        } catch (\PDOException $e) {
            return view('install', [
                'step' => 2,
                'dbDefaults' => $validated,
                'error' => '数据库连接失败：' . $e->getMessage(),
            ]);
        }

        // Switch session to file driver BEFORE modifying DB (so sessions survive migrate:fresh)
        $this->updateEnv(['SESSION_DRIVER' => 'file']);
        config(['session.driver' => 'file']);

        // Write database config to .env
        $this->updateEnv([
            'DB_HOST' => $validated['db_host'],
            'DB_PORT' => $validated['db_port'],
            'DB_DATABASE' => $validated['db_database'],
            'DB_USERNAME' => $validated['db_username'],
            'DB_PASSWORD' => $validated['db_password'] ?? '',
        ]);

        // Create file marker BEFORE migration
        File::put(storage_path('install_step3'), 'pending');

        // Return a special "migrating" view that will auto-redirect after server restarts
        return view('install', [
            'step' => 'migrating',
            'dbConfig' => [
                'host' => $validated['db_host'],
                'port' => $validated['db_port'],
                'database' => $validated['db_database'],
            ],
        ]);
    }

    /**
     * AJAX endpoint to check migration status and run migration.
     */
    public function runMigration(Request $request)
    {
        // Check if migration is already done
        if (File::get(storage_path('install_step3')) === 'done') {
            return response()->json(['status' => 'done']);
        }

        // Update runtime config from .env
        config(['database.connections.mysql.host' => env('DB_HOST', '127.0.0.1')]);
        config(['database.connections.mysql.port' => env('DB_PORT', '3306')]);
        config(['database.connections.mysql.database' => env('DB_DATABASE', 'pease_api')]);
        config(['database.connections.mysql.username' => env('DB_USERNAME', 'root')]);
        config(['database.connections.mysql.password' => env('DB_PASSWORD', '')]);

        // Purge and reconnect with new credentials
        DB::purge('mysql');
        DB::reconnect('mysql');

        // Run migrations
        try {
            Artisan::call('migrate:fresh', ['--force' => true, '--seed' => false]);
            File::put(storage_path('install_step3'), 'done');
            return response()->json(['status' => 'done']);
        } catch (\Exception $e) {
            File::put(storage_path('install_step3'), 'failed:' . $e->getMessage());
            return response()->json(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Show step 3 form (GET request).
     */
    public function step3()
    {
        if (self::isInstalled()) {
            return redirect('/')->with('error', 'Application is already installed.');
        }

        return view('install', [
            'step' => 3,
        ]);
    }

    /**
     * Step 3: Create admin account and finish installation.
     */
    protected function processStep3(Request $request)
    {
        $validated = $request->validate([
            'admin_username' => 'required|string|min:3|max:32|alpha_num',
            'admin_email' => 'required|email|max:191',
            'admin_password' => 'required|string|min:6|confirmed',
        ]);

        try {
            // Create admin user using Eloquent (handles timestamp casts properly)
            User::create([
                'username' => $validated['admin_username'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'display_name' => $validated['admin_username'],
                'role' => 100, // Super admin
                'status' => 1,
                'quota' => 0,
                'used_quota' => 0,
                'request_count' => 0,
                'group' => 'default',
                'aff_code' => $this->generateAffCode(),
                'created_time' => time(),
                'last_login_at' => time(),
            ]);

        // Mark as installed
        File::put(storage_path('installed'), json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => config('pease-api.version', '1.0.0'),
        ]));

        // Clean up the step 2→3 marker file
        File::delete(storage_path('install_step3'));

            // Generate app key if not set
            if (empty(env('APP_KEY')) || env('APP_KEY') === 'SomeRandomStringSomeRandomString') {
                Artisan::call('key:generate', ['--force' => true]);
            }

            // Create symbolic link for storage
            if (!File::exists(public_path('storage'))) {
                Artisan::call('storage:link', ['--force' => true]);
            }

            // Cache config and routes
            Artisan::call('config:cache');
            Artisan::call('route:cache');

        } catch (\Exception $e) {
            return view('install', [
                'step' => 3,
                'error' => '创建管理员失败：' . $e->getMessage(),
            ]);
        }

        return view('install', [
            'step' => 'done',
            'admin_username' => $validated['admin_username'],
        ]);
    }

    /**
     * Check PHP environment requirements.
     */
    protected function getEnvironmentChecks(): array
    {
        return [
            [
                'name' => 'PHP 版本 (>= 8.2)',
                'passed' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'current' => PHP_VERSION,
            ],
            [
                'name' => 'PHP 扩展：BCMath',
                'passed' => extension_loaded('bcmath'),
                'current' => extension_loaded('bcmath') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：Ctype',
                'passed' => extension_loaded('ctype'),
                'current' => extension_loaded('ctype') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：cURL',
                'passed' => extension_loaded('curl'),
                'current' => extension_loaded('curl') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：DOM',
                'passed' => extension_loaded('dom'),
                'current' => extension_loaded('dom') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：Fileinfo',
                'passed' => extension_loaded('fileinfo'),
                'current' => extension_loaded('fileinfo') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：JSON',
                'passed' => extension_loaded('json'),
                'current' => extension_loaded('json') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：Mbstring',
                'passed' => extension_loaded('mbstring'),
                'current' => extension_loaded('mbstring') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：OpenSSL',
                'passed' => extension_loaded('openssl'),
                'current' => extension_loaded('openssl') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：PDO (MySQL)',
                'passed' => extension_loaded('pdo_mysql'),
                'current' => extension_loaded('pdo_mysql') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：Tokenizer',
                'passed' => extension_loaded('tokenizer'),
                'current' => extension_loaded('tokenizer') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：XML',
                'passed' => extension_loaded('xml'),
                'current' => extension_loaded('xml') ? '已安装' : '未安装',
            ],
            [
                'name' => 'PHP 扩展：Redis',
                'passed' => extension_loaded('redis'),
                'current' => extension_loaded('redis') ? '已安装' : '未安装（可选）',
                'optional' => true,
            ],
            [
                'name' => 'PHP 扩展：Zip',
                'passed' => extension_loaded('zip'),
                'current' => extension_loaded('zip') ? '已安装' : '未安装',
            ],
        ];
    }

    /**
     * Check directory write permissions.
     */
    protected function getDirectoryChecks(): array
    {
        $dirs = [
            storage_path(),
            storage_path('framework'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
            base_path('.env'),
        ];

        return collect($dirs)->map(function ($path) {
            return [
                'name' => str_replace(base_path() . '/', '', $path),
                'path' => $path,
                'passed' => is_writable($path),
            ];
        })->toArray();
    }

    /**
     * Update .env file with key-value pairs.
     */
    protected function updateEnv(array $values): void
    {
        $envPath = base_path('.env');
        $content = File::get($envPath);

        foreach ($values as $key => $value) {
            // Handle values with spaces or special characters
            if (preg_match('/[\s=#]/', $value) && !preg_match('/^".*"$/', $value)) {
                $value = '"' . $value . '"';
            }

            // Replace existing key
            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
            } else {
                // Append new key
                $content .= "\n{$key}={$value}";
            }
        }

        File::put($envPath, $content);
    }

    /**
     * Generate a random affiliate code.
     */
    protected function generateAffCode(): string
    {
        return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }
}