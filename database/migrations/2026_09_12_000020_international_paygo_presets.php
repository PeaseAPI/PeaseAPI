<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-6 国际按量厂商预置（R1/R4）：OpenAI / Google Gemini / xAI 官方 API 价目
 *
 * 1. 精度扩张：coding_plan_model_ratios 的 input_rate/cached_rate/output_rate
 *    decimal(12,4) → decimal(12,6)。USD/百万 tokens ÷1000 存 /1k 后出现 5~6 位小数
 *    （如 $0.02/1M = 0.00002/1k，4 位精度会截断为 0）；对存量 ≤4 位小数值无损，
 *    vendors.unit_exchange_rate 已有 decimal(12,6) 先例。down 不收缩精度（有损）。
 * 2. 新厂商行（plan_kind=2 按量 Token Plan / billing_mode=2 / currency=USD / status=0）：
 *    openai 与 google 的 code 已被转发渠道占用（Codex / Gemini CLI，plan_kind=1 按请求计费，
 *    不受本迁移影响），按 cmcc/cmcc-token 拆分先例以 openai-token / google-token 挂按量价目；
 *    xai 为全新厂商。三者均默认停用——错误价格 = 资损，须管理员核对后启用。
 * 3. 比率行数据 = P1 解析器快照 JSON（storage/app/private/coding-plan-snapshots/，
 *    2026-09-12 抓取），与 sync-official diff 口径一致；行内 remark 注明快照来源、
 *    促销/恢复价口径、长尾疑点（缓存读价占位等）。幂等：行已存在（vendor+model+cost_mode）则跳过，
 *    绝不覆盖已启用的人工行。
 */

