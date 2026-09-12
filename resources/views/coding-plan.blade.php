@php
    $systemName = 'Pease API';
    $systemLogo = '';
    $systemFooter = '';
    try {
        if (app()->bound('db') && \DB::connection()->getPdo()) {
            $systemName = \App\Services\OptionService::get('SystemName', $systemName);
            $systemLogo = \App\Services\OptionService::get('SystemLogo', '');
            $systemFooter = \App\Services\OptionService::get('SystemFooter', '');
        }
    } catch (\Throwable $e) {}

    // 与 /api/coding_plan/offers 共用同一聚合实现与缓存键（flushCache 统一失效）
    $offers = ['vendors' => [], 'stale_days' => 7];
    try {
        if (app()->bound('db') && \DB::connection()->getPdo()) {
            $offers = \Cache::remember(
                'coding_plan_offers',
                300,
                fn () => app(\App\Services\CodingPlanRatioService::class)->publicOffers()
            );
        }
    } catch (\Throwable $e) {}

    $vendors = $offers['vendors'] ?? [];
    $staleDays = (int) ($offers['stale_days'] ?? 7);

    // 按产品类型分组排序：1=订阅制 Coding Plan 在前，2=按量 Token Plan 在后（PHP 8 usort 稳定，组内保持 sort,id 序）
    $vendors = array_values($vendors);
    usort($vendors, fn ($a, $b) => ((int) ($a['plan_kind'] ?? 1)) <=> ((int) ($b['plan_kind'] ?? 1)));

    $formatTime = function ($ts): string {
        if (! $ts) {
            return '尚未校对';
        }

        return date('Y-m-d H:i', (int) $ts);
    };
    $billingModeLabel = fn ($mode) => ((int) $mode) === 2 ? '按积分折算' : '按次提交';
    $costModeLabel = fn ($mode) => match ($mode) {
        'per_1k_tokens' => '每千 Token',
        'per_token_parts' => '分段（输入/缓存/输出）',
        default => '每次请求',
    };
    // 分段口径（per_token_parts）：unit_cost 不参与，展示输入/缓存命中/输出三段千 token 系数
    $unitCostLabel = function ($ratio): string {
        if (($ratio['cost_mode'] ?? '') === 'per_token_parts') {
            return sprintf(
                '入 %s / 缓存 %s / 出 %s（/千token）',
                $ratio['input_rate'] ?? 0,
                $ratio['cached_rate'] ?? 0,
                $ratio['output_rate'] ?? 0
            );
        }

        return (string) ($ratio['unit_cost'] ?? 1);
    };
    // 官方档位价格：null = 待核对
    $tierPriceLabel = function ($tier): string {
        if ($tier['price'] === null || $tier['price'] === '') {
            return $tier['price_note'] ?: '以官网为准';
        }
        $label = '¥'.rtrim(rtrim(number_format((float) $tier['price'], 2, '.', ''), '0'), '.');

        return $tier['price_note'] !== '' && $tier['price_note'] !== null
            ? $label.'（'.$tier['price_note'].'）'
            : $label;
    };
    $tierQuotaLabel = function ($tier): string {
        $quota = ($tier['quota'] === null || $tier['quota'] === '') ? '—' : number_format((float) $tier['quota']);
        $unit = ! empty($tier['quota_unit']) ? ' '.$tier['quota_unit'] : '';
        $note = ! empty($tier['quota_note']) ? ' · '.$tier['quota_note'] : '';

        return $quota.$unit.$note;
    };
    $matchLabel = fn ($type) => $type === 'prefix' ? '前缀匹配' : '全等匹配';
    $sourceLabel = fn ($status) => match ((int) $status) {
        1 => '定价源正常',
        2 => '定价源异常',
        default => '未配置定价源',
    };
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Coding Plan 抵扣介绍 - {{ $systemName }}</title>
<meta name="description" content="{{ $systemName }} Coding Plan 积分折算规则与抵扣比率 - 每 6 小时自动校对">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --primary:#6366f1;--primary-light:#818cf8;--accent:#8b5cf6;
    --bg-darker:#020617;--bg-card:#1e293b;
    --text-light:#f1f5f9;--text-muted:#94a3b8;
    --border:rgba(148,163,184,0.15);
    --gradient:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#ec4899 100%);
    --gradient-soft:linear-gradient(135deg,rgba(99,102,241,0.15) 0%,rgba(139,92,246,0.15) 100%);
    --ok:#34d399;--warn:#fbbf24;--bad:#f87171;
}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg-darker);color:var(--text-light);line-height:1.6;min-height:100vh}
a{color:inherit;text-decoration:none}
.container{max-width:1080px;margin:0 auto;padding:0 24px}
.bg-decoration{position:fixed;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
.bg-decoration::before{content:'';position:absolute;top:-20%;left:-10%;width:60%;height:60%;border-radius:50%;background:radial-gradient(circle,rgba(99,102,241,0.25) 0%,transparent 70%);filter:blur(80px)}
.bg-decoration::after{content:'';position:absolute;bottom:-20%;right:-10%;width:50%;height:50%;border-radius:50%;background:radial-gradient(circle,rgba(236,72,153,0.2) 0%,transparent 70%);filter:blur(80px)}
nav{position:sticky;top:0;z-index:100;background:rgba(15,23,42,0.8);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
nav .container{display:flex;align-items:center;justify-content:space-between;height:64px}
.nav-brand{display:flex;align-items:center;gap:12px}
.nav-logo{width:36px;height:36px;border-radius:10px;overflow:hidden;flex-shrink:0;background:var(--gradient);display:flex;align-items:center;justify-content:center;font-weight:800}
.nav-logo img{width:100%;height:100%;object-fit:cover}
.nav-name{font-size:18px;font-weight:700;color:#fff}
.nav-links{display:flex;align-items:center;gap:8px}
.nav-links a{padding:8px 16px;font-size:14px;color:var(--text-muted);border-radius:10px}
.nav-links a:hover{color:#fff;background:rgba(148,163,184,0.1)}
.hero{padding:72px 0 48px;text-align:center}
.hero .tag{display:inline-block;padding:6px 16px;border-radius:999px;border:1px solid rgba(99,102,241,0.4);background:var(--gradient-soft);font-size:13px;color:var(--primary-light);margin-bottom:20px}
.hero h1{font-size:clamp(28px,4vw,40px);font-weight:800;margin-bottom:14px}
.hero h1 .grad{background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero p{color:var(--text-muted);font-size:15px;max-width:640px;margin:0 auto}
.tpl-bar{max-width:860px;margin:18px auto 0;padding:12px 18px;border-radius:12px;background:var(--bg-card);border:1px dashed rgba(99,102,241,0.45);font-size:13px;color:var(--text-muted);text-align:center}
.tpl-bar b{color:var(--primary-light)}
.flow{display:flex;flex-wrap:wrap;gap:14px;justify-content:center;align-items:stretch;margin:40px auto 0;max-width:860px}
.flow .step{flex:1 1 220px;padding:18px 20px;border-radius:14px;background:var(--bg-card);border:1px solid var(--border);text-align:left}
.flow .step .k{font-size:12px;color:var(--primary-light);margin-bottom:6px;font-weight:600}
.flow .step .v{font-size:14px;color:var(--text-light)}
.flow .step .d{font-size:12px;color:var(--text-muted);margin-top:4px}
.flow .arrow{align-self:center;color:var(--primary-light);font-size:20px;flex:0 0 auto}
.section{padding:48px 0}
.section-title{text-align:center;margin-bottom:36px}
.section-title h2{font-size:clamp(22px,3vw,30px);font-weight:700;margin-bottom:10px}
.section-title p{color:var(--text-muted);font-size:14px}
.vendor{padding:24px;border-radius:16px;background:var(--bg-card);border:1px solid var(--border);margin-bottom:20px}
.vendor-head{display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between;margin-bottom:6px}
.vendor-title{display:flex;align-items:center;gap:12px}
.vendor-logo{width:44px;height:44px;border-radius:12px;overflow:hidden;flex-shrink:0;background:var(--gradient);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px}
.vendor-logo img{width:100%;height:100%;object-fit:cover}
.vendor-title h3{font-size:17px;font-weight:700;color:#fff}
.vendor-title .meta{font-size:12px;color:var(--text-muted)}
.vendor-badges{display:flex;flex-wrap:wrap;gap:8px}
.badge{padding:4px 10px;border-radius:999px;font-size:12px;border:1px solid var(--border);color:var(--text-muted)}
.badge.ok{color:var(--ok);border-color:rgba(52,211,153,0.4);background:rgba(52,211,153,0.08)}
.badge.warn{color:var(--warn);border-color:rgba(251,191,36,0.4);background:rgba(251,191,36,0.08)}
.badge.bad{color:var(--bad);border-color:rgba(248,113,113,0.4);background:rgba(248,113,113,0.08)}
.vendor-desc{font-size:13px;color:var(--text-muted);margin-bottom:14px}
.vendor-desc a{color:var(--primary-light)}
.muted{color:var(--text-muted);font-size:12px;font-weight:400}
.kind-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;margin:34px 0 14px;padding-bottom:10px;border-bottom:1px solid var(--border)}
.kind-head:first-of-type{margin-top:0}
.kind-head h3{font-size:16px;font-weight:700;color:#fff}
.kind-head span{font-size:12px;color:var(--text-muted)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:10px 12px;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,0.08);color:var(--text-light)}
tr:last-child td{border-bottom:none}
td .stale-tag{color:var(--warn);font-size:12px}
.empty{padding:36px;text-align:center;color:var(--text-muted);background:var(--bg-card);border:1px dashed var(--border);border-radius:16px}
.note{max-width:860px;margin:0 auto;padding:20px 24px;border-radius:14px;background:var(--gradient-soft);border:1px solid rgba(99,102,241,0.3);font-size:13px;color:var(--text-muted)}
.note b{color:var(--text-light)}
.faq-item{padding:18px 22px;border-radius:12px;background:var(--bg-card);border:1px solid var(--border);margin-bottom:12px}
.faq-item h3{font-size:15px;font-weight:600;color:#fff;margin-bottom:6px}
.faq-item p{font-size:13px;color:var(--text-muted)}
footer{padding:40px 0;border-top:1px solid var(--border);margin-top:40px;text-align:center;color:var(--text-muted);font-size:13px}
@media (max-width:640px){.flow .arrow{display:none}.vendor-head{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
<div class="bg-decoration"></div>
@include('partials.public-nav')

<div class="hero">
    <div class="container">
        <span class="tag">Coding Plan 抵扣介绍</span>
        <h1>订阅额度如何<span class="grad">折算平台积分</span></h1>
        <p>各厂商 Coding Plan 订阅账号池的模型抵扣规则全程公开。平台每 6 小时自动校对一次比率，
        上游新模型优惠期内如有偏差将以「待复核」标记提示，并在人工确认后更新。</p>
        <div class="tpl-bar">
            <b>官方模型模板已内置</b>：阿里云百炼 · 智谱 GLM · 腾讯 TokenHub · DeepSeek · 火山引擎 · 联通 · 移动
            —— 控制台选择模型提供商即自动预载其官方套餐档位与现有模型折算清单，并随官方同步定期更新。
        </div>
        <div class="flow">
            <div class="step"><div class="k">① 模型用量</div><div class="v">Prompt / 缓存命中 / Completion tokens</div><div class="d">按请求 / 按千 Token / 分段三系数三种口径</div></div>
            <div class="arrow">→</div>
            <div class="step"><div class="k">② 折算供应商单位</div><div class="v">× 比率表 unit_cost</div><div class="d">精确模型 &gt; 最长前缀 &gt; 默认 1</div></div>
            <div class="arrow">→</div>
            <div class="step"><div class="k">③ 折算平台积分</div><div class="v">× 汇率 unit_exchange_rate</div><div class="d">统一折算池，余额可见</div></div>
        </div>
    </div>
</div>

<div class="section">
    <div class="container">
        <div class="section-title">
            <h2>各厂商抵扣比率</h2>
            <p>订阅制 Coding Plan 与按量 Token Plan 均按两级折算 · 核对窗口 {{ $staleDays }} 天 · 每 6 小时自动校对 · 模型清单由官方模板预载并定期更新 · 超期未复核的比率会标为「待复核」</p>
        </div>

        @php $lastKind = null; @endphp
        @forelse($vendors as $vendor)
            @php $kind = (int) ($vendor['plan_kind'] ?? 1) === 2 ? 2 : 1; @endphp
            @if($kind !== $lastKind)
                <div class="kind-head">
                    <h3>{{ $kind === 2 ? '按量 Token Plan' : '订阅制 Coding Plan' }}</h3>
                    <span>{{ $kind === 2 ? '按 token 用量折算扣减，适合阿里百炼 / 腾讯混元 / 百度千帆 / 火山方舟等按量开放平台' : '包月 / 包量订阅，按请求次数或资源点折算，适合火山引擎 / 联通 / 移动 / 智谱等订阅制产品' }}</span>
                </div>
                @php $lastKind = $kind; @endphp
            @endif
            <div class="vendor">
                <div class="vendor-head">
                    <div class="vendor-title">
                        <span class="vendor-logo">@if(!empty($vendor['logo']))<img src="{{ $vendor['logo'] }}" alt="">@else{{ mb_substr($vendor['name'], 0, 1) }}@endif</span>
                        <div>
                            <h3>{{ $vendor['name'] }}</h3>
                            <div class="meta">
                                {{ $billingModeLabel($vendor['billing_mode']) }}
                                @if(!empty($vendor['unit_name'])) · 单位：{{ $vendor['unit_name'] }}@endif
                                · 1 {{ $vendor['unit_name'] ?: '单位' }} = {{ $vendor['unit_exchange_rate'] }} 平台积分
                                · 最近校对：{{ $formatTime($vendor['last_checked_at']) }}
                            </div>
                        </div>
                    </div>
                    <div class="vendor-badges">
                        <span class="badge">已收录 {{ count($vendor['ratios'] ?? []) }} 个模型</span>
                        <span class="badge {{ (int) $vendor['stale_count'] > 0 ? 'warn' : 'ok' }}">
                            {{ (int) $vendor['stale_count'] > 0 ? $vendor['stale_count'].' 条待复核' : '比率已核对' }}
                        </span>
                        @if((int) $vendor['change_count'] > 0)
                            <span class="badge warn">{{ $vendor['change_count'] }} 处上游变更待确认</span>
                        @endif
                        <span class="badge {{ (int) $vendor['source_status'] === 2 ? 'bad' : ((int) $vendor['source_status'] === 1 ? 'ok' : '') }}">
                            {{ $sourceLabel($vendor['source_status']) }}
                        </span>
                    </div>
                </div>
                @if(!empty($vendor['docs_url']))
                    <div class="vendor-desc">厂商订阅与模型说明：<a href="{{ $vendor['docs_url'] }}" target="_blank" rel="noopener">{{ $vendor['docs_url'] }}</a></div>
                @endif
                @if(!empty($vendor['tiers']))
                    <table>
                        <thead>
                        <tr>
                            <th>官方套餐档位</th>
                            <th>价格</th>
                            <th>额度 / 权益</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($vendor['tiers'] as $tier)
                            <tr>
                                <td>{{ $tier['name'] }}@if(!empty($tier['period'])) <span class="muted">/ {{ $tier['period'] }}</span>@endif</td>
                                <td>{{ $tierPriceLabel($tier) }}</td>
                                <td>{{ $tierQuotaLabel($tier) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <div class="vendor-desc">以上为厂商官方套餐档位（个人版/团队版等）与额度，供订阅对照；价格与额度以官网购买页为准。</div>
                @endif
                @if(!empty($vendor['ratios']))
                    <table>
                        <thead>
                        <tr>
                            <th>模型</th>
                            <th>匹配方式</th>
                            <th>计费口径</th>
                            <th>单位成本（unit_cost）</th>
                            <th>最近修改</th>
                            <th>状态</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($vendor['ratios'] as $ratio)
                            <tr>
                                <td>{{ $ratio['model'] }}</td>
                                <td>{{ $matchLabel($ratio['match_type']) }}</td>
                                <td>{{ $costModeLabel($ratio['cost_mode']) }}</td>
                                <td>{{ $unitCostLabel($ratio) }}</td>
                                <td>{{ $formatTime($ratio['updated_at']) }}</td>
                                <td>
                                    @if($ratio['stale'])
                                        <span class="stale-tag">待复核（超 {{ $staleDays }} 天未人工核对）</span>
                                    @else
                                        正常
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="empty">该厂商暂无启用的折算比率，将按全局默认（每次请求 1 单位）计费。</div>
                @endif
            </div>
        @empty
            <div class="empty">暂未配置任何 Coding Plan 供应商。管理员可在控制台「Coding Plan 积分管理 → 供应商」中添加。</div>
        @endforelse

        <div class="note">
            <b>模型清单从哪里来、如何保持最新：</b>
            管理员在控制台选择模型提供商时，系统按内置官方模板自动预载该厂商的套餐档位与现有模型折算清单（无需逐条录入）；
            此后平台每 6 小时自动执行一次比率校对（<code>coding-plan:verify-ratios</code>）：
            ① 超过 {{ $staleDays }} 天未人工复核的比率标记为「待复核」；
            ② 管理员为供应商配置了结构化定价源（pricing_source_url）时，自动与上游价格清单比对，
            发现新模型 / 单位成本变化 / 模型下架都会计入「待确认变更」，由管理员确认后应用到模型清单。
            <b>变更不会自动生效</b>——为避免把上游优惠误算进你的账单，所有比率调整均需管理员确认后在控制台更新。
        </div>
    </div>
</div>

<div class="section">
    <div class="container">
        <div class="section-title">
            <h2>常见问题</h2>
        </div>
        <div class="faq-item">
            <h3>为什么我的实际扣费和官网价格有出入？</h3>
            <p>厂商（如火山引擎）常在新增模型时推出限时优惠，官方文档价格与优惠期实际价格可能不同。请以本页「最近校对」时间与「待复核」标记为准；发现偏差可联系管理员，我们会在人工确认后更新比率。</p>
        </div>
        <div class="faq-item">
            <h3>两级折算是什么意思？</h3>
            <p>先用比率表把模型用量折算为「供应商原生单位」（按次、按千 Token，或按输入/缓存/输出分段三系数折算），再乘以供应商汇率（unit_exchange_rate）折算为平台积分。账号级汇率可覆盖供应商默认汇率。</p>
        </div>
        <div class="faq-item">
            <h3>分段折算（输入/缓存/输出）如何计算？</h3>
            <p>对齐智谱 GLM、阿里云百炼等官方公式：消耗 =（输入 token × 输入系数 + 缓存命中 × 缓存系数 + 输出 token × 输出系数）÷ 1000。例如智谱 GLM-5.3 系数为 6.9 / 1.7 / 24（每万 token），换算为千 token 即 0.69 / 0.17 / 2.4。各模型系数不同，见各厂商比率表。</p>
        </div>
        <div class="faq-item">
            <h3>「官方套餐档位」表格是什么？</h3>
            <p>展示各厂商官方订阅档位（个人版 Lite/Standard/Pro、团队版坐席、用量包等）的价格与额度，供选型对照。表格数据由管理员按官方文档维护，价格与额度以官网购买页为准；本平台的订阅价格与配额以购买页为准。</p>
        </div>
        <div class="faq-item">
            <h3>添加模型提供商时需要手动录入所有模型吗？</h3>
            <p>不需要。平台内置主流厂商（阿里云百炼、智谱 GLM、腾讯 TokenHub、DeepSeek、火山引擎、联通、移动）的官方模板，
            管理员选择提供商即自动预载其官方套餐档位与现有模型折算清单；模型清单会随官方同步与人工校对定期更新，
            新增模型、抵扣率调整与模型下架都会先进入「待确认变更」，确认后才生效。</p>
        </div>
        <div class="faq-item">
            <h3>匹配不到我的模型时如何计费？</h3>
            <p>比率表解析顺序为：全等匹配 → 最长前缀匹配 → 全局默认（每次请求 1 单位）。新模型上线后管理员会尽快补充专属比率。</p>
        </div>
    </div>
</div>

@include('partials.public-footer')
</body>
</html>
