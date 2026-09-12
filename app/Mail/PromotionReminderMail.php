<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CodingPlanPromotion;
use App\Services\OptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Coding Plan 厂商活动到期提醒（P2-3）：通知订阅对应厂商套餐的用户。
 */
class PromotionReminderMail extends BaseMailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly CodingPlanPromotion $promotion,
        public readonly int $remainingDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【活动提醒】{$this->promotion->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.promotion-reminder',
            with: [
                'systemName' => OptionService::get('SystemName', config('app.name', 'Pease API')),
                'title' => $this->promotion->title,
                'description' => (string) $this->promotion->description,
                'vendor' => (string) $this->promotion->vendor,
                'kind' => (string) $this->promotion->kind,
                'endsAt' => date('Y-m-d H:i', (int) $this->promotion->ends_at),
                'remainingDays' => $this->remainingDays,
                'sourceUrl' => (string) $this->promotion->source_url,
            ],
        );
    }
}
