<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 阿里云短信服务（直接调用阿里云 OpenAPI，无需 SDK）
 *
 * 使用 SendSms 接口发送短信验证码。
 * 文档：https://help.aliyun.com/document_detail/101414.html
 */
class AliyunSmsService
{
    /**
     * 发送短信验证码。
     *
     * @param  string  $phone  手机号（不带 +86，11 位）
     * @param  string  $code   验证码
     * @return array 返回 success/message 两个键
     */
    public function sendCode(string $phone, string $code): array
    {
        $accessKeyId     = (string) OptionService::get('AliyunSmsAccessKeyId', '');
        $accessKeySecret = (string) OptionService::get('AliyunSmsAccessKeySecret', '');
        $signName        = (string) OptionService::get('AliyunSmsSignName', '');
        $templateCode    = (string) OptionService::get('AliyunSmsTemplateCode', '');
        $region          = (string) OptionService::get('AliyunSmsRegion', 'cn-hangzhou');

        if ($accessKeyId === '' || $accessKeySecret === '' || $signName === '' || $templateCode === '') {
            return ['success' => false, 'message' => '阿里云短信配置不完整'];
        }

        $params = [
            'PhoneNumbers'  => $phone,
            'SignName'       => $signName,
            'TemplateCode'   => $templateCode,
            'TemplateParam'  => json_encode(['code' => $code]),
            'OutId'          => '',
            'SmsUpExtendCode' => '',
        ];

        $endpoint = 'https://dysmsapi.aliyuncs.com';
        $common = [
            'AccessKeyId'      => $accessKeyId,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => Str::uuid()->toString(),
            'SignatureVersion' => '1.0',
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Format'           => 'JSON',
            'Version'          => '2017-05-25',
            'RegionId'         => $region,
            'Action'           => 'SendSms',
        ];

        $all = array_merge($common, $params);
        $signature = $this->sign($all, $accessKeySecret);
        $all['Signature'] = $signature;

        try {
            $resp = Http::asForm()->timeout(15)->post($endpoint, $all);
            $body = $resp->json();
        } catch (\Throwable $e) {
            Log::error('AliyunSms send failed', ['phone' => $phone, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => '短信发送失败：' . $e->getMessage()];
        }

        $code = $body['Code'] ?? 'Unknown';
        $message = $body['Message'] ?? '未知错误';

        if ($code === 'OK') {
            return ['success' => true, 'message' => '发送成功'];
        }

        Log::warning('AliyunSms send non-OK', ['phone' => $phone, 'body' => $body]);

        $tipMap = [
            'isv.BUSINESS_LIMIT_CONTROL' => '发送频率过高，请稍后再试',
            'isv.DAY_LIMIT_CONTROL'      => '当日发送量超限',
            'isv.SMS_SIGNATURE_ILLEGAL'  => '短信签名不合法',
            'isv.SMS_TEMPLATE_ILLEGAL'   => '短信模板不合法',
            'isv.MOBILE_NUMBER_ILLEGAL'  => '手机号不合法',
            'InvalidPhoneNumber'         => '手机号格式错误',
        ];
        return ['success' => false, 'message' => $tipMap[$code] ?? ('发送失败：' . $message)];
    }

    /**
     * 计算阿里云 RPC API 签名。
     */
    private function sign(array $params, string $secret): string
    {
        ksort($params);
        $canonical = '';
        foreach ($params as $k => $v) {
            $canonical .= '&' . $this->encode($k) . '=' . $this->encode((string) $v);
        }
        $stringToSign = 'POST&' . $this->encode('/') . '&' . $this->encode(substr($canonical, 1));
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secret . '&', true));
        return $signature;
    }

    /**
     * 阿里云特殊的 URL 编码（RFC3986 + 特殊处理 + 号）。
     */
    private function encode(string $s): string
    {
        $encoded = rawurlencode($s);
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $encoded);
    }
}