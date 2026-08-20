<?php

declare(strict_types=1);

namespace App\Relay\Constant;

/**
 * Relay 模式常量
 * 对标 new-api relay/constant/relay_mode.go
 */
class RelayMode
{
    public const Chat = 'chat';

    public const ChatCompletions = 'chat';

    public const Completions = 'completions';

    public const Embeddings = 'embeddings';

    public const ImageGenerations = 'image_generations';

    public const ImageEdits = 'image_edits';

    public const AudioTranscriptions = 'audio_transcriptions';

    public const AudioTranslations = 'audio_translations';

    public const AudioSpeech = 'audio_speech';

    public const Rerank = 'rerank';

    public const Moderations = 'moderations';

    public const Responses = 'responses';

    public const ResponsesCompact = 'responses_compact';

    public const ClaudeMessages = 'claude_messages';

    public const GeminiChat = 'gemini_chat';

    public const Realtime = 'realtime';

    public static function fromPath(string $path): string
    {
        return match (true) {
            str_contains($path, 'chat/completions') => self::Chat,
            str_contains($path, 'completions') && ! str_contains($path, 'chat') => self::Completions,
            str_contains($path, 'embeddings') => self::Embeddings,
            str_contains($path, 'images/generations') => self::ImageGenerations,
            str_contains($path, 'images/edits') => self::ImageEdits,
            str_contains($path, 'audio/transcriptions') => self::AudioTranscriptions,
            str_contains($path, 'audio/translations') => self::AudioTranslations,
            str_contains($path, 'audio/speech') => self::AudioSpeech,
            str_contains($path, 'rerank') => self::Rerank,
            str_contains($path, 'moderations') => self::Moderations,
            str_contains($path, 'responses/compact') => self::ResponsesCompact,
            str_contains($path, 'responses') => self::Responses,
            str_contains($path, 'messages') => self::ClaudeMessages,
            str_contains($path, 'v1beta/models') || str_contains($path, 'gemini') => self::GeminiChat,
            str_contains($path, 'realtime') => self::Realtime,
            default => self::Chat,
        };
    }

    public static function toFormat(string $mode): string
    {
        return match ($mode) {
            self::Chat => RelayFormat::OpenAI,
            self::Completions => RelayFormat::OpenAI,
            self::Embeddings => RelayFormat::Embedding,
            self::ImageGenerations, self::ImageEdits => RelayFormat::OpenAIImage,
            self::AudioTranscriptions, self::AudioTranslations, self::AudioSpeech => RelayFormat::OpenAIAudio,
            self::Rerank => RelayFormat::Rerank,
            self::Moderations => RelayFormat::OpenAIModeration,
            self::Responses => RelayFormat::OpenAIResponses,
            self::ResponsesCompact => RelayFormat::OpenAIResponsesCompaction,
            self::ClaudeMessages => RelayFormat::Claude,
            self::GeminiChat => RelayFormat::Gemini,
            self::Realtime => RelayFormat::OpenAIRealtime,
            default => RelayFormat::OpenAI,
        };
    }
}
