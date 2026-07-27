<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $url, public array $payload) {}

    public function handle(): void
    {
        try {
            \Illuminate\Support\Facades\Http::timeout(30)->post($this->url, $this->payload);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Webhook failed: " . $e->getMessage());
        }
    }
}
