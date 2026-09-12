<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\EmailService;
use Illuminate\Mail\Mailable;

/**
 * 所有外发邮件的基类。
 *
 * SMTP 配置运行时存于 options 表（管理端可热更，见 EmailService::applySmtpConfig）。
 * 经队列发送的邮件在 queue worker 进程中投递，而 worker 的 config 只来自 .env
 * 静态文件——web 进程入队前 applySmtpConfig() 注入的配置不会随 payload 传递
 * （入队时 Mailer 只会把 mailer 名，如 'smtp'，盖到 mailable 上，见
 * Illuminate\Mail\Mailer::queue 的 $view->mailer($this->name)）。
 * 因此必须在发送进程内于 parent::send 前重新应用运行时 SMTP 配置，
 * 否则 worker 会按 .env 残留值解析 smtp 邮件器导致投递失败。
 */
abstract class BaseMailable extends Mailable
{
    public function send($mailer)
    {
        app(EmailService::class)->applySmtpConfig();

        return parent::send($mailer);
    }
}
