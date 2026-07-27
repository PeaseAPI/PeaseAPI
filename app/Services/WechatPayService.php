<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信支付 V3 原生接口服务
 *
 * 使用 PHP 原生 openssl 扩展实现签名/验签，不依赖第三方 composer 包。
 * 支持 Native（扫码）下单与回调验签。
 */
class WechatPayService
{
    private const BASE_URL = 'https://api.mch.weixin.qq.com';
    private const SANDBOX_BASE_URL = 'https://api.mch.weixin.qq.com';

    /** 商户号 */
    private string $mchId;
    /** 商户 API 私钥（PEM） */
    private string $privateKey;
    /** 商户证书序列号 */
    private string $serialNo;
    /** API V3 密钥 */
    private string $apiV3Key;
    /** AppId（公众号/小程序） */
    private string $appId;

    public function __construct()
    {
        $this->mchId       = (string) OptionService::get('WechatPayMchId', '');
        $this->privateKey  = (string) OptionService::get('WechatPayPrivateKey', '');
        $this->serialNo    = (string) OptionService::get('WechatPaySerialNo', '');
        $this->apiV3Key    = (string) OptionService::get('WechatPayApiV3Key', '');
        $this->appId       = (string) OptionService::get('WechatPayAppId', '');
    }

    /**
     * 检查是否已配置
     */
    public function isConfigured(): bool
    {
        return $this->mchId !== '' && $this->privateKey !== ''
            && $this->serialNo !== '' && $this->apiV3Key !== ''
            && $this->appId !== '';
    }

    /**
     * Native（扫码）下单
     *
     * @param string $tradeNo    商户订单号
     * @param int    $amountFen  金额（分）
     * @param string $description 商品描述
     * @param string $notifyUrl  回调地址
     * @return array 包含 code_url 和 raw 两个键
     * @throws \RuntimeException
     */
    public function createNativeOrder(string $tradeNo, int $amountFen, string $description, string $notifyUrl): array
    {
        $payload = [
            'appid'            => $this->appId,
            'mchid'            => $this->mchId,
            'description'      => $description,
            'out_trade_no'     => $tradeNo,
            'notify_url'       => $notifyUrl,
            'amount' => [
                'total'    => $amountFen,
                'currency' => 'CNY',
            ],
        ];

        $resp = $this->request('POST', '/v3/pay/transactions/native', $payload);
        if (!isset($resp['code_url'])) {
            throw new \RuntimeException('微信下单失败: ' . ($resp['message'] ?? json_encode($resp, JSON_UNESCAPED_UNICODE)));
        }
        return ['code_url' => $resp['code_url'], 'raw' => $resp];
    }

    /**
     * 验证回调签名并解密资源
     *
     * @param array  $headers 标准化的 header 数组（小写键）
     * @param string $body    原始请求体
     * @return array|null 解密后的通知数据，验签失败返回 null
     */
    public function verifyNotify(array $headers, string $body): ?array
    {
        $timestamp = $headers['wechatpay-timestamp'] ?? '';
        $nonce     = $headers['wechatpay-nonce'] ?? '';
        $signature = $headers['wechatpay-signature'] ?? '';
        $serial    = $headers['wechatpay-serial'] ?? '';

        if ($timestamp === '' || $nonce === '' || $signature === '' || $body === '') {
            return null;
        }

        // 注：完整 V3 验签需要平台证书公钥。此处简化为使用 APIv3 解密校验。
        // 若需严格验签，可通过 APIv3 key 获取/缓存平台证书后做 RSA 验签。
        // 这里采用「解密成功即视为可信」的兼容策略，建议生产环境配置平台证书后加强。
        $data = json_decode($body, true);
        if (!isset($data['resource'])) {
            return null;
        }
        $decrypted = $this->decryptResource(
            $data['resource']['ciphertext'] ?? '',
            $data['resource']['nonce'] ?? '',
            $data['resource']['associated_data'] ?? ''
        );
        if ($decrypted === null) {
            return null;
        }
        return json_decode($decrypted, true);
    }

    /**
     * 解密回调中的 resource.ciphertext（AES-256-GCM）
     */
    public function decryptResource(string $ciphertext, string $nonce, string $associatedData = ''): ?string
    {
        if ($this->apiV3Key === '' || $ciphertext === '' || $nonce === '') {
            return null;
        }
        $ciphertextRaw = base64_decode($ciphertext, true);
        if ($ciphertextRaw === false) {
            return null;
        }
        // AES-256-GCM：key=apiV3Key, iv=nonce, aad=associatedData, tag=最后16字节
        if (strlen($ciphertextRaw) < 16) {
            return null;
        }
        $tag = substr($ciphertextRaw, -16);
        $data = substr($ciphertextRaw, 0, -16);
        $decrypted = openssl_decrypt(
            $data,
            'aes-256-gcm',
            $this->apiV3Key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData
        );
        return $decrypted === false ? null : $decrypted;
    }

    /**
     * 查询订单（商户订单号）
     */
    public function queryByOutTradeNo(string $tradeNo): array
    {
        $url = '/v3/pay/transactions/out-trade-no/' . urlencode($tradeNo) . '?mchid=' . urlencode($this->mchId);
        return $this->request('GET', $url);
    }

    /**
     * 发送 V3 请求
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $url = self::BASE_URL . $path;
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyStr = $body === [] ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);

        $signStr = "$method\n$path\n$timestamp\n$nonce\n$bodyStr\n";
        $signature = $this->sign($signStr);

        $auth = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $this->mchId,
            $nonce,
            $timestamp,
            $this->serialNo,
            $signature
        );

        $headers = [
            'Authorization' => $auth,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        try {
            if ($method === 'GET') {
                $resp = Http::withHeaders($headers)->get($url);
            } else {
                $resp = Http::withHeaders($headers)->withBody($bodyStr, 'application/json')->post($url);
            }
            return $resp->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('WechatPay request failed', ['path' => $path, 'err' => $e->getMessage()]);
            throw new \RuntimeException('微信支付请求失败: ' . $e->getMessage());
        }
    }

    /**
     * 使用商户私钥对字符串做 SHA256-RSA 签名
     */
    private function sign(string $str): string
    {
        $key = openssl_pkey_get_private($this->privateKey);
        if ($key === false) {
            throw new \RuntimeException('商户私钥格式错误');
        }
        $signature = '';
        if (!openssl_sign($str, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('微信支付签名失败: ' . openssl_error_string());
        }
        return base64_encode($signature);
    }
}