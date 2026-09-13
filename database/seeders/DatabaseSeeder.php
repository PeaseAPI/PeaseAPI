<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 默认超级管理员（本地/测试环境用；服务器部署后必须立即改密或删除该账号）。
        // users 表必填：username/password/aff_code/created_at；aff_code 不在 User::$fillable，
        // 故走 query builder 绕过 fillable。
        DB::table('users')->insertOrIgnore([
            'username' => 'admin',
            'password' => Hash::make((string) env('SEED_ADMIN_PASSWORD', 'admin123')),
            'aff_code' => 'admin',
            'display_name' => '超级管理员',
            'email' => 'admin@example.com',
            'role' => 100,
            'status' => 1,
            'created_at' => now(),
        ]);

        // Coding Plan 供应商元配置 + 默认模型折算比率
        $this->call(CodingPlanVendorSeeder::class);
    }
}
