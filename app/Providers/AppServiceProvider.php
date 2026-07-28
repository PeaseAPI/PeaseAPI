<?php

namespace App\Providers;

use App\Services\OptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 全局共享系统名称 / Logo / 页脚 / 短信开关，使后台修改后前台/用户面板/标题联动
        View::composer('*', function ($view) {
            $systemName = config('app.name', 'Pease API');
            $systemLogo = '';
            $footerHtml = '';
            $smsEnabled = false;
            try {
                if (app()->bound('db') && DB::connection()->getPdo()) {
                    $systemName = OptionService::get('SystemName', $systemName);
                    $systemLogo = OptionService::get('SystemLogo', '');
                    $footerHtml = OptionService::get('Footer', '');
                    // 短信服务开关：从环境变量或配置获取，默认关闭
                    $smsEnabled = !empty(env('SMS_ALIYUN_ACCESS_KEY_ID')) && !empty(env('SMS_ALIYUN_ACCESS_KEY_SECRET'));
                }
            } catch (\Throwable $e) {
                // 数据库未迁移或安装前静默回退到默认值
            }
            $view->with([
                'systemName' => $systemName,
                'systemLogo' => $systemLogo,
                'footerHtml' => $footerHtml,
                'smsEnabled' => $smsEnabled,
            ]);
        });
    }
}