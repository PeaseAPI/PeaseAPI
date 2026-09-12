# 上游套餐知识库 & 官方校对体系 — 任务总账（TASKS.md）

> **跨会话任务持久化账本。** AI 助手 / 开发者在任何新会话（含 VSCode 重启后）必须**先完整阅读本文件**，再按「当前执行指针」继续推进；每完成一项就勾选 `[x]` 并在文末「变更记录」追加一行。本文件是需求的一部分：把上游 AI 厂商的 Token Plan / Coding Plan 套餐、折扣、积分、模型上下架、活动期限全部纳入平台知识库，做到**每 6 小时自动抓取官方页面 → 与预存快照校对 → 变更进入人工确认闭环**。

---

## 0. 恢复协议（新会话必读）

1. 读本文件全文，重点看「当前执行指针」与最新「变更记录」。
2. 读 `docs/upstream-snapshots/` 下最新日期目录的快照（预存校对基准）。
3. 相关既有实现速查：
   - 比率引擎：`app/Services/CodingPlanRatioService.php`（exact > prefix > 默认；per_request / per_1k_tokens / per_token_parts 三段率；time_discounts 时段折扣，北京时间口径固定 `Asia/Shanghai`）
   - 官方模板目录：`app/Services/CodingPlanCatalog.php`（厂商/档位/比率只读模板 + apply）
   - 6 小时校对命令：`app/Console/Commands/VerifyCodingPlanRatios.php`（stale 检测 + `pricing_source_url` JSON 源 diff：new / changed(split_rates, time_discounts) / missing；**只记录不自动改价**，管理端「官方同步」页应用/忽略）
   - 调度：`routes/console.php`（`pease:sync-coding-plan-official` + `pease:verify-coding-plan-ratios`，均 everySixHours，先 sync 后 verify）
   - 官方源同步（2026-09-12 新增）：`app/Services/CodingPlanOfficialSourceService.php`（15 家厂商源注册表 + 代理抓取 + 快照保留 10 份 + 复用 diff/忽略标记/流水写入）+ `app/Services/CodingPlanParsers/*`（deepseek 转置表 / openai Markdown 已就绪，其余待补）+ `coding-plan:sync-official` 命令
   - 模型：`CodingPlanVendor`（billing_mode、plan_kind=1 Coding Plan/2 Token Plan、unit_exchange_rate、docs_url、pricing_source_url）、`CodingPlanVendorTier`（官方档位）、`CodingPlanModelRatio`（比率 + time_discounts）、`CodingPlanRatioCheck`（校对流水）、`CodingPlanAccount`（账号池）
   - 回归自检：`php artisan coding-plan:test-time-discounts`（28 项断言）+ `php tests/coding-plan-parser-fixture.php`（解析器 21 项断言）
   - 数据表迁移序号：000002 厂商预置 → 000003~000009 官方价目/时段折扣（详见 operations-guide.md §8）
4. 任务完成定义：代码 + 迁移/预置数据 + 测试（能跑则跑 `php artisan` 验证）+ 文档（operations-guide.md 对应小节）+ 本文件勾选。

---

## 1. 背景与目标（用户原始需求拆解）

| # | 需求 | 对应任务 |
|---|------|----------|
| R1 | 覆盖所有已支持 AI 厂商的套餐：Token Plan / Coding Plan，个人版/团队版/企业版 | 已有 `CodingPlanVendor`+`Tier` 骨架；P3 补数据、P3-6 补新厂商 |
| R2 | 折扣率、积分制、按次/按 token 均要支持 | 已有三 cost_mode + unit_exchange_rate 两级折算 ✅ |
| R3 | 分时段折扣（如 DeepSeek 错峰），细化到输入/输出/缓存命中折算积分 | 已有 time_discounts + 三段率（迁移 000009）✅，P3 补录剩余厂商 |
| R4 | 找不到网页的厂商自行检索补全（OpenAI 等，必要时走代理 `http://127.0.0.1:7890`） | P0-2 快照 + P3-6 预置 |
| R5 | 每家找到**确定**的官方页面，每 6 小时抓取 + 与预存校对一次 | **P1 官方源适配器（核心工程）** |
| R6 | 校对不只折扣积分，还要**模型新上架/下架** | P1-6 模型目录源 + check kind=model_catalog |
| R7 | 活动结束时间提醒客户 | P2 活动表 + 到期提醒 + 介绍页倒计时 |
| R8 | 拆分细节形成任务，重启后可恢复 | 本文件 ✅ |
| R9 | **多货币计费**：厂商标注官方计价币种（OpenAI/Google=USD，Google 另有 HKD 区），平台配置汇率，客户可选结算货币 | **P7 多货币与结算体系** |
| R10 | **厂商→模型上架流**：选定厂商后列出其可选模型，勾选是否提供 | **P8 厂商模型上架流** |
| R11 | **成本感知路由**：同模型多供应商报价不同（联通 Coding Plan GLM-5.1 最便宜）→ 低成本源优先调度，耗尽才 failover 到贵的 | **P9 成本感知路由** |
| R12 | **配额恢复感知**：上游「时间窗用量达上限」时记录恢复时间点，期间调度跳过，到点自动切回 | **P9 配额恢复感知** |

