<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuthzRole;
use Illuminate\Http\JsonResponse;

/**
 * 权限控制器 - 对标 new-api controller/authz.go
 * 返回 RBAC 权限目录与角色列表
 */
class AuthzController extends Controller
{
    /**
     * 权限目录 - 列出系统中所有可用权限定义
     */
    public function catalog(): JsonResponse
    {
        $catalog = $this->buildPermissionCatalog();

        $roles = AuthzRole::orderByDesc('id')->get();

        return $this->success([
            'permissions' => $catalog,
            'roles' => $roles,
        ]);
    }

    /**
     * 构建权限目录
     * 对标 Go 端 authz catalog，按模块分组列出权限项
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildPermissionCatalog(): array
    {
        return [
            [
                'module' => 'user',
                'name' => '用户管理',
                'permissions' => [
                    ['code' => 'user:view', 'name' => '查看用户'],
                    ['code' => 'user:create', 'name' => '创建用户'],
                    ['code' => 'user:update', 'name' => '更新用户'],
                    ['code' => 'user:delete', 'name' => '删除用户'],
                    ['code' => 'user:manage', 'name' => '管理用户操作'],
                ],
            ],
            [
                'module' => 'channel',
                'name' => '渠道管理',
                'permissions' => [
                    ['code' => 'channel:view', 'name' => '查看渠道'],
                    ['code' => 'channel:create', 'name' => '创建渠道'],
                    ['code' => 'channel:update', 'name' => '更新渠道'],
                    ['code' => 'channel:delete', 'name' => '删除渠道'],
                    ['code' => 'channel:test', 'name' => '测试渠道'],
                    ['code' => 'channel:key', 'name' => '查看渠道Key'],
                ],
            ],
            [
                'module' => 'token',
                'name' => '令牌管理',
                'permissions' => [
                    ['code' => 'token:view', 'name' => '查看令牌'],
                    ['code' => 'token:create', 'name' => '创建令牌'],
                    ['code' => 'token:update', 'name' => '更新令牌'],
                    ['code' => 'token:delete', 'name' => '删除令牌'],
                ],
            ],
            [
                'module' => 'log',
                'name' => '日志管理',
                'permissions' => [
                    ['code' => 'log:view', 'name' => '查看日志'],
                    ['code' => 'log:stat', 'name' => '日志统计'],
                    ['code' => 'log:clean', 'name' => '清理日志'],
                ],
            ],
            [
                'module' => 'redemption',
                'name' => '兑换码管理',
                'permissions' => [
                    ['code' => 'redemption:view', 'name' => '查看兑换码'],
                    ['code' => 'redemption:create', 'name' => '创建兑换码'],
                    ['code' => 'redemption:update', 'name' => '更新兑换码'],
                    ['code' => 'redemption:delete', 'name' => '删除兑换码'],
                ],
            ],
            [
                'module' => 'option',
                'name' => '系统配置',
                'permissions' => [
                    ['code' => 'option:view', 'name' => '查看配置'],
                    ['code' => 'option:update', 'name' => '更新配置'],
                ],
            ],
            [
                'module' => 'system',
                'name' => '系统管理',
                'permissions' => [
                    ['code' => 'system:performance', 'name' => '性能监控'],
                    ['code' => 'system:task', 'name' => '系统任务'],
                    ['code' => 'system:info', 'name' => '系统信息'],
                ],
            ],
        ];
    }
}