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
        $public = OptionService::loadPublic();
        $pricing = [];
        foreach (['ModelRatio', 'GroupRatio', 'CompletionRatio', 'ModelPrice', 'CacheRatio'] as $key) {
            $pricing[$key] = $public[$key] ?? [];
        }
        $pricing['DisplayInCurrencyEnabled'] = $public['DisplayInCurrencyEnabled'] ?? true;
        $pricing['DisplayTokenStatEnabled'] = $public['DisplayTokenStatEnabled'] ?? true;
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
        // Mask secret keys
        foreach (OptionService::SECRET_KEYS as $key) {
            if (!empty($all[$key])) {
                $all[$key] = '******';
            } else {
                $all[$key] = '';
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
        if (!is_array($options) || empty($options)) {
            $options = $request->except(['_token', '_method']);
        }
        if (!is_array($options) || empty($options)) {
            return response()->json(['success' => false, 'message' => 'No options provided'], 400);
        }

        $updated = [];
        $skipped = [];
        foreach ($options as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            // Skip masked secrets
            if (OptionService::isSecret($key) && ($value === '******' || $value === '')) {
                $skipped[] = $key;
                continue;
            }
            if (!OptionService::isKnown($key)) {
                Log::debug('OptionController.update: unknown key skipped', ['key' => $key]);
                $skipped[] = $key;
                continue;
            }
            OptionService::set($key, $value);
            $updated[] = $key;
        }

        // Clear caches so changes take effect immediately
        OptionService::clearCache();
        Cache::forget('pricing');
        Cache::forget('public_options');

        // Support both HTML form submission (redirect back) and AJAX (JSON)
        if ($request->expectsJson() || $request->isXmlHttpRequest()) {
            return response()->json([
                'success' => true,
                'message' => 'Options updated',
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
        $acknowledged = (bool) $request->input('acknowledged', false);
        if (!$acknowledged) {
            return response()->json(['success' => false, 'message' => 'Acknowledgement required'], 400);
        }
        OptionService::set('PaymentComplianceAcknowledged', true);
        OptionService::set('PaymentComplianceAcknowledgedAt', time());
        return response()->json(['success' => true, 'message' => 'Payment compliance acknowledged']);
    }

    /**
     * GET /option/channel_affinity_cache - 渠道亲和缓存统计（Root）
     */
    public function affinityCacheStat(): JsonResponse
    {
        if (!OptionService::get('ChannelAffinityEnabled', false)) {
            return response()->json(['success' => true, 'data' => ['enabled' => false, 'count' => 0]]);
        }

        $redis = $this->redis();
        if ($redis === null) {
            return response()->json(['success' => true, 'data' => ['enabled' => true, 'count' => 0, 'error' => 'Redis unavailable']]);
        }

        $prefix = config('pease-api.cache_prefix', 'pease:') . 'affinity:';
        try {
            $keys = $redis->keys($prefix . '*');
            $count = is_array($keys) ? count($keys) : 0;
            $samples = [];
            foreach (array_slice((array) $keys, 0, 10) as $key) {
                $shortKey = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
                $samples[$shortKey] = $redis->ttl($key);
            }
            return response()->json([
                'success' => true,
                'data' => [
                    'enabled' => true,
                    'count' => $count,
                    'expire_minutes' => OptionService::get('ChannelAffinityExpireMinutes', 60),
                    'samples' => $samples,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'data' => ['enabled' => true, 'count' => 0, 'error' => $e->getMessage()],
            ]);
        }
    }

    /**
     * DELETE /option/channel_affinity_cache - 清除渠道亲和缓存（Root）
     */
    public function clearAffinityCache(): JsonResponse
    {
        $redis = $this->redis();
        if ($redis === null) {
            return response()->json(['success' => true, 'data' => ['deleted' => 0, 'error' => 'Redis unavailable']]);
        }

        $prefix = config('pease-api.cache_prefix', 'pease:') . 'affinity:';
        try {
            $keys = $redis->keys($prefix . '*');
            $deleted = 0;
            if (is_array($keys) && !empty($keys)) {
                $deleted = $redis->del($keys);
            }
            return response()->json(['success' => true, 'data' => ['deleted' => $deleted]]);
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
        return response()->json(['success' => true, 'message' => 'Model ratios reset to defaults']);
    }

    /**
     * GET /option/waffo-pancake/catalog - Waffo-Pancake 目录（Root）
     */
    public function waffoPancakeCatalog(): JsonResponse
    {
        if (!OptionService::get('WaffoPancakeEnabled', false)) {
            return response()->json(['success' => false, 'message' => 'Waffo-Pancake not enabled'], 400);
        }
        // Placeholder: integrate with Waffo-Pancake API in production
        return response()->json([
            'success' => true,
            'data' => [
                'products' => [],
                'message' => 'Catalog integration pending - configure WaffoPancakeMerchantId/WaffoPancakeApiKey',
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
        return response()->json(['success' => true, 'message' => 'Waffo-Pancake paired']);
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
        return response()->json(['success' => true, 'message' => 'Waffo-Pancake config saved']);
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
            'message' => 'Subscription product created (stub)',
            'data' => [
                'product_id' => 'stub_' . substr(md5(uniqid('', true)), 0, 12),
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
     * Get Redis connection (graceful fallback when Redis unavailable).
     */
    private function redis(): mixed
    {
        try {
            return app('redis');
        } catch (\Throwable $e) {
            return new class {
                public function keys($pattern) { return []; }
                public function ttl($key) { return -2; }
                public function del($keys) { return 0; }
            };
        }
    }
}