# PeaseAPI 运维操作手册（Operations Guide）

面向管理员与运维的**全流程操作手册**：设置变更、支付网关配置与验收、订阅运营、定时任务、缓存维护、故障排查与 API 速查。

> 相关文档：[部署文档](deployment.md)（安装/升级/备份）｜ [使用指南](usage-guide.md)（渠道/令牌/账号池）｜ [设置键位参考](settings-reference.md)（每个配置键的权威定义）

---

## 目录

- [1. 设置变更 SOP](#1-设置变更-sop)
- [2. 支付网关配置与验收](#2-支付网关配置与验收)
- [3. 订阅运营](#3-订阅运营)
- [4. 定时任务](#4-定时任务)
- [5. 缓存与维护操作](#5-缓存与维护操作)
- [6. 故障排查矩阵](#6-故障排查矩阵)
- [7. API 速查](#7-api-速查)
- [8. 已知边界与后续计划](#8-已知边界与后续计划)

---

## 1. 设置变更 SOP

所有系统配置通过 **管理后台 → 系统设置**（Root）修改，保存链路与键位定义见 [设置键位参考](settings-reference.md)。

**通用流程：**

1. 定位分区：站点 / 认证 / 支付 / 计费 / 模型 / 内容 / 运维 / 安全 / 请求限制 / 维护。
2. 修改并保存。保存为**逐键请求**：仅提交变更字段；若弹出“部分设置项未被后端识别，已跳过”，检查该键是否为预留键或需要新增别名（见键位参考「开发者」一节）。
3. 验证：回读 `GET /api/option/` 确认落库；按分区验证前台行为。
4. 显示类设置（名称/Logo/页脚/公告/额度显示/汇率）保存后前端会自动刷新 `status` 缓存，无需手动清理。

**高危操作提醒：**

- `QuotaPerUnit` 全站换算基准，变更会使历史展示与已换算金额产生口径差，请在低峰期操作并公告。
- `Price`（充值单价）直接决定用户支付金额（金额 = 额度 × Price），调整前先在测试环境验证 `/api/user/topup/info` 返回值。
- 密钥类键保存后回显为 `******`；重复保存不会清空原值（空值/掩码提交会被跳过）。

---

## 2. 支付网关配置与验收

### 2.1 易支付（Epay）——当前已完整支持

**配置（管理后台 → 支付设置）：**

| 配置项 | 键 | 说明 |
|--------|-----|------|
| 网关地址 | `PayAddress` | 易支付提交页地址，如 `https://pay.example.com` |
| 商户 ID | `EpayId` | 商户 PID |
| 商户密钥 | `EpayKey` | MD5 签名密钥（密钥项） |
| 额度单价 | `Price` | 充值金额 = 额度 × Price（元） |
| 限额 | `MinTopUpAmount` / `TopUpMaxAmount` | 前端限制与后端校验 |
| 支付方式 | `PayMethods` / `PayMethod1-4*` | 支付宝/微信等展示项 |

**用户侧流程（充值）：**

1. 前端请求 `POST /api/user/topup` → 后端校验配置与金额，生成订单（`trade_no` 前缀 `TU` + 时间戳 + 随机），落 `topup` 表 `status=0`。
2. 后端返回 `{ url, 隐藏字段... }`——`url` 为易支付提交地址，其余字段以隐藏表单 POST 提交（MD5 签名）。
3. 用户支付完成 → 易支付回调 `GET/POST /api/user/epay/notify`（已自动生成）。
4. 回调验签 → 充值订单按 `trade_no` 匹配 → 原子置 `status 0/2→1`（条件 UPDATE，天然幂等）→ 入账。

**用户侧流程（订阅购买）：**

1. 前端请求订阅支付 → 订单落 `subscription_orders` 表 `status=0`（约定 `0=pending / 1=paid / 2=cancelled`），`trade_no` 前缀 `SE`（续费为 `RENEW` + 时间戳）。
2. 同样跳转易支付支付；回调统一进入 `epayNotify`，**先按 `SE` 前缀路由到订阅履约**（`SubscriptionService::fulfillOrder`），再走充值匹配。
3. 履约：条件 UPDATE `0/2→1` 抢占 → 事务内激活/续费订阅 → 重复回调自动 no-op。

**订单超时取消（充值/订阅两表通用）：** 超过支付窗口仍未支付的 `status=0` 订单由 `orders:cancel-expired` 任务（每 10 分钟）置为 `status=2`——充值订单刷新 `updated_at`，订阅订单写入 `cancelled_at`。窗口默认 1440 分钟，环境变量 `PEASE_API_ORDER_TIMEOUT_MINUTES` 可调（≤0 关闭）。**取消 ≠ 拒付**：已取消订单若收到带有效签名的支付回调，仍会照常履约入账（支付事实以网关回调为准），无需人工补偿。

**验收清单：**

- [ ] 后台配置 `PayAddress/EpayId/EpayKey` 后，`GET /api/user/topup/info` 返回的支付方式列表包含易支付项。
- [ ] 小额真实下单：`topup`/`subscription_orders` 出现 `status=0` 记录，跳转支付成功。
- [ ] 回调后订单变 `status=1`，配额/订阅正确入账；**手工重放回调**应直接返回成功且不重复入账（幂等）。
- [ ] 商户后台核对签名方式（MD5）与异步通知地址 `https://<你的域名>/api/user/epay/notify`。
- [ ] 余额支付（`payWithBalance`）：扣款、订单、履约一次事务完成，余额不足返回明确错误。
- [ ] 超时取消：将测试订单 `created_at` 调到窗口外，执行 `php artisan orders:cancel-expired --minutes=60` 后应变 `status=2`；随后重放该订单的有效回调应照常入账（迟到支付兜底）。

### 2.2 余额支付（订阅续费/购买）

- 订阅弹窗选择余额支付时直接扣减用户余额，无第三方回调；失败自动回滚。
- 自动续费：`user_subscriptions.auto_renew=1` 的订阅在每日 `subscription:reset` 任务中尝试余额扣费续期，余额不足则顺延过期（见 §3.3）。

### 2.3 Stripe / Creem / Waffo / 微信 V3 / 支付宝

- 配置键已预留（见键位参考 §5），`topup/info` 会在配置了对应密钥后展示入口。
- **后端履约链路为占位实现**：Stripe 仅生成简化支付意图信息，Creem/Waffo 未对接真实 API；请勿在生产开启，等待后续版本（见 §8）。
- Waffo-Pancake 管理端点为配对/配置占位。

### 2.4 支付合规

首次启用任一支付前，需在支付设置完成合规确认（`POST /api/option/payment_compliance`），写入 `PaymentComplianceAcknowledged(At)`。请求体 `acknowledged=true` 与 `confirmed=true` 均可（管理前端发送后者）。

确认状态会随 `GET /api/user/topup/info` 输出（`payment_compliance_confirmed` / `payment_compliance_terms_version`）：未确认时前台钱包锁定邀请奖励入口（充值方式列表仍由各支付开关控制）。

---

## 3. 订阅运营

### 3.1 套餐管理

- 位置：管理后台 → 订阅管理。套餐字段含额度（QuotaPerUnit 换算的 USD 面额）、周期、价格、`auto_renew` 支持、三个网关产品 ID 预留列。
- 修改套餐不影响已生效订阅；新购买按新配置执行。

### 3.2 订阅订单

- 表：`subscription_orders`（`status`：0=pending / 1=paid / 2=cancelled，取消时间见 `cancelled_at`）。
- 幂等模型：履约前先条件 UPDATE 抢占（`0/2→1`），双回调/重复投递为 no-op。
- ⚠️ 管理后台暂无订单查询界面，可临时用 SQL：
  `SELECT id,user_id,plan_id,trade_no,status,created_at FROM subscription_orders ORDER BY id DESC LIMIT 50;`

### 3.3 每日重置与自动续费（subscription:reset）

每日 00:00 执行（`withoutOverlapping` + `onOneServer`）：

1. 到期订阅：置为过期，回收订阅权益。
2. `auto_renew=1` 且余额充足：扣费续期（按 QuotaPerUnit 换算，向上取整）并生成续费记录（`RENEW` 前缀）。
3. `auto_renew=1` 且余额不足：顺延过期，通知用户。

**手动执行：** `php artisan subscription:reset`（配合 `--help` 查看参数）。

### 3.4 Coding Plan 联动

- `CodingPlanRequireSubscription=true` 时，使用 Coding Plan 需持有对应厂商的有效订阅。
- 账号池/用量折算操作见 [使用指南 → Coding Plan](usage-guide.md#coding-plan-账号池与转换-api)。

### 3.5 Coding Plan 供应商初始化

供应商数据来自两处，**升级后需手动执行一次 seeder**：

```bash
php artisan db:seed --class=CodingPlanVendorSeeder --force
```

- **国际订阅制 4 家**（anthropic / openai / google / alibaba）：seeder 写入并**默认启用**，每家带一条前缀兜底比率（1 请求 = 1 积分，Qwen 按千 token 折算），语义明确、可直接使用。
- **国内 12 家**（火山引擎 / 联通 / 移动 / 智谱 / 阿里百炼 / 腾讯混元 / 百度千帆 / 火山方舟 / DeepSeek / Moonshot 等）：由迁移 `2026_09_11_000002_add_coding_plan_vendor_presets` 幂等预置，**默认停用 `status=0`**。
- **官方价目已核对预置**（迁移 `000003/000004/000005` + 模板目录 `App\Services\CodingPlanCatalog`，2026-09-11 官方文档核对）：
  - 档位：阿里云 8 档、腾讯 TokenHub 8 档、智谱 5 档、**火山方舟 Agent Plan 4 档（40/200/500/1000 元 → 2万/10万/25万/50万 AFP）**、**百度千帆 4 档（9.9/40/200/600 元 → 1400/6600/4.5万/16.5万 积分）**、**联通 Coding Plan 2 档（40/200 元 → 1.8万/9万 次请求）+ Token Plan 6 档（个人版 15/30/45 元 → 600/1200/1800 万 tokens；团队版 198/698/1398 元 → 2.5万/10万/25万 credits）**（迁移 `000006`）；火山 Coding Plan 仅 Lite/Pro 壳档（官方未公布额度）。
  - 折算标准（比率行全部**停用**，管理员核对汇率后逐条启用）：智谱 glm-5.3/flash、阿里 qwen3.6-plus、**DeepSeek flash/v4-pro（高峰口径，单位=元）**、**Moonshot kimi-k3/k2.7-code(-highspeed)/k2.6（单位=元）**、**火山方舟 13 条官方 AFP 系数（doubao-mini 0.25 → kimi-k3 10，2026-09-01 起输入不分段，auto 活动系数 0.5 至 2026-11-08）**、**联通 11 条（Coding Plan per_request 1:1；Token Plan 个人版 1:1 前缀兜底 + 团队版 credits exact 0.93/0.07/0.11，由官方线性折算示例导出，1 credit ≈ 0.01 元）**。
  - 仍需人工补全：**腾讯混元逐模型积分系数**（官方「套餐内积分抵扣规则」页未定位到，官方页存在 WAF）、**百度千帆积分↔token 折算**（官方未公布）、**移动全部档位与折算**（官方页 WAF 无法程序化访问，需人工抄录）。
- **启用前必须人工核对**（错误价格 = 资损）：在管理后台「模型折算比率」确认 `unit_cost` / 三段系数与 `unit_exchange_rate`（供应商单位 → 平台积分；DeepSeek/Moonshot 的 1 单位 = 1 元）后再启用厂商与比率。
- 启用后可用 `php artisan coding-plan:verify-ratios`（每 6 小时自动跑）做 stale 检测与定价源 diff；为厂商配置 `pricing_source_url`（结构化 JSON）可自动发现新增/变价/下架，变更只记录待确认，绝不自动改价。**内置官方定价源**：`GET /api/coding_plan/pricing_source/{厂商code}` 直接把模板目录发布为约定 JSON（供应商编辑框「使用内置官方源」一键填入），官方改价随目录更新自动进入待确认清单。

---

## 4. 定时任务

注册于 `routes/console.php`，生产环境需配置系统 cron：`* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`（或开发用 `php artisan schedule:work`）。

| 任务 | 频率 | 说明 |
|------|------|------|
| SyncChannelCache | 每 60s（`pease-api.sync_frequency`，env `PEASE_API_SYNC_FREQUENCY`，单位秒） | 同步渠道能力缓存 |
| PollTasks | 每 5s（`pease-api.task_poll_frequency`，env `PEASE_API_TASK_POLL_FREQUENCY`，单位秒） | 轮询 MJ/Suno/视频异步任务 |
| **ResetSubscriptions** | 每日 00:00 | 订阅重置 + 自动续费 |
| ResetCodingPlanUsage | 每 5 分钟 | Coding Plan 用量窗口重置/过期账号禁用 |
| VerifyCodingPlanRatios | 每 6 小时（`CodingPlanRatioVerifyEnabled` 开关；`--vendor=` 可指定厂商） | 折算比率校对：stale 检测 + 定价源 diff（新增模型/抵扣率变化/老模型下架，固化 pending_keys，只记录不自动改价），结果落 `coding_plan_ratio_checks`，管理端「官方同步」页生成待确认清单（应用/忽略） |
| CleanLogs | 每日 03:00 | 日志清理（保留期可配） |
| CancelExpiredOrders | 每 10 分钟 | 超时取消未支付订单（窗口 `PEASE_API_ORDER_TIMEOUT_MINUTES`，默认 1440 分钟，≤0 关闭） |
| FixAbilities | 每小时 | 修复渠道能力不一致 |
| RefreshPricing | 每日 04:00 | 刷新定价表 |
| auth-cleanup | 每小时 | 过期 AuthFlow/UserSession 清理 |
| instance-heartbeat | 每分钟 | 实例心跳 |
| perf-metrics-cleanup | 每日 05:00 | 性能指标过期清理（`PERF_METRICS_RETENTION_DAYS`） |

高频任务使用 Laravel 11 亚分钟调度；多实例部署依赖 `onOneServer()`（需 Redis/缓存锁）。

---

## 5. 缓存与维护操作

| 操作 | 命令/路径 | 说明 |
|------|-----------|------|
| 清配置缓存 | `php artisan cache:clear` 或 `OptionService::clearCache()` | 直接改库后必做 |
| 定价缓存 | 保存倍率后 `update()` 自动失效；`RefreshPricing` 同效 | `pricing`（300s）、`public_options`（60s） |
| 日志深度清理 | 后台维护页触发异步任务（`/api/system-task/log-cleanup`），可查进度 | 分批删除，不阻塞 |
| 渠道亲和缓存 | 开启 `ChannelAffinityEnabled` 后，转发成功（响应 <400）自动回写 `channel_affinity:{user_id}:{group}:{model}`（TTL=`ChannelAffinityExpireMinutes` 分钟，下限 1）；`GET /api/option/channel_affinity_cache` 统计、`DELETE` 支持 `?all=true` 全清、`?rule_name=` 单键清除 | 枚举/全清依赖 Redis 驱动（SCAN），其它驱动优雅降级；单键清除不受影响 |
| 模型限流 | 设置键 `ModelRateLimitEnabled/Count/Duration` | 计数存 cache，改配置即时生效 |

---

## 6. 故障排查矩阵

| 症状 | 检查点 |
|------|--------|
| 设置保存后“成功”但未生效 | 响应 `data.skipped` 是否含该键（前端会有警告 toast）→ 键未对齐，见键位参考；或直接改库未清缓存 |
| 前台显示的站点名/Logo 未更新 | 是否走的旧 `status` 缓存 → 强刷前台；确认保存的是 `SystemName/SystemLogo` 规范键或其别名 |
| 充值/订阅下单 500 | `PayAddress/EpayId/EpayKey` 是否配置；`Price` 是否为 0；`storage/logs/laravel.log` |
| 支付完成但未入账 | 商户后台通知地址是否为 `/api/user/epay/notify`；`topup`/`subscription_orders` 状态；日志中 `epay notify sign error`（密钥不匹配） |
| 回调重复入账？ | 不会——条件 UPDATE 抢占（`0/2→1`）保证幂等；若出现说明未走最新代码 |
| 订单已“取消”但用户后来付款了 | 无需人工补偿——网关有效签名回调会照常履约入账（取消只是不再期待支付）；核对订单最终 `status=1`、配额/订阅已入账 |
| 订阅未按日重置 | 系统 cron 是否配置；`php artisan schedule:list` 核对；多实例需共享缓存驱动 |
| 自动续费未扣款 | `user_subscriptions.auto_renew` 是否为 1；余额是否充足；当日任务日志 |
| 模型限流不生效 | `ModelRateLimitEnabled` 是否开启（默认关闭）；限流计数在 cache 驱动中 |
| OAuth 回调地址错误 | `ServerAddress` 必须为对外可访问地址（含协议） |
| 邮件/短信不发送 | SMTP* 或 AliyunSms* 配置；密钥项是否被 `******` 误覆盖（正常会被跳过，不会清空） |
| 亲和缓存统计恒为 0 / 提示 Redis unavailable | `CACHE_STORE` 是否为 `redis`（统计用 SCAN 枚举 `channel_affinity:*`，file 等驱动下功能不受影响，仅无法枚举/批量清除）；单键清除不受影响 |
| 亲和未生效（同用户+组+模型未固定渠道） | `ChannelAffinityEnabled` 是否开启（默认关闭）；上一笔同键请求是否成功（失败/4xx/5xx 不回写）；渠道 `models` 是否包含该模型（不匹配时读侧自动放弃）；亲和键 TTL 过期 |
| 用量/日志页面无数据 | Relay 是否成功（仅成功路径写消费日志，流式/非流式均写）；`LogConsumeEnabled` 是否为 true（默认开启，关闭后不写）；充值/兑换/签到日志由 `QuotaService::addQuota` 写入（type=1），与消费日志（type=2）分开统计 |

---

## 7. API 速查

| 分组 | 端点 | 权限 |
|------|------|------|
| 设置 | `GET/PUT /api/option/` | Root |
| 设置 | `POST /api/option/payment_compliance` / `rest_model_ratio` | Root |
| 设置 | `GET /api/option/channel_affinity_cache`、`GET /api/log/channel_affinity_usage_cache` | Root |
| Waffo-Pancake | `/api/option/waffo-pancake/*` | Root |
| 充值 | `GET /api/user/topup/info`、`POST /api/user/topup` | 登录 |
| 支付回调 | `GET/POST /api/user/epay/notify` | 公开（验签） |
| 订阅 | 订阅套餐/购买/我的订阅（`/api/user/subscription*` 等） | 登录 |
| 异步任务 | `POST /api/system-task/log-cleanup`、`GET /api/system-task/current|list|{id}` | Root |
| 日志 | `GET /api/log/`（列表）、`/api/log/stat`（统计 quota/rpm/tpm）、`/api/log/search`（关键字）、`/api/log/token`（按 Token）；self 变体 `/api/log/self`、`/api/log/self/stat`、`/api/log/self/search` | Root / 登录 |
| 用量 | `GET /api/data/`、`/api/data/users`、`/api/data/flow`（管理员，全部用户）；`/api/data/self`、`/api/data/flow/self`（登录用户本人） | Root / 登录 |
| 计费兼容 | `GET /api/dashboard/billing/usage`、`/api/v1/dashboard/billing/usage`（OpenAI 计费面板，`total_usage` = 消费 quota / `QuotaPerUnit`） | 登录 |
| 公开内容 | `/api/notice` `/api/about` `/api/home_page_content` `/api/pricing` | 公开 |

订单前缀约定：`TU`=充值、`SE`=订阅购买、`RENEW`=订阅续费、`ST`=Stripe、`CR`=Creem、`WA`=Waffo、`WX`=微信原生、`AL`=支付宝原生。

---

## 8. 已知边界与后续计划

| 事项 | 状态 |
|------|------|
| Stripe/Creem/Waffo 真实履约对接 | 待办（配置键与 UI 已预留；`subscription_plans` 已保留产品 ID 列） |
| 订阅订单管理后台界面 | 待办（可先用 SQL 查询，见 §3.2） |
| 订单超时取消任务 | ✅ 已完成（`orders:cancel-expired` 每 10 分钟执行；窗口 `PEASE_API_ORDER_TIMEOUT_MINUTES` 默认 24h；已取消订单的迟到回调照常履约，`completeTopUp` 已收敛为条件 UPDATE 幂等模型） |
| 敏感词/SSRF/Worker 等预留设置的后端消费 | 待办（键已可持久化回显） |
| 数据库迁移 | ✅ 已完成（26/26 全部 Ran；含积分制迁移修复：`unsignedDecimal`→`decimal()->unsigned()`、幂等守卫防 DDL 非事务重放） |
| 日志子系统 | ✅ Round 13 已完成（Log 模型对齐表结构、9 个缺失端点补齐、Relay 成功路径自动写消费日志、`usedata` 表缺失改为从 logs 实时聚合、logs 表字符串列补默认值修复签到/兑换写日志 500）；消费日志 `quota` 自 Round 14 起为真实计费值（见下行） |
| Relay 计费扣除 | ✅ Round 14 已完成（`BillingService::postConsume`：Relay 成功后按倍率计费并原子扣减用户/Token 额度，累计用户 `request_count`/`used_quota` 与渠道 `used_quota`；计费公式 quota = (prompt×模型倍率 + completion×模型倍率×补全倍率)×分组倍率，`ModelPrice` 按次固定价优先且额度 = 单价×`QuotaPerUnit`；分组取 Token 组优先、其次用户组；余额不足照扣至负数，扣减为 SQL 侧原子增量更新。剩余边界：`CacheRatio` 预留未消费（适配器尚未提取 cached_tokens）、未实装请求前预扣（`PreConsumedQuota` 仍为纯默认值）、`LogConsumeEnabled=false` 时只关日志不关扣费） |
| 全链路 E2E 自测（Round 14.5） | ✅ 已完成（真实 HTTP 内核 + 假 OpenAI 上游，67 项断言：认证/Token CRUD/Relay 非流式+流式计费/别名路由/日志/管理端点/签到/兑换/充值/分组可见性）。连带修复一批仅在真实请求链路暴露的问题：① Relay HTTP 层此前完全不可用——`RelayInfo::setChannel()` 从未被调用（上游以空凭证+相对 URI 发出）、`ChannelAdapterInterface.php` 内联重复声明 `BaseAdapter` 导致类加载即 fatal、`streamHandler` 签名不兼容（已统一为 `?callable $callback = null` 并让内容统一走 Response 管道）、`ApiType::OpenAI` 常量名错误、`ApiRateLimit` 依赖 Redis 专有 `ttl()`；② 流式请求计费恒为 0——OpenAI 适配器 WRITEFUNCTION 现解析 SSE usage chunk（依赖上游 `stream_options.include_usage`）；③ 严重越权：`AdminAuth/RootAuth` 用 int 与枚举实例比较恒为 false，任何登录用户可通过管理鉴权（已改为比较 backing value，`UserRole` 对齐 new-api 等级 1/10/100 并迁移历史 role=2 数据）；④ 注册必 500（未写 `users.created_at`）且不校验用户名唯一；⑤ `PUT /api/token/`、`GET /api/token/search`、`DELETE /api/token/{id}/`（尾斜杠）等 SPA 契约缺失、Token 接口曾无所有权校验（IDOR）；⑥ 兑换码批量创建复用同一 key 触发唯一键冲突；⑦ `/web-api/pricings` 查询不存在的 `enabled` 列必 500（PaymentController/PaymentService 已对齐真实表结构，Stripe webhook 增加验签+幂等入账，真实 Stripe 履约仍待办）；⑧ `ModelController` 读错请求属性导致 Token 级分组可见性失效。回归：R9/R10/R11/R12/R13/R14 全绿 |
| Relay 计费补全（Round 15） | ✅ 已完成（① `CacheRatio` 接线：`RelayInfo::$cachedTokens` 由各适配器提取——OpenAI/OpenAICompatible 读 `usage.prompt_tokens_details.cached_tokens`（非流式与 SSE usage chunk），Claude/AnthropicNative 读 `usage.cache_read_input_tokens`；计费公式升级 `TextQuotaService::calculateTotalCostWithCache`：quota = ((prompt−cached)×模型倍率 + cached×模型倍率×CacheRatio + completion×模型倍率×补全倍率)×分组倍率，**未配置该模型 CacheRatio 时缓存不打折（等价 1.0，与未接线时期旧账完全一致）**；消费日志 `other` 新增 `cache_ratio`/`cache_tokens`；② 请求前预扣：`BillingService::preConsume()` 在 Controller 层按 `PreConsumedQuota`（默认 500；≤0 关闭）原子条件扣减（`WHERE quota >= pre` 防并发超扣），可用余额 = min(用户 quota, 受限 Token remain_quota)，不足返回 **429 `insufficient_balance`**（OpenAI 错误结构；流式在 SSE 头发出前返回 JSON 429），上游失败/异常路径退款（`refundPreConsumed`），成功后 `logConsume` 结算冲销——净扣费恒等于实际计费；③ 连带修复：**Claude 转换路径此前从不设置 token 计数（非流式+流式计费恒为 0）**——`ClaudeAdapter::formatResponse` 现回填 prompt/completion/cached，`streamHandler` 增加行缓冲并解析 `message_start`（输入+缓存命中）/`message_delta`（输出）后转发 usage chunk；`AnthropicNativeAdapter` 流式透传同步解析 usage；④ E2E 扩至 **74 项**（新增 `/api/pricing` 公开端点、缓存计费非流式/流式 690、未配置 CacheRatio 750 基线、预扣 429/结算净额 750/受限 Token 429）。回归 R9-R14 全绿，Pint 通过 |
| 设置子系统优化（Round 16） | ✅ 已完成（① 热路径运行时 memo：`OptionService::get()` 进程内缓存命中即返回（计费/鉴权/限流一次请求 6+ 次取值免重复存储往返），`set/setMany/clearCache` 即时失效；② `loadAll()` 聚合缓存 `option:__all__`（60s）：Root `GET /api/option/`、`/api/pricing`、`public_options` 底层由每请求全表查询降为单键缓存读，任何写入即时失效；③ `clearCache()` 非 Redis 驱动回退：修复 file/database 驱动下全局清缓存为 no-op（原仅 Redis SCAN 生效），安装/种子流程及绕过 Service 的直写不再有 60s 陈旧窗口；④ `JSON_KEYS` 写入校验：非法 JSON 字符串拒绝落库（`PUT /api/option/` per-key 计入 `skipped` 并透出，不再静默替换为默认空表）；⑤ bool 规范化存储 `'true'/'false'`（原 `(string)` 强转产出 `'1'/''`）；⑥ `setMany()` 事务化防半途落库；⑦ 连带补齐 helpers `setOption()` 与 DeploymentController 直写缺失的缓存失效，Stats 中间件改走 `OptionService::get()` 统一读取路径。E2E 扩至 **81 项**（新增 S16 七项：非法 JSON per-key 拒绝、pricing 失效、bool 存储、memo 失效、file 驱动 clearCache 回退、聚合缓存生命周期、setMany 批量）。回归 R9-R14 全绿，E2E 连续两次 81/81，Pint 通过 |
| 敏感词后端消费（Round 17） | ✅ 已完成（① 新建 `SensitiveWordService`：词表解析（换行/中英文逗号/分号分隔、大小写不敏感 `mb_stripos`）、payload 文本字段提取（仅扫描 `text/content/prompt/input/system` 键，`b64_json`/`image_url`/`data` 等媒体键天然跳过避免 base64 误报）；② Relay 接线：`CheckSensitiveOnPromptEnabled` 开 → 请求内容检查（`RelayController::handleNormal/handleStream` 在 `preConsume` 之前，命中即 400 `sensitive_word_detected`——不扣费、不写消费日志、不调用上游；流式在 SSE 头发出前返回 JSON 400）；`StopOnSensitiveEnabled` 开 → 上游响应检查（非流式命中替换 400，计费照常；流式在输出回调内滚动窗口扫描原始 SSE，命中后下发错误事件并丢弃后续 chunk，`[DONE]` 收尾）；③ 语义：主开关 `SensitiveWordEnabled`（别名 CheckSensitiveEnabled）关闭全放行仅一次缓存读；错误消息不回显命中词（防词表枚举探针），命中词仅进服务端 warning 日志（scene/word/user_id/model）；词表/开关变更经运行时 memo 即时生效；④ `StopOnSensitiveEnabled` 补入 DEFAULTS+BOOL_KEYS；E2E 扩至 **92 项**（新增 S17 十一项：主开关关闭放行计费、prompt 命中非流式 400 不扣费不写日志、流式 400 JSON、命中不触达上游、干净 prompt 放行、StopOn 非流式 400、流式截断、StopOn 关闭透传、词表热更新、别名同源、分隔符解析+非文本键跳过）。回归 R9-R14 全绿，E2E 连续两次 92/92，Pint 通过。连带修复：清理上轮 E2E 异常中止（S10 未走到）遗留的 7 个 option 残留行（CompletionRatio/GroupRatio/QuotaForNewUser/CheckinEnabled/ChannelAffinityEnabled/Price/EpayKey），已回落 DEFAULTS |
| 全库缺陷清扫 | ✅ 已完成（① Webhook 加固：`/api/stripe/webhook` 原落在 `TopUpController` 无验签实现上（`PaymentController` 的验签版本从未接路由）——现委托 `PaymentService::handleStripeWebhook` 统一验签+幂等入账；`creemWebhook`/`waffoWebhook` 补 HMAC-SHA256 验签（`CreemWebhookSecret`/`WaffoPancakeWebhookSecret`，未配置即拒绝，防伪造回调免费入账）；`completeTopUp` 入账改 `increment()` 原子更新防并发丢单；② `CleanLogs`/`CleanLogsJob` 传 Carbon 给 int 时间戳列（MySQL 前缀转数字静默 no-op，日志永远清不掉）改为 `getTimestamp()`；③ `PerformanceController::summary` 查询 `perf_metrics` 不存在的 `created_at`/`duration`/`is_error` 列 + MySQL 不支持的 `PERCENTILE_CONT ... WITHIN GROUP` 必 500，重写为 `bucket_ts` 聚合口径（PHP 侧桶级分位数）；④ `task:poll` 查询签到任务表不存在的 `status` 列（计划任务日日抛异常）加 Schema 守卫；⑤ `RelayService::relay` 幻影列 `Ability.name`→`model`、`MidjourneyService::proxyImage` `image_id`→`mj_id`；⑥ `Task` 家族 6 个适配器不兼容父类签名（`buildHeaders(RelayInfo)` vs `BaseAdapter::buildHeaders(array)`）及 `JimengAdapter` 可见性缺失导致类加载即 fatal——统一改名 `buildRequestHeaders` 并删除死代码；⑦ `POST /api/oauth/email/bind` 原挂在公开路由段（无 UserAuth），api guard 为 web session → `$request->user()` 恒 null → 匿名调用必 500，已移入 UserAuth 组（匿名 401，登录态正常绑定）。资金链精读：订阅履约/续订（`fulfillOrder`/`renewSubscription` 事务+条件更新互斥）、微信/支付宝原生回调验签（WechatPayService 为 APIv3 解密即信任策略+幂等入账、AlipayService 公钥验签）均实证通过（新增活体测试 `/tmp/sub-fulfill-live-test.php` 12/12）。预留/半成品（不删）：`SubscriptionResetService` 零调用死代码（且以 `updated_at` 作重置标记语义有误）、`pricings` 表+`/web-api/pricings`+`pricing:refresh`（清幻影键 `model_pricing`）无维护方无消费方。回归 R9-R14 全绿 + E2E 92/92 连续两次，Pint 通过。Model/中间件层复审（Token/User/PerfMetric/SubscriptionPlan、TokenAuth/Distributor/ModelRateLimit/GlobalApiRateLimit/routes/console.php 调度）无新缺陷，另修：⑧ `routes/console.php` perf-metrics 每日清理查 `perf_metrics` 不存在的 `created_at`（该表仅 `bucket_ts`，每日 05:00 必 SQL 异常）改为 `bucket_ts` 整型时间戳口径，且保留期原用运行时 `env()`（config:cache 后返回 null→0 天→每晚全表清空风险），迁至 `config('pease-api.perf_metrics_retention_days')` 并加 `max(1,…)` 下限；⑨ `pease:instance-heartbeat` 向 timestamp 列 `system_instances.last_heartbeat` 写 int 时间戳（存成 0000-00-00 或严格模式报错）改为写 Carbon `now()`；⑩ Task 适配器家族对 `RelayInfo` 的 8 个幻影方法调用（intelephense 实报属实——一旦启用即 `Call to undefined method` fatal）：`getChannel`×7 改为代码库约定的属性访问 `$info->channel`（TaskAdapter×3 + Gemini/Kling/Vertex/Ali 子类 + JimengAdapter，Jimeng 处加 `?->` nullsafe）；其余 7 个访问器 `getParam`×7/`getRequestBody`×11/`setRequestBody`/`setResponseData`/`getResponseData`/`setResponseBody`×2/`getError` 全库无定义——在 `RelayInfo` 类尾成批补齐（新增 `$responseData`/`$error` 属性；`getRequestBody` 直接返回既有 `$requestBody` 属性与同步适配器属性访问共存；`getParam` 请求体优先、query 兜底；活体冒烟全路径通过）。该家族现仍为零调用者的预留扩展点；⑪ 幻影方法系统化扫描（`/tmp/phantom-method-check.php`：反射校验 app/ 下 164 类的 `$this->X()`/`$info->X()` 调用面）另揪出 3 个 OpenAI 兼容适配器的 6 处幻影调用 `formatOpenAICompatibleRequest/Response`——trait 从未定义该委托 helper（调用即 fatal），已在 `OpenAICompatibleTrait` 提取同名 protected 通用实现（原 `formatRequest`/`formatResponse` 本体）并委托，12+ 现有 trait 用户行为不变；连带修复：Volcengine 模型 `ark-` 前缀改写原写在 `$info->request`（原始 Request）而非 `$info->requestBody`（转发体）导致改写无效；Volcengine/ChinaUnicom/ChinaMobile 的 `errorHandler` 用 `parent::errorHandler()` 委托到 `BaseAdapter` 的**空 no-op**（trait 方法不在父类链上）→ 上游错误被静默吞掉，已删覆盖回归 trait 的 OpenAI 标准错误格式化；同类 `parent::` 全库仅此 3 处，均已清零）） |
| 本地缓存驱动 | ⚠️ 本机未运行 Redis，`.env` 的 `CACHE_STORE=redis`/`QUEUE_CONNECTION=redis` 需先启动 Redis 或临时改为 `file`/`sync`（R9/R10 回归脚本需以 `CACHE_STORE=file` 前缀运行） |

> 维护约定：每次功能落地后同步更新 [键位参考](settings-reference.md) 与本手册对应小节。

