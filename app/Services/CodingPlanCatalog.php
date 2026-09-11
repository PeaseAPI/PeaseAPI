<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Coding Plan 官方模板目录（单一事实源，随代码发布维护）
 *
 * 收录各厂商官方订阅套餐档位（个人版/团队版/座席/用量包）与每模型官方折算标准，
 * 供管理端「官方同步」页一键落地：新建厂商 + 预置档位 + 预置折算比率（全部停用，
 * 管理员核对汇率/系数后手动启用 —— 错误价格 = 资损，绝不自动生效）。
 *
 * 数据核对来源（2026-09-11 抓取官方文档）：
 *  - 阿里云百炼 Token Plan：docs.bailian.console.aliyun.com/zh/model-studio/token-plan-personal-overview（及团队版页）
 *  - 智谱 GLM Coding Plan：docs.bigmodel.cn/cn/coding-plan/overview（含团队版权益页 /team）
 *  - 腾讯云 TokenHub Token Plan：cloud.tencent.com/document/product/1823/130060
 *  - DeepSeek API：api-docs.deepseek.com/zh-cn/quick_start/pricing/
 *  - 火山方舟 Agent Plan：volcengine.com/docs/82379/2366394（档位）与 2658332（模型抵扣系数调整公告）
 *  - 火山引擎 Coding Plan：volcengine.com/docs/82379/1925114（额度数值 JS 渲染未公布，价格以购买页为准）
 *  - Moonshot Kimi：platform.kimi.com/docs/pricing/chat（按量计费，无订阅制）
 *  - 百度千帆 Token Plan：cloud.baidu.com/product/codingplan.html（积分↔token 折算未公布，不预置比率）
 *  - 联通：support.cucloud.cn/document/127/591/2357（Coding Plan arcid=7015 / Token Plan arcid=7080，2026-09-11 经代理直连抓取全量文档核对）
 *  - 移动：官方帮助中心 ecloud.10086.cn 为纯前端 SPA（HTML 壳仅 1KB），但 CMS 数据接口可程序化直连：
 *    GET /op-help-center/request-api/service-api/article/content/{文件UID}（带 article/info/{id} 取 UID，
 *    头 categoryRootParent/tentId/isPreview）——2026-09-11 据此抓取 MoMA 平台全部套餐文档核对：
 *    Coding Plan ART 98320/98337、Token Plan 个人版 ART 100224、团队版 ART 99471、接入地址 ART 100418。
 *
 * 维护约定：
 *  1. 官方改价 → 更新本文件模板 → 管理端重新「应用模板」（幂等，仅覆盖仍是官方预置态的行）；
 *  2. ratios 里 cost_mode=per_token_parts 的三段系数为「每千 token 供应商单位」，
 *     高峰/非高峰折扣等口径差异写进 notes 与 remark，由管理员决定是否跟投；
 *  3. 折算比率与价格的后续变化优先靠 pricing_source_url 定时 diff 监测
 *     （coding-plan:verify-ratios，每 6 小时，变更写入 coding_plan_ratio_checks 待确认）。
 */
class CodingPlanCatalog
{
    /** 应用模板时写入 remark 的官方行前缀（与迁移 000003 约定一致，便于识别与回滚） */
    public const TIER_REMARK_PREFIX = '官方档位';

    public const RATIO_REMARK_PREFIX = '官方折算标准';

    /** 智谱官方非高峰窗口（工作日 14:00-18:00 以外 5 折；与迁移 000009 预置一致） */
    public const TIME_WINDOWS_ZHIPU = [
        ['name' => '工作日非高峰(00-14点)', 'days' => [1, 2, 3, 4, 5], 'start' => '00:00', 'end' => '14:00', 'discount' => 0.5],
        ['name' => '工作日非高峰(18-24点)', 'days' => [1, 2, 3, 4, 5], 'start' => '18:00', 'end' => '24:00', 'discount' => 0.5],
        ['name' => '周末全天', 'days' => [6, 7], 'start' => '00:00', 'end' => '24:00', 'discount' => 0.5],
    ];

    /** DeepSeek 官方空闲窗口（高峰=周一至五 9:00-12:00、14:00-18:00，其余减半） */
    public const TIME_WINDOWS_DEEPSEEK = [
        ['name' => '工作日空闲(00-09点)', 'days' => [1, 2, 3, 4, 5], 'start' => '00:00', 'end' => '09:00', 'discount' => 0.5],
        ['name' => '工作日空闲(12-14点)', 'days' => [1, 2, 3, 4, 5], 'start' => '12:00', 'end' => '14:00', 'discount' => 0.5],
        ['name' => '工作日空闲(18-24点)', 'days' => [1, 2, 3, 4, 5], 'start' => '18:00', 'end' => '24:00', 'discount' => 0.5],
        ['name' => '周末全天', 'days' => [6, 7], 'start' => '00:00', 'end' => '24:00', 'discount' => 0.5],
    ];

    /**
     * 官方模板：code => 模板定义
     *
     * tiers 条目：name, price(null=以官网为准), price_note, period, quota(null=不限/未知),
     *             quota_unit, quota_note, sort, status
     * ratios 条目：model, match_type(exact|prefix), cost_mode(per_request|per_1k_tokens|per_token_parts),
     *              unit_cost, input_rate/cached_rate/output_rate(仅 per_token_parts), sort, status
     */
    public static function templates(): array
    {
        return [
            'aliyun' => self::aliyun(),
            'zhipu' => self::zhipu(),
            'volcengine' => self::volcengine(),
            'volcengine-ark' => self::volcengineArk(),
            'deepseek' => self::deepseek(),
            'moonshot' => self::moonshot(),
            'tencent' => self::tencent(),
            'tencent-team' => self::tencentTeam(),
            'baidu' => self::baidu(),
            'unicom' => self::unicom(),
            'unicom-token' => self::unicomToken(),
            'cmcc' => self::cmcc(),
            'cmcc-token' => self::cmccToken(),
            'cmcc-token-team' => self::cmccTokenTeam(),
        ];
    }

    /**
     * 阿里云百炼 Token Plan（个人版 + 团队版，Credits 统一计量）
     *
     * 官方口径要点：
     *  - 个人版 Lite/Standard/Pro 限时价 39/139/499（原价 60/180/600），月 Credits 10000/40000/不限，
     *    Lite/Standard 另有每 7 天滚动限额 2500/10000；用量包 100 元/2 万 Credits，最多同时持有 5 个。
     *  - 团队版按座席订阅：标准/高级/尊享 150/550/1398 每座席每月（2.5 万/10 万/25 万 Credits，月总额度制）；
     *    共享用量包 5000 元/62.5 万 Credits，跨座席共享，1 个月有效。
     *  - Credits 按模型分档抵扣，官方未公布逐模型系数表（以控制台用量详情为准）；
     *    官方计费示例 qwen3.6-plus：输入 8349 token→1.67 Credits、缓存命中 40794→0.82、输出 573→0.69，
     *    即千 token 系数 0.2/0.02/1.2（本目录唯一可逐段对账的官方分段标准）。
     *  - 限时夜间五折：每晚 22:00–次日 08:00 调用 qwen3.8-max、deepseek-v4-pro-0813、
     *    deepseek-v4-flash-0731 五折；如需跟投可由管理员下调对应模型系数。
     */
    private static function aliyun(): array
    {
        return [
            'name' => '阿里云百炼 Token Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => 'Credits',
            'docs_url' => 'https://docs.bailian.console.aliyun.com/zh/model-studio/token-plan-personal-overview',
            'verified_at' => '2026-09-11',
            'notes' => '夜间 22:00-08:00 指定模型（qwen3.8-max / deepseek-v4-pro-0813 / deepseek-v4-flash-0731）五折；模板未预置这三行的三率，管理员按需录入后在比率行 time_discounts 配置窗口（22:00→08:00 跨零点写法）即可自动套折扣；7 天滚动限额窗口内未用完不结转；升级按剩余时长折算补差；个人版仅限编程/智能体工具交互式使用。',
            'tiers' => [
                ['name' => '个人版 · Lite', 'price' => 39, 'price_note' => '限时价（原价 60）', 'period' => '月', 'quota' => 10000, 'quota_unit' => 'Credits', 'quota_note' => '每 7 天限额 2500', 'sort' => 10, 'status' => 1],
                ['name' => '个人版 · Standard', 'price' => 139, 'price_note' => '限时价（原价 180）', 'period' => '月', 'quota' => 40000, 'quota_unit' => 'Credits', 'quota_note' => '每 7 天限额 10000', 'sort' => 20, 'status' => 1],
                ['name' => '个人版 · Pro', 'price' => 499, 'price_note' => '限时价（原价 600）', 'period' => '月', 'quota' => null, 'quota_unit' => 'Credits', 'quota_note' => '额度不限，无 7 天限额', 'sort' => 30, 'status' => 1],
                ['name' => '个人版 · 用量包', 'price' => 100, 'price_note' => '每个/月', 'period' => '月', 'quota' => 20000, 'quota_unit' => 'Credits', 'quota_note' => '需有效订阅，最多同时持有 5 个', 'sort' => 40, 'status' => 1],
                ['name' => '团队版 · 标准坐席', 'price' => 150, 'price_note' => '每座席每月', 'period' => '月/座席', 'quota' => 25000, 'quota_unit' => 'Credits', 'quota_note' => '月总额度制，无 7 天窗口', 'sort' => 50, 'status' => 1],
                ['name' => '团队版 · 高级坐席', 'price' => 550, 'price_note' => '每座席每月', 'period' => '月/座席', 'quota' => 100000, 'quota_unit' => 'Credits', 'quota_note' => '月总额度制，无 7 天窗口', 'sort' => 60, 'status' => 1],
                ['name' => '团队版 · 尊享坐席', 'price' => 1398, 'price_note' => '每座席每月', 'period' => '月/座席', 'quota' => 250000, 'quota_unit' => 'Credits', 'quota_note' => '月总额度制，无 7 天窗口', 'sort' => 70, 'status' => 1],
                ['name' => '团队版 · 共享用量包', 'price' => 5000, 'price_note' => '每个/月', 'period' => '月', 'quota' => 625000, 'quota_unit' => 'Credits', 'quota_note' => '跨坐席共享，有效期 1 个月', 'sort' => 80, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'qwen3.6-plus', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.2, 'cached_rate' => 0.02, 'output_rate' => 1.2, 'sort' => 10, 'status' => 0],
            ],
        ];
    }