---

## 2. 数据流架构（目标态）

```
官方页面/接口（每家厂商确定 URL，含需代理的国际源）
        │  CodingPlanOfficialSourceService（内置 source 注册表：url/format/解析器/代理标记）
        ▼
标准化条目 {model, match_type, cost_mode, unit_cost|input/cached/output_rate, time_discounts}
        │  快照预存 storage/app/private/coding-plan-snapshots/{vendor}/{Y-m-d-Hi}.json（Laravel 11 local disk root=storage/app/private；保留最近 10 份）
        ▼
与库内 coding_plan_model_ratios / 上一次快照 diff
        ▼
coding_plan_ratio_checks（new / changed / missing / time_discounts / model_catalog）
        ▼
管理端「官方同步」页人工确认（应用 / 忽略）——绝不自动改价
        ▼
活动类变更同步写 coding_plan_promotions → 到期前 7 天提醒（公告/邮件/介绍页倒计时）
```

---

## 3. 当前执行指针

> **下一步从 P1-2 剩余解析器开始**（volcengine/unicom/cmcc/baidu 源待探测；aliyun 源已重定位但内容=套餐档位归 P2-1；siliconflow 结构已摸清、待 P7 多货币口径落地后接入），原始响应已存 `storage/app/private/coding-plan-snapshots/*/` 供解析器调试。P0 已全部完成；P1-1/3/4/5/7/8/10 已完成（2026-09-12）。新需求 R9-R12 → P7/P8/P9（见任务清单末尾）。

---

## 4. 任务清单

### P0 需求梳理、检索与快照预存（2026-09-12 本轮完成）
- [x] P0-1 现状盘点：确认已有比率引擎/校对命令/调度/预置数据（见 §0）
- [x] P0-2 首轮官方抓取并预存快照：`docs/upstream-snapshots/2026-09-12/`（sources.md 数据源清单 + pricing-data.md 抓取数据；OpenAI 走代理 + `.md` 后缀技巧；联通/火山/移动为 SPA，方案记录在快照内）
- [x] P0-3 建立本任务账本

