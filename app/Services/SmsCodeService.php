<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 短信验证码服务
 *
 * 负责验证码的生成、发送（限流）、校验。
 * 验证码缓存键：sms:code:{phone}
 * 发送时间键：sms:sent:{phone}
 * 当日计数键：sms:day:{phone}:{Ymd}
 * IP 小时计数键：sms:ip:{ip}:{YmdH}
 */
class SmsCodeService
{
    /**
     * 发送验证码。
     *
     * @param  string  $phone  手机号
     * @param  string  $ip     请求 IP
     * @return array 返回 success/message 两个键
     */
    public function send(string $phone, string $ip = ''): array
    {
        // 校验手机号格式
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return ['success' => false, 'message' => '手机号格式不正确'];
        }

        if (!OptionService::get('SmsEnabled', false)) {
            return ['success' => false, 'message' => '短信服务未开启'];
        }

        // 限流：同一号码发送间隔
        $interval = (int) OptionService::get('SmsSendInterval', 60);
        $sentKey = 'sms:sent:' . $phone;
        if (Cache::has($sentKey)) {
            $remain = Cache::get($sentKey, 0) + $interval - time();
            return ['success' => false, 'message' => '发送过于频繁，请 ' . max(1, $remain) . ' 秒后重试'];
        }

        // 限流：同一号码当日上限
        $dailyLimit = (int) OptionService::get('SmsDailyLimit', 10);
        $dayKey = 'sms:day:' . $phone . ':' . date('Ymd');
        $dayCount = (int) Cache::get($dayKey, 0);
        if ($dayCount >= $dailyLimit) {
            return ['success' => false, 'message' => '当日发送次数已达上限'];
        }

        // 限流：同一 IP 每小时上限
        if ($ip !== '') {
            $ipHourLimit = (int) OptionService::get('SmsIpHourLimit', 5);
            $ipKey = 'sms:ip:' . $ip . ':' . date('YmdH');
            $ipCount = (int) Cache::get($ipKey, 0);
            if ($ipCount >= $ipHourLimit) {
                return ['success' => false, 'message' => '请求过于频繁，请稍后再试'];
            }
        }

        // 生成验证码
        $length = (int) OptionService::get('SmsCodeLength', 6);
        $code = $this->generateCode($length);
        $ttl = (int) OptionService::get('SmsCodeTTL', 300);

        // 调用短信服务发送
        $provider = (string) OptionService::get('SmsProvider', 'aliyun');

        if ($provider === 'log') {
            Log::info('SMS code (dev/log mode)', ['phone' => $phone, 'code' => $code, 'ip' => $ip]);
            Cache::put('sms:code:' . $phone, $code, $ttl);
            Cache::put($sentKey, time(), $interval);
            Cache::put($dayKey, $dayCount + 1, now()->endOfDay()->diffInSeconds(now()) + 60);
            if ($ip !== '') {
                Cache::put($ipKey, $ipCount + 1, 3600);
            }
            // 本地开发直接返回验证码，便于联调测试
            return ['success' => true, 'message' => '验证码已发送（开发模式：' . $code . '）'];
        }

        if ($provider === 'aliyun') {
            $service = app(AliyunSmsService::class);
        } else {
            return ['success' => false, 'message' => '不支持的短信服务商'];
        }

        $result = $service->sendCode($phone, $code);
        if (!$result['success']) {
            return $result;
        }

        // 写入缓存
        Cache::put('sms:code:' . $phone, $code, $ttl);
        Cache::put($sentKey, time(), $interval);
        Cache::put($dayKey, $dayCount + 1, now()->endOfDay()->diffInSeconds(now()) + 60);
        if ($ip !== '') {
            Cache::put($ipKey, $ipCount + 1, 3600);
        }

        Log::info('SMS code sent', ['phone' => $phone, 'ip' => $ip]);
        return ['success' => true, 'message' => '验证码已发送'];
    }

    /**
     * 校验验证码（校验成功后删除）。
     */
    public function verify(string $phone, string $code): bool
    {
        if ($code === '' || $phone === '') {
            return false;
        }
        $key = 'sms:code:' . $phone;
        $stored = Cache::get($key, '');
        if ($stored === '' || !hash_equals((string) $stored, (string) $code)) {
            return false;
        }
        Cache::forget($key);
        return true;
    }

    /**
     * 生成指定长度数字验证码。
     */
    private function generateCode(int $length): string
    {
        $length = max(4, min(8, $length));
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }
}