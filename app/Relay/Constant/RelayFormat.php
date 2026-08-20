<?php

declare(strict_types=1);

namespace App\Relay\Constant;

/**
 * Relay 格式类型
 * 对标 new-api relay/constant/relay_format.go
 */
class RelayFormat
{
    public const string OpenAI = 'openai';                       // OpenAI Chat

    public const string OpenAICompletions = 'openai_completions'; // OpenAI Completions

    public const string OpenAIResponses = 'openai_responses';              // OpenAI Responses

    public const string OpenAIResponsesCompaction = 'openai_responses_compaction';    // 紧凑 Responses

    public const string OpenAIImage = 'openai_image';                  // OpenAI Image

    public const string OpenAIAudio = 'openai_audio';                  // OpenAI Audio

    public const string Claude = 'claude';                      // Claude Messages

    public const string Gemini = 'gemini';                      // Gemini

    public const string Embedding = 'embedding';                   // Embedding

    public const string Rerank = 'rerank';                      // Rerank

    public const string OpenAIModeration = 'openai_moderation';           // OpenAI Moderation

    public const string OpenAIRealtime = 'openai_realtime';              // WebSocket Realtime
}
