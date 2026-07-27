<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * I18n Middleware - 对标 new-api middleware/i18n.go
 *
 * 根据 Accept-Language 头设置语言环境
 */
class I18n
{
    protected array $supportedLanguages = [
        'zh-CN', 'zh-TW', 'en', 'ja', 'ko', 'ru',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    protected function resolveLocale(Request $request): string
    {
        // 优先使用系统设置中的 Language 选项
        try {
            $systemLang = \App\Services\OptionService::get('Language');
            if (!empty($systemLang)) {
                foreach ($this->supportedLanguages as $supported) {
                    if (strcasecmp($systemLang, $supported) === 0) {
                        return $supported;
                    }
                }
                // 检查前缀匹配 (如 zh => zh-CN)
                $prefix = substr($systemLang, 0, 2);
                foreach ($this->supportedLanguages as $supported) {
                    if (strcasecmp($prefix, substr($supported, 0, 2)) === 0) {
                        return $supported;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 数据库可能未初始化 (安装阶段)，忽略错误
        }

        // 其次使用 cookie 中保存的语言
        $cookieLang = $request->cookie('locale');
        if (!empty($cookieLang)) {
            foreach ($this->supportedLanguages as $supported) {
                if (strcasecmp($cookieLang, $supported) === 0) {
                    return $supported;
                }
            }
        }

        // 最后使用 Accept-Language 头
        $header = $request->header('Accept-Language', '');
        if (empty($header)) {
            return config('app.locale', 'zh-CN');
        }

        $languages = [];
        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);
            $quality = 1.0;
            if (count($segments) > 1 && str_starts_with($segments[1], 'q=')) {
                $quality = (float) substr($segments[1], 2);
            }
            $languages[$tag] = $quality;
        }

        arsort($languages);

        foreach ($languages as $tag => $quality) {
            if ($quality <= 0) {
                continue;
            }
            foreach ($this->supportedLanguages as $supported) {
                if (strcasecmp($tag, $supported) === 0) {
                    return $supported;
                }
                $prefix = substr($tag, 0, 2);
                $supportedPrefix = substr($supported, 0, 2);
                if (strcasecmp($prefix, $supportedPrefix) === 0) {
                    return $supported;
                }
            }
        }

        return config('app.locale', 'zh-CN');
    }
}