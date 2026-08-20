<?php

namespace App\Services;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\Log;
use App\Models\Token;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log as LaravelLog;

class RelayService
{
    public function relay(Token $token, string $abilityName, array $payload, ?string $requestId = null): array
    {
        $ability = Ability::where('name', $abilityName)->where('enabled', true)->first();
        if (! $ability) {
            return ['success' => false, 'error' => __('Ability not found or disabled'), 'status' => 404];
        }

        $tokenAbility = $token->abilities()->where('ability_id', $ability->id)->first();
        if (! $tokenAbility) {
            return ['success' => false, 'error' => __('Token does not have this ability'), 'status' => 403];
        }

        $channels = Channel::whereHas('abilities', function ($q) use ($ability) {
            $q->where('ability_id', $ability->id);
        })->where('status', 1)->orderBy('priority', 'desc')->get();

        if ($channels->isEmpty()) {
            return ['success' => false, 'error' => __('No available channels'), 'status' => 503];
        }

        $lastError = null;
        foreach ($channels as $channel) {
            try {
                $response = $this->sendRequest($channel, $ability, $payload);
                if ($response['success']) {
                    $this->logRequest($token, $channel, $ability, $response, $requestId);

                    return $response;
                }
                $lastError = $response['error'] ?? 'Unknown error';
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                LaravelLog::error("Channel {$channel->id} failed: ".$e->getMessage());

                continue;
            }
        }

        return ['success' => false, 'error' => $lastError ?? 'All channels failed', 'status' => 502];
    }

    protected function sendRequest(Channel $channel, Ability $ability, array $payload): array
    {
        $baseUrl = rtrim($channel->base_url, '/');
        $path = $ability->setting['path'] ?? '';
        $url = $baseUrl.$path;

        $headers = [];
        if ($channel->type === 1) { // OpenAI
            $headers['Authorization'] = 'Bearer '.$channel->key;
        } elseif ($channel->type === 2) { // Anthropic
            $headers['x-api-key'] = $channel->key;
            $headers['anthropic-version'] = '2023-06-01';
        } else {
            $headers['Authorization'] = 'Bearer '.$channel->key;
        }

        $timeout = config('pease-api.relay.timeout', 120);
        $response = Http::withHeaders($headers)->timeout($timeout)->post($url, $payload);

        if ($response->successful()) {
            $data = $response->json();

            return ['success' => true, 'data' => $data, 'status' => $response->status()];
        }

        return ['success' => false, 'error' => $response->body(), 'status' => $response->status()];
    }

    protected function logRequest(Token $token, Channel $channel, Ability $ability, array $response, ?string $requestId): void
    {
        $data = $response['data'] ?? [];
        $usage = $data['usage'] ?? [];

        Log::create([
            'user_id' => $token->user_id,
            'token_id' => $token->id,
            'channel_id' => $channel->id,
            'ability_id' => $ability->id,
            'type' => $ability->api_type,
            'model' => $data['model'] ?? '',
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'quota' => ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0),
            'request_id' => $requestId ?? uniqid(),
            'ip' => request()->ip(),
            'detail' => json_encode($data),
            'created_time' => time(),
        ]);
    }
}