### P1 官方源自动抓取适配器（核心工程，下一步从这里开始）
- [x] P1-1 新建 `app/Services/CodingPlanOfficialSourceService.php`：source 注册表（vendor → {url, format(json|markdown|html), parser, proxy:bool, model_catalog_url?}），可被 `coding_plan_vendors.pricing_source_url` 覆盖（自管源优先）✅ 2026-09-12（15 家注册表齐备；解析器未就绪的源仅存原始快照 `.raw.txt`）
- [ ] P1-2 逐厂商解析器（输入=HTTP 响应体，输出=标准化条目数组，格式见 P1-3）：
  - [x] deepseek：`https://api-docs.deepseek.com/zh-cn/quick_start/pricing/`（**真实结构=转置表**：模型为列、指标为行、rowspan 分段、单位在格内如「0.02元」；纵向表做兜底；空闲/高峰 → time_discounts 窗口与迁移 000009 零漂移）✅ 2026-09-12 端到端「无变更」
  - [x] openai：`https://platform.openai.com/docs/pricing.md`（**需代理**；Markdown 表；short/long context 双列 + Batch 半价表 + cache writes 列）✅ 2026-09-12 全链路：解析 67 模型（长上下文价忽略、裸缩写补全、Batch 首表优先、无缓存价=输入价保守）→ diff「新增 67」待人工确认
  - [x] google：`https://ai.google.dev/gemini-api/docs/pricing`（**需代理**；SSR 中文机翻 HTML；模型名只在锚点 id，表在 standard/batch/flex/priority 子标题下）✅ 2026-09-12 全链路：解析 28 模型（只取 standard 层；双价取「起为」恢复价=2027-01-01 长期价，促销价归 P2；分档长上下文取首档；「/小时（存储价格）」「每张图片」换算价、模态列表「/ 图片」精确区分）→ diff「新增 28」待人工确认
  - [x] anthropic：`https://docs.anthropic.com/en/docs/about-claude/pricing`（**需代理**；Next.js SSR，表格由 div+CSS 渲染无 `<table>`，按 `<tr>` 平铺扫描）✅ 2026-09-12 全链路：解析 17 模型（显示名「Claude Opus 4.6」→ claude-opus-4.6；「Model」表头行重置列定位；Batch 半价表/CCU 说明表/1M 长上下文合并名行跳过，同模型首条为准）→ diff「新增 17」待人工确认
  - [x] xai：`https://docs.x.ai/developers/models.md`（**.md 直取**；原 /docs/models 308 → /developers/models；grok-4.6 $2/$0.5/$6；长上下文分档取 < 200k 首档、≥200k 条件价跳过；Imagine/Voice 按次计价表跳过）→ 真抓 7 模型「新增 7」✅2026-09-12
  - [x] zhipu：`https://docs.bigmodel.cn/cn/coding-plan/overview.md`（Mintlify `.md` 直取；页面=套餐积分配额表，**无模型按量价**）✅ 2026-09-12：`catalog_from_pricing` 复用同响应体产 `model_catalog`（GLM‑5.3 等 6 模型，U+2011 非断连字符归一化）→ 目录 diff「新增 4」待人工确认；按量三率已预置迁移 000009
  - [x] aliyun：源重定位 ✅2026-09-12——help.aliyun.com 旧页 404 → `docs.bailian.console.aliyun.com/llms.txt`（126KB 索引）+ `llms-full.txt`（5.7MB 全站拼接）可抓；**单页 .md 直取被 WAF 拦**（要求动态 `X-Request-Context` 头）；内容=Token Plan 个人/团队**套餐档位**（Lite 39/Standard 139/Pro 499 元 + 用量包，CNY，无按量 token 价）→ 注册表改指 llms-full.txt（parser=null 仅存快照），档位解析归 P2-1、币种归 P7-1
  - [x] moonshot：`https://platform.kimi.ai/docs/pricing/chat.md`（**需代理**）✅ 2026-09-12 全链路：platform.moonshot.cn 301 → platform.kimi.com（Kimi 开放平台）；llms.txt 索引 + .md 直取，表格为 JSX `<DocTable rows={[...]}>`；**注册国际站 kimi.ai（$），中文站 kimi.com 为 ¥ 不配**；列序=命中价在前（与 DeepSeek 相反）；batch/tools 独立页不配 → 真抓 4 模型，**与库内预置零 diff（交叉验证通过）**
  - [x] tencent：`https://cloud.tencent.com/document/product/1823/130060`（Slate SSR；套餐积分配额页无按量价 → `catalog_from_pricing`；Model ID 表逐变体拆分——`data-slate-string` 每 ul 一个 ID，点/横线双风格并存；「整格全 ID」规则滤掉概览/工具生态格）→ 目录新增 16 ✅2026-09-12
  - [ ] siliconflow：`https://siliconflow.cn/pricing`（SSR；结构已摸清：`pricing-row-{text|image|audio|video}-*` 行 + `<a title="vendor/model">` + 「费用发生时段: 9点～18点」双时段价组；**¥/M tokens 人民币口径 → 待 P7-1 currency 字段落地后接入**，否则美元字段存人民币=资损口径错误）
  - [x] volcengine：`https://docs.volcengine.com/api/doc/getDocDetail?DocumentID=1544106&lang=zh` ✅ 2026-09-12（六）——doccenter garfish SPA 无内嵌数据、llms.txt 301 → **从前端 bundle 挖出 XHR API**（`grep '/api/doc/'` main.js，旧 doc 1099320 已 301 → 1544106 model-pricing）；响应 `Result.Content` = **Quill delta JSON 字符串**（zone：Z 正文/R 行/C cell 引用/x* cell 文本）→ 逐 zone 正则提 `doubao-*` → 真抓 38 模型目录「新增 38」；按量价=元/百万 token（CNY）→ 三率归 P7-1
  - [ ] unicom：`support.cucloud.cn/document/127/591/2357.html?...arcid=7015/7080`（探测结论：1MB DedeCMS 模板壳**正文纯 XHR**、无 `__INITIAL_STATE__`；hostConfig.js 仅站点路径配置 → 文档 API 未定位，待挖页面混淆 JS 或依赖已核对预置）
  - [ ] cmcc：`https://ecloud.10086.cn/op-help-center/doc/article/98322` 与 `outline/108724`（探测结论：React SPA `cloud-cms-service-web` 纯壳 1KB → 文档内容走 CMS XHR，API 未定位，待挖 app.js）
  - [x] minimax：`https://platform.minimax.io/docs/guides/pricing-paygo.md`（**需代理**）✅ 2026-09-12 全链路：minimaxi.com/minimax.io 均 llms.txt + .md 直取；**注册国际站 minimax.io（$），国内 minimaxi.com 为 ¥ 不配**；Priority Tab（1.5x 条件价）与 Legacy Accordion（M2.5/M2.1）整段剥离；M3 ≤512k/>512k 分档取首档；划线促销「~~$0.60~~ $0.30」Permanent 50% off 取实价 → 真抓 3 模型「新增 3」待人工确认
  - [x] baidu：`https://cloud.baidu.com/doc/qianfan/s/wmh4sv6ya` ✅ 2026-09-12（六）——旧 doc hlpl7xe2f 已 302 → 从 index 定位新页（415KB **全 SSR**，217 行价格表）；「模型名称|版本名称|服务内容|子项|在线推理|批量推理|单位」表，「版本名称」列=API 调用 id 但**连排无分隔**（`ERNIE-5.1ERNIE-5.1-Speed-Preview`）→ 按 `(?:ernie|bce)-` 前瞻切分 + modelName 白名单；量包表无「版本名称」表头自动跳过 → 真抓 31 模型目录（ERNIE + 托管 deepseek/glm/kimi/qwen）「新增 30」；按量价=元/千 tokens（CNY，单位天然 /千届时无需 ÷1000）→ 三率归 P7-1
