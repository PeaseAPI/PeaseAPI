<?php

/**
 * Relay 路由 - 完全对标 new-api 的 relay-router.go
 * 支持所有 OpenAI 兼容 API
 */

use App\Http\Controllers\MidjourneyController;
use App\Http\Controllers\ModelController;
use App\Http\Controllers\RelayController;
use App\Http\Controllers\SunoController;
use App\Http\Controllers\VideoController;
use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\Cors;
use App\Http\Middleware\DecompressRequest;
use App\Http\Middleware\Distributor;
use App\Http\Middleware\ModelRateLimit;
use App\Http\Middleware\Stats;
use App\Http\Middleware\SystemPerformanceCheck;
use App\Http\Middleware\TokenAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Relay Routes (OpenAI Compatible API)
|--------------------------------------------------------------------------
|
| 对标 new-api relay-router.go
| 支持 /v1, /v1beta, /mj, /suno, /v1/video 等路由
|
*/

// 路由分组 - 带必要的中间件
$relayMiddleware = [
    Stats::class,
    Cors::class,
    DecompressRequest::class,
    TokenAuth::class,
    ApiRateLimit::class,
];

// ==================== /v1/models ====================
Route::middleware($relayMiddleware)->group(function () {
    // 模型列表
    Route::get('/v1/models', [ModelController::class, 'list']);
    Route::get('/v1/models/{model}', [ModelController::class, 'retrieve']);

    // Gemini 模型
    Route::get('/v1beta/models', [ModelController::class, 'listGemini']);
    Route::get('/v1beta/openai/models', [ModelController::class, 'list']);
});

// ==================== /v1 核心 API ====================
// 路由组包含 Distributor 中间件，用于选择合适的渠道
$relayWithDistributor = array_merge($relayMiddleware, [
    Distributor::class,
    ModelRateLimit::class,
    SystemPerformanceCheck::class,
]);

Route::middleware($relayWithDistributor)->prefix('v1')->group(function () {
    // Chat Completions (主要 API)
    Route::post('/chat/completions', [RelayController::class, 'chatCompletions']);
    Route::post('/completions', [RelayController::class, 'completions']);

    // Responses API
    Route::post('/responses', [RelayController::class, 'responses']);
    Route::post('/responses/compact', [RelayController::class, 'responsesCompact']);

    // Embeddings
    Route::post('/embeddings', [RelayController::class, 'embeddings']);

    // Image Generation
    Route::post('/images/generations', [RelayController::class, 'imageGenerations']);
    Route::post('/images/edits', [RelayController::class, 'imageEdits']);
    Route::post('/edits', [RelayController::class, 'edits']);

    // Audio
    Route::post('/audio/transcriptions', [RelayController::class, 'audioTranscriptions']);
    Route::post('/audio/translations', [RelayController::class, 'audioTranslations']);
    Route::post('/audio/speech', [RelayController::class, 'audioSpeech']);

    // Rerank
    Route::post('/rerank', [RelayController::class, 'rerank']);

    // Moderations
    Route::post('/moderations', [RelayController::class, 'moderations']);

    // Claude Messages API
    Route::post('/messages', [RelayController::class, 'claudeMessages']);

    // Gemini relay routes
    Route::post('/engines/{model}/embeddings', [RelayController::class, 'geminiEmbeddings']);
    Route::post('/models/{path}', [RelayController::class, 'geminiRelay'])->where(['path' => '.*']);

    // Not implemented
    Route::post('/images/variations', [RelayController::class, 'notImplemented']);
    Route::get('/files', [RelayController::class, 'notImplemented']);
    Route::post('/files', [RelayController::class, 'notImplemented']);
    Route::delete('/files/{id}', [RelayController::class, 'notImplemented']);
    Route::get('/files/{id}', [RelayController::class, 'notImplemented']);
    Route::get('/files/{id}/content', [RelayController::class, 'notImplemented']);
    Route::post('/fine-tunes', [RelayController::class, 'notImplemented']);
    Route::get('/fine-tunes', [RelayController::class, 'notImplemented']);
    Route::get('/fine-tunes/{id}', [RelayController::class, 'notImplemented']);
    Route::post('/fine-tunes/{id}/cancel', [RelayController::class, 'notImplemented']);
    Route::get('/fine-tunes/{id}/events', [RelayController::class, 'notImplemented']);
    Route::delete('/models/{model}', [RelayController::class, 'notImplemented']);
});

// ==================== /v1beta Gemini ====================
Route::middleware($relayWithDistributor)->prefix('v1beta')->group(function () {
    Route::post('/models/{path}', [RelayController::class, 'geminiRelay'])->where(['path' => '.*']);
});

// ==================== WebSocket Realtime ====================
$wsMiddleware = [
    Stats::class,
    Cors::class,
    TokenAuth::class,
    Distributor::class,
];

Route::middleware($wsMiddleware)->get('/v1/realtime', [RelayController::class, 'realtime']);

// ==================== Playground ====================
Route::middleware([Stats::class, Cors::class, TokenAuth::class, Distributor::class])
    ->post('/pg/chat/completions', [RelayController::class, 'playground']);

// ==================== Midjourney ====================
$mjMiddleware = [Stats::class, TokenAuth::class, SystemPerformanceCheck::class];

Route::middleware($mjMiddleware)->prefix('mj')->group(function () {
    Route::get('/image/{id}', [MidjourneyController::class, 'getImage']);
    Route::post('/submit/action', [MidjourneyController::class, 'submitAction']);
    Route::post('/submit/shorten', [MidjourneyController::class, 'submitShorten']);
    Route::post('/submit/modal', [MidjourneyController::class, 'submitModal']);
    Route::post('/submit/imagine', [MidjourneyController::class, 'submitImagine']);
    Route::post('/submit/change', [MidjourneyController::class, 'submitChange']);
    Route::post('/submit/simple-change', [MidjourneyController::class, 'submitSimpleChange']);
    Route::post('/submit/describe', [MidjourneyController::class, 'submitDescribe']);
    Route::post('/submit/blend', [MidjourneyController::class, 'submitBlend']);
    Route::post('/submit/edits', [MidjourneyController::class, 'submitEdits']);
    Route::post('/submit/video', [MidjourneyController::class, 'submitVideo']);
    Route::get('/task/{id}/fetch', [MidjourneyController::class, 'fetchTask']);
    Route::get('/task/{id}/image-seed', [MidjourneyController::class, 'getImageSeed']);
    Route::post('/task/list-by-condition', [MidjourneyController::class, 'listByCondition']);
    Route::post('/insight-face/swap', [MidjourneyController::class, 'insightFaceSwap']);
    Route::post('/submit/upload-discord-images', [MidjourneyController::class, 'uploadDiscordImages']);
});

// /:mode/mj 路由 (支持多模式)
Route::middleware($mjMiddleware)->prefix('{mode}/mj')->where(['mode' => '(?:fast|relax|relaxturbo)?'])->group(function () {
    Route::get('/image/{id}', [MidjourneyController::class, 'getImage']);
    Route::post('/submit/action', [MidjourneyController::class, 'submitAction']);
    Route::post('/submit/imagine', [MidjourneyController::class, 'submitImagine']);
    Route::post('/submit/change', [MidjourneyController::class, 'submitChange']);
    Route::get('/task/{id}/fetch', [MidjourneyController::class, 'fetchTask']);
});

// ==================== Suno ====================
$sunoMiddleware = [Stats::class, TokenAuth::class, Distributor::class, SystemPerformanceCheck::class];

Route::middleware($sunoMiddleware)->prefix('suno')->group(function () {
    Route::post('/submit/{action}', [SunoController::class, 'submit']);
    Route::post('/fetch', [SunoController::class, 'fetch']);
    Route::get('/fetch/{id}', [SunoController::class, 'fetchOne']);
});

// ==================== Video ====================
$videoMiddleware = [Stats::class, TokenAuth::class, Distributor::class, SystemPerformanceCheck::class];

Route::middleware($videoMiddleware)->prefix('v1')->group(function () {
    Route::post('/video/generations', [VideoController::class, 'generate']);
    Route::get('/video/generations/{task_id}', [VideoController::class, 'getVideo']);
    Route::post('/videos/{video_id}/remix', [VideoController::class, 'remix']);
    Route::post('/videos', [VideoController::class, 'create']);
    Route::get('/videos/{task_id}', [VideoController::class, 'getVideo']);
    Route::get('/videos/{task_id}/content', [VideoController::class, 'getVideoContent']);
});

// Kling
Route::middleware($videoMiddleware)->prefix('kling/v1')->group(function () {
    Route::post('/videos/text2video', [VideoController::class, 'klingText2Video']);
    Route::post('/videos/image2video', [VideoController::class, 'klingImage2Video']);
    Route::get('/videos/text2video/{task_id}', [VideoController::class, 'getKlingVideo']);
    Route::get('/videos/image2video/{task_id}', [VideoController::class, 'getKlingVideo']);
});

// Jimeng (即梦)
Route::middleware($videoMiddleware)->prefix('jimeng')->group(function () {
    Route::any('{path}', [VideoController::class, 'jimeng'])->where(['path' => '.*']);
});

// ==================== Dashboard ====================
$dashboardMiddleware = [Stats::class, Cors::class, TokenAuth::class];

Route::middleware($dashboardMiddleware)->group(function () {
    Route::get('/dashboard/billing/subscription', [RelayController::class, 'dashboardSubscription']);
    Route::get('/v1/dashboard/billing/subscription', [RelayController::class, 'dashboardSubscription']);
    Route::get('/dashboard/billing/usage', [RelayController::class, 'dashboardUsage']);
    Route::get('/v1/dashboard/billing/usage', [RelayController::class, 'dashboardUsage']);
});
