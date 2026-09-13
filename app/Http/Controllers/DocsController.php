<?php

namespace App\Http\Controllers;

use App\Services\OptionService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class DocsController extends Controller
{
    /**
     * 站内文档注册表（单一数据源：index 卡片、show 路由、链接重写共用）。
     * file 为 base_path('docs/') 下相对路径；GitHub 仓库内相对链接保持原样，
     * 站内渲染时由 rewriteInternalLinks() 自动改写为 /docs/{slug}。
     */
    public const DOCS = [
        ['slug' => 'deployment', 'title' => '部署指南', 'icon' => '🚀', 'description' => '独立服务器、宝塔面板、Docker 等多种部署方式完整文档', 'file' => 'deployment.md'],
        ['slug' => 'guide-install', 'title' => '分步：安装到初始化', 'icon' => '🧭', 'description' => '从克隆代码到管理员登录、调度挂载与生产加固清单，一步步走完首次部署', 'file' => 'guides/00-install-to-init.md'],
        ['slug' => 'guide-channels', 'title' => '分步：渠道·模型·Key', 'icon' => '🔌', 'description' => '添加渠道、配置模型与计费倍率、测试连通、健康监控的逐步操作', 'file' => 'guides/01-setup-channels-models-keys.md'],
        ['slug' => 'guide-coding-pool', 'title' => '分步：Coding 池从零到上线', 'icon' => '🏊', 'description' => '登记供应商、录入账号池、同步官方源与校对、模型倍率、促销与转换 API 发布', 'file' => 'guides/02-setup-coding-pool.md'],
        ['slug' => 'guide-customers', 'title' => '分步：客户·令牌·分组', 'icon' => '👥', 'description' => '给客户开户、归组、发令牌、配额度、充值兑换的完整流程', 'file' => 'guides/03-setup-customers-tokens.md'],
        ['slug' => 'guide-subscriptions', 'title' => '分步：订阅·支付·工单', 'icon' => '💳', 'description' => '创建订阅套餐、接入易支付收款与验收、每日重置与自动续费、工单客服', 'file' => 'guides/04-setup-subscriptions-payments.md'],
        ['slug' => 'guide-ops', 'title' => '分步：日常运维 SOP', 'icon' => '📋', 'description' => '每日/每周/每月巡检清单、额度对账、备份与升级序列、命令速查表', 'file' => 'guides/05-ops-daily-runbook.md'],
        ['slug' => 'usage-guide', 'title' => '使用手册', 'icon' => '📖', 'description' => '系统设置、渠道配置、模型 Key 管理、Coding Plan 等全部使用文档', 'file' => 'usage-guide.md'],
        ['slug' => 'operations-guide', 'title' => '运维手册', 'icon' => '🛠️', 'description' => '设置变更 SOP、支付网关验收、定时任务、故障排查矩阵与 API 速查', 'file' => 'operations-guide.md'],
        ['slug' => 'settings-reference', 'title' => '设置键位参考', 'icon' => '⚙️', 'description' => '每个系统设置键的权威定义：类型、默认值、别名与生效链路', 'file' => 'settings-reference.md'],
        ['slug' => 'features', 'title' => '功能解读', 'icon' => '✨', 'description' => '工单支持、Coding Plan 双池、智能成本路由、时段折扣、健康告警等新功能全景解读', 'file' => 'features.md'],
        ['slug' => 'development', 'title' => '开发文档', 'icon' => '🧑‍💻', 'description' => '环境搭建、测试体系、解析器开发、迁移规范与已知陷阱备忘', 'file' => 'development.md'],
    ];

    private function siteOptions(): array
    {
        $systemName = 'Pease API';
        $systemLogo = '';
        $systemFooter = '';
        $registerEnabled = true;
        $passwordLoginEnabled = true;
        try {
            if (app()->bound('db') && \DB::connection()->getPdo()) {
                $systemName = OptionService::get('SystemName', $systemName);
                $systemLogo = OptionService::get('SystemLogo', '');
                $systemFooter = OptionService::get('SystemFooter', '');
                $registerEnabled = (bool) OptionService::get('RegisterEnabled', true);
                $passwordLoginEnabled = (bool) OptionService::get('PasswordLoginEnabled', true);
            }
        } catch (\Throwable $e) {
        }

        return compact('systemName', 'systemLogo', 'systemFooter', 'registerEnabled', 'passwordLoginEnabled');
    }

    private function docCards(): array
    {
        return array_map(static fn (array $d) => [
            'slug' => $d['slug'],
            'title' => $d['title'],
            'icon' => $d['icon'],
            'description' => $d['description'],
            'file' => $d['file'],
        ], self::DOCS);
    }

    /**
     * 文档列表页
     */
    public function index(): Response
    {
        $docs = $this->docCards();

        return response()->view('docs.index', array_merge($this->siteOptions(), compact('docs')));
    }

    /**
     * 显示具体文档
     */
    public function show(string $slug): Response
    {
        $doc = null;
        foreach (self::DOCS as $d) {
            if ($d['slug'] === $slug) {
                $doc = $d;
                break;
            }
        }

        if ($doc === null) {
            abort(404);
        }

        $filePath = base_path('docs/'.$doc['file']);

        if (! File::exists($filePath)) {
            abort(404, __('Document file does not exist'));
        }

        $markdownContent = $this->rewriteInternalLinks(File::get($filePath), $doc['file']);

        $allDocs = array_map(static fn (array $d) => [
            'slug' => $d['slug'],
            'title' => $d['title'],
            'icon' => $d['icon'],
        ], self::DOCS);

        return response()->view('docs.view', array_merge(
            $this->siteOptions(),
            ['doc' => $doc, 'slug' => $slug, 'markdownContent' => $markdownContent, 'allDocs' => $allDocs],
        ));
    }

    /**
     * 把 markdown 内指向仓库其他文档的相对链接（.../*.md 或 *.md，可带 #anchor）
     * 改写为站内 /docs/{slug}；未命中注册表（如 TASKS.md、CHANGELOG.md、外链）保持原样。
     * GitHub 上渲染原始文件时这些链接本就有效，无需改动仓库源文件。
     */
    private function rewriteInternalLinks(string $markdown, string $currentFile): string
    {
        $fileToSlug = [];
        foreach (self::DOCS as $d) {
            $fileToSlug[$d['file']] = $d['slug'];
        }

        $currentDir = trim(dirname($currentFile), '.');

        return (string) preg_replace_callback('/\]\(([^)\s]+\.md)(#[^)\s]*)?\)/', function (array $m) use ($fileToSlug, $currentDir) {
            $raw = $m[1];
            $anchor = $m[2] ?? '';

            // 归一化：剥掉 ./ 前缀与 ../ 回退段
            $normalized = $raw;
            while (str_starts_with($normalized, './')) {
                $normalized = substr($normalized, 2);
            }
            while (str_starts_with($normalized, '../')) {
                $normalized = substr($normalized, 3);
                $currentDir = ''; // 回退到 docs 根
            }
            $normalized = ltrim($normalized, '/');

            // 候选路径：相对当前文档目录、docs 根
            $candidates = $currentDir !== ''
                ? [$currentDir.'/'.$normalized, $normalized]
                : [$normalized];

            foreach ($candidates as $candidate) {
                foreach ($fileToSlug as $file => $target) {
                    if ($candidate === $file) {
                        return '](/docs/'.$target.$anchor.')';
                    }
                }
            }

            // 未命中注册表：保持原样（GitHub 专用文件等）
            return ']('.$raw.$anchor.')';
        }, $markdown);
    }
}