    /**
     * 智谱 GLM Coding Plan（个人版 + 团队版，资源点/积分计量）
     *
     * 官方口径要点：
     *  - 个人版 Lite/Pro/Max：每 5 小时 2000/12000/28000 积分，每周 10000/60000/140000（购买页价格，文档未列）。
     *  - 团队版按席位订阅（2 席位起购、无上限）：标准版每席位 5 小时 15000 / 周 66000；高级版 35000 / 155000。
     *    超额可开启按量付费（按模型 API 刊例价 9 折，限时）；支持按成员设消耗上限；企业认证可开专票。
     *  - 抵扣系数（每万 token）：GLM-5.3 输入 6.9 / 缓存命中 1.7 / 输出 24；GLM-5.3-Flash 2.3 / 0.56 / 8；
     *    MCP 工具（联网搜索/网页读取/开源仓库）每次按 Output 系数计 1.2 资源点。
     *    存储口径 = 官方系数 ÷ 10（换算为每千 token）：6.9/1.7/24 → 0.69/0.17/2.4。
     *  - 高峰时段：每周一至周五 14:00–18:00（UTC+8）按 1 倍计；其余非高峰时段按基础消耗 50% 抵扣；
     *    另有夜间畅用活动（23:00–次日 09:00）。
     *  - 历史模型自动路由：GLM-5.2 / GLM-5.1 → GLM-5.3；GLM-5-Turbo / GLM-4.7 → GLM-5.3-Flash
     *    （折算与统计均按目标模型计；平台侧建议将旧模型名配置为指向同一比率或提示用户换名）。
     */
    private static function zhipu(): array
    {
        return [
            'name' => '智谱 GLM Coding Plan',
            'plan_kind' => 1,
            'billing_mode' => 2,
            'unit_name' => '资源点',
            'docs_url' => 'https://docs.bigmodel.cn/cn/coding-plan/overview',
            'verified_at' => '2026-09-11',
            'notes' => '高峰（周一至五 14:00-18:00）1 倍、非高峰 5 折；历史模型自动路由 5.2/5.1→5.3、5-Turbo/4.7→5.3-Flash；MCP 工具 1.2/次；团队版 2 席位起购、超额按刊例 9 折。',
            'tiers' => [
                ['name' => '个人版 · Lite', 'price' => null, 'price_note' => '以官网购买页为准', 'period' => '月', 'quota' => 2000, 'quota_unit' => '资源点/5小时', 'quota_note' => '每周 10000', 'sort' => 10, 'status' => 1],
                ['name' => '个人版 · Pro', 'price' => null, 'price_note' => '以官网购买页为准', 'period' => '月', 'quota' => 12000, 'quota_unit' => '资源点/5小时', 'quota_note' => '每周 60000', 'sort' => 20, 'status' => 1],
                ['name' => '个人版 · Max', 'price' => null, 'price_note' => '以官网购买页为准', 'period' => '月', 'quota' => 28000, 'quota_unit' => '资源点/5小时', 'quota_note' => '每周 140000', 'sort' => 30, 'status' => 1],
                ['name' => '团队版 · 标准版（每席位）', 'price' => null, 'price_note' => '以官网为准（售前咨询）', 'period' => '月/席位', 'quota' => 15000, 'quota_unit' => '资源点/5小时', 'quota_note' => '每周 66000；2 席位起购', 'sort' => 40, 'status' => 1],
                ['name' => '团队版 · 高级版（每席位）', 'price' => null, 'price_note' => '以官网为准（售前咨询）', 'period' => '月/席位', 'quota' => 35000, 'quota_unit' => '资源点/5小时', 'quota_note' => '每周 155000；2 席位起购', 'sort' => 50, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'glm-5.3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.69, 'cached_rate' => 0.17, 'output_rate' => 2.4, 'time_discounts' => self::TIME_WINDOWS_ZHIPU, 'sort' => 10, 'status' => 0],
                ['model' => 'glm-5.3-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.23, 'cached_rate' => 0.056, 'output_rate' => 0.8, 'time_discounts' => self::TIME_WINDOWS_ZHIPU, 'sort' => 20, 'status' => 0],
            ],
        ];
    }

    /**
     * 火山引擎 Agent/Coding Plan（个人版，资源点计量）
     *
     * 官方口径要点：
     *  - 火山方舟同时运营 Agent Plan（含 Small/Medium 档位、AFP 抵扣、超额后付费）与
     *    Coding Plan（Lite/Pro）两条个人版产品线，另提供团队/企业采购通道。
     *  - 官方文档站正文为 JS 渲染，程序化抓取仅能确认导航与公告标题（如「模型抵扣系数调整公告」
     *    「Agent/Coding Plan 指定模型抵扣系数限时折扣活动」），逐档价格与逐模型系数无法核证。
     *  - 模板仅预置已交叉验证的 Agent Plan Small（¥9.9 起，停用态）；建议管理员人工录入后
     *    再启用，并配置 pricing_source_url 让定时校对盯住「模型抵扣系数调整」类公告。
     */
    private static function volcengine(): array
    {
        return [
            'name' => '火山引擎 Coding Plan',
            'plan_kind' => 1,
            'billing_mode' => 2,
            'unit_name' => '点',
            'docs_url' => 'https://www.volcengine.com/docs/82379/1925114',
            'verified_at' => '2026-09-11',
            'notes' => 'Coding Plan 个人版 Lite/Pro 双档（官方套餐概览 2026-09-08 更新）；额度数值官方页未公布，以购买页为准。Agent Plan（AFP 抵扣）另见 volcengine-ark 模板。逐模型抵扣系数存在「模型抵扣系数调整公告」，建议配置 pricing_source_url 定时监测。',
            'tiers' => [
                ['name' => 'Coding Plan · Lite', 'price' => null, 'price_note' => '价格以官网购买页为准', 'period' => '月', 'quota' => null, 'quota_unit' => '点', 'quota_note' => '支持 Doubao/GLM/DeepSeek/Kimi/MiniMax 系；5 小时与周限额周期刷新', 'sort' => 10, 'status' => 0],
                ['name' => 'Coding Plan · Pro', 'price' => null, 'price_note' => '价格以官网购买页为准', 'period' => '月', 'quota' => null, 'quota_unit' => '点', 'quota_note' => '高强度开发档；5 小时与周限额周期刷新', 'sort' => 20, 'status' => 0],
            ],
            'ratios' => [
                ['model' => 'doubao-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 10, 'status' => 0],
                ['model' => 'kimi-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 20, 'status' => 0],
                ['model' => 'deepseek-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 30, 'status' => 0],
            ],
        ];
    }

    /**
     * DeepSeek 开放平台（纯 API 按量，无订阅套餐；模板仅提供官方价格折算参考）
     *
     * 官方口径要点（2026-09-11 抓取，单位：元/百万 token，高峰口径）：
     *  - deepseek-flash：输入缓存命中 0.04 / 未命中 2，输出 8；并发 2500。
     *  - deepseek-v4-pro：输入缓存命中 0.30 / 未命中 9，输出 27；并发 500。
     *  - 空闲时段（北京时间周一至五 9:00-12:00、14:00-18:00 以外）全部减半。
     *  - 换算为千 token（÷1000）：flash 0.002/0.008、pro 0.009/0.027。
     *    ⚠️ flash 缓存命中 0.04元/百万 = 0.00004 元/千token，低于比率列 decimal(12,4) 精度，
     *    存储按 0.0001（向上取整，对平台保守）；管理员启用前请按 remark 复核。
     *  - DeepSeek 无套餐档位（充值余额直接扣费），转售场景建议 plan_kind=2（按量），
     *    unit_name 记「元」并把 unit_exchange_rate 设为 1元 = N 平台积分。
     */
    private static function deepseek(): array
    {
        return [
            'name' => 'DeepSeek 开放平台 Token Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '元',
            'docs_url' => 'https://api-docs.deepseek.com/zh-cn/quick_start/pricing/',
            'verified_at' => '2026-09-11',
            'notes' => '纯 API 按量计费（无套餐档位），折算单位=人民币元（unit_exchange_rate 设 1 元 = N 平台积分）；高峰=周一至五 9:00-12:00、14:00-18:00，空闲全部减半；deepseek-v4-flash 等旧模型名自动路由到 deepseek-flash 并按 Flash 价计费。',
            'tiers' => [],
            'ratios' => [
                ['model' => 'deepseek-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.002, 'cached_rate' => 0.0001, 'output_rate' => 0.008, 'time_discounts' => self::TIME_WINDOWS_DEEPSEEK, 'sort' => 10, 'status' => 0],
                ['model' => 'deepseek-v4-pro', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.009, 'cached_rate' => 0.0003, 'output_rate' => 0.027, 'time_discounts' => self::TIME_WINDOWS_DEEPSEEK, 'sort' => 20, 'status' => 0],
            ],
        ];
    }

    /**
     * 腾讯云 TokenHub Token Plan（个人版 通用 + Hy 双系列，积分计量）
     *
     * 官方口径要点（2026-09-11 抓取 product/1823/130060）：
     *  - 通用 Token Plan：Lite 39 元/780 积分、Standard 99/1980、Pro 299/5980、Max 599/11980（每订阅月）。
     *  - Hy Token Plan（混元）：Lite 28/560、Standard 78/1560、Pro 238/4760、Max 468/9360（每订阅月）。
     *  - 通用系列模型：tc-code-latest（Auto 路由）、deepseek-v4-flash-202605、deepseek-v4-pro-0813（原厂直供）、
     *    minimax-m2.7/m3、glm-5（2026-10-09 下线）、glm-5.1（2026-10-09 下线）、glm-5.2、glm-5.3、
     *    glm-5.3-flash、hy4-preview、kimi-k2.7-code、kimi-k3；Hy 系列：hy3（hy3-preview 自动路由）、hy4-preview。
     *  - 2026-08-31 17:00 起调整为积分抵扣模式；逐模型积分系数已核对（1823/133811，2026-09-10 更新）：
     *    新逻辑模型（与档位无关）按「未命中输入/缓存命中/输出」三率积分价（积分/百万 tokens）：
     *    glm-5.3 160/40/560、glm-5.3-flash 16/4.6/56、kimi-k3 400/40/2000、kimi-k2.7-code 130/26/540、
     *    minimax-m3 42/8.4/168（输入>512k 翻倍为 84/16.8/336）、hy4-preview 120/6/360；
     *    Auto（tc-code-latest）与旧逻辑模型（deepseek-v4-*、glm-5/5.1/5.2、minimax-m2.7）官方按档位统一价
     *    （Lite 22.285/Standard 19.8/Pro 18.687/Max 8.43），模板取 Standard 口径 19.8 → 0.0198 积分/千 token；
     *  - 限时活动（至 2026-09-30）：tc-code-latest 11/11/11、kimi-k2.7-code 65/270/13、minimax-m3 21/84/4.2、
     *    hy4-preview 100/200/4、glm-5.3 136/476/34、kimi-k3 380/1900/38（95 折，9/4-9/30）；活动结束恢复刊例。
     *  - 限制：每主账号最多同时持有 2 个（通用+Hy 各 1）、同系列仅 1 档、仅升配、不退订、自然月有效、
     *    到期额度与 API Key 同时失效；套餐内模型库动态更新（新增/替换/下线以公告为准）。
     */
    private static function tencent(): array
    {
        return [
            'name' => '腾讯混元 Token Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '积分',
            'docs_url' => 'https://cloud.tencent.com/document/product/1823/130060',
            'verified_at' => '2026-09-11',
            'notes' => '积分抵扣规则已核对（1823/133811，2026-09-10 更新）：新逻辑模型三率积分价（积分/百万 tokens）glm-5.3 160/40/560、glm-5.3-flash 16/4.6/56、kimi-k3 400/40/2000、kimi-k2.7-code 130/26/540、minimax-m3 42/8.4/168（>512k 翻倍）、hy4-preview 120/6/360，已 ÷1000 存积分/千 token；Auto/旧逻辑模型按档位统一价（22.285/19.8/18.687/8.43），模板取 Standard 口径；限时活动（至 9/30）：tc-code-latest 11、kimi-k2.7-code 65/270/13、minimax-m3 21/84/4.2、hy4-preview 100/200/4、glm-5.3 136/476/34、kimi-k3 380/1900/38；glm-5/glm-5.1 2026-10-09 下线；不退订、仅升配。',
            'tiers' => [
                ['name' => '通用 · Lite', 'price' => 39, 'price_note' => '', 'period' => '月', 'quota' => 780, 'quota_unit' => '积分/订阅月', 'quota_note' => '约 70 轮龙虾交互', 'sort' => 10, 'status' => 1],
                ['name' => '通用 · Standard', 'price' => 99, 'price_note' => '', 'period' => '月', 'quota' => 1980, 'quota_unit' => '积分/订阅月', 'quota_note' => '约 200 轮龙虾交互', 'sort' => 20, 'status' => 1],
                ['name' => '通用 · Pro', 'price' => 299, 'price_note' => '', 'period' => '月', 'quota' => 5980, 'quota_unit' => '积分/订阅月', 'quota_note' => '', 'sort' => 30, 'status' => 1],
                ['name' => '通用 · Max', 'price' => 599, 'price_note' => '', 'period' => '月', 'quota' => 11980, 'quota_unit' => '积分/订阅月', 'quota_note' => '', 'sort' => 40, 'status' => 1],
                ['name' => 'Hy · Lite', 'price' => 28, 'price_note' => '', 'period' => '月', 'quota' => 560, 'quota_unit' => '积分/订阅月', 'quota_note' => '混元 Hy3/Hy4 专用', 'sort' => 50, 'status' => 1],
                ['name' => 'Hy · Standard', 'price' => 78, 'price_note' => '', 'period' => '月', 'quota' => 1560, 'quota_unit' => '积分/订阅月', 'quota_note' => '混元 Hy3/Hy4 专用', 'sort' => 60, 'status' => 1],
                ['name' => 'Hy · Pro', 'price' => 238, 'price_note' => '', 'period' => '月', 'quota' => 4760, 'quota_unit' => '积分/订阅月', 'quota_note' => '混元 Hy3/Hy4 专用', 'sort' => 70, 'status' => 1],
                ['name' => 'Hy · Max', 'price' => 468, 'price_note' => '', 'period' => '月', 'quota' => 9360, 'quota_unit' => '积分/订阅月', 'quota_note' => '混元 Hy3/Hy4 专用', 'sort' => 80, 'status' => 1],
            ],
            'ratios' => [
                // 新逻辑模型（2026-08-31 起上架，与档位无关）：积分/千 token = 官方积分/百万 ÷ 1000
                ['model' => 'glm-5.3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.16, 'cached_rate' => 0.04, 'output_rate' => 0.56, 'sort' => 10, 'status' => 0],
                ['model' => 'glm-5.3-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.016, 'cached_rate' => 0.0046, 'output_rate' => 0.056, 'sort' => 20, 'status' => 0],
                ['model' => 'kimi-k3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.4, 'cached_rate' => 0.04, 'output_rate' => 2.0, 'sort' => 30, 'status' => 0],
                ['model' => 'kimi-k2.7-code', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.13, 'cached_rate' => 0.026, 'output_rate' => 0.54, 'sort' => 40, 'status' => 0],
                ['model' => 'minimax-m3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.042, 'cached_rate' => 0.0084, 'output_rate' => 0.168, 'sort' => 50, 'status' => 0],
                ['model' => 'hy4-preview', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.12, 'cached_rate' => 0.006, 'output_rate' => 0.36, 'sort' => 60, 'status' => 0],
                // Auto 路由（按档位同价 Lite 22.285/Standard 19.8/Pro 18.687/Max 8.43 → Standard 口径）
                ['model' => 'tc-code-latest', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.0198, 'cached_rate' => 0.0198, 'output_rate' => 0.0198, 'sort' => 70, 'status' => 0],
                // 旧逻辑模型（deepseek-v4-*、glm-5/5.1/5.2、minimax-m2.7）：官方未列逐模型价，预估表按档位统一价抵扣 ≈ Auto 档位价 → Standard 口径兜底
                ['model' => 'deepseek-', 'match_type' => 'prefix', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.0198, 'cached_rate' => 0.0198, 'output_rate' => 0.0198, 'sort' => 80, 'status' => 0],
                ['model' => 'glm-', 'match_type' => 'prefix', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.0198, 'cached_rate' => 0.0198, 'output_rate' => 0.0198, 'sort' => 90, 'status' => 0],
                ['model' => 'minimax-', 'match_type' => 'prefix', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.0198, 'cached_rate' => 0.0198, 'output_rate' => 0.0198, 'sort' => 100, 'status' => 0],
                ['model' => 'kimi-', 'match_type' => 'prefix', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.0198, 'cached_rate' => 0.0198, 'output_rate' => 0.0198, 'sort' => 110, 'status' => 0],
                // Hy 系列（Hy Token Plan 套餐调用）：hy3 按档位同价 Lite 16/Standard 15.6/Pro 14.875/Max 14.4 → Standard 口径
                ['model' => 'hy3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.0156, 'cached_rate' => 0.0156, 'output_rate' => 0.0156, 'sort' => 120, 'status' => 0],
                ['model' => 'hy3-preview', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.0156, 'cached_rate' => 0.0156, 'output_rate' => 0.0156, 'sort' => 130, 'status' => 0],
                // hy3-202608 为新逻辑差价（官方示例：Lite 档 18 万输入+1 万输出+82 万缓存 = 4.25 积分）
                ['model' => 'hy3-202608', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.02, 'cached_rate' => 0.005, 'output_rate' => 0.08, 'sort' => 140, 'status' => 0],
            ],
        ];
    }

    /**
     * 腾讯云 TokenHub Token Plan 企业版·专业套餐（自定义积分额度，积分计量）
     *
     * 官方口径要点（2026-09-11 抓取 product/1823/130659，2026-09-10 更新）：
     *  - 专业套餐按月自定义购买积分额度（最低 5 万积分），刊例价 5 万积分 = 500 元/月 → 1 积分 = 0.01 元（官方明示）。
     *  - 企业版积分抵扣价与个人版不同（广州区，命中缓存/未命中输入/输出，积分/百万 tokens）：
     *    auto 50/324/1596、glm-5.3-flash 23/80/280、glm-5.3 200/800/2800、glm-5.2 200/800/2800、
     *    glm-5 100/400/1800（输入 32k+：150/600/2200）、glm-5.1 130/600/2400（32k+：200/800/2800）、
     *    glm-5-turbo 120/500/2200（32k+：180/700/2600）、kimi-k3 200/2000/10000、kimi-k2.7-code 130/650/2700、
     *    kimi-k2.7-code-highspeed 260/1300/5400、kimi-k2.6 110/650/2700、minimax-m2.7 42/210/840、
     *    minimax-m3 42/210/840（512k+：84/420/1680）、deepseek-v4-flash 20/100/200、deepseek-v4-pro 100/1200/2400；
     *    DeepSeek V4 峰谷计费（模板取高峰价，空闲价约减半）：v4-flash-0731 高峰 10/300/900（空闲 5/150/450）、
     *    v4-pro-0813 高峰 30/900/2700（空闲 15/450/1350）、原厂直供 v4-flash-202605 高峰 4/200/800（空闲 2/100/400）、
     *    v4-pro-202606 高峰 30/900/2700（空闲 15/450/1350）、vision-exp 高峰 4/200/800（空闲 2/100/400）；
     *    周末全天按空闲价；新加坡区另有差异（约 ±5%~25%），以控制台为准。
     *  - 抵扣顺序：席位额度优先 → 共享积分包（先到期先扣）；计费周期末未用积分作废；不退订。
     *  - 另有企业版「轻享套餐」：自定义 Token 资源池（最低 5,000 万 tokens），广州 100 元/月、新加坡 130 元/月，
     *    输入/输出/缓存命中统一按实际 Token 1:1 抵扣（无逐模型系数，综合约 2 元/百万 tokens），不单独建模板。
     *  - 模型库较个人版多出 glm-5/glm-5.1/glm-5-turbo（2026-10-09 下线）、kimi-k2.6、kimi-k2.7-code-highspeed 等。
     */
    private static function tencentTeam(): array
    {
        return [
            'name' => '腾讯混元 Token Plan 企业版',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '积分',
            'docs_url' => 'https://cloud.tencent.com/document/product/1823/130659',
            'verified_at' => '2026-09-11',
            'notes' => '专业套餐自定义积分额度（≥5 万），刊例 5 万积分=500 元/月（1 积分=0.01 元官方明示）；系数取广州区、DeepSeek 取高峰价（空闲价与新加坡区差异见官方文档）；轻享套餐为自定义 Token 池（≥5,000 万 tokens，广州 100 元/月）1:1 抵扣；额度周期末作废；不退订。',
            'tiers' => [
                ['name' => '专业套餐 · 自定义积分', 'price' => 500, 'price_note' => '刊例：5 万积分/月起，支持按月自定义', 'period' => '月', 'quota' => 50000, 'quota_unit' => '积分/月', 'quota_note' => '最低 5 万积分；1 积分=0.01 元；周期末未用作废', 'sort' => 10, 'status' => 1],
            ],
            'ratios' => [
                // 广州区积分价（命中缓存/未命中输入/输出，积分/百万 tokens ÷ 1000）；DeepSeek 系取高峰价
                ['model' => 'auto', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.324, 'cached_rate' => 0.05, 'output_rate' => 1.596, 'sort' => 10, 'status' => 0],
                ['model' => 'glm-5.3-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.08, 'cached_rate' => 0.023, 'output_rate' => 0.28, 'sort' => 20, 'status' => 0],
                ['model' => 'glm-5.3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.8, 'cached_rate' => 0.2, 'output_rate' => 2.8, 'sort' => 30, 'status' => 0],
                ['model' => 'glm-5.2', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.8, 'cached_rate' => 0.2, 'output_rate' => 2.8, 'sort' => 40, 'status' => 0],
                ['model' => 'glm-5', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.4, 'cached_rate' => 0.1, 'output_rate' => 1.8, 'sort' => 50, 'status' => 0],
                ['model' => 'glm-5.1', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.6, 'cached_rate' => 0.13, 'output_rate' => 2.4, 'sort' => 60, 'status' => 0],
                ['model' => 'glm-5-turbo', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.5, 'cached_rate' => 0.12, 'output_rate' => 2.2, 'sort' => 70, 'status' => 0],
                ['model' => 'kimi-k3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 2.0, 'cached_rate' => 0.2, 'output_rate' => 10.0, 'sort' => 80, 'status' => 0],
                ['model' => 'kimi-k2.7-code', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.65, 'cached_rate' => 0.13, 'output_rate' => 2.7, 'sort' => 90, 'status' => 0],
                ['model' => 'kimi-k2.7-code-highspeed', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 1.3, 'cached_rate' => 0.26, 'output_rate' => 5.4, 'sort' => 100, 'status' => 0],
                ['model' => 'kimi-k2.6', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.65, 'cached_rate' => 0.11, 'output_rate' => 2.7, 'sort' => 110, 'status' => 0],
                ['model' => 'minimax-m2.7', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.21, 'cached_rate' => 0.042, 'output_rate' => 0.84, 'sort' => 120, 'status' => 0],
                ['model' => 'minimax-m3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.21, 'cached_rate' => 0.042, 'output_rate' => 0.84, 'sort' => 130, 'status' => 0],
                ['model' => 'deepseek-v4-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.1, 'cached_rate' => 0.02, 'output_rate' => 0.2, 'sort' => 140, 'status' => 0],
                ['model' => 'deepseek-v4-pro', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 1.2, 'cached_rate' => 0.1, 'output_rate' => 2.4, 'sort' => 150, 'status' => 0],
                ['model' => 'deepseek-v4-flash-0731', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.3, 'cached_rate' => 0.01, 'output_rate' => 0.9, 'sort' => 160, 'status' => 0],
                ['model' => 'deepseek-v4-pro-0813', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.9, 'cached_rate' => 0.03, 'output_rate' => 2.7, 'sort' => 170, 'status' => 0],
                ['model' => 'deepseek-v4-flash-202605', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.2, 'cached_rate' => 0.004, 'output_rate' => 0.8, 'sort' => 180, 'status' => 0],
                ['model' => 'deepseek-v4-pro-202606', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.9, 'cached_rate' => 0.03, 'output_rate' => 2.7, 'sort' => 190, 'status' => 0],
                ['model' => 'deepseek/deepseek-v4-flash-vision-exp', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.2, 'cached_rate' => 0.004, 'output_rate' => 0.8, 'sort' => 200, 'status' => 0],
            ],
        ];
    }

    /**
     * 火山方舟 Agent Plan（个人版 Small/Medium/Large/Max，AFP 统一计量）
     *
     * 官方口径要点（2026-09-11 抓取 docs/82379/2366394 与 2658332）：
     *  - 四档月价 40/200/500/1000 元，月额度 2 万/10 万/25 万/50 万 AFP；
     *    周 7000/35000/87500/175000，5 小时 2000/10000/25000/50000，日额度=月额度一半。
     *  - AFP 抵扣公式（2026-09-01 起，输入不再分段）：AFP =（输入 token×输入系数 + 输出 token×输出系数）/ 10000。
     *  - 官方逐模型输入/输出系数（÷10 换算为每千 token 存储口径）：
     *    doubao-seed-2.0-mini 0.25；doubao-seed-2.0-lite、deepseek-v4-flash、doubao-embedding-vision 0.5；
     *    doubao-seed-2.1-turbo、doubao-seed-evolving、minimax-m3 2.5；kimi-k2.7-code、glm-5.2（将下线）、glm-5.3 4.5；
     *    deepseek-v4-pro 5.5；kimi-k3 10。AFP 无缓存折扣段（cached_rate=0，命中 token 仅按输入段计）。
     *  - Auto 模式活动系数 0.5（至 2026-11-08，夜间大幅路由 kimi-k3）；glm-5.3-flash 折扣活动等以公告为准。
     *  - 扣减语义为「按用量折算 AFP」，与阿里 Credits 同构归入 plan_kind=2。
     */
    private static function volcengineArk(): array
    {
        return [
            'name' => '火山方舟 Agent Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => 'AFP',
            'docs_url' => 'https://www.volcengine.com/docs/82379/2366394',
            'verified_at' => '2026-09-11',
            'notes' => 'AFP =（输入×系数+输出×系数）/10000（2026-09-01 起输入不分段）；周/5小时/日限额随档位；支持超额后付费；Auto 模式活动系数 0.5 至 2026-11-08；glm-5.2 即将下线。',
            'tiers' => [
                ['name' => 'Agent Plan · Small', 'price' => 40, 'price_note' => '限时活动价 9.9 元/月起', 'period' => '月', 'quota' => 20000, 'quota_unit' => 'AFP', 'quota_note' => '周 7,000 / 5小时 2,000；轻量体验（不支持视频生成）', 'sort' => 10, 'status' => 1],
                ['name' => 'Agent Plan · Medium', 'price' => 200, 'price_note' => '', 'period' => '月', 'quota' => 100000, 'quota_unit' => 'AFP', 'quota_note' => '周 35,000 / 5小时 10,000；1-2 个项目并行', 'sort' => 20, 'status' => 1],
                ['name' => 'Agent Plan · Large', 'price' => 500, 'price_note' => '', 'period' => '月', 'quota' => 250000, 'quota_unit' => 'AFP', 'quota_note' => '周 87,500 / 5小时 25,000；支持视频生成', 'sort' => 30, 'status' => 1],
                ['name' => 'Agent Plan · Max', 'price' => 1000, 'price_note' => '', 'period' => '月', 'quota' => 500000, 'quota_unit' => 'AFP', 'quota_note' => '周 175,000 / 5小时 50,000；支持视频生成', 'sort' => 40, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'doubao-seed-2.0-mini', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.025, 'cached_rate' => 0, 'output_rate' => 0.025, 'sort' => 10, 'status' => 0],
                ['model' => 'doubao-seed-2.0-lite', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.05, 'cached_rate' => 0, 'output_rate' => 0.05, 'sort' => 20, 'status' => 0],
                ['model' => 'deepseek-v4-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.05, 'cached_rate' => 0, 'output_rate' => 0.05, 'sort' => 30, 'status' => 0],
                ['model' => 'doubao-seed-2.1-turbo', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.25, 'cached_rate' => 0, 'output_rate' => 0.25, 'sort' => 40, 'status' => 0],
                ['model' => 'doubao-seed-evolving', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.25, 'cached_rate' => 0, 'output_rate' => 0.25, 'sort' => 50, 'status' => 0],
                ['model' => 'minimax-m3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.25, 'cached_rate' => 0, 'output_rate' => 0.25, 'sort' => 60, 'status' => 0],
                ['model' => 'kimi-k2.7-code', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.45, 'cached_rate' => 0, 'output_rate' => 0.45, 'sort' => 70, 'status' => 0],
                ['model' => 'glm-5.2', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.45, 'cached_rate' => 0, 'output_rate' => 0.45, 'sort' => 80, 'status' => 0],
                ['model' => 'glm-5.3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.45, 'cached_rate' => 0, 'output_rate' => 0.45, 'sort' => 90, 'status' => 0],
                ['model' => 'deepseek-v4-pro', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.55, 'cached_rate' => 0, 'output_rate' => 0.55, 'sort' => 100, 'status' => 0],
                ['model' => 'kimi-k3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 1.0, 'cached_rate' => 0, 'output_rate' => 1.0, 'sort' => 110, 'status' => 0],
                ['model' => 'doubao-embedding-vision', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.05, 'cached_rate' => 0, 'output_rate' => 0.05, 'sort' => 120, 'status' => 0],
                ['model' => 'auto', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.05, 'cached_rate' => 0, 'output_rate' => 0.05, 'sort' => 130, 'status' => 0],
            ],
        ];
    }

    /**
     * Moonshot Kimi 开放平台（纯 API 按量，无订阅套餐）
     *
     * 官方口径要点（2026-09-11 抓取 platform.kimi.com/docs/pricing/chat）：
     *  - kimi-k3：缓存命中 ¥2 / 未命中 ¥20 / 输出 ¥100（每百万 token，1M 上下文）；
     *    kimi-k2.7-code：1.3 / 6.5 / 27；kimi-k2.7-code-highspeed：2.6 / 13 / 54；kimi-k2.6：1.1 / 6.5 / 27。
     *  - 官方明确「按量计费模式、无订阅制方案」，故无套餐档位；unit_name 记「元」。
     *  - 注意：引擎对缓存命中段按「输入 + 缓存」双计（保守取向），本模板 cached_rate 取官方命中价，
     *    启用后实际命中扣费略高于官方口径，管理员如需精确可下调 input_rate。
     */
    private static function moonshot(): array
    {
        return [
            'name' => 'Moonshot Kimi Token Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '元',
            'docs_url' => 'https://platform.kimi.com/docs/pricing/chat',
            'verified_at' => '2026-09-11',
            'notes' => '纯 API 按量计费（官方无订阅制方案），折算单位=人民币元；Batch API 按 5 折；托管智能体/联网搜索另计。',
            'tiers' => [],
            'ratios' => [
                ['model' => 'kimi-k3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 2.0, 'cached_rate' => 0.2, 'output_rate' => 10.0, 'sort' => 10, 'status' => 0],
                ['model' => 'kimi-k2.7-code', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.65, 'cached_rate' => 0.13, 'output_rate' => 2.7, 'sort' => 20, 'status' => 0],
                ['model' => 'kimi-k2.7-code-highspeed', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 1.3, 'cached_rate' => 0.26, 'output_rate' => 5.4, 'sort' => 30, 'status' => 0],
                ['model' => 'kimi-k2.6', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.65, 'cached_rate' => 0.11, 'output_rate' => 2.7, 'sort' => 40, 'status' => 0],
            ],
        ];
    }

    /**
     * 百度智能云千帆 Token Plan（个人版 Mini/Lite/Pro/Max，积分制 ⇄ Token 制双轨）
     *
     * 官方口径要点（2026-09-11 抓取 cloud.baidu.com/doc/qianfan/s/Dmrabu8b6，2026-09-10 更新）：
     *  - 四档月价（原价）：Mini 9.9 元/1,400 积分、Lite 40/6,600、Pro 200/45,000、Max 600/165,000；
     *    首购五折 4.9/19.9/99.9/299.9（每日 10 点限量秒杀）、到期续费专享 6 折。
     *  - 双轨同价额度：Token 制 1,000 万/4,200 万/2.3 亿/7 亿 tokens ⇄ 积分制 1,400/6,600/45,000/165,000 积分；
     *    Token 制按实际消耗 Token 1:1 抵扣（不区分输入/输出/缓存）；deepseek-v4-pro-0813 按 1.8 倍抵扣（仅 Token 制）。
     *  - 积分制「即将支持」：逐模型系数未公布，仅示例（V4-Pro 输入 853 tokens≈1 积分、缓存 10,240≈1、输出 427≈1）。
     *  - 模型范围：deepseek-v4-pro（-0813）、deepseek-v4-flash（-0731，2026-09-29 下线）、glm-5.3/5.3-flash/5.2/5.1、
     *    kimi-k2.6（2026-09-29 下线）；指定模型闲时低至 0.5 折；企业版（席位+共享积分包）价格未公布，暂不建模板。
     */
    private static function baidu(): array
    {
        return [
            'name' => '百度智能云千帆 Token Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '积分',
            'docs_url' => 'https://cloud.baidu.com/doc/qianfan/s/Dmrabu8b6',
            'verified_at' => '2026-09-11',
            'notes' => '官方文档（2026-09-10）双轨同价额度：Token 制 1,000 万/4,200 万/2.3 亿/7 亿 tokens ⇄ 积分制 1,400/6,600/45,000/165,000 积分；Token 制 1:1 扣减（deepseek-v4-pro-0813 按 1.8 倍抵扣，仅 Token 制）；积分制「即将支持」（逐模型系数未公布，仅示例：V4-Pro 输入 853 tokens≈1 积分）；指定模型闲时低至 0.5 折；首购五折每日 10 点限量、续费焕新 6 折；企业版（席位+共享积分包）价格未公布。',
            'tiers' => [
                ['name' => '个人版 · Mini', 'price' => 9.9, 'price_note' => '首购五折 4.9（限量秒杀）', 'period' => '月', 'quota' => 1400, 'quota_unit' => '积分', 'quota_note' => '双轨同价：Token 制额度 1,000 万 tokens/月', 'sort' => 10, 'status' => 1],
                ['name' => '个人版 · Lite', 'price' => 40, 'price_note' => '首购五折 19.9（限量秒杀）', 'period' => '月', 'quota' => 6600, 'quota_unit' => '积分', 'quota_note' => '双轨同价：Token 制额度 4,200 万 tokens/月', 'sort' => 20, 'status' => 1],
                ['name' => '个人版 · Pro', 'price' => 200, 'price_note' => '首购五折 99.9（限量秒杀）', 'period' => '月', 'quota' => 45000, 'quota_unit' => '积分', 'quota_note' => '双轨同价：Token 制额度 2.3 亿 tokens/月', 'sort' => 30, 'status' => 1],
                ['name' => '个人版 · Max', 'price' => 600, 'price_note' => '首购五折 299.9（限量秒杀）', 'period' => '月', 'quota' => 165000, 'quota_unit' => '积分', 'quota_note' => '双轨同价：Token 制额度 7 亿 tokens/月', 'sort' => 40, 'status' => 1],
            ],
            'ratios' => [
                // Token 制（当前生效）：按实际消耗 Token 1:1 抵扣，不区分输入/输出/缓存
                ['model' => 'deepseek-v4-pro-0813', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1.8, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 10, 'status' => 0],
                ['model' => 'deepseek-', 'match_type' => 'prefix', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 20, 'status' => 0],
                ['model' => 'glm-', 'match_type' => 'prefix', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 30, 'status' => 0],
                ['model' => 'kimi-', 'match_type' => 'prefix', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 40, 'status' => 0],
            ],
        ];
    }

    /**
     * 中国联通 Coding Plan（联通云 AI 服务平台 AISP，按「模型调用次数」计量）
     *
     * 官方口径要点（2026-09-11 抓取 support.cucloud.cn/document/127/591/2357，arcid=7015）：
     *  - Lite 40元/月：每 5 小时约 1,200 次 / 每周约 9,000 次 / 每订阅月约 18,000 次请求；
     *  - Pro 200元/月：每 5 小时约 6,000 次 / 每周约 45,000 次 / 每订阅月约 90,000 次请求；
     *  - 额度消耗：单次提问按实际「模型调用次数」扣减（简单 Agent 任务约 5-10 次、复杂 10-30+ 次），
     *    即 1 次模型调用 = 1 次额度，与模型无关 → per_request 1:1（比率行停用，管理员核对后启用）；
     *  - 支持模型（贵阳基地二区/武汉四区）：aisp-auto-route（智能路由）、DeepSeek-V4-Flash、glm-5.1/glm-5、
     *    Qwen3.6-27B、kimi-k2.6/kimi-k2.5、Qwen3.5-397B-A17B、Qwen3-235B-A22B、MiniMax-M2.5；
     *    DeepSeek-V4-Flash 仅供尝鲜（上下文 200K），高峰期易限流；
     *  - 订阅不退款、仅升配；额度按 5 小时/周/订阅月周期刷新（月额度订阅月第 1 日 00:00 刷新）。
     */
    private static function unicom(): array
    {
        return [
            'name' => '中国联通 Coding Plan',
            'plan_kind' => 1,
            'billing_mode' => 2,
            'unit_name' => '次',
            'docs_url' => 'https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7015&lang=zh',
            'verified_at' => '2026-09-11',
            'notes' => '按「模型调用次数」扣减（简单 Agent 任务约 5-10 次、复杂 10-30+ 次）；Lite 40 元/月、Pro 200 元/月；5 小时/周/订阅月三重限额；不退款、仅升配；兼容 OpenAI/Anthropic 协议（aigw-gzgy2.cucloud.cn:8443）。',
            'tiers' => [
                ['name' => 'Coding Plan · Lite', 'price' => 40, 'price_note' => '', 'period' => '月', 'quota' => 18000, 'quota_unit' => '次/订阅月', 'quota_note' => '每 5 小时约 1,200 次、每周约 9,000 次；入门尝鲜档', 'sort' => 10, 'status' => 1],
                ['name' => 'Coding Plan · Pro', 'price' => 200, 'price_note' => '', 'period' => '月', 'quota' => 90000, 'quota_unit' => '次/订阅月', 'quota_note' => '每 5 小时约 6,000 次、每周约 45,000 次；额度为 Lite 的 5 倍', 'sort' => 20, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'aisp-auto-route', 'match_type' => 'exact', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 10, 'status' => 0],
                ['model' => 'DeepSeek-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 20, 'status' => 0],
                ['model' => 'glm-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 30, 'status' => 0],
                ['model' => 'Qwen', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 40, 'status' => 0],
                ['model' => 'kimi-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 50, 'status' => 0],
                ['model' => 'MiniMax-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 60, 'status' => 0],
            ],
        ];
    }

    /**
     * 中国联通 Token Plan（个人版 tokens 1:1 + 团队版 credits 折算，两条产品线）
     *
     * 官方口径要点（2026-09-11 抓取同页，arcid=7080 区段）：
     *  - 个人版：Lite/Pro/Max 15/30/45 元/月，600/1,200/1,800 万 tokens，tokens 1:1 扣减；
     *    个人版仅开放 DeepSeek-V4-Flash 与 MiniMax-M2.5（贵阳二区/武汉四区/广州一区）。
     *  - 团队版：Lite/Pro/Max 198/698/1398 元/月，25,000/100,000/250,000 credits（仅贵阳二区，独享 DeepSeek-V4-Pro）；
     *    官方未公布逐模型系数，但给出线性折算示例（25,000 credits ≈ V4-Pro 27 百万 / V4-Flash 357 百万 /
     *    MiniMax-M2.5 227 百万 tokens）→ 1 credit ≈ 0.01 元，credits/百万 tokens = 综合单价 × 100
     *    → 每千 tokens 系数 DeepSeek-V4-Pro 0.93、DeepSeek-V4-Flash 0.07、MiniMax-M2.5 0.11（与三组示例全部吻合）。
     *  - exact 行为团队版 credits 口径（优先命中），prefix 行为个人版 tokens 1:1 兜底；个人版与团队版互不切换、仅升配。
     *  - 按量兜底刊例（元/千 tokens，非套餐口径）：DeepSeek-V3 0.002/0.008、R1 0.004/0.016、V3.1 0.004/0.012（入/出）。
     */
    private static function unicomToken(): array
    {
        return [
            'name' => '中国联通 Token Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '千token',
            'docs_url' => 'https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7080&lang=zh',
            'verified_at' => '2026-09-11',
            'notes' => '个人版 tokens 1:1（600/1,200/1,800 万/月，15/30/45 元）；团队版 credits（25,000/100,000/250,000，198/698/1398 元），折算系数由官方线性示例导出（1 credit ≈ 0.01 元）；个人版仅 V4-Flash/M2.5，团队版独享 V4-Pro；不退款、仅升配。',
            'tiers' => [
                ['name' => '个人版 · Lite', 'price' => 15, 'price_note' => '', 'period' => '月', 'quota' => 6000000, 'quota_unit' => 'tokens', 'quota_note' => '仅 DeepSeek-V4-Flash / MiniMax-M2.5；tokens 1:1 扣减', 'sort' => 10, 'status' => 1],
                ['name' => '个人版 · Pro', 'price' => 30, 'price_note' => '', 'period' => '月', 'quota' => 12000000, 'quota_unit' => 'tokens', 'quota_note' => '2 倍于 Lite 额度；仅 DeepSeek-V4-Flash / MiniMax-M2.5', 'sort' => 20, 'status' => 1],
                ['name' => '个人版 · Max', 'price' => 45, 'price_note' => '', 'period' => '月', 'quota' => 18000000, 'quota_unit' => 'tokens', 'quota_note' => '3 倍于 Lite 额度；仅 DeepSeek-V4-Flash / MiniMax-M2.5', 'sort' => 30, 'status' => 1],
                ['name' => '团队版 · Lite', 'price' => 198, 'price_note' => '', 'period' => '月', 'quota' => 25000, 'quota_unit' => 'credits', 'quota_note' => '约 27 百万 tokens（V4-Pro）/ 357 百万（V4-Flash）/ 227 百万（M2.5）；仅贵阳二区', 'sort' => 40, 'status' => 1],
                ['name' => '团队版 · Pro', 'price' => 698, 'price_note' => '', 'period' => '月', 'quota' => 100000, 'quota_unit' => 'credits', 'quota_note' => '4 倍于 Lite 额度；含 DeepSeek-V4-Pro；仅贵阳二区', 'sort' => 50, 'status' => 1],
                ['name' => '团队版 · Max', 'price' => 1398, 'price_note' => '', 'period' => '月', 'quota' => 250000, 'quota_unit' => 'credits', 'quota_note' => '10 倍于 Lite 额度；含 DeepSeek-V4-Pro；仅贵阳二区', 'sort' => 60, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'DeepSeek-V4-Pro', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.93, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 10, 'status' => 0],
                ['model' => 'DeepSeek-V4-Flash', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.07, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 20, 'status' => 0],
                ['model' => 'MiniMax-M2.5', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.11, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 30, 'status' => 0],
                ['model' => 'DeepSeek-', 'match_type' => 'prefix', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 40, 'status' => 0],
                ['model' => 'MiniMax-', 'match_type' => 'prefix', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 50, 'status' => 0],
            ],
        ];
    }

    /**
     * 中国移动 Coding Plan（按「模型调用次数」扣减的订阅包，MoMA 平台）
     *
     * 官方口径要点（2026-09-11 经 CMS API 抓取 ART 98320《Coding Plan介绍》、98337《套餐QA》核对）：
     *  - Lite 40 元/月：每 5 小时约 1,200 次 / 每周约 9,000 次 / 每订阅月 18,000 次；
     *    Pro 200 元/月：Lite 的 5 倍（6,000/45,000/90,000 次）；周一 00:00 重置周限额。
     *  - 仅支持 MiniMax-M2.5（192K 上下文，抵扣系数 1，每请求扣 1 次；简单任务约 5-10 次/提问、
     *    复杂 10-30+ 次）；超出限制使用（不转按量）；仅呼和浩特/武汉/郑州/广州8 四资源池。
     *  - 接入（ART 98322）：OpenAI 兼容 https://zhenze-{region}.cmecloud.cn/api/coding/v1，
     *    Anthropic 协议去 /v1；模型名 MiniMax-M2.5 或 Auto 路由名 cm-code-latest（不区分大小写）。
     *  - 营销活动（至 2026-12-31）：首订 Lite 7.9 元 / Pro 39.9 元，续订 5 折券 1 次；不退订、仅升配。
     */
    private static function cmcc(): array
    {
        return [
            'name' => '中国移动 Coding Plan',
            'plan_kind' => 1,
            'billing_mode' => 2,
            'unit_name' => '次请求',
            'docs_url' => 'https://ecloud.10086.cn/op-help-center/doc/article/98320',
            'verified_at' => '2026-09-11',
            'notes' => '按「模型调用次数」扣减（每请求扣 1 次；简单任务约 5-10 次/提问、复杂 10-30+ 次）；仅 MiniMax-M2.5（192K）；Lite 40 元 1.8 万次/月、Pro 200 元 9 万次/月（5 小时/周/月三重限额，周一 00:00 重置周限额）；活动价首订 7.9/39.9 元（至 2026-12-31）；超限限用不转按量；仅编程工具接入（严禁 API 直调）；不退款、仅升配。',
            'tiers' => [
                ['name' => 'Coding Plan · Lite', 'price' => 40, 'price_note' => '活动价 7.9 元/月（至 2026-12-31）', 'period' => '月', 'quota' => 18000, 'quota_unit' => '次请求/订阅月', 'quota_note' => '每 5 小时约 1,200 次、每周约 9,000 次；日均请求 <600 次适用', 'sort' => 10, 'status' => 1],
                ['name' => 'Coding Plan · Pro', 'price' => 200, 'price_note' => '活动价 39.9 元/月（至 2026-12-31）', 'period' => '月', 'quota' => 90000, 'quota_unit' => '次请求/订阅月', 'quota_note' => '每 5 小时约 6,000 次、每周约 45,000 次；额度为 Lite 的 5 倍', 'sort' => 20, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'MiniMax-M2.5', 'match_type' => 'exact', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 10, 'status' => 0],
                ['model' => 'cm-code-latest', 'match_type' => 'exact', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 20, 'status' => 0],
            ],
        ];
    }

    /**
     * 中国移动 Token Plan 个人版（「算力豆」计量：豆按模型兑换率折算为 tokens）
     *
     * 官方口径要点（2026-09-11 经 CMS API 抓取 ART 100224《Token Plan个人版介绍》核对）：
     *  - 档位：月包 5/10/20/40/100/200/500 元 → 200/450/950/2,000/5,500/12,000/35,000 豆（每档限购 1 个）；
     *    次包 10/20/100 元 → 400/900/5,000 豆（可重复订购）；尝鲜包 9.9 元 → 1,200 豆（限 1 个，仅 Auto）。
     *  - 1 豆兑换 tokens：GLM-5.1 1500、GLM-5.2 1450、Deepseek-V4-Flash 11000、Qwen3.7-max 800、
     *    Qwen3.6-plus 1300、Qwen3.6-Flash 2200、kimi-k2.6 1500、Kimi-K3 650、
     *    Qwen3.7-plus 6500（≤256K）/2300（256K-1M）、Minimax-m3 9000（≤512K）/4500（512K-1M）、Auto 10000；
     *    图片 Qwen-image-2.0-pro 1 豆=0.03 张；视频 happyhorse 系 720P 1 豆=0.015 秒（预扣费多退少补）。
     *  - 比率行存「豆/千 token = 1000 ÷ 兑换率」（不分输入/输出/缓存全量折算）；
     *    上下文分段模型取低段系数，高段（Qwen3.7-plus 0.4348 / Minimax-m3 0.2222）由管理员手动下调启用。
     *  - 接入（ART 100418）：https://moma.cmecloud.cn/tokenplan-personal/v1/chat/completions（OpenAI 兼容）；
     *    视觉模型端点独立且不支持 AI 工具。先扣最快到期套餐；月包仅升配、不退订。
     */
    private static function cmccToken(): array
    {
        return [
            'name' => '中国移动 Token Plan 个人版',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '算力豆',
            'docs_url' => 'https://ecloud.10086.cn/op-help-center/doc/article/100224',
            'verified_at' => '2026-09-11',
            'notes' => '「算力豆」计量：1 豆按模型兑换率折算 tokens（GLM-5.1 1500、V4-Flash 11000、Auto 10000 等，豆/千 token=1000÷兑换率，全量 token 统一折算不分段）；月包 5~500 元 7 档（限购各 1）+ 次包 3 档（可复购）+ 尝鲜包 9.9 元 1200 豆（仅 Auto）；豆率随官方调整可能下调；先扣最快到期套餐；月包仅升配、不退订；视觉/视频模型兑换口径特殊（张/秒）由管理员按需录入。',
            'tiers' => [
                ['name' => '月包 · 5 元', 'price' => 5, 'price_note' => '', 'period' => '月', 'quota' => 200, 'quota_unit' => '算力豆', 'quota_note' => 'Auto 路由约 200 万 tokens', 'sort' => 10, 'status' => 1],
                ['name' => '月包 · 10 元', 'price' => 10, 'price_note' => '', 'period' => '月', 'quota' => 450, 'quota_unit' => '算力豆', 'quota_note' => 'Auto 约 450 万 / V4-Flash 约 4,950 万 tokens', 'sort' => 20, 'status' => 1],
                ['name' => '月包 · 20 元', 'price' => 20, 'price_note' => '', 'period' => '月', 'quota' => 950, 'quota_unit' => '算力豆', 'quota_note' => 'Auto 约 950 万 tokens', 'sort' => 30, 'status' => 1],
                ['name' => '月包 · 40 元', 'price' => 40, 'price_note' => '', 'period' => '月', 'quota' => 2000, 'quota_unit' => '算力豆', 'quota_note' => 'Auto 约 2,000 万 tokens', 'sort' => 40, 'status' => 1],
                ['name' => '月包 · 100 元', 'price' => 100, 'price_note' => '', 'period' => '月', 'quota' => 5500, 'quota_unit' => '算力豆', 'quota_note' => 'Auto 约 5,500 万 tokens', 'sort' => 50, 'status' => 1],
                ['name' => '月包 · 200 元', 'price' => 200, 'price_note' => '', 'period' => '月', 'quota' => 12000, 'quota_unit' => '算力豆', 'quota_note' => 'Auto 约 1.2 亿 tokens', 'sort' => 60, 'status' => 1],
                ['name' => '月包 · 500 元', 'price' => 500, 'price_note' => '', 'period' => '月', 'quota' => 35000, 'quota_unit' => '算力豆', 'quota_note' => 'Auto 约 3.5 亿 tokens', 'sort' => 70, 'status' => 1],
                ['name' => '次包 · 10 元', 'price' => 10, 'price_note' => '一次性补充包，可重复订购', 'period' => '月', 'quota' => 400, 'quota_unit' => '算力豆', 'quota_note' => '有效期 1 逻辑月；先订先扣', 'sort' => 80, 'status' => 1],
                ['name' => '次包 · 20 元', 'price' => 20, 'price_note' => '一次性补充包，可重复订购', 'period' => '月', 'quota' => 900, 'quota_unit' => '算力豆', 'quota_note' => '有效期 1 逻辑月；先订先扣', 'sort' => 90, 'status' => 1],
                ['name' => '次包 · 100 元', 'price' => 100, 'price_note' => '一次性补充包，可重复订购', 'period' => '月', 'quota' => 5000, 'quota_unit' => '算力豆', 'quota_note' => '有效期 1 逻辑月；先订先扣', 'sort' => 100, 'status' => 1],
                ['name' => '尝鲜包 · 9.9 元', 'price' => 9.9, 'price_note' => '每账号限购 1 个', 'period' => '月', 'quota' => 1200, 'quota_unit' => '算力豆', 'quota_note' => '仅支持 Auto 智能路由，不可指定模型', 'sort' => 110, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'ZHIPU/GLM-5.1', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.6667, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 10, 'status' => 0],
                ['model' => 'ZHIPU/GLM-5.2', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.6897, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 20, 'status' => 0],
                ['model' => 'Deepseek-V4-Flash', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.0909, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 30, 'status' => 0],
                ['model' => 'Qwen/Qwen3.7-max', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1.25, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 40, 'status' => 0],
                ['model' => 'Qwen/Qwen3.6-plus', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.7692, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 50, 'status' => 0],
                ['model' => 'Qwen/Qwen3.6-Flash', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.4545, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 60, 'status' => 0],
                ['model' => 'Kimi/kimi-k2.6', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.6667, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 70, 'status' => 0],
                ['model' => 'Kimi/Kimi-K3', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1.5385, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 80, 'status' => 0],
                ['model' => 'Qwen/Qwen3.7-plus', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.1538, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 90, 'status' => 0],
                ['model' => 'Minimax/Minimax-m3', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.1111, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 100, 'status' => 0],
                ['model' => 'Auto', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 110, 'status' => 0],
            ],
        ];
    }

    /**
     * 中国移动 Token Plan 团队版（「折算 tokens」倍率计量：消耗 M tokens 扣 M×N）
     *
     * 官方口径要点（2026-09-11 经 CMS API 抓取 ART 99471《TokenPlan团队版介绍》核对）：
     *  - 档位：团队版 5,000 元/月 = 55 亿 tokens/50 key；Lite 1,000 元/月 = 10 亿 tokens/10 key；
     *    可重复订购，基于 key 的额度管理；不支持升/降配；超额部分次月额度优先扣除。
     *  - 抵扣系数 N（官方折算示例：Minimax-M3 N=2 时输入 50K+输出 0.5K → 扣 101K 折算 tokens）：
     *    MiniMax-M2.5 1、Qwen3.6-Plus 1.5（≤256K）/6（256K-1M）、DeepSeek-V4-Flash 0.6、Qwen3.7-Max 10、
     *    GLM-5.1 3.5（0-200K 全段）、GLM-5.2 4.5、Minimax-M3 2（≤512K）/3（512K-1M）、Minimax-M2.7 1.1、
     *    Kimi-K2.7-code 7、Kimi-K2.6 3、Qwen/GLM-5.2 2（官方表原样条目，疑为 GLM-5.2 渠道别名，照录）、
     *    DeepSeek-V4-Pro 5。
     *  - unit_name=「千折算tokens」，比率行 unit_cost 即官方系数 N（每千基础 token 消耗 N 千折算 tokens）；
     *    官方明示后续可能不定期下调系数（以官网文档为准）。
     *  - 接入（ART 100418）：https://zhenze-huhehaote.cmecloud.cn/tokenplan/v1（OpenAI 兼容）。
     */
    private static function cmccTokenTeam(): array
    {
        return [
            'name' => '中国移动 Token Plan 团队版',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '千折算tokens',
            'docs_url' => 'https://ecloud.10086.cn/op-help-center/doc/article/99471',
            'verified_at' => '2026-09-11',
            'notes' => '「折算 tokens」倍率计量：消耗 M tokens 扣 M×N（N=官方抵扣系数，unit_cost 即 N）；团队版 5,000 元 55 亿 tokens/50 key、Lite 1,000 元 10 亿 tokens/10 key；可重复订购、基于 key 的额度管理；不支持升/降配；超额部分次月额度优先扣除；官方可能不定期下调系数（Qwen3.6-Plus 高段 6、Minimax-M3 高段 3 由管理员按 256K/512K 分界手动调整）。',
            'tiers' => [
                ['name' => '团队版 · Lite', 'price' => 1000, 'price_note' => '', 'period' => '月', 'quota' => 1000000000, 'quota_unit' => '折算tokens', 'quota_note' => '10 亿折算 tokens/月，支持 10 个 key', 'sort' => 10, 'status' => 1],
                ['name' => '团队版', 'price' => 5000, 'price_note' => '', 'period' => '月', 'quota' => 5500000000, 'quota_unit' => '折算tokens', 'quota_note' => '55 亿折算 tokens/月，支持 50 个 key', 'sort' => 20, 'status' => 1],
            ],
            'ratios' => [
                ['model' => 'MiniMax-M2.5', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 10, 'status' => 0],
                ['model' => 'Qwen/Qwen3.6-Plus', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1.5, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 20, 'status' => 0],
                ['model' => 'DeepSeek-V4-Flash', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 0.6, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 30, 'status' => 0],
                ['model' => 'Qwen/Qwen3.7-Max', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 10, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 40, 'status' => 0],
                ['model' => 'ZHIPU/GLM-5.1', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 3.5, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 50, 'status' => 0],
                ['model' => 'ZHIPU/GLM-5.2', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 4.5, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 60, 'status' => 0],
                ['model' => 'Minimax/Minimax-M3', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 2, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 70, 'status' => 0],
                ['model' => 'Minimax/Minimax-M2.7', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 1.1, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 80, 'status' => 0],
                ['model' => 'Kimi/Kimi-K2.7-code', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 7, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 90, 'status' => 0],
                ['model' => 'Kimi/Kimi-K2.6', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 3, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 100, 'status' => 0],
                ['model' => 'Qwen/GLM-5.2', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 2, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 110, 'status' => 0],
                ['model' => 'Qwen/DeepSeek-V4-Pro', 'match_type' => 'exact', 'cost_mode' => 'per_1k_tokens', 'unit_cost' => 5, 'input_rate' => 0, 'cached_rate' => 0, 'output_rate' => 0, 'sort' => 120, 'status' => 0],
            ],
        ];
    }

    /**
     * 幂等落地一个官方模板：确保厂商存在 + 预置套餐档位与折算标准。
     *
     * 安全规则（防资损）：
     *  - 新建厂商一律 status=0（停用），启用须管理员手动设 unit_exchange_rate 后开启；
     *  - ratios/tiers 均按模板自带 status 写入（预置折算标准默认 status=0）；
     *  - 更新已存在的行时仅覆盖官方预置态（remark 以「官方档位/官方折算标准」开头），
     *    且不改动 status（管理员已启用的行不会被 apply 关闭），自建/改过的行完全跳过；
     *  - 返回 inserted/updated/skipped 统计供管理端提示。
     *
     * @return array{vendor_created: bool, tiers: array{inserted: int, updated: int, skipped: int}, ratios: array{inserted: int, updated: int, skipped: int}}
     */
    public static function apply(string $code, ?int $now = null): array
    {
        $templates = self::templates();
        if (! isset($templates[$code])) {
            throw new InvalidArgumentException("Unknown coding plan catalog template [{$code}].");
        }

        $template = $templates[$code];
        $now = $now ?? time();
        $version = $template['verified_at'] ?? '未核对';
        $tierRemark = self::TIER_REMARK_PREFIX.'（模板 v'.$version.' 核对）';
        $ratioRemark = self::RATIO_REMARK_PREFIX.'（模板 v'.$version.' 核对）';

        $vendorCreated = false;
        $vendor = DB::table('coding_plan_vendors')->where('code', $code)->first();
        if ($vendor === null) {
            DB::table('coding_plan_vendors')->insert([
                'code' => $code,
                'name' => $template['name'],
                'plan_kind' => $template['plan_kind'],
                'billing_mode' => $template['billing_mode'],
                'unit_name' => $template['unit_name'],
                'unit_exchange_rate' => 1,
                'docs_url' => $template['docs_url'],
                'pricing_source_url' => null,
                'status' => 0,
                'sort' => 110,
                'remark' => '官方模板落地（默认停用）：启用前请设置 unit_exchange_rate 并核对折算比率',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $vendorCreated = true;
        } elseif (empty($vendor->docs_url) && ! empty($template['docs_url'])) {
            DB::table('coding_plan_vendors')->where('code', $code)
                ->update(['docs_url' => $template['docs_url'], 'updated_at' => $now]);
        }

        $tierStats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($template['tiers'] as $tier) {
            $existing = DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', $code)
                ->where('name', $tier['name'])
                ->first();

            if ($existing === null) {
                DB::table('coding_plan_vendor_tiers')->insert([
                    'vendor_code' => $code,
                    'name' => $tier['name'],
                    'price' => $tier['price'],
                    'price_note' => $tier['price_note'] ?? '',
                    'period' => $tier['period'] ?? null,
                    'quota' => $tier['quota'],
                    'quota_unit' => $tier['quota_unit'] ?? null,
                    'quota_note' => $tier['quota_note'] ?? null,
                    'status' => (int) $tier['status'],
                    'sort' => (int) $tier['sort'],
                    'remark' => $tierRemark,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $tierStats['inserted']++;

                continue;
            }

            // 仅同步仍是官方预置态的行；status 保留现值（管理员可能已上架该档位）
            if (is_string($existing->remark) && str_starts_with($existing->remark, self::TIER_REMARK_PREFIX)) {
                DB::table('coding_plan_vendor_tiers')->where('id', $existing->id)->update([
                    'price' => $tier['price'],
                    'price_note' => $tier['price_note'] ?? '',
                    'period' => $tier['period'] ?? null,
                    'quota' => $tier['quota'],
                    'quota_unit' => $tier['quota_unit'] ?? null,
                    'quota_note' => $tier['quota_note'] ?? null,
                    'sort' => (int) $tier['sort'],
                    'remark' => $tierRemark,
                    'updated_at' => $now,
                ]);
                $tierStats['updated']++;
            } else {
                $tierStats['skipped']++;
            }
        }

        return self::applyRatios($code, $template, $ratioRemark, $now, $vendorCreated, $tierStats);
    }

    /**
     * apply() 的折算标准部分（独立成方法避免单方法过长）。
     *
     * @param  array<string, mixed>  $template
     * @param  array{inserted: int, updated: int, skipped: int}  $tierStats
     * @return array{vendor_created: bool, tiers: array{inserted: int, updated: int, skipped: int}, ratios: array{inserted: int, updated: int, skipped: int}}
     */
    private static function applyRatios(string $code, array $template, string $ratioRemark, int $now, bool $vendorCreated, array $tierStats): array
    {
        $ratioStats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($template['ratios'] as $ratio) {
            $existing = DB::table('coding_plan_model_ratios')
                ->where('vendor', $code)
                ->where('model', $ratio['model'])
                ->where('match_type', $ratio['match_type'])
                ->first();

            if ($existing === null) {
                DB::table('coding_plan_model_ratios')->insert([
                    'vendor' => $code,
                    'model' => $ratio['model'],
                    'match_type' => $ratio['match_type'],
                    'cost_mode' => $ratio['cost_mode'],
                    'unit_cost' => $ratio['unit_cost'],
                    'input_rate' => $ratio['input_rate'],
                    'cached_rate' => $ratio['cached_rate'],
                    'output_rate' => $ratio['output_rate'],
                    'time_discounts' => array_key_exists('time_discounts', $ratio) && is_array($ratio['time_discounts'])
                        ? json_encode($ratio['time_discounts'], JSON_UNESCAPED_UNICODE)
                        : null,
                    'status' => (int) $ratio['status'],
                    'sort' => (int) $ratio['sort'],
                    'remark' => $ratioRemark,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $ratioStats['inserted']++;

                continue;
            }

            // 仅同步仍是官方预置态的行；status 保留现值（官方标准被启用后不因 apply 被关闭）
            if (is_string($existing->remark) && str_starts_with($existing->remark, self::RATIO_REMARK_PREFIX)) {
                DB::table('coding_plan_model_ratios')->where('id', $existing->id)->update([
                    'cost_mode' => $ratio['cost_mode'],
                    'unit_cost' => $ratio['unit_cost'],
                    'input_rate' => $ratio['input_rate'],
                    'cached_rate' => $ratio['cached_rate'],
                    'output_rate' => $ratio['output_rate'],
                    'time_discounts' => array_key_exists('time_discounts', $ratio) && is_array($ratio['time_discounts'])
                        ? json_encode($ratio['time_discounts'], JSON_UNESCAPED_UNICODE)
                        : null,
                    'sort' => (int) $ratio['sort'],
                    'remark' => $ratioRemark,
                    'updated_at' => $now,
                ]);
                $ratioStats['updated']++;
            } else {
                $ratioStats['skipped']++;
            }
        }

        return [
            'vendor_created' => $vendorCreated,
            'tiers' => $tierStats,
            'ratios' => $ratioStats,
        ];
    }
}
