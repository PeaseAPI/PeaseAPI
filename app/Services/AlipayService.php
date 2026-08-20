<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * 原生支付宝支付服务（RSA2，纯 openssl 实现，无第三方依赖）
 */
class AlipayService
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    private const GATEWAY_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    private string $appId;

    private string $privateKey;

    private string $alipayPublicKey;

    private string $gateway;

    private string $charset = 'UTF-8';

    private string $signType = 'RSA2';

    public function __construct()
    {
        $this->appId = (string) OptionService::get('AlipayAppId', '');
        $this->privateKey = (string) OptionService::get('AlipayPrivateKey', '');
        $this->alipayPublicKey = (string) OptionService::get('AlipayAlipayPublicKey', '');
        $mode = (string) OptionService::get('AlipayMode', 'normal');
        $this->gateway = $mode === 'sandbox' ? self::GATEWAY_SANDBOX : self::GATEWAY;
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->privateKey !== '' && $this->alipayPublicKey !== '';
    }

    /**
     * 当面付预下单（生成二维码）
     */
    public function createPrecreateOrder(string $outTradeNo, float $totalAmount, string $subject, string $notifyUrl): array
    {
        $bizContent = json_encode([
            'out_trade_no' => $outTradeNo,
            'total_amount' => sprintf('%.2f', $totalAmount),
            'subject' => $subject,
            'product_code' => 'FACE_TO_FACE_PAYMENT',
        ], JSON_UNESCAPED_UNICODE);

        $params = $this->buildPublicParams('alipay.trade.precreate', $bizContent, $notifyUrl);
        $params['sign'] = $this->sign($this->buildSignString($params));

        $resp = Http::asForm()->post($this->gateway, $params);
        $body = $resp->body();
        $json = json_decode($body, true);

        $key = 'alipay_trade_precreate_response';
        if (! isset($json[$key])) {
            throw new \RuntimeException('支付宝响应格式异常: '.$body);
        }

        if (! $this->verifyResponseSign($body, $key)) {
            throw new \RuntimeException('支付宝响应验签失败');
        }

        $sub = $json[$key];
        if (($sub['code'] ?? '') !== '10000') {
            throw new \RuntimeException(
                '支付宝下单失败: '.($sub['sub_msg'] ?? $sub['msg'] ?? '未知错误').
                ' (code='.($sub['code'] ?? '').',sub_code='.($sub['sub_code'] ?? '').')'
            );
        }

        return [
            'qr_code' => $sub['qr_code'] ?? '',
            'out_trade_no' => $sub['out_trade_no'] ?? $outTradeNo,
        ];
    }

    /**
     * 手机网站支付 URL
     */
    public function createWapPayUrl(string $outTradeNo, float $totalAmount, string $subject, string $notifyUrl, string $returnUrl = ''): string
    {
        $bizContent = json_encode([
            'out_trade_no' => $outTradeNo,
            'total_amount' => sprintf('%.2f', $totalAmount),
            'subject' => $subject,
            'product_code' => 'QUICK_WAP_WAY',
        ], JSON_UNESCAPED_UNICODE);

        $params = $this->buildPublicParams('alipay.trade.wap.pay', $bizContent, $notifyUrl);
        if ($returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }
        $params['sign'] = $this->sign($this->buildSignString($params));

        return $this->gateway.'?'.http_build_query($params);
    }

    /**
     * 订单查询
     */
    public function queryOrder(string $outTradeNo): array
    {
        $bizContent = json_encode(['out_trade_no' => $outTradeNo], JSON_UNESCAPED_UNICODE);
        $params = $this->buildPublicParams('alipay.trade.query', $bizContent, '');
        $params['sign'] = $this->sign($this->buildSignString($params));

        $resp = Http::asForm()->post($this->gateway, $params);
        $body = $resp->body();
        $json = json_decode($body, true);
        $key = 'alipay_trade_query_response';
        if (! isset($json[$key])) {
            return [];
        }

        return $json[$key];
    }

    /**
     * 异步回调验签
     */
    public function verifyNotify(array $params): bool
    {
        $sign = $params['sign'] ?? '';
        if ($sign === '' || $this->alipayPublicKey === '') {
            return false;
        }
        $signType = $params['sign_type'] ?? $this->signType;
        unset($params['sign'], $params['sign_type']);

        $signStr = $this->buildSignString($params);

        return $this->verify($signStr, $sign, $signType);
    }

    private function buildPublicParams(string $method, string $bizContent, string $notifyUrl): array
    {
        $params = [
            'app_id' => $this->appId,
            'method' => $method,
            'format' => 'JSON',
            'charset' => $this->charset,
            'sign_type' => $this->signType,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => $bizContent,
        ];
        if ($notifyUrl !== '') {
            $params['notify_url'] = $notifyUrl;
        }

        return $params;
    }

    /**
     * 待签名串（按 key 升序拼接，过滤空值与 sign/sign_type）
     */
    private function buildSignString(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $pairs[] = $k.'='.$v;
        }

        return implode('&', $pairs);
    }

    /**
     * RSA2 签名
     */
    private function sign(string $data): string
    {
        $key = $this->normalizePrivateKey($this->privateKey);
        $signature = '';
        if (! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('支付宝签名失败: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * 验签
     */
    private function verify(string $data, string $sign, string $signType): bool
    {
        $key = $this->normalizePublicKey($this->alipayPublicKey);
        $algo = $signType === 'RSA' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        $raw = base64_decode($sign, true);
        if ($raw === false) {
            $raw = $sign;
        }

        return openssl_verify($data, $raw, $key, $algo) === 1;
    }

    /**
     * 验证支付宝网关响应的签名
     */
    private function verifyResponseSign(string $body, string $responseKey): bool
    {
        $start = strpos($body, '"'.$responseKey.'":');
        if ($start === false) {
            return false;
        }
        $start += strlen('"'.$responseKey.'":');
        $objStart = strpos($body, '{', $start);
        if ($objStart === false) {
            return false;
        }
        $depth = 0;
        $end = $objStart;
        $len = strlen($body);
        for ($i = $objStart; $i < $len; $i++) {
            if ($body[$i] === '{') {
                $depth++;
            } elseif ($body[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $responseJson = substr($body, $objStart, $end - $objStart + 1);

        $json = json_decode($body, true);
        $sign = $json['sign'] ?? '';
        if ($sign === '') {
            return false;
        }

        return $this->verify($responseJson, $sign, $this->signType);
    }

    /**
     * 规范化私钥（兼容纯 Base64 与 PEM）
     */
    private function normalizePrivateKey(string $key)
    {
        if (strpos($key, '-----BEGIN') === false) {
            $key = "-----BEGIN RSA PRIVATE KEY-----\n".
                chunk_split($key, 64, "\n").
                "-----END RSA PRIVATE KEY-----\n";
        }
        $res = openssl_pkey_get_private($key);
        if (! $res) {
            throw new \RuntimeException('支付宝私钥格式错误: '.openssl_error_string());
        }

        return $res;
    }

    /**
     * 规范化支付宝公钥（兼容纯 Base64 与 PEM）
     */
    private function normalizePublicKey(string $key)
    {
        if (strpos($key, '-----BEGIN') === false) {
            $key = "-----BEGIN PUBLIC KEY-----\n".
                chunk_split($key, 64, "\n").
                "-----END PUBLIC KEY-----\n";
        }
        $res = openssl_pkey_get_public($key);
        if (! $res) {
            throw new \RuntimeException('支付宝公钥格式错误: '.openssl_error_string());
        }

        return $res;
    }
}
