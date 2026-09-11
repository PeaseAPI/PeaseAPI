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
 *  - 联通 / 移动：官方页无法程序化访问（超大 payload / WAF），模板仅建壳，待人工补全
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
            'baidu' => self::baidu(),
            'unicom' => self::shell('unicom', '中国联通 Coding Plan', 1, '点', 'https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7015&lang=zh',
                '官方页内嵌超大 payload 无法程序化抓取；请人工录入档位与抵扣规则，并配置 pricing_source_url 启用定时监测。'),
            'unicom-token' => self::shell('unicom-token', '中国联通 Token Plan', 2, '千token', 'https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7080&lang=zh',
                '官方页内嵌超大 payload 无法程序化抓取；请人工录入档位与抵扣规则，并配置 pricing_source_url 启用定时监测。'),
            'cmcc' => self::shell('cmcc', '中国移动 Coding Plan', 1, '点', 'https://ecloud.10086.cn/op-help-center/doc/article/98322',
                '官方页拒绝程序化访问（WAF）；请人工录入档位与抵扣规则，并配置 pricing_source_url 启用定时监测。'),
            'cmcc-token' => self::shell('cmcc-token', '中国移动 Token Plan', 2, '千token', 'https://ecloud.10086.cn/op-help-center/doc/outline/108724',
                '官方页拒绝程序化访问（WAF）；请人工录入档位与抵扣规则，并配置 pricing_source_url 启用定时监测。'),
        ];
    }

    /**
     * 壳模板：官方页无法程序化核对，仅落地厂商元数据（默认停用），
     * 档位与折算标准需人工录入，或配置 pricing_source_url 后由定时校对补全。
     */
    private static function shell(string $code, string $name, int $planKind, string $unitName, string $docsUrl, string $notes): array
    {
        return [
            'name' => $name,
            'plan_kind' => $planKind,
            'billing_mode' => 2,
            'unit_name' => $unitName,
            'docs_url' => $docsUrl,
            'verified_at' => null,
            'notes' => $notes,
            'tiers' => [],
            'ratios' => [],
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
            'notes' => '夜间 22:00-08:00 指定模型五折；7 天滚动限额窗口内未用完不结转；升级按剩余时长折算补差；个人版仅限编程/智能体工具交互式使用。',
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
                ['model' => 'glm-5.3', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.69, 'cached_rate' => 0.17, 'output_rate' => 2.4, 'sort' => 10, 'status' => 0],
                ['model' => 'glm-5.3-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.23, 'cached_rate' => 0.056, 'output_rate' => 0.8, 'sort' => 20, 'status' => 0],
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
                ['model' => 'doubao-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => null, 'cached_rate' => null, 'output_rate' => null, 'sort' => 10, 'status' => 0],
                ['model' => 'kimi-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => null, 'cached_rate' => null, 'output_rate' => null, 'sort' => 20, 'status' => 0],
                ['model' => 'deepseek-', 'match_type' => 'prefix', 'cost_mode' => 'per_request', 'unit_cost' => 1, 'input_rate' => null, 'cached_rate' => null, 'output_rate' => null, 'sort' => 30, 'status' => 0],
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
                ['model' => 'deepseek-flash', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.002, 'cached_rate' => 0.0001, 'output_rate' => 0.008, 'sort' => 10, 'status' => 0],
                ['model' => 'deepseek-v4-pro', 'match_type' => 'exact', 'cost_mode' => 'per_token_parts', 'unit_cost' => 1, 'input_rate' => 0.009, 'cached_rate' => 0.0003, 'output_rate' => 0.027, 'sort' => 20, 'status' => 0],
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
     *  - 2026-08-31 17:00 起调整为积分抵扣模式；逐模型积分系数见「套餐内积分抵扣规则」文档
     *    （程序化抓取未定位到该页，模板不预置系数 —— 防资损，需管理员人工核对后录入）。
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
            'notes' => '积分抵扣模式（2026-08-31 起），逐模型系数需按官方「套餐内积分抵扣规则」人工核对；glm-5/glm-5.1 将于 2026-10-09 下线；自然月有效、仅升配、每主账号通用+Hy 各 1 个。',
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
            'ratios' => [],
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
     * 百度智能云千帆 Token Plan（个人版 Mini/Lite/Pro/Max，积分制）
     *
     * 官方口径要点（2026-09-11 抓取 cloud.baidu.com/product/codingplan.html）：
     *  - 四档月价（原价）：Mini 9.9 元/1,400 积分、Lite 40/6,600、Pro 200/45,000、Max 600/165,000；
     *    首购五折 4.9/19.9/99.9/299.9（每日 10 点限量秒杀）、到期续费专享 6 折。
     *  - 积分制与 Token 制双轨并行；支持 GLM/DeepSeek/Kimi 等主流模型与 Cursor/Cline 等工具，
     *    兼容 OpenAI 与 Anthropic 协议；夜间 21:00-次日 08:00 套餐内 Tokens 2 折起。
     *  - 积分↔token 折算规则官方页未公布（以控制台用量详情为准），模板不预置比率 —— 防资损。
     */
    private static function baidu(): array
    {
        return [
            'name' => '百度智能云千帆 Token Plan',
            'plan_kind' => 2,
            'billing_mode' => 2,
            'unit_name' => '积分',
            'docs_url' => 'https://cloud.baidu.com/product/codingplan.html',
            'verified_at' => '2026-09-11',
            'notes' => '积分制+Token 制双轨；夜间 21:00-次日 08:00 Tokens 2 折起；首购五折每日 10 点限量；续费焕新 6 折；积分↔token 折算以控制台用量详情为准。',
            'tiers' => [
                ['name' => '个人版 · Mini', 'price' => 9.9, 'price_note' => '首购五折 4.9（限量秒杀）', 'period' => '月', 'quota' => 1400, 'quota_unit' => '积分', 'quota_note' => '新手尝鲜，7 天限额已取消', 'sort' => 10, 'status' => 1],
                ['name' => '个人版 · Lite', 'price' => 40, 'price_note' => '首购五折 19.9（限量秒杀）', 'period' => '月', 'quota' => 6600, 'quota_unit' => '积分', 'quota_note' => '日常开发，7 天限额已取消', 'sort' => 20, 'status' => 1],
                ['name' => '个人版 · Pro', 'price' => 200, 'price_note' => '首购五折 99.9（限量秒杀）', 'period' => '月', 'quota' => 45000, 'quota_unit' => '积分', 'quota_note' => '高频开发', 'sort' => 30, 'status' => 1],
                ['name' => '个人版 · Max', 'price' => 600, 'price_note' => '首购五折 299.9（限量秒杀）', 'period' => '月', 'quota' => 165000, 'quota_unit' => '积分', 'quota_note' => '重度开发', 'sort' => 40, 'status' => 1],
            ],
            'ratios' => [],
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
