<?php

declare(strict_types=1);

namespace App\Relay\Constant;

/**
 * Relay 协议类型
 *
 * 用于区分入站请求的协议格式，决定适配器行为：
 * - OpenAI:   入站为 OpenAI 格式，出站按渠道类型转换
 * - Anthropic: 入站为 Anthropic 原生格式，出站原样透传（不转换）
 */
class RelayProtocol
{
    /**
     * OpenAI 兼容协议（默认）
     * 入站请求遵循 OpenAI API 格式
     */
    public const string OpenAI = 'openai';

    /**
     * Anthropic 原生协议
     * 入站请求遵循 Anthropic Messages API 格式
     * 适配器将原样透传到上游，不做格式转换
     */
    public const string Anthropic = 'anthropic';
}