- [x] P1-3 统一标准化格式（与 `VerifyCodingPlanRatios::fetchSource` JSON 约定一致：`models[]{model,match_type,cost_mode,unit_cost,input_rate,cached_rate,output_rate,time_discounts}`）✅ 实现于 `CodingPlanOfficialSourceService::normalizeStructuredEntries()`；解析器同约定输出（单位统一=币种/1k tokens，官方每百万标价 ÷1000）
- [x] P1-4 快照预存：`storage/app/private/coding-plan-snapshots/{vendor}/{Y-m-d-Hi}.json` + 清理策略（保留 10 份，`.raw.txt` 随 JSON 连带清理）；抓取失败沿用上次快照并在 check 记 source_failed ✅ 2026-09-12
- [x] P1-5 diff 与预存基准对比（把现有 diff 逻辑抽成可复用方法），结果写 `coding_plan_ratio_checks` ✅ 2026-09-12（`fetchStructuredSource()`/`diffEntries()`/`diffCatalogModels()`/`markIgnoredChanges()`/`recordCheck()`；diffEntries 兼容 keyed map 与 list 输入，数值 diff 仅对启用行、存在性比对含停用行）
  - [ ] P1-5b `VerifyCodingPlanRatios::diffPricingSource` 切换到复用方法（低优先重构，现有实现工作正常）
- [ ] P1-6 模型上下架检测：各厂商「模型列表」源单独解析 → `kind=model_catalog`（new=上架 / missing=下架；含 OpenAI `/docs/models.md`、xai Retirement、腾讯 GLM-5/5.1 2026-10-09 下线等）【diffCatalogModels + openai 目录源、zhipu catalog_from_pricing（目录与定价同一响应体，注册表布尔开关）已接，其余厂商目录源待接】
- [x] P1-7 新命令 `coding-plan:sync-official {--vendor=} {--snapshot-only}`：抓取→快照→diff→check 串起来；调度每 6 小时（先 sync 后 verify）；保留手动 `pricing_source_url` 自管源 ✅ 2026-09-12（`--vendor` 为合并而非过滤，与 verify 同口径；快照含 proxy_used/parser/entries/catalog 元数据）
- [x] P1-8 代理支持：`PEASE_API_HTTP_PROXY` env（默认空；本机开发 `http://127.0.0.1:7890`），仅对 proxy:true 的源使用；写进 settings-reference.md ✅ 2026-09-12
- [ ] P1-9 健康告警：同一源连续 ≥2 次抓取失败 → check 流水标记 + 管理端同步页红点
- [x] P1-10 解析器单元测试：以 `docs/upstream-snapshots/2026-09-12/` 存档为 fixture，断言解析数值 ✅ `php tests/coding-plan-parser-fixture.php`（42 项断言：deepseek 转置/纵向、openai 长上下文/Batch/裸缩写、google 双价恢复价/分档/存储与图价噪声、anthropic 显示名转 id/Batch 去重/CCU、zhipu U+2011 归一化等容错）

### P2 活动与到期提醒（R7）
- [ ] P2-1 迁移新表 `coding_plan_promotions`：vendor, kind(=discount/free/price_change/model_retirement), title, description, discount, starts_at, ends_at(可空=官方未公布), source_url, status, remind_days(默认7), sort, remark
- [ ] P2-2 迁移预置已知活动（均注来源 URL）：
  - 智谱「夜间畅用」每日 23:00–次日 09:00：ZCode 端 GLM-5.3-Flash 畅用、其他 Agent 额度翻倍（docs.bigmodel.cn/cn/coding-plan/overview）
  - 阿里云 Token Plan 个人版限时价 Lite 39 / Standard 139 / Pro 499（原价 60/180/600，截止未公布 → ends_at=null + note）
  - 阿里云夜间五折 22:00–次日 08:00：qwen3.8-max / deepseek-v4-pro-0813 / deepseek-v4-flash-0731
  - 火山方舟 auto 活动系数 0.5 **至 2026-11-08**
  - 腾讯 TokenHub：GLM-5 / GLM-5.1 **2026-10-09 下线**（kind=model_retirement）
  - Google Gemini 促销价 **至 2026-12-31**（2027-01-01 恢复原价）
  - OpenAI gpt-5.6-sol 促销价 **至少至 2026-11-21**
  - xAI Imagine 图像相关 **2026-11-02 退役**（kind=model_retirement）
- [ ] P2-3 提醒链路：调度每日 09:00（新命令 `coding-plan:remind-promotions`）扫描 remind_days 内到期活动 → ①站内公告 ②`EmailService` 邮件通知订阅对应厂商套餐的用户 ③介绍页倒计时徽标；到期后自动置过期并记流水防重发
- [ ] P2-4 公开 API `GET /api/coding_plan/promotions`（仅 status=1 且未过期）+ 管理端维护页 + i18n

### P3 数据补全与新增厂商预置（R1/R3/R4）
- [ ] P3-1 阿里云夜间五折三行比率（qwen3.8-max 等；官方三率未公布，先建行 status=0 + time_discounts 22:00→08:00 跨零点窗口模板）
- [ ] P3-2 腾讯旧逻辑模型逐档价（deepseek-v4-*、glm-5/5.1/5.2、minimax-m2.7）
- [ ] P3-3 百度积分制逐模型系数（官方「即将支持」后补录）
- [ ] P3-4 移动个人版视觉/视频模型豆率（按需）
- [ ] P3-5 新厂商预置：`siliconflow`（时段价样例见快照 §8）、`minimax`（M3/M2.7）、Kimi K3 官方价核对更新
- [ ] P3-6 国际按量厂商预置（plan_kind=2 Token Plan，单位=美元/百万 tokens）：openai（快照全表）、google（促销价+恢复价双列备注）、xai（grok-4.6）、anthropic（拿到源后）
- [ ] P3-7 把 2026-09-12 快照与现库不一致处核对后更新进 `CodingPlanCatalog` 模板与新迁移（幂等 upsert，不改已启用人工行）：阿里云个人版限时价 39/139/499 + 7 天限额 2500/10000/40000 + 用量包 100 元/2 万 Credits 等（diff 明细见快照文末）

### P4 前端与文档
- [ ] P4-1 介绍页 `/coding-plan`：活动倒计时徽标、厂商最后核对时间/源状态、模型上下架公告位
- [ ] P4-2 管理端「官方同步」页：抓取历史（快照列表/失败原因）、源健康状态、promotions 维护入口
- [ ] P4-3 i18n：`public/src/i18n/locales/*.json` 补齐新增文案（zh/en 最少，其余跟随）
- [ ] P4-4 文档：operations-guide.md §4/§8、usage-guide.md、settings-reference.md 增补 sync-official / promotions / 代理配置

### P5 测试与验收
- [ ] P5-1 适配器单测（fixture=快照存档）
- [ ] P5-2 全链路手测：`php artisan coding-plan:sync-official` → checks 有新变更 → 管理端应用 → 比率生效；`coding-plan:test-time-discounts` 仍 28/28 通过
- [ ] P5-3 迁移全量回放：`php artisan migrate:fresh` 后 seeder/预置无报错（宝塔环境注意 `unsignedDecimal` 历史坑）
- [ ] P5-4 提醒链路手测：造一条 ends_at=明天的活动 → 命令跑一次 → 公告+邮件生成且次日不重发

### P6 持续运营约定
- [ ] P6-1 每 6 小时自动抓取校对（调度已定）；每周人工抽查 2 家源
- [ ] P6-2 官方公布新套餐/活动时：先更新快照 → 再进 Catalog/迁移 → 勾选对应任务

### P7 多货币与结算体系（R9，2026-09-12 新增）
- [ ] P7-1 迁移 000010：`coding_plan_vendors` 加 `currency`（官方计价币种，默认 CNY；openai/google/xai/anthropic 预置 USD，google 备注 HKD 区），`coding_plan_vendor_tiers` 加 `currency`（档位标价币种，缺省继承厂商）
- [ ] P7-2 汇率配置：新表 `currency_rates`（code PK、rate 相对基准 CNY、source=manual/api、updated_at）+ 管理端 CRUD；USD 缺省回落既有 `UsdExchangeRate` option，`currency_rates` 有值时优先
- [ ] P7-3 `CurrencyExchangeService`：`convert(amount, from, to)`（以基准 CNY 中转，双向）；计费落库时把「所用汇率快照」写进用量/订单 meta（汇率随时间变化需留痕可审计）
- [ ] P7-4 客户结算货币：用户级 `settlement_currency`（默认平台基准币种）；报价、账单、余额展示层换算；结算口径=交易时刻汇率快照
- [ ] P7-5 前端：vendors-tab 加币种选择 + 汇率维护页；档位/介绍页展示官方原币价 + 折算价双列；i18n

### P8 厂商→模型上架流（R10，2026-09-12 新增）
- [ ] P8-1 模型清单 API：`GET /api/coding_plan/vendors/{code}/models`（聚合 Catalog 模板 + 比率表 + 官方目录快照，标注：已提供(status=1)/已停用/官方新增/官方下架）
- [ ] P8-2 上架流页面：管理端选厂商 → 模型清单勾选 → 批量启用/停用比率行（status）→ 联动渠道 ability/models 映射（复用 SyncChannelCache）
- [ ] P8-3 官方同步的 `model_catalog` new/missing 变更在清单页高亮 + 一键应用/忽略（承接 P1-6 check 闭环）

### P9 成本感知路由与配额恢复感知（R11/R12，2026-09-12 新增）
- [ ] P9-1 成本排序器：同模型多渠道/多厂商候选，按「每 1k tokens 平台成本」（三段率 × 时段折扣 × unit_exchange_rate × 币种汇率）升序生成动态优先级；模式开关 `ModelRouteStrategy`（cost_first | static，默认 static 保护现网行为）
- [ ] P9-2 跨源 failover：首选源（如联通 Coding Plan 账号池）配额耗尽（`pickAccount` 无可用账号 / 全员 STATUS_EXHAUSTED）→ 自动切换次选源渠道；路由决策写用量日志（reason=cost_failover，记录落选候选与成本）
- [ ] P9-3 恢复回归：上游「已达时间窗上限」（quota_exceeded/429）→ 账号池已有 `reset_*_at` 自动恢复（`CodingPlanPoolService::resetExpiredWindows`），补渠道级 `cooldown_until` + 解析上游 Retry-After/重置文案；恢复到点调度自动切回低成本源；无窗口信息时用保守默认（如 5h 窗口起点）
- [ ] P9-4 可观测与验收：管理端展示每模型各源「成本/状态/恢复倒计时」；手测断言 failover 与恢复回归路径（含 cost_first 下恢复后切回）

---

## 5. 厂商数据源清单（权威表，随抓取结果更新）

| 厂商 code | 产品 | 确定页面 | 格式 | 代理 | 状态 |
|---|---|---|---|---|---|
| deepseek | API 按量 | https://api-docs.deepseek.com/zh-cn/quick_start/pricing/ | HTML | 否 | ✅已接解析器（转置表） |
| openai | API 按量 | https://platform.openai.com/docs/pricing.md（.md 后缀=Markdown） | MD | **是** | ✅已抓取 |
| openai | 模型目录 | https://platform.openai.com/docs/models.md | MD | **是** | ✅已接（sync-official 目录 diff） |
| google | Gemini API | https://ai.google.dev/gemini-api/docs/pricing | HTML | **是** | ✅已抓取 |
| anthropic | Claude API | https://docs.anthropic.com/en/docs/about-claude/pricing | HTML | **是** | ⚠️区域封锁，备选 .md |
| xai | Grok API | https://docs.x.ai/developers/models.md | Markdown | **是** | ✅已解析 |
| zhipu | GLM Coding Plan | https://docs.bigmodel.cn/cn/coding-plan/overview（llms.txt 索引） | Mintlify | 否 | ✅已抓取 |
| aliyun | Token Plan | https://docs.bailian.console.aliyun.com/llms-full.txt（源重定位；单页 .md 被 WAF 拦） | Markdown 拼接 | 否 | ✅已抓取（内容=套餐档位，归 P2-1/P7） |
| tencent | TokenHub Token Plan | https://cloud.tencent.com/document/product/1823/130060 | HTML | 否 | ✅已抓取 |
| siliconflow | 模型价格中心 | https://siliconflow.cn/pricing | HTML | 否 | ✅已抓取 |
| moonshot | Kimi API | https://platform.kimi.ai/docs/pricing/chat.md（moonshot.cn 301 → kimi.com；国际站 $） | Mintlify .md | **是** | ✅已解析（4 模型，与预置零 diff） |
| volcengine | 方舟模型服务/Agent Plan | https://docs.volcengine.com/api/doc/getDocDetail?DocumentID=1544106&lang=zh（旧 1099320 已 301 → 1544106） | JSON（Quill delta） | 否 | ✅已解析（38 模型目录「新增 38」；按量价 CNY 归 P7-1） |
| unicom | Coding/Token Plan | https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7015 / 7080 | DedeCMS 壳+XHR 正文 | 否 | ⏳API 未定位（1MB 模板壳无内嵌数据） |
| cmcc | Coding/Token Plan | https://ecloud.10086.cn/op-help-center/doc/article/98322 、/doc/outline/108724 | React SPA（cloud-cms-service-web） | 否 | ⏳API 未定位（纯壳，正文走 CMS XHR） |
| minimax | API 按量 | https://platform.minimax.io/docs/guides/pricing-paygo.md（国际站 $；国内站 ¥） | Mintlify .md | **是** | ✅已解析（3 模型「新增 3」） |
| baidu | 千帆 | https://cloud.baidu.com/doc/qianfan/s/wmh4sv6ya（旧 hlpl7xe2f 已 302） | HTML（SSR） | 否 | ✅已解析（31 模型目录「新增 30」；按量价元/千 tokens CNY 归 P7-1） |

