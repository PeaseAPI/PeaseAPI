<?php

namespace App\Http\Controllers;

use App\Models\Option;
use App\Services\OptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OptionController extends Controller
{
    // ============================================
    // PUBLIC CONTENT ENDPOINTS (No Auth)
    // ============================================

    /**
     * GET /notice - 系统公告
     */
    public function notice(): JsonResponse
    {
        return $this->contentResponse('Notice', '');
    }

    /**
     * GET /about - 关于页面
     */
    public function about(): JsonResponse
    {
        return $this->contentResponse('About', '');
    }

    /**
     * GET /home_page_content - 首页内容
     */
    public function homePageContent(): JsonResponse
    {
        return $this->contentResponse('HomePageContent', '');
    }

    /**
     * GET /user-agreement - 用户协议
     */
    public function userAgreement(): JsonResponse
    {
        return $this->contentResponse('UserAgreement', '');
    }

    /**
     * GET /privacy-policy - 隐私政策
     */
    public function privacyPolicy(): JsonResponse
    {
        return $this->contentResponse('PrivacyPolicy', '');
    }

    /**
     * GET /pricing - 定价信息（公开）
     */
    public function pricing(): JsonResponse
    {
        // 缓存键 'pricing'：update()/resetModelRatio() 保存后失效（见 docs/settings-reference.md 缓存一节）
        $pricing = Cache::remember('pricing', 300, function () {
            $public = OptionService::loadPublic();
            $data = [];
            foreach (['ModelRatio', 'GroupRatio', 'CompletionRatio', 'ModelPrice', 'CacheRatio'] as $key) {
                $data[$key] = $public[$key] ?? [];
            }
            $data['DisplayInCurrencyEnabled'] = $public['DisplayInCurrencyEnabled'] ?? true;
            $data['DisplayTokenStatEnabled'] = $public['DisplayTokenStatEnabled'] ?? true;

            return $data;
        });

        return response()->json(['success' => true, 'data' => $pricing]);
    }

    // ============================================
    // ROOT-ONLY CONFIGURATION ENDPOINTS
    // ============================================

    /**
     * GET /option/ - 获取全部系统配置（Root）
     */
    public function index(): JsonResponse
    {
        $all = OptionService::loadAll();
        // Mask secret keys (including alias names of secret keys, e.g. GitHubClientSecret)
        foreach (array_keys($all) as $key) {
            if (OptionService::isSecret(OptionService::canonicalKey($key))) {
                $all[$key] = ! empty($all[$key]) ? '******' : '';
            }
        }

        return response()->json(['success' => true, 'data' => $all]);
    }

    /**
     * PUT /option/ - 更新系统配置（Root）
     */
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        // Support both JSON API (flat key=>value) and form POST (options[Key])
        $options = $request->input('options');
        if (! is_array($options) || empty($options)) {
            $options = $request->except(['_token', '_method']);
        }
        if (! is_array($options) || empty($options)) {
            return response()->json(['success' => false, 'message' => __('No options provided')], 400);
        }

        $updated = [];
        $skipped = [];
        foreach ($options as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            // Skip masked secrets (resolve aliases first, e.g. GitHubClientSecret)
            if (OptionService::isSecret(OptionService::canonicalKey($key)) && ($value === '******' || $value === '')) {
                $skipped[] = $key;

                continue;
            }
            if (! OptionService::isKnown($key)) {
                Log::debug('OptionController.update: unknown key skipped', ['key' => $key]);
                $skipped[] = $key;

                continue;
            }
            try {
                OptionService::set($key, $value);
                $updated[] = $key;
            } catch (\InvalidArgumentException $e) {
                // e.g. invalid JSON for a ratio map — surface per-key instead of failing the whole batch
                Log::warning('OptionController.update: invalid value rejected', ['key' => $key, 'error' => $e->getMessage()]);
                $skipped[] = $key.' ('.$e->getMessage().')';
            }
        }

        // Clear caches so changes take effect immediately
        OptionService::clearCache();
        Cache::forget('pricing');
        Cache::forget('public_options');

        // Support both HTML form submission (redirect back) and AJAX (JSON)
        if ($request->expectsJson() || $request->isXmlHttpRequest()) {
            $message = __('Options updated');
            if (! empty($skipped)) {
                // 透出被跳过的键，避免"保存成功"假象（前端会 toast 警告）
                $message .= ' ('.__('skipped: :keys', ['keys' => implode(', ', $skipped)]).')';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => ['updated' => $updated, 'skipped' => $skipped],
            ]);
        }

        return redirect()->route('admin.system-settings')->with('success', __('Settings saved successfully.'));
    }

    /**
     * POST /option/payment_compliance - 确认支付合规（Root）
     */
    public function paymentCompliance(Request $request): JsonResponse
    {
        // 兼容两种载荷：acknowledged=true（文档 / new-api 风格）与 confirmed=true（管理前端 system-settings/api.ts）
        $acknowledged = $request->boolean('acknowledged') || $request->boolean('confirmed');
        if (! $acknowledged) {
            return response()->json(['success' => false, 'message' => __('Acknowledgement required')], 400);
        }
        OptionService::set('PaymentComplianceAcknowledged', true);
        $acknowledgedAt = time();
        OptionService::set('PaymentComplianceAcknowledgedAt', $acknowledgedAt);

        return response()->json([
            'success' => true,
            'message' => __('Payment compliance acknowledged'),
            'data' => [
                'acknowledged_at' => $acknowledgedAt,
                'terms_version' => 'v1',
            ],
        ]);
    }

    /**
     * GET /option/channel_affinity_cache - 渠道亲和缓存统计（Root）
     *
     * 亲和键由 Distributor 在转发成功后经 ChannelAffinityService 写入：
     * channel_affinity:{user_id}:{group}:{model}，值渠道 ID，TTL=ChannelAffinityExpireMinutes 分钟。
     * 响应形状对齐管理前端 CacheStats（total/unknown/by_rule_name/cache_capacity/cache_algo）；
     * 规则型亲和引擎上线前所有键无规则归属（unknown=total，by_rule_name 空）。
     */
    public function affinityCacheStat(): JsonResponse
    {
        $capacity = (int) OptionService::get('channel_affinity_setting.max_entries', 0);

        if (! OptionService::get('ChannelAffinityEnabled', false)) {
            return response()->json([
                'success' => true,
                'data' => $this->affinityStatsData(0, $capacity, false),
            ]);
        }

        $redis = $this->redis();
        if ($redis === null) {
            return response()->json(['success' => true, 'data' => array_merge(
                $this->affinityStatsData(0, $capacity, true),
                ['cache_store' => config('cache.default'), 'error' => __('Redis unavailable')]
            )]);
        }

        $prefix = config('cache.prefix', '').':channel_affinity:';
        try {
            $keys = $this->scanKeys($redis, $prefix);
            $samples = [];
            foreach (array_slice($keys, 0, 10) as $key) {
                $shortKey = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
                $samples[$shortKey] = $redis->ttl($key);
            }

            $data = $this->affinityStatsData(count($keys), $capacity, true);
            $data['expire_minutes'] = OptionService::get('ChannelAffinityExpireMinutes', 60);
            $data['samples'] = $samples;

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'data' => array_merge(
                    $this->affinityStatsData(0, $capacity, true),
                    ['error' => $e->getMessage()]
                ),
            ]);
        }
    }

    /**
     * 亲和统计公共形状（对齐前端 CacheStats；count 为兼容旧字段保留）
     */
    private function affinityStatsData(int $total, int $capacity, bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'total' => $total,
            'count' => $total,
            'unknown' => $total,
            'by_rule_name' => [],
            'cache_capacity' => $capacity,
            'cache_algo' => 'ttl',
        ];
    }

    /**
     * DELETE /option/channel_affinity_cache - 清除渠道亲和缓存（Root）
     *
     * 支持 ?all=true 全清，或 ?rule_name=1:default:gpt-4 清除单个亲和键。
     */
    public function clearAffinityCache(Request $request): JsonResponse
    {
        if ($request->filled('rule_name')) {
            $ruleName = trim((string) $request->input('rule_name'));
            if ($ruleName !== '') {
                Cache::forget('channel_affinity:'.$ruleName);

                return response()->json(['success' => true, 'data' => ['deleted' => 1]]);
            }
        }

        $redis = $this->redis();
        if ($redis === null) {
            return response()->json(['success' => true, 'data' => [
                'deleted' => 0,
                'cache_store' => config('cache.default'),
                'error' => __('Redis unavailable'),
            ]]);
        }

        $prefix = config('cache.prefix', '').':channel_affinity:';
        try {
            $keys = $this->scanKeys($redis, $prefix);
            $deleted = 0;
            if (! empty($keys)) {
                $deleted = $redis->del($keys);
            }

            return response()->json(['success' => true, 'data' => ['deleted' => (int) $deleted]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /option/rest_model_ratio - 重置模型倍率为默认值（Root）
     */
    public function resetModelRatio(): JsonResponse
    {
        // Reset to empty maps; downstream services will rebuild defaults
        OptionService::set('ModelRatio', []);
        OptionService::set('CompletionRatio', []);
        OptionService::set('ModelPrice', []);
        OptionService::set('CacheRatio', []);
        Cache::forget('pricing');

        return response()->json(['success' => true, 'message' => __('Model ratios reset to defaults')]);
    }

    /**
     * GET /option/waffo-pancake/catalog - Waffo-Pancake 目录（Root）
     */
    public function waffoPancakeCatalog(): JsonResponse
    {
        if (! OptionService::get('WaffoPancakeEnabled', false)) {
            return response()->json(['success' => false, 'message' => __('Waffo-Pancake not enabled')], 400);
        }

        // Placeholder: integrate with Waffo-Pancake API in production
        return response()->json([
            'success' => true,
            'data' => [
                'products' => [],
                'message' => __('Catalog integration pending - configure WaffoPancakeMerchantId/WaffoPancakeApiKey'),
            ],
        ]);
    }

    /**
     * POST /option/waffo-pancake/pair - 创建配对（Root）
     */
    public function waffoPancakePair(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id' => 'required|string',
            'api_key' => 'required|string',
        ]);
        OptionService::set('WaffoPancakeMerchantId', $validated['merchant_id']);
        OptionService::set('WaffoPancakeApiKey', $validated['api_key']);

        return response()->json(['success' => true, 'message' => __('Waffo-Pancake paired')]);
    }

    /**
     * POST /option/waffo-pancake/save - 保存 Waffo-Pancake 配置（Root）
     */
    public function waffoPancakeSave(Request $request): JsonResponse
    {
        $data = $request->all();
        $allowed = ['WaffoPancakeEnabled', 'WaffoPancakeMerchantId', 'WaffoPancakeApiKey', 'WaffoPancakeWebhookSecret'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                OptionService::set($key, $data[$key]);
            }
        }

        return response()->json(['success' => true, 'message' => __('Waffo-Pancake config saved')]);
    }

    /**
     * POST /option/waffo-pancake/subscription-product - 创建订阅产品（Root）
     */
    public function waffoPancakeSubscriptionProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'quota' => 'required|integer|min:0',
            'period' => 'sometimes|string|in:monthly,yearly,lifetime',
        ]);

        // Placeholder: call Waffo-Pancake API to create product
        return response()->json([
            'success' => true,
            'message' => __('Subscription product created (stub)'),
            'data' => [
                'product_id' => 'stub_'.substr(md5(uniqid('', true)), 0, 12),
                'name' => $validated['name'],
                'price' => $validated['price'],
                'quota' => $validated['quota'],
                'period' => $validated['period'] ?? 'monthly',
            ],
        ]);
    }

    /**
     * GET /option/waffo-pancake/subscription-product-options - 订阅产品选项（Root）
     */
    public function waffoPancakeSubscriptionOptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'periods' => ['monthly', 'yearly', 'lifetime'],
                'currencies' => ['USD', 'CNY', 'EUR'],
                'features' => ['quota', 'auto_renew', 'reset_period'],
            ],
        ]);
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Return a single text content option with fallback.
     */
    private function contentResponse(string $key, string $default = ''): JsonResponse
    {
        $value = OptionService::get($key, $default);

        return response()->json(['success' => true, 'data' => $value]);
    }

    /**
     * Get Redis connection (null when the cache store is not Redis or Redis is unavailable).
     */
    private function redis(): mixed
    {
        $store = (string) config('cache.default');
        if ((string) config("cache.stores.{$store}.driver") !== 'redis') {
            return null;
        }

        try {
            return app('redis')->connection();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Enumerate redis keys by prefix using SCAN (non-blocking, unlike KEYS).
     */
    private function scanKeys($redis, string $prefix): array
    {
        $keys = [];
        $cursor = null;
        do {
            [$cursor, $batch] = $redis->scan($cursor ?? 0, ['match' => "{$prefix}*", 'count' => 200]);
            foreach ((array) $batch as $key) {
                $keys[] = $key;
            }
        } while (! empty($cursor) && (int) $cursor !== 0);

        return $keys;
    }
}
