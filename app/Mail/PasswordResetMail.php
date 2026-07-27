<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $link,
        public readonly string $email,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '密码重置',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.password-reset',
            with: [
                'link' => $this->link,
                'email' => $this->email,
                'systemName' => \App\Services\OptionService::get('SystemName', config('app.name', 'Pease API')),
            ],
        );
    }
}