---

## 6. 变更记录

- 2026-09-12：建立账本；完成 P0（首轮 9+ 家官方源抓取、快照预存 `docs/upstream-snapshots/2026-09-12/`）；确认 OpenAI/Google/xAI/Anthropic 需代理（本机 `http://127.0.0.1:7890` 已验证可用）；发现 OpenAI 文档页支持 `.md` 后缀直取 Markdown（P1-2 关键技巧）。
- 2026-09-12（二）：新增需求 R9-R12 → P7 多货币与结算体系 / P8 厂商模型上架流 / P9 成本感知路由与配额恢复感知（复用点已探明：`CodingPlanAccount` 5h/周/月窗口 + STATUS_EXHAUSTED 自动恢复、`ChannelSelectService` 静态调度、`UsdExchangeRate`、前端 `currency.ts`）。P1 核心工程落地：`CodingPlanOfficialSourceService`（15 家源注册表 + 代理抓取 `PEASE_API_HTTP_PROXY` + 快照保留 10 份 + 复用 diff/忽略/流水）+ `coding-plan:sync-official` 命令（每 6h 先于 verify）+ deepseek/openai 解析器（deepseek 真实结构=转置表；openai 代理链路解析 67 模型 → 「新增 67」待人工确认）+ fixture 测试 21 项断言（`tests/coding-plan-parser-fixture.php`）。发现并修正：快照实际落 `storage/app/private/`（Laravel 11 local disk root）；diffEntries 需兼容 list 输入（数字索引 key 误配）；停用行（status=0）不应报「新增」。回归：test-time-discounts 28/28、verify-ratios 无回归。
- 2026-09-12（三）：P1-2 再落三家解析器并全链路真抓验证（5/5 厂商 0 失败，共 116 处待确认变更）：google（SSR 中文机翻；锚点 id 模型名 + standard 层表；双价「起为」取恢复价防 2027-01-01 刷屏；分档长上下文首档；存储价「/小时」「每张图片」换算价与模态列表「/ 图片」噪声精确区分 → 28 模型）、anthropic（Next.js SSR div 表无 `<table>`，`<tr>` 平铺扫描 + 「Model」表头行重置列定位；显示名转 id；Batch/1M/CCU 表跳过同模型首条为准 → 17 模型）、zhipu（Mintlify `.md` 直取；页面=套餐积分配额无按量价 → `catalog_from_pricing` 注册表开关复用响应体产 model_catalog，U+2011 归一化 → 目录新增 4）。基建：`htmlTables()` 基类助手；sync 命令目录解析提前到条目判空前（entries 空 + catalog 空才算 SOURCE_FAILED）；fixture 扩至 42 项断言。回归：test-time-discounts 28/28、verify-ratios 无回归、pint PASS。
- 2026-09-12（四）：P1-2 再落 xai/tencent 两家解析器并全链路真抓验证（7 厂商 0 失败）：xai（**发现 `.md` 直取技巧同样适用**——原 /docs/models 已 308 → /developers/models.md；Text API Pricing 表长上下文分档取 < 200k 首档、≥200k 条件价跳过；Imagine/Voice 按次计价表跳过 → 7 模型「新增 7」）、tencent（Slate SSR；套餐积分配额页无按量价 → `catalog_from_pricing`；Model ID 表逐变体拆分 + 「整格全 ID」规则滤概览/工具格；GLM-5/5.1 下线经 catalog missing 呈现 → 目录新增 16）。**配置修复**：`.env` 补 `PEASE_API_HTTP_PROXY`（上轮代理仅临时环境变量，导致本轮境外源全超时误报抓取失败）。**工程决策**：siliconflow 结构摸清（pricing-row-{text|image|audio|video} 行 + `title="vendor/model"` + 「费用发生时段」双时段价组）但 ¥/M tokens 人民币口径待 P7-1 currency 字段，现在接入=美元字段存人民币资损口径错误 → 延后 P7；aliyun 原 URL 已 404 待重定位（llms.txt 索引法待试）。fixture 扩至 51 项断言（xai 分档/÷1000/非 token 表、tencent 双风格变体/杂质过滤）；修复 fixture 尾部重复 echo/exit。回归：test-time-discounts 28/28、verify-ratios 无回归、pint PASS。
- 2026-09-12（五）：P1-2 再落 moonshot/minimax 两家解析器（现 9 厂商接通、0 失败），**llms.txt 索引法成为 SPA 探测标准动作**：moonshot（platform.moonshot.cn 301 → platform.kimi.com；`/docs/llms.txt` 直达索引 → `platform.kimi.ai/docs/pricing/chat.md`；表格=Mintlify JSX `<DocTable rows={[...]}>`，行内 `<>{"$"}</>` 价格元素 + `\"` JSON 转义 → 入口先归一化再匹配；**列序=缓存命中价在前**与 DeepSeek 相反；**注册国际站 kimi.ai 美元口径**，中文站 ¥ 不配——同 siliconflow 资损逻辑 → 真抓 4 模型**与库内预置零 diff，交叉验证通过**）、minimax（minimax.io/`pricing-paygo.md`；Standard/Priority 双 Tab → Priority 1.5x 条件价整段剥离、Legacy Accordion（M2.5/M2.1 退役）剥离、M3 ≤512k/>512k 分档取首档、划线促销 `~~$0.60~~ $0.30` Permanent 50% off 取实价 → 真抓 3 模型「新增 3」）。**aliyun 源重定位完成**：llms.txt（126KB）+ llms-full.txt（5.7MB）可抓，但**单页 .md 直取被 WAF 拦**（动态 `X-Request-Context` 头）；内容=Token Plan 个人/团队套餐档位（39/139/499 元 CNY，无按量价）→ 注册表改指 llms-full.txt 仅存快照，档位解析归 P2-1、币种归 P7-1。fixture 扩至 61 项断言（moonshot JSX/列序/上下文数字防污染、minimax 划线实价/Tab/Accordion 剥离/分档）。回归：fixture 61/61、pint PASS；待确认变更 121 处（moonshot 零 diff 未增）。
- 2026-09-12（六）：P1-2 最终 4 家 SPA 源探测收官——**llms.txt/.md 技巧在国内门户失效**，volcengine/baidu 落地、unicom/cmcc 留待：volcengine（doccenter garfish SPA 无内嵌数据、llms.txt 301 → **JS bundle 挖 API 法**：`grep '/api/doc/'` portal CDN main.js → `GET docs.volcengine.com/api/doc/getDocDetail?DocumentID=1544106&lang=zh`（无鉴权，需 `Referer: docs.volcengine.com/...`；旧 doc 1099320 已 301 → 1544106 model-pricing）；响应 Content=**Quill delta JSON**（529KB，zone 结构：Z 正文/R 行/C cell 引用（insert={id}）/x* cell 文本）→ 逐 zone 正则提 `doubao-*` → **VolcengineDocParser** 真抓 38 模型目录「新增 38」）、baidu（旧 doc QIANFAN/s/hlpl7xe2f 302 → 从 index 定位新页 `qianfan/s/wmh4sv6ya` 415KB 全 SSR、217 行价格表 → **BaiduParser**：「版本名称」列=API id 但连排无分隔（`ERNIE-5.1ERNIE-5.1-Speed-Preview`）→ `(?:ernie|bce)-` 前瞻 preg_split + modelName 白名单，量包表无版本名称表头自动跳过 → 真抓 31 模型目录（ERNIE + 托管 deepseek-v4/glm-5.x/kimi-k2.6/qwen3.5/OCR 系）「新增 30」）。**重要口径发现：P1-2 剩余国内厂商按量价全为 CNY**（volcengine 元/百万 token、baidu 元/千 tokens——后者单位天然 /千届时无需 ÷1000），与 siliconflow/aliyun 同构 → 按量三率统一等 P7-1 currency 字段，本轮均以 `catalog_from_pricing` 产目录（解析器 parsePricing 返回空 + 注释）。unicom 探测：1MB DedeCMS 模板壳正文纯 XHR（无 `__INITIAL_STATE__`，hostConfig.js 仅站点路径）→ API 未定位；cmcc：React `cloud-cms-service-web` 纯壳 1KB → CMS API 未定位；两家价目已有迁移 000006/000008 核对预置，待后续挖 JS 或人工。fixture 扩至 63 项断言（baidu 连排切分/量包表跳过/CNY 空 pricing、volcengine 双层 JSON 构造样本（json_encode 免手写转义）/逐 zone 提取/叙述句防整体入库/壳 HTML 安全空）。E2E：snapshot-only + 真抓 7 厂商 0 失败，目录 pending volcengine 38 + baidu 30；待确认变更累计 ~189 处。回归：fixture 63/63、pint PASS。