return new class extends Migration
{
    /** 预置厂商：code => [name, pricing_source_url, docs_url, sort, remark] */
    public const PRESET_VENDORS = [
        'openai-token' => ['OpenAI API Token Plan（按量）', 'https://platform.openai.com/docs/pricing.md', 'https://platform.openai.com/docs', 105, '官方价目参照预置（快照 2026-09-12）：Batch API 统一 5 折；GPT-5.6 Sol 促销至少至 2026-11-21；区域端点对 2026-03-05 后模型加价 10%；启用前请核对'],
        'google-token' => ['Google Gemini API Token Plan（按量）', 'https://ai.google.dev/gemini-api/docs/pricing', 'https://ai.google.dev/gemini-api/docs', 106, '官方价目参照预置（快照 2026-09-12）：全表促销价至 2026-12-31（本价 ×0.5），2027-01-01 起恢复本价（解析器口径取恢复价，促销价走活动表）；Flex 档 ×0.5 / Priority 档 ×1.8（比率行按 Standard 档预置）；Batch API 5 折；缓存读价人工核读与解析有出入，启用前以官方页为准'],
        'xai' => ['xAI Grok Token Plan（按量）', 'https://docs.x.ai/docs/models', 'https://docs.x.ai/docs', 107, '官方价目参照预置（快照 2026-09-12）：Imagine 图像 $0.02/张起、视频 $0.05/秒起（按张/秒计费未入 token 比率模型）；启用前请核对'],
    ];

    /** 预置比率：code => [[model, input_rate, cached_rate, output_rate, remark], ...]（USD/1k tokens） */
    public const PRESET_RATIOS = [
        'openai-token' => [
            ['gpt-6-astra', 0.01, 0.001, 0.05, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.6-sol', 0.004, 0.0004, 0.02, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.6-terra', 0.002, 0.0002, 0.012, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.6-luna', 0.0002, 0.00002, 0.0012, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.5', 0.005, 0.0005, 0.03, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.5-pro', 0.03, 0.03, 0.18, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-5.4', 0.0025, 0.00025, 0.015, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.4-mini', 0.00075, 0.000075, 0.0045, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.4-nano', 0.0002, 0.00002, 0.00125, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.4-pro', 0.03, 0.03, 0.18, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-5.2', 0.00175, 0.000175, 0.014, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.2-pro', 0.021, 0.021, 0.168, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-5.1', 0.00125, 0.000125, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5', 0.00125, 0.000125, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5-mini', 0.00025, 0.000025, 0.002, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5-nano', 0.00005, 0.000005, 0.0004, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5-pro', 0.015, 0.015, 0.12, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-4.1', 0.002, 0.0005, 0.008, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4.1-mini', 0.0004, 0.0001, 0.0016, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4.1-nano', 0.0001, 0.000025, 0.0004, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4o', 0.0025, 0.00125, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4o-2024-05-13', 0.005, 0.005, 0.015, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-4o-mini', 0.00015, 0.000075, 0.0006, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['o1', 0.015, 0.0075, 0.06, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['o1-pro', 0.15, 0.15, 0.6, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['o3-pro', 0.02, 0.02, 0.08, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['o3', 0.002, 0.0005, 0.008, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['o4-mini', 0.0011, 0.000275, 0.0044, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['o3-mini', 0.0011, 0.00055, 0.0044, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4-turbo-2024-04-09', 0.01, 0.01, 0.03, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-4-0613', 0.03, 0.03, 0.06, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-3.5-turbo', 0.0005, 0.0005, 0.0015, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-3.5-turbo-0125', 0.0005, 0.0005, 0.0015, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-3.5-turbo-1106', 0.001, 0.001, 0.002, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-3.5-turbo-instruct', 0.0015, 0.0015, 0.002, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['davinci-002', 0.002, 0.002, 0.002, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['babbage-002', 0.0004, 0.0004, 0.0004, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-5.6-cyber', 0.0125, 0.00125, 0.075, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.5-cyber', 0.0125, 0.00125, 0.075, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-realtime-2.1', 0.032, 0.0004, 0.064, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-realtime-2.1-mini', 0.01, 0.0003, 0.02, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-realtime-2', 0.032, 0.0004, 0.064, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-realtime-1.5', 0.032, 0.0004, 0.064, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-realtime-mini', 0.01, 0.0003, 0.02, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-realtime', 0.032, 0.0004, 0.064, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-audio-1.5', 0.032, 0.032, 0.064, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-audio-mini', 0.01, 0.01, 0.02, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-audio', 0.032, 0.032, 0.064, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-image-2.5-sunburst', 0.008, 0.002, 0.03, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-image-2.5-flare', 0.008, 0.002, 0.03, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-image-2', 0.008, 0.002, 0.03, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-image-1.5', 0.008, 0.002, 0.032, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-image-1-mini', 0.0025, 0.00025, 0.008, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-image-1', 0.01, 0.0025, 0.04, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['chatgpt-image-latest', 0.008, 0.002, 0.032, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4o-transcribe', 0.0025, 0.0025, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-4o-mini-transcribe', 0.00125, 0.00125, 0.005, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['gpt-4o-transcribe-diarize', 0.0025, 0.0025, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价（解析器以输入价占位）；Batch API 统一 5 折'],
            ['chat-latest', 0.005, 0.0005, 0.03, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5.3-codex', 0.00175, 0.000175, 0.014, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-rosalind-research', 0.005, 0.0005, 0.025, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-5-search-api', 0.00125, 0.000125, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['o4-mini-2025-04-16', 0.004, 0.001, 0.016, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4.1-2025-04-14', 0.003, 0.00075, 0.012, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4.1-mini-2025-04-14', 0.0008, 0.0002, 0.0032, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4.1-nano-2025-04-14', 0.0002, 0.00005, 0.0008, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4o-2024-08-06', 0.00375, 0.001875, 0.015, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
            ['gpt-4o-mini-2024-07-18', 0.0003, 0.00015, 0.0012, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；Batch API 统一 5 折'],
        ],
        'google-token' => [
            ['gemini-3.8-flash', 0.0015, 0.00015, 0.0075, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.7-flash', 0.0015, 0.00015, 0.0075, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.6-flash', 0.0015, 0.00015, 0.0075, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.5-flash', 0.0015, 0.00015, 0.009, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.5-live-translate-preview', 0.0035, null, 0.021, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.5-transcribe-live', 0.0035, null, 0.021, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.5-transcribe', 0.002, null, 0.012, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.5-flash-lite', 0.0003, 0.00003, 0.0025, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.1-flash-lite', 0.00025, 0.00005, 0.0015, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-omni-1.1-flash', 0.0015, null, 0.009, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-omni-flash-preview', 0.0015, null, 0.009, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.1-pro-preview', 0.002, 0.0002, 0.012, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.1-flash-image', null, null, 0.003, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.1-flash-lite-image', null, null, 0.0015, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3.1-flash-tts-preview', 0.001, null, 0.02, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3-flash-preview', 0.0005, 0.0001, 0.003, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-3-pro-image', null, null, 0.012, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-2.5-pro', 0.00125, 0.000125, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-2.5-flash', 0.0003, 0.0001, 0.0025, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-2.5-flash-lite', 0.0001, 0.00003, 0.0004, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-2.5-flash-image', 0.0003, null, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-2.5-flash-preview-tts', 0.0005, null, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-2.5-pro-preview-tts', 0.001, null, 0.02, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-embedding-2', 0.012, null, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-embedding', 0.00015, null, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-robotics-er-2', 0.002, 0.0002, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-robotics-er-2-streaming', 0.002, null, 0.01, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
            ['gemini-robotics-er', 0.001, null, 0.005, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；促销价（至 2026-12-31）为本价 ×0.5，2027-01-01 恢复本价；Flex=×0.5/Priority=×1.8（本行为 Standard 档）'],
        ],
        'xai' => [
            ['grok-4.6', 0.002, 0.0005, 0.006, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
            ['grok-4.5', 0.002, 0.0003, 0.006, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
            ['grok-4.3', 0.00125, 0.0002, 0.0025, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
            ['grok-4.20-0309-reasoning', 0.00125, 0.0002, 0.0025, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
            ['grok-4.20-0309-non-reasoning', 0.00125, 0.0002, 0.0025, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
            ['grok-build-0.1', 0.001, 0.0002, 0.002, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
            ['grok-4.20-multi-agent-0309', 0.00125, 0.0002, 0.0025, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ],
    ];

    public function up(): void
    {
        // 精度扩张（对既有 ≤4 位小数值无损）
        foreach (['input_rate', 'cached_rate', 'output_rate'] as $col) {
            Schema::table('coding_plan_model_ratios', function (Blueprint $table) use ($col) {
                $table->decimal($col, 12, 6)->unsigned()->default(0)->change();
            });
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            foreach (self::PRESET_VENDORS as $code => [$name, $pricing, $docs, $sort, $remark]) {
                if (DB::table('coding_plan_vendors')->where('code', $code)->exists()) {
                    continue;
                }
                $now = time();
                DB::table('coding_plan_vendors')->insert([
                    'code' => $code,
                    'name' => $name,
                    'billing_mode' => 2,
                    'plan_kind' => 2,
                    'unit_name' => '千token',
                    'currency' => 'USD',
                    'docs_url' => $docs,
                    'pricing_source_url' => $pricing,
                    'status' => 0,
                    'sort' => $sort,
                    'remark' => $remark,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('coding_plan_model_ratios')) {
            $now = time();
            foreach (self::PRESET_RATIOS as $vendor => $rows) {
                foreach ($rows as [$model, $input, $cached, $output, $remark]) {
                    $exists = DB::table('coding_plan_model_ratios')->where([
                        'vendor' => $vendor,
                        'model' => $model,
                        'cost_mode' => 'per_token_parts',
                    ])->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('coding_plan_model_ratios')->insert([
                        'vendor' => $vendor,
                        'model' => $model,
                        'match_type' => 'exact',
                        'cost_mode' => 'per_token_parts',
                        'unit_cost' => 1,
                        'input_rate' => $input ?? 0,
                        'cached_rate' => $cached ?? 0,
                        'output_rate' => $output ?? 0,
                        'time_discounts' => null,
                        'status' => 0,
                        'sort' => 0,
                        'remark' => $remark,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // 比率行：仅删本迁移插入的预置行（remark 前缀识别，人工行不受影响）
        if (Schema::hasTable('coding_plan_model_ratios')) {
            DB::table('coding_plan_model_ratios')
                ->whereIn('vendor', array_keys(self::PRESET_VENDORS))
                ->where('match_type', 'exact')
                ->where('remark', 'like', '官方折算标准（模板 v2026-09-12%')
                ->delete();
        }
        // 厂商行：未被人工启用（status=0）才删
        if (Schema::hasTable('coding_plan_vendors')) {
            DB::table('coding_plan_vendors')
                ->whereIn('code', array_keys(self::PRESET_VENDORS))
                ->where('status', 0)
                ->delete();
        }
        // 精度保留 decimal(12,6) 不收缩（回滚到 4 位会截断存量数据）
    }
};
