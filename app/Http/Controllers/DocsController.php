<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class DocsController extends Controller
{
    /**
     * 文档列表页
     */
    public function index(): Response
    {
        $systemName = 'Pease API';
        $systemLogo = '';
        $systemFooter = '';
        $registerEnabled = true;
        $passwordLoginEnabled = true;
        try {
            if (app()->bound('db') && \DB::connection()->getPdo()) {
                $systemName = \App\Services\OptionService::get('SystemName', $systemName);
                $systemLogo = \App\Services\OptionService::get('SystemLogo', '');
                $systemFooter = \App\Services\OptionService::get('SystemFooter', '');
                $registerEnabled = (bool) \App\Services\OptionService::get('RegisterEnabled', true);
                $passwordLoginEnabled = (bool) \App\Services\OptionService::get('PasswordLoginEnabled', true);
            }
        } catch (\Throwable $e) {}

        $docs = [
            [
                'slug' => 'deployment',
                'title' => '部署指南',
                'icon' => '🚀',
                'description' => '独立服务器、宝塔面板、Docker 等多种部署方式完整文档',
                'file' => 'deployment.md',
            ],
            [
                'slug' => 'usage-guide',
                'title' => '使用手册',
                'icon' => '📖',
                'description' => '系统设置、渠道配置、模型 Key 管理、Coding Plan 等全部使用文档',
                'file' => 'usage-guide.md',
            ],
        ];

        return response()->view('docs.index', compact('systemName', 'systemLogo', 'systemFooter', 'registerEnabled', 'passwordLoginEnabled', 'docs'));
    }

    /**
     * 显示具体文档
     */
    public function show(string $slug): Response
    {
        $systemName = 'Pease API';
        $systemLogo = '';
        $systemFooter = '';
        $registerEnabled = true;
        $passwordLoginEnabled = true;
        try {
            if (app()->bound('db') && \DB::connection()->getPdo()) {
                $systemName = \App\Services\OptionService::get('SystemName', $systemName);
                $systemLogo = \App\Services\OptionService::get('SystemLogo', '');
                $systemFooter = \App\Services\OptionService::get('SystemFooter', '');
                $registerEnabled = (bool) \App\Services\OptionService::get('RegisterEnabled', true);
                $passwordLoginEnabled = (bool) \App\Services\OptionService::get('PasswordLoginEnabled', true);
            }
        } catch (\Throwable $e) {}

        $docsMap = [
            'deployment' => ['title' => '部署指南', 'icon' => '🚀', 'file' => 'deployment.md'],
            'usage-guide' => ['title' => '使用手册', 'icon' => '📖', 'file' => 'usage-guide.md'],
        ];

        if (!isset($docsMap[$slug])) {
            abort(404);
        }

        $doc = $docsMap[$slug];
        $filePath = base_path('docs/' . $doc['file']);

        if (!File::exists($filePath)) {
            abort(404, '文档文件不存在');
        }

        $markdownContent = File::get($filePath);

        $allDocs = [
            ['slug' => 'deployment', 'title' => '部署指南', 'icon' => '🚀'],
            ['slug' => 'usage-guide', 'title' => '使用手册', 'icon' => '📖'],
        ];

        return response()->view('docs.view', compact('systemName', 'systemLogo', 'systemFooter', 'registerEnabled', 'passwordLoginEnabled', 'doc', 'slug', 'markdownContent', 'allDocs'));
    }
}