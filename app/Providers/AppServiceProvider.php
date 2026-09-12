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
            $systemFooter = '';
            $registerEnabled = true;
            $passwordLoginEnabled = true;
            $smsEnabled = false;
            $phoneLoginEnabled = false;
            $phoneRegisterEnabled = false;
            $phonePasswordResetEnabled = false;
            $emailVerificationEnabled = false;
            try {
                if (app()->bound('db') && DB::connection()->getPdo()) {
                    $systemName = OptionService::get('SystemName', $systemName);
                    $systemLogo = OptionService::get('SystemLogo', '');
                    $footerHtml = OptionService::get('Footer', '');
                    // 公开页统一导航/页脚所需：自定义页脚 HTML 与注册/密码登录开关
                    $systemFooter = OptionService::get('SystemFooter', '');
                    $registerEnabled = (bool) OptionService::get('RegisterEnabled', true);
                    $passwordLoginEnabled = (bool) OptionService::get('PasswordLoginEnabled', true);
                    // 短信服务开关：从数据库选项读取（后台系统设置控制）
                    $smsEnabled = (bool) OptionService::get('SmsEnabled', false);
                    $phoneLoginEnabled = (bool) OptionService::get('PhoneLoginEnabled', false);
                    $phoneRegisterEnabled = (bool) OptionService::get('PhoneRegisterEnabled', false);
                    $phonePasswordResetEnabled = (bool) OptionService::get('PhonePasswordResetEnabled', false);
                    $emailVerificationEnabled = (bool) OptionService::get('EmailVerificationEnabled', false);
                }
            } catch (\Throwable $e) {
                // 数据库未迁移或安装前静默回退到默认值
            }
            $view->with([
                'systemName' => $systemName,
                'systemLogo' => $systemLogo,
                'footerHtml' => $footerHtml,
                'systemFooter' => $systemFooter,
                'registerEnabled' => $registerEnabled,
                'passwordLoginEnabled' => $passwordLoginEnabled,
                'smsEnabled' => $smsEnabled,
                'phoneLoginEnabled' => $phoneLoginEnabled,
                'phoneRegisterEnabled' => $phoneRegisterEnabled,
                'phonePasswordResetEnabled' => $phonePasswordResetEnabled,
                'emailVerificationEnabled' => $emailVerificationEnabled,
            ]);
        });
    }
}
