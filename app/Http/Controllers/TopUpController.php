<?php

namespace App\Http\Controllers;

use App\Models\TopUp;
use App\Models\User;
use App\Services\AlipayService;
use App\Services\OptionService;
use App\Services\WechatPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TopUpController extends Controller
{
    /**
     * 充值信息（用户）
     * GET /api/user/topup/info
     */
    public function info(Request $request)
    {
        $user = $request->user();

        // 构建可用支付方式列表
        $paymentMethods = [];
        $epayEnabled = (bool) OptionService::get('EpayId');

        // 易支付模式：从 PayMethods 配置读取（微信/支付宝/QQ/网银等）
        if ($epayEnabled) {
            $payMethods = json_decode(OptionService::get('PayMethods', '[]'), true) ?: [];
            if (! empty($payMethods)) {
                foreach ($payMethods as $method) {
                    $paymentMethods[] = [
                        'name' => $method['name'] ?? '',
                        'icon' => $method['icon'] ?? '',
                        'type' => $method['type'] ?? '',
                        'min_topup' => isset($method['min_topup']) ? (int) $method['min_topup'] : 0,
                        'gateway' => 'epay',
                    ];
                }
            } else {
                // 默认提供支付宝和微信
                $paymentMethods[] = ['name' => '支付宝', 'icon' => 'SiAlipay', 'type' => 'alipay', 'min_topup' => 0, 'gateway' => 'epay'];
                $paymentMethods[] = ['name' => '微信支付', 'icon' => 'SiWechat', 'type' => 'wxpay', 'min_topup' => 0, 'gateway' => 'epay'];
            }
        }

        // Stripe
        if (OptionService::get('StripeApiKeys')) {
            $paymentMethods[] = ['name' => 'Stripe', 'icon' => 'SiStripe', 'type' => 'stripe', 'min_topup' => 0, 'gateway' => 'stripe'];
        }

        // Creem
        if (OptionService::get('CreemApiKey')) {
            $paymentMethods[] = ['name' => 'Creem', 'icon' => 'LuCreditCard', 'type' => 'creem', 'min_topup' => 0, 'gateway' => 'creem'];
        }

        // 原生微信支付 V3
        if (OptionService::get('WechatPayEnabled') && OptionService::get('WechatPayMchId')) {
            $paymentMethods[] = [
                'name' => '微信支付',
                'icon' => 'SiWechat',
                'type' => 'native_wechat',
                'min_topup' => 0,
                'gateway' => 'native_wechat',
            ];
        }

        // 原生支付宝
        if (OptionService::get('AlipayEnabled') && OptionService::get('AlipayAppId')) {
            $paymentMethods[] = [
                'name' => '支付宝',
                'icon' => 'SiAlipay',
                'type' => 'native_alipay',
                'min_topup' => 0,
                'gateway' => 'native_alipay',
            ];
        }

        return $this->success([
            'quota' => $user->quota,
            'used_quota' => $user->used_quota,
            'amount_enabled' => (bool) OptionService::get('TopUpEnabled', true),
            'min_amount' => (int) OptionService::get('MinTopUpAmount', 1),
            'epay_enabled' => $epayEnabled,
            'stripe_enabled' => (bool) OptionService::get('StripeApiKeys'),
            'creem_enabled' => (bool) OptionService::get('CreemApiKey'),
            'waffo_enabled' => (bool) OptionService::get('WaffoMerchantId'),
            'wechat_enabled' => (bool) OptionService::get('WechatPayEnabled'),
            'alipay_enabled' => (bool) OptionService::get('AlipayEnabled'),
            'top_up_link' => OptionService::get('TopUpLink', ''),
            'payment_methods' => $paymentMethods,
        ]);
    }

    /**
     * 用户充值记录
     * GET /api/user/topup/self
     */
    public function selfList(Request $request)
    {
        $user = $request->user();
        $records = TopUp::where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 10));

        return $this->paginate($records);
    }

    /**
     * 所有充值记录（管理员）
     * GET /api/user/topup
     */
    public function adminList(Request $request)
    {
        $this->requireAdmin($request);
        $query = TopUp::query();
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        $records = $query->orderByDesc('id')->paginate((int) $request->input('per_page', 10));

        return $this->paginate($records);
    }

    /**
     * 计算金额（易支付）
     * POST /api/user/amount
     */
    public function calcAmount(Request $request)
    {
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }
        $price = (float) OptionService::get('Price', 0.01);
        $money = round($amount * $price, 2);

        return $this->success([
            'amount' => $amount,
            'money' => $money,
            'price' => $price,
        ]);
    }

    /**
     * 易支付下单
     * POST /api/user/pay
     *
     * 支持的支付方式（通过 payment_type 参数选择）：
     * - alipay: 支付宝支付
     * - wxpay:  微信支付
     * - qqpay:  QQ支付
     * - bank:   网银支付
     */
    public function epayPay(Request $request)
    {
        $user = $request->user();
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }

        // 支付方式：从前端传入，默认支付宝
        $paymentType = $request->input('payment_type', 'alipay');
        $allowedTypes = ['alipay', 'wxpay', 'qqpay', 'bank'];
        if (! in_array($paymentType, $allowedTypes)) {
            return $this->error('不支持的支付方式，可选: alipay(支付宝), wxpay(微信), qqpay(QQ), bank(网银)');
        }

        $price = (float) OptionService::get('Price', 0.01);
        $money = round($amount * $price, 2);
        $tradeNo = 'TU'.date('YmdHis').Str::random(8);

        $topUp = TopUp::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'money' => $money,
            'trade_no' => $tradeNo,
            'trade_no_internal' => $tradeNo,
            'status' => 0,
            'payment_method' => 'epay_'.$paymentType,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $epayId = OptionService::get('EpayId');
        $epayKey = OptionService::get('EpayKey');
        $epayUrl = OptionService::get('EpayUrl', OptionService::get('PayAddress', ''));
        if (! $epayId || ! $epayKey) {
            return $this->error('易支付未配置，请在后台系统设置 > 支付配置中填写易支付商户ID和密钥');
        }
        if (! $epayUrl) {
            return $this->error('易支付网关地址未配置，请在后台系统设置 > 支付配置中填写易支付网关地址');
        }

        // 构造易支付跳转 URL
        $params = [
            'pid' => $epayId,
            'type' => $paymentType,
            'out_trade_no' => $tradeNo,
            'notify_url' => url('/api/user/epay/notify'),
            'return_url' => url('/wallet'),
            'name' => '充值 '.$amount.' 额度',
            'money' => sprintf('%.2f', $money),
            'sign_type' => 'MD5',
        ];
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '') {
                $signStr .= $k.'='.$v.'&';
            }
        }
        $signStr = rtrim($signStr, '&').$epayKey;
        $params['sign'] = md5($signStr);

        $payUrl = rtrim($epayUrl, '/').'/submit.php?'.http_build_query($params);

        return $this->success([
            'trade_no' => $tradeNo,
            'pay_url' => $payUrl,
        ]);
    }

    /**
     * 易支付回调
     * GET/POST /api/user/epay/notify
     */
    public function epayNotify(Request $request)
    {
        $tradeNo = $request->input('out_trade_no');
        $tradeStatus = $request->input('trade_status');
        $money = $request->input('money');
        $sign = $request->input('sign');

        $epayKey = OptionService::get('EpayKey');
        if (! $epayKey) {
            return response('fail');
        }

        // 验签
        $params = $request->except(['sign', 'sign_type']);
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '') {
                $signStr .= $k.'='.$v.'&';
            }
        }
        $signStr = rtrim($signStr, '&').$epayKey;
        $expectedSign = md5($signStr);
        if (! is_string($sign) || ! hash_equals($expectedSign, $sign)) {
            Log::warning('epay notify sign error', ['trade_no' => $tradeNo]);

            return response('fail');
        }

        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return response('fail');
        }

        $this->completeTopUp($tradeNo, (float) $money, $request->input('trade_no', ''));

        return response('success');
    }

    /**
     * Stripe 金额
     * POST /api/user/stripe/amount
     */
    public function stripeAmount(Request $request)
    {
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }
        $price = (float) OptionService::get('StripePrice', 0.01);
        $money = round($amount * $price, 2);

        return $this->success(['amount' => $amount, 'money' => $money]);
    }

    /**
     * Stripe 支付
     * POST /api/user/stripe/pay
     */
    public function stripePay(Request $request)
    {
        $user = $request->user();
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }

        $price = (float) OptionService::get('StripePrice', 0.01);
        $money = round($amount * $price, 2);
        $tradeNo = 'ST'.date('YmdHis').Str::random(8);

        $topUp = TopUp::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'money' => $money,
            'trade_no' => $tradeNo,
            'trade_no_internal' => $tradeNo,
            'status' => 0,
            'payment_method' => 'stripe',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $apiKey = OptionService::get('StripeApiKeys');
        if (! $apiKey) {
            return $this->error('Stripe 未配置');
        }

        // 简化：返回 payment intent 信息
        return $this->success([
            'trade_no' => $tradeNo,
            'client_secret' => '',
            'amount' => (int) ($money * 100),
            'currency' => OptionService::get('StripeCurrency', 'usd'),
        ]);
    }

    /**
     * Stripe Webhook
     * POST /api/stripe/webhook
     */
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        if (! $event || ! isset($event['type'])) {
            return response('invalid', 400);
        }

        if ($event['type'] === 'checkout.session.completed' || $event['type'] === 'payment_intent.succeeded') {
            $data = $event['data']['object'] ?? [];
            $tradeNo = $data['client_reference_id'] ?? ($data['metadata']['trade_no'] ?? '');
            $money = ($data['amount_total'] ?? 0) / 100;
            $paymentId = $data['id'] ?? '';
            if ($tradeNo) {
                $this->completeTopUp($tradeNo, (float) $money, $paymentId);
            }
        }

        return response('success');
    }

    /**
     * Creem 支付
     * POST /api/user/creem/pay
     */
    public function creemPay(Request $request)
    {
        $user = $request->user();
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }

        $price = (float) OptionService::get('CreemPrice', 0.01);
        $money = round($amount * $price, 2);
        $tradeNo = 'CR'.date('YmdHis').Str::random(8);

        TopUp::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'money' => $money,
            'trade_no' => $tradeNo,
            'trade_no_internal' => $tradeNo,
            'status' => 0,
            'payment_method' => 'creem',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $this->success(['trade_no' => $tradeNo]);
    }

    /**
     * Creem Webhook
     * POST /api/creem/webhook
     */
    public function creemWebhook(Request $request)
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        if (! $event || ! isset($event['event_type'])) {
            return response('invalid', 400);
        }

        if ($event['event_type'] === 'checkout.completed' || $event['event_type'] === 'payment.completed') {
            $data = $event['object'] ?? [];
            $tradeNo = $data['metadata']['trade_no'] ?? '';
            $money = $data['amount'] ?? 0;
            $paymentId = $data['id'] ?? '';
            if ($tradeNo) {
                $this->completeTopUp($tradeNo, (float) $money, $paymentId);
            }
        }

        return response('success');
    }

    /**
     * Waffo 金额
     * POST /api/user/waffo/amount
     */
    public function waffoAmount(Request $request)
    {
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }
        $price = (float) OptionService::get('WaffoPrice', 0.01);
        $money = round($amount * $price, 2);

        return $this->success(['amount' => $amount, 'money' => $money]);
    }

    /**
     * Waffo 支付
     * POST /api/user/waffo/pay
     */
    public function waffoPay(Request $request)
    {
        $user = $request->user();
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }

        $price = (float) OptionService::get('WaffoPrice', 0.01);
        $money = round($amount * $price, 2);
        $tradeNo = 'WA'.date('YmdHis').Str::random(8);

        TopUp::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'money' => $money,
            'trade_no' => $tradeNo,
            'trade_no_internal' => $tradeNo,
            'status' => 0,
            'payment_method' => 'waffo',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $this->success(['trade_no' => $tradeNo]);
    }

    /**
     * Waffo Webhook
     * POST /api/waffo/webhook
     */
    public function waffoWebhook(Request $request)
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        if (! $event) {
            return response('invalid', 400);
        }

        $tradeNo = $event['out_trade_no'] ?? ($event['metadata']['trade_no'] ?? '');
        $money = $event['money'] ?? 0;
        $paymentId = $event['trade_no'] ?? ($event['id'] ?? '');
        $status = $event['trade_status'] ?? ($event['status'] ?? '');
        if ($tradeNo && $status === 'success') {
            $this->completeTopUp($tradeNo, (float) $money, $paymentId);
        }

        return response('success');
    }

    /**
     * 管理员手动完成充值
     * POST /api/user/topup/complete
     */
    public function adminComplete(Request $request)
    {
        $this->requireAdmin($request);
        $request->validate(['trade_no' => 'required|string']);
        $tradeNo = $request->input('trade_no');
        $topUp = TopUp::where('trade_no', $tradeNo)->first();
        if (! $topUp) {
            return $this->error('订单不存在');
        }
        $this->completeTopUp($tradeNo, (float) $topUp->money, 'admin_manual');

        return $this->success();
    }

    /**
     * 完成充值订单（幂等）
     */
    private function completeTopUp(string $tradeNo, float $money, string $paymentId = ''): void
    {
        $topUp = TopUp::where('trade_no', $tradeNo)->lockForUpdate()->first();
        if (! $topUp || $topUp->status === 1) {
            return;
        }

        DB::transaction(function () use ($topUp, $paymentId) {
            $topUp->status = 1;
            $topUp->payment_id = $paymentId;
            $topUp->updated_at = time();
            $topUp->save();

            $user = User::find($topUp->user_id);
            if ($user) {
                $user->quota += $topUp->amount;
                $user->save();
            }
        });

        Log::info('topup completed', ['trade_no' => $tradeNo, 'amount' => $topUp->amount]);
    }

    private function requireAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user || $user->role < 10) {
            abort(403, __('Admin permission required'));
        }
    }

    /**
     * 原生微信支付 V3 下单（Native 扫码）
     * POST /api/user/wechat/pay
     */
    public function wechatPay(Request $request)
    {
        $user = $request->user();
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }

        if (! OptionService::get('WechatPayEnabled')) {
            return $this->error('微信支付未启用');
        }

        $service = new WechatPayService;
        if (! $service->isConfigured()) {
            return $this->error('微信支付未配置完整，请在后台填写 AppId/商户号/密钥/证书');
        }

        $price = (float) OptionService::get('Price', 0.01);
        $money = round($amount * $price, 2);
        $amountFen = (int) round($money * 100);
        $tradeNo = 'WX'.date('YmdHis').Str::random(8);

        TopUp::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'money' => $money,
            'trade_no' => $tradeNo,
            'trade_no_internal' => $tradeNo,
            'status' => 0,
            'payment_method' => 'wechat_native',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $notifyUrl = (string) OptionService::get('WechatPayNotifyUrl', '');
        if ($notifyUrl === '') {
            $notifyUrl = url('/api/wechat/notify');
        }

        try {
            $result = $service->createNativeOrder(
                $tradeNo,
                $amountFen,
                '充值 '.$amount.' 额度',
                $notifyUrl
            );
        } catch (\Throwable $e) {
            Log::error('wechatPay order failed', ['trade_no' => $tradeNo, 'err' => $e->getMessage()]);

            return $this->error('微信下单失败: '.$e->getMessage());
        }

        return $this->success([
            'trade_no' => $tradeNo,
            'code_url' => $result['code_url'],
            'pay_type' => 'qrcode',
        ]);
    }

    /**
     * 原生微信支付回调
     * POST /api/wechat/notify
     */
    public function wechatNotify(Request $request)
    {
        $body = $request->getContent();
        $headers = array_change_key_case($request->headers->all(), CASE_LOWER);
        // 取出单个值（header 可能是数组）
        $flatHeaders = [];
        foreach ($headers as $k => $v) {
            $flatHeaders[$k] = is_array($v) ? ($v[0] ?? '') : $v;
        }

        $service = new WechatPayService;
        $data = $service->verifyNotify($flatHeaders, $body);
        if ($data === null) {
            Log::warning('wechat notify verify failed', ['body' => $body]);

            return response('fail', 400);
        }

        $tradeNo = $data['out_trade_no'] ?? '';
        $tradeState = $data['trade_state'] ?? '';
        $transactionId = $data['transaction_id'] ?? '';
        $amountTotal = $data['amount']['total'] ?? 0;
        $money = $amountTotal > 0 ? $amountTotal / 100.0 : 0.0;

        if ($tradeState !== 'SUCCESS') {
            return response('success');
        }

        $this->completeTopUp($tradeNo, (float) $money, $transactionId);

        return response('success');
    }

    /**
     * 原生支付宝下单（当面付扫码 / 手机网站）
     * POST /api/user/alipay/pay
     */
    public function alipayPay(Request $request)
    {
        $user = $request->user();
        $amount = (int) $request->input('amount', 0);
        if ($amount <= 0) {
            return $this->error('充值额度必须大于 0');
        }

        if (! OptionService::get('AlipayEnabled')) {
            return $this->error('支付宝支付未启用');
        }

        $service = new AlipayService;
        if (! $service->isConfigured()) {
            return $this->error('支付宝未配置完整，请在后台填写 AppId/私钥/支付宝公钥');
        }

        $price = (float) OptionService::get('Price', 0.01);
        $money = round($amount * $price, 2);
        $tradeNo = 'AL'.date('YmdHis').Str::random(8);

        TopUp::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'money' => $money,
            'trade_no' => $tradeNo,
            'trade_no_internal' => $tradeNo,
            'status' => 0,
            'payment_method' => 'alipay_native',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $notifyUrl = (string) OptionService::get('AlipayNotifyUrl', '');
        if ($notifyUrl === '') {
            $notifyUrl = url('/api/alipay/notify');
        }

        $payType = $request->input('pay_type', 'qrcode'); // qrcode|wap
        try {
            if ($payType === 'wap') {
                $returnUrl = url('/wallet');
                $payUrl = $service->createWapPayUrl($tradeNo, $money, '充值 '.$amount.' 额度', $notifyUrl, $returnUrl);

                return $this->success([
                    'trade_no' => $tradeNo,
                    'pay_url' => $payUrl,
                    'pay_type' => 'wap',
                ]);
            }
            $result = $service->createPrecreateOrder($tradeNo, $money, '充值 '.$amount.' 额度', $notifyUrl);

            return $this->success([
                'trade_no' => $tradeNo,
                'qr_code' => $result['qr_code'],
                'pay_type' => 'qrcode',
            ]);
        } catch (\Throwable $e) {
            Log::error('alipay order failed', ['trade_no' => $tradeNo, 'err' => $e->getMessage()]);

            return $this->error('支付宝下单失败: '.$e->getMessage());
        }
    }

    /**
     * 原生支付宝异步回调
     * GET/POST /api/alipay/notify
     */
    public function alipayNotify(Request $request)
    {
        $params = $request->all();
        $service = new AlipayService;
        if (! $service->verifyNotify($params)) {
            Log::warning('alipay notify sign error', ['params' => $params]);

            return response('fail');
        }

        $tradeStatus = $params['trade_status'] ?? '';
        $tradeNo = $params['out_trade_no'] ?? '';
        $money = (float) ($params['total_amount'] ?? 0);
        $paymentId = $params['trade_no'] ?? '';

        if (! in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return response('success');
        }

        $this->completeTopUp($tradeNo, $money, $paymentId);

        return response('success');
    }
}
