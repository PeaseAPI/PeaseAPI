<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $code,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '邮箱验证码',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification',
            with: [
                'code' => $this->code,
                'systemName' => \App\Services\OptionService::get('SystemName', config('app.name', 'Pease API')),
            ],
        );
    }
}