# PeaseAPI 系统设置键位参考（Settings Reference）

本文档是系统设置（Options）的**权威键位参考**，覆盖：设置保存链路、类型转换规则、前端↔后端命名别名机制、扩展前缀持久化组，以及全部分类键位表。

> 相关文档：[部署文档](deployment.md) ｜ [使用指南](usage-guide.md) ｜ [运维操作手册](operations-guide.md)
>
> 最后同步：Round 14.5（全链路 E2E 自测后接线状态更新——注册校验、流式计费 usage 解析、Stripe 部分接线、限流驱动无关化；详见运维手册 Round 14.5 行）

---

## 目录

- [架构与保存链路](#架构与保存链路)
- [API 端点](#api-端点)
- [类型转换与缓存](#类型转换与缓存)
- [别名机制（ALIASES）](#别名机制aliases)
- [扩展前缀（EXTENSION_PREFIXES）](#扩展前缀extension_prefixes)
- [键位参考表](#键位参考表)
- [设置变更验证 SOP](#设置变更验证-sop)
- [开发者：如何新增设置键](#开发者如何新增设置键)

---

## 架构与保存链路

所有系统配置存储于 `options` 表（key→value），由 `app/Services/OptionService.php` 统一管理：

```
管理界面（React system-settings 分区）
  │  useSettingsForm → 逐字段变更
  │  PUT /api/option/  携带扁平 { "Key": value }
  ▼
OptionController::update
  │  1. 密钥掩码跳过（'******' / 空值，按 canonicalKey 判定）
  │  2. OptionService::isKnown(key)  ← DEFAULTS ∪ ALIASES ∪ EXTENSION_PREFIXES
  │     └─ 未知键 → 记入响应 data.skipped（不再静默丢弃）
  ▼
OptionService::set(key, value)
  │  canonicalKey(key) → cast(key) → Option::set（仅存规范键）
  ▼
缓存失效：Option::clearCache() + pricing + public_options
```

**读取**：`GET /api/option/`（Root）返回 `loadAll()` = DEFAULTS 合并 DB 存量，并**同时输出别名键**（值等于规范键），保证前端任一命名均可回显。

关键约定：

| 事项 | 说明 |
|------|------|
| 数据库只存规范键 | 别名仅在读写边界转换，不产生重复行 |
| 掩码密钥 | `SECRET_KEYS` 及其别名在 GET 中输出 `******`；PUT 收到 `******` 或空串时跳过（不清空） |
| 未知键处理 | 响应仍为 `success=true`，但 `data.skipped` 与 `message` 会列出被跳过的键；前端 toast 警告 |
| 权限 | 设置读写均需 Root |

---

## API 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/option/` | 全量配置（含默认值；密钥掩码） |
| PUT | `/api/option/` | 批量更新，扁平 `{ Key: value }`；也兼容表单 `options[Key]` |
| POST | `/api/option/payment_compliance` | 支付合规确认（`acknowledged=true` 或 `confirmed=true` 均可，管理前端发送 `confirmed`） |
| POST | `/api/option/rest_model_ratio` | 重置倍率为默认 |
| GET | `/api/option/channel_affinity_cache` | 渠道亲和缓存统计（CacheStats 形状：`total/unknown/by_rule_name/cache_capacity/cache_algo`；枚举需 Redis 驱动，其它驱动优雅降级） |
| DELETE | `/api/option/channel_affinity_cache` | 清除亲和缓存：`?all=true` 全清 / `?rule_name=<键后缀>` 单键清除（键格式 `channel_affinity:{user_id}:{group}:{model}`） |
| GET | `/api/log/channel_affinity_usage_cache` | 亲和键用量查询（参数 `rule_name/using_group/key_hint/key_fp`；规则引擎上线前回显参数并返回零值统计） |
| POST | `/api/option/waffo-pancake/pair` / `/save` | Waffo-Pancake 配对/保存 |
| POST/GET | `/api/option/waffo-pancake/subscription-product(-options)` | 订阅产品（占位实现） |

---

## 类型转换与缓存

`OptionService::cast()` 按键类型归一化：

| 类型 | 常量 | 行为 |
|------|------|------|
| 布尔 | `BOOL_KEYS` | `'1'/'true'/'yes'/'on'` → true；写库规范化为 `'true'/'false'`（Round 16 起，存储可读且读取路径自洽） |
| 整数 | `INT_KEYS` | `(int)` 强转 |
| 浮点 | `FLOAT_KEYS` | `(float)` 强转 |
| JSON | `JSON_KEYS` | 读：字符串自动 `json_decode`，失败回退默认；写（Round 16 起）：字符串入参必须是合法 JSON，否则抛 `InvalidArgumentException`——`PUT /api/option/` 将该键计入 `skipped` 并透出，不再静默替换为默认空表 |
| 扩展前缀键 | `EXTENSION_PREFIXES` | 原样存储/返回，由前端解析 |
| 其它 | — | 字符串原样 |

缓存（Round 16 起三层，写路径全部即时失效）：

1. **运行时 memo**（`OptionService::$runtimeCache`，进程内 / 单请求生命周期）：`OptionService::get()` 命中即返回，免去热路径（计费/鉴权/限流一次请求 6+ 次取值）对缓存存储的重复往返；`set()/setMany()/clearCache()` 即时失效。
2. **单键缓存** `option:{key}`（60s）：跨请求兜底；`Option::set()` 写后失效。
3. **聚合缓存** `option:__all__`（60s）：`Option::loadAll()`（Root `GET /api/option/`、`/api/pricing` 与 `public_options` 底层）由每次全表查询降为一次存储读；任何 `Option::set()`/`clearCache()` 即时失效。

全局清缓存 `OptionService::clearCache()`：Redis 驱动走 SCAN+DEL 快路径；**其它驱动（file/database/…）回退为逐个失效所有已知键（DEFAULTS + 别名双向）**——修复此前非 Redis 驱动下全局清缓存为 no-op、绕过 Service 直写 DB 后最长 60s 陈旧的问题（helpers `setOption()` 与 DeploymentController 直写也已补失效）。`setMany()` 已事务化（批量保存不再可能半途落库）。`OptionController::update()` 结束后仍统一失效 `pricing`（`GET /api/pricing`，300s）与 `public_options`（`GET /api/status` 的公共选项读缓存，60s）。

---

## 别名机制（ALIASES）

前端控制台沿用 new-api 命名，与 Laravel 后端规范键存在历史差异。**未映射时 PUT 会被 isKnown 拒绝 → 保存无效**（本轮已修复）。当前全量别名（`OptionService::ALIASES`）：

### 支付

| 前端键 | 规范键 | 说明 |
|--------|--------|------|
| `EpayUrl` | `PayAddress` | 易支付网关地址 |
| `MinTopUp` | `MinTopUpAmount` | 最低充值额度 |
| `StripePrice` | `StripeUnitPrice` | Stripe 单份额度单价 |
| `StripeMinTopUp` | `StripeMinAmount` | |
| `StripeApiSecret` | `StripeApiKeys` | Stripe 密钥（后端按单值消费） |
| `WaffoUnitPrice` | `WaffoPrice` | |
| `WaffoMinTopUp` | `WaffoMinAmount` | |

### 显示 / 汇率

| 前端键 | 规范键 |
|--------|--------|
| `USDExchangeRate` | `UsdExchangeRate` |
| `general_setting.quota_display_type` | `QuotaDisplayType` |
| `general_setting.custom_currency_symbol` | `CustomCurrencySymbol` |
| `general_setting.custom_currency_exchange_rate` | `CustomCurrencyExchangeRate` |
| `general_setting.docs_link` | `DocLink` |
| `Logo` | `SystemLogo` |

### 安全 / 限流 / 绘图

| 前端键 | 规范键 |
|--------|--------|
| `ModelRequestRateLimitEnabled` | `ModelRateLimitEnabled` |
| `ModelRequestRateLimitCount` | `ModelRateLimitCount` |
| `ModelRequestRateLimitDurationMinutes` | `ModelRateLimitDuration` |
| `CheckSensitiveEnabled` | `SensitiveWordEnabled` |
| `MjNotifyEnabled` | `MJNotify` |

### OAuth（含点号表单风格）

| 前端键 | 规范键 |
|--------|--------|
| `GitHubOAuthEnabled` / `GitHubClientId` / `GitHubClientSecret` | `GithubOAuthEnabled` / `GithubClientId` / `GithubClientSecret` |
| `discord.enabled` / `discord.client_id` / `discord.client_secret` | `DiscordOAuthEnabled` / `DiscordClientId` / `DiscordClientSecret` |
| `oidc.enabled` / `oidc.client_id` / `oidc.client_secret` / `oidc.well_known` / `oidc.authorization_endpoint` / `oidc.token_endpoint` / `oidc.user_info_endpoint` | `OIDCEnabled` / `OIDCClientId` / `OIDCClientSecret` / `OIDCWellKnown` / `OIDCAuthorizationEndpoint` / `OIDCTokenEndpoint` / `OIDCUserInfoEndpoint` |
| `passkey.enabled` / `passkey.rp_id` / `passkey.origins` | `PasskeyEnabled` / `PasskeyRPID` / `PasskeyROrigins` |

### 其它点号组

| 前端键 | 规范键 |
|--------|--------|
| `legal.user_agreement` / `legal.privacy_policy` | `UserAgreement` / `PrivacyPolicy` |
| `checkin_setting.enabled` / `checkin_setting.min_quota` | `CheckinEnabled` / `CheckinQuota` |
| `channel_affinity_setting.enabled` | `ChannelAffinityEnabled` |
| `perf_metrics_setting.enabled` / `perf_metrics_setting.retention_days` | `PerformanceMetricEnabled` / `PerfMetricMaxAge` |

> 注意：`ModelRequestRateLimitSuccessCount`、`ModelRequestRateLimitGroup`、`passkey.attachment_preference` 等无后端消费者，作为**持久化回显键**保存（见下节）。

---

## 扩展前缀（EXTENSION_PREFIXES）

以下点号组允许按前缀持久化（原样存取，后端暂未消费，用于设置回显与后续接入）：

| 前缀 | 界面位置 | 状态 |
|------|---------|------|
| `console_setting.*` | 控制台内容 | **已消费**（公告/FAQ/API 信息/Uptime Kuma） |
| `model_setting.*` / `model_deployment.*`（含 ionet） | 模型设置 | 预留 |
| `claude.*` / `gemini.*` / `grok.*` / `global.*` | 模型设置各卡片 | 预留 |
| `monitor_setting.*` / `group_ratio_setting.*` / `tool_price_setting.*` | 模型 / 倍率 | 预留 |
| `quota_setting.*` / `billing_setting.*` / `payment_setting.*` | 计费 / 支付 | 预留（amount_options/amount_discount） |
| `fetch_setting.*`（SSRF） / `token_setting.*` | 请求限制 | 预留 |
| `performance_setting.*` / `perf_metrics_setting.*` | 运维 / 监控 | 预留 |
| `general_setting.*` / `legal.*` / `checkin_setting.*` / `passkey.*` | 通用 / 法务 / 签到 / Passkey | 未别名部分预留 |
| `channel_affinity_setting.*` | 渠道亲和 | 部分消费：`enabled` 别名→`ChannelAffinityEnabled`；`max_entries` 用于统计容量显示；`rules` 预留规则引擎 |

---

## 键位参考表

> 「界面」列为管理后台设置分区；「消费」列为主要后端读取方。类型 B=布尔 I=整数 F=浮点 J=JSON S=字符串。

### 1. 站点通用（site / maintenance）

| 键 | 类型 | 默认 | 界面 | 消费 | 说明 |
|----|------|------|------|------|------|
| SystemName | S | Pease API | 站点设置 | status 公开 | 站点名称 |
| SystemLogo / Logo→别名 | S | '' | 站点设置 | status | Logo URL |
| SystemFooter / Footer | S | '' | 站点设置 | 前台页脚 | 页脚文本 |
| HomePageContent / About | S | '' | 站点设置 | 公开端点 | 首页/关于内容 |
| ServerAddress | S | '' | 站点设置 | OAuth 回调、邮件链接 | 站点对外地址 |
| Notice | S | '' | 公告 | 公开端点 | 系统公告 |
| HeaderNavModules / SidebarModulesAdmin | J | [] | 顶部导航/侧栏模块 | 前台布局 | 模块开关 |
| UserAgreement / PrivacyPolicy（legal.*） | S | '' | 站点设置 | 协议页 | 法务文本 |
| DocLink / TopUpLink / ChatLink(2-4) / HomePageLink | S | '' | 站点/计费 | 前台跳转 | 外链 |

### 2. 注册登录与认证（auth）

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| RegisterEnabled / PasswordRegisterEnabled / PasswordLoginEnabled | B | true | 注册登录开关（Round 14.5 起注册强制校验用户名唯一性与格式 `^[a-zA-Z0-9_]+$`，并写入 `created_at`——修复此前重复用户名/注册必 500） |
| EmailVerificationEnabled | B | false | 邮箱验证 |
| EmailDomainRestrictionEnabled + EmailDomainWhitelist(J) | B/J | false | 邮箱域名白名单 |
| TurnstileCheckEnabled + TurnstileSiteKey / TurnstileSecretKey(密) | B/S | false | 人机校验 |
| GithubOAuthEnabled + GithubClientId / GithubClientSecret(密) | B/S | false | GitHub OAuth |
| DiscordOAuthEnabled + DiscordClientId / DiscordClientSecret(密) | B/S | false | Discord OAuth |
| OIDCEnabled + OIDC* 系列 | B/S | false | OIDC（WellKnown/端点/Scopes/DisplayName） |
| LinuxDOOAuthEnabled + LinuxDOClientId / LinuxDOClientSecret(密) | B/S | false | LinuxDO OAuth |
| WeChatAuthEnabled / TelegramOAuthEnabled | B | false | 微信/Telegram 登录 |
| OAuthRegisterEnabled / OAuthStateTTL(I=600) | B/I | true | OAuth 注册与状态有效期 |
| PasskeyEnabled / PasskeyRPID / PasskeyROrigins(J) | B/S/J | false | Passkey 登录 |
| TwoFAEnabled / TwoFARequired / SecureVerificationEnabled | B | false | 2FA / 敏感操作验证 |
| SessionCookieSameSite / SessionCookieSecure | S/B | lax/false | 会话 Cookie |
| SmsEnabled + AliyunSms*（AccessKeySecret 为密） | B/S | false | 阿里云短信 |
| SmsCodeTTL/Length/SendInterval/DailyLimit/IpHourLimit | I | 300/6/60/10/5 | 短信限流参数 |
| SMTPServer/Port(I=587)/Account/From + SMTPToken(密) | S/I | — | 邮件发送 |
| SMTPSSLEnabled / SMTPStartTLSEnabled / SMTPInsecureSkipVerify / SMTPForceAuthLogin | B | false | 预留（SMTP 传输参数） |

### 3. 运营参数（operations / general）

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| QuotaForNewUser / QuotaForInviter / QuotaForInvitee | I | 0 | 注册/邀请奖励 |
| QuotaRemindThreshold | I | 1000 | 余额提醒阈值 |
| PreConsumedQuota | I | 500 | 请求前预扣额度（Round 15 起真实消费：Relay 进入时按 min(用户余额, 受限 Token 余额) 原子预扣，不足返回 429 `insufficient_balance`；成功结算为实际计费、上游失败退款；≤0 关闭预扣） |
| SelfUseModeEnabled / DemoSiteEnabled | B | false | 自用/演示模式 |
| CheckinEnabled / CheckinQuota(I=1000) / CheckinMaxContinuous(I=7) | B/I | false | 签到（checkin_setting.* 别名见上） |
| CheckinStreakEnabled / CheckinStreakRules(J) / CheckinStreakResetHour(I) | B/J/I | false | 连续签到 |
| RedemptionEnabled | B | true | 兑换码 |
| LogConsumeEnabled | B | true | 消费日志开关（`false` 时 Relay 成功不再写消费日志，但**计费扣除照常执行**）；`LogNotConsumeEnabled` 预留未实现 |
| DefaultCollapseSidebar | B | false | 预留（界面偏好） |

### 4. 计费与显示（billing / general）

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| QuotaPerUnit | I | 500000 | **1 美元额度单位**（全站换算基准；Round 14 已接线：`ModelPrice` 按次固定价额度 = 单价 × 此值） |
| Price | F | 7.3 | 按量计费单价（充值金额 = 额度 × Price） |
| DisplayInCurrencyEnabled / DisplayTokenStatEnabled | B | true | 前台显示方式 |
| QuotaDisplayType | S | USD | USD/CNY/自定义 |
| UsdExchangeRate | F | 1 | 汇率 |
| CustomCurrencySymbol / CustomCurrencyExchangeRate | S/F | ¤/1 | 自定义货币 |
| BillingPromptRatio | F | 0 | 计费提示阈值比例 |
| TieredBillingEnabled / TieredBillingRules(J) | B/J | false | 分层计费 |
| ModelRatio / GroupRatio / CompletionRatio / ModelPrice / CacheRatio | J | [] | 倍率表（公开定价接口输出；Round 14 已接线 Relay 计费：quota = (prompt×模型倍率 + completion×模型倍率×补全倍率)×分组倍率，`ModelPrice` 按次固定价优先；Round 14.5 起流式请求 tokens 改从 SSE `usage` chunk 读取——上游需响应 `stream_options.include_usage`，修复流式计费恒为 0；公开定价 `/web-api/pricings` 已对齐真实表结构；Round 15 起 `CacheRatio` 已消费：quota = ((prompt−cached)×模型倍率 + cached×模型倍率×CacheRatio + completion×模型倍率×补全倍率)×分组倍率，适配器自动提取 cached_tokens（OpenAI 系 `usage.prompt_tokens_details.cached_tokens`，Claude/Anthropic 系 `usage.cache_read_input_tokens`，流式同样支持），未配置该模型的 CacheRatio 时缓存 token 不打折（等价 1.0，行为与未接线时一致）） |
| AutoGroupRatioEnabled / AutoGroupRatio(J) / AutoGroupSetting | B/J/S | false | 自动分组倍率 |

### 5. 支付（integrations → 支付设置）

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| TopUpEnabled | B | true | 余额充值入口 |
| MinTopUpAmount（MinTopUp→别名） | I | 1 | 最低充值额度 |
| TopUpRatio | F | 1 | 充值比例 |
| PayMethods | J/S | 支付宝+微信 | 支付方式列表 |
| PayAddress（EpayUrl→别名） | S | '' | **易支付网关地址** |
| EpayEnabled / EpayId / EpayKey(密) / EpayMinAmount(I=1) / EpayMaxAmount(I=1000) | B/S/I | false | 易支付参数 |
| StripeEnabled / StripeApiKeys(密，StripeApiSecret→别名) / StripeWebhookSecret(密) | B/S | false | Stripe（Round 14.5 部分接线：`StripeApiKeys` 决定支付方式探测（`stripe_enabled` 以密钥是否配置为准，后端不读 `StripeEnabled`）并用于创建 Checkout Session；`StripeWebhookSecret` 用于回调验签+幂等入账，未配置则拒绝处理 webhook（`/api/stripe/webhook` 已统一走该验签链路）；真实 Stripe 履约仍待办） |
| StripeUnitPrice（StripePrice→别名）/ StripeCurrency / StripePriceId / StripePromotionCodesEnabled | F/S/B | 0.1/usd//false | Stripe 计价（`StripeCurrency` 已消费 = Checkout 结账货币；按额度下单金额复用 §4 的 `Price`；`StripeUnitPrice` 仅旧 `TopUpController::stripePay` 预留路径读取；`StripePriceId`/促销开关与 `StripeMin/MaxAmount` 仍预留） |
| StripeMinAmount/MaxAmount（StripeMinTopUp→别名） | I | 1/1000 | |
| CreemEnabled / CreemApiKey(密) / CreemWebhookSecret(密) / CreemPrice / CreemMin/MaxAmount / CreemTestMode / CreemProducts(J) | B/S/F/I | false | Creem（`CreemWebhookSecret` = HMAC-SHA256(raw body, secret) 验签 `creem-signature` 头，未配置则拒绝处理 `/api/creem/webhook`） |
| WaffoEnabled / WaffoMerchantId / WaffoApiKey(密) / WaffoPrice / WaffoMin/MaxAmount + Waffo* 预留组 | B/S/F/I | false | Waffo |
| WaffoPancakeEnabled / WaffoPancakeMerchantId / WaffoPancakeApiKey / WaffoPancakeWebhookSecret | S | — | Waffo-Pancake（`WaffoPancakeWebhookSecret` = HMAC-SHA256(raw body, secret) 验签 `X-Signature` 头，未配置则拒绝处理 `/api/waffo/webhook`） |
| WechatPayEnabled + WechatPayAppId/MchId/ApiV3Key(密)/SerialNo/PrivateKey(密)/NotifyUrl | B/S | false | 原生微信支付 V3 |
| AlipayEnabled + AlipayAppId/PrivateKey(密)/AlipayPublicKey(密)/Mode/NotifyUrl | B/S | false | 原生支付宝 |
| PaymentComplianceAcknowledged(At) | B/I | false | 支付合规确认；`/api/user/topup/info` 输出 `payment_compliance_confirmed` / `payment_compliance_terms_version`，前台钱包据此锁定邀请奖励入口 |

### 6. 订阅与 Coding Plan

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| SubscriptionEnabled | B | false | 订阅功能总开关 |
| SubscriptionResetDay | I | 1 | 月度重置日 |
| CodingPlanRequireSubscription | B | false | 使用 Coding Plan 是否要求有效订阅 |
| CodingPlanRatioVerifyEnabled | B | true | 折算比率自动校对总开关（`coding-plan:verify-ratios`，每 6 小时） |
| CodingPlanRatioStaleDays | I | 7 | 比率核对窗口（天）；超过该天数未人工复核的比率在公开介绍页/管理端标记「待复核」 |

### 7. 模型与可靠性（models）

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| RetryTimes / MaxRetryTimes | I | 0/3 | 重试次数 |
| AutomaticDisableChannelEnabled / AutomaticEnableChannelEnabled | B | false | 渠道自动禁用/启用 |
| ChannelDisableThreshold / ChannelTestTimeout | I | 5/30 | 渠道阈值 |
| AutomaticDisableKeywords / AutomaticDisableStatusCodes / AutomaticRetryStatusCodes | S | '' | 预留（禁用规则） |
| ChannelAffinityEnabled / ChannelAffinityExpireMinutes(I=60) | B/I | false | 渠道亲和开关 / 键 TTL（分钟）；开启后 Distributor 在转发成功（<400）时回写 `channel_affinity:{user_id}:{group}:{model}` |
| GroupModelRatioEnabled / ModelRatioSetEnable / AutomaticModelRatioEnabled | B | false | 倍率策略 |

### 8. 安全与限流（security / request-limits）

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| ModelRateLimitEnabled | B | false | 模型限流总开关（**中间件已消费**） |
| ModelRateLimitCount | I | 60 | 窗口内默认上限 |
| ModelRateLimitDuration | I | 60 | 窗口时长（分钟） |
| ModelRequestRateLimitSuccessCount / ModelRequestRateLimitGroup | I/S | 10/'' | 预留（new-api 语义） |
| SensitiveWordEnabled | B | false | 敏感词主开关（CheckSensitiveEnabled→别名，**Round 17 起 Relay 链路已消费**） |
| SensitiveWords | S | '' | 敏感词列表（换行/中英文逗号/分号分隔，大小写不敏感；写入经运行时 memo 即时生效） |
| CheckSensitiveOnPromptEnabled | B | false | 检查用户请求内容：命中即 400 `sensitive_word_detected`（预扣之前 → 不扣费不写消费日志不调上游；非流式+流式皆覆盖，流式在 SSE 头发出前返回 JSON） |
| StopOnSensitiveEnabled | B | false | 检查上游响应内容：非流式命中替换为 400（上游成本已产生，计费照常）；流式命中截断输出并下发错误事件。仅扫描 JSON 文本字段（text/content/prompt/input/system），b64_json/image_url 等媒体键天然跳过 |
| GlobalApiRateLimit / GlobalWebRateLimit | I | 180/60 | 全局限流（Round 14.5 起限流窗口计数已缓存驱动无关，file/redis 皆可用——修复对 Redis `ttl()` 的硬依赖） |
| IPRateLimitEnabled / IPRateLimitCount / IPRateLimitDuration | B/I | false | IP 限流 |
| CriticalRateLimit / SearchRateLimit / EmailVerificationRateLimit | I | 3/30/3 | 各场景限流 |

### 9. 控制台内容（content）

| 键 | 类型 | 默认 | 说明 |
|----|------|------|------|
| console_setting.api_info_enabled / api_info(J) | B/J | true | API 信息页 |
| console_setting.announcements_enabled / announcements(J) | B/J | true | 控制台公告 |
| console_setting.faq_enabled / faq(J) | B/J | true | FAQ |
| console_setting.uptime_kuma_enabled / uptime_kuma_groups(J) | B/J | false | Uptime Kuma |
| DrawingEnabled / MjAccountFilterEnabled / MjActionCheckSuccessEnabled / MjForwardUrlEnabled / MjModeClearEnabled / MjNotifyEnabled(→MJNotify) | B | false | 绘图/MJ |
| DataExportEnabled / DataExportInterval(I=5) / DataExportDefaultTime | B/I/S | false | 预留（数据导出） |
| FriendLinks(J) / Chats | J/S | []/[] | 友链 / 聊天入口 |

### 10. 预留 / 回显（后端暂未消费）

`WorkerUrl / WorkerValidKey / WorkerAllowHttpImageRequestEnabled`、`performance_setting.*`（磁盘缓存/资源监控）、`perf_metrics_setting.flush_interval/bucket_time`、`fetch_setting.*`（SSRF）、`token_setting.max_user_tokens`、`model_setting.*`、`EmailAliasRestrictionEnabled`、`LinuxDOMinimumTrustLevel`、`CustomCallbackAddress` 等。

> 这些键**可正常保存与回显**，但修改不会影响后端行为。功能落地时请将消费者切换到对应键并更新本表（敏感词四键已于 Round 17 转入消费，见 §8）。

---

## 设置变更验证 SOP

1. **保存**：管理后台对应分区修改 → 保存。若 toast 提示“部分设置项未被后端识别，已跳过”，说明该键未对齐（检查 ALIASES/EXTENSION_PREFIXES）。
2. **回读**：`curl -s -H "Authorization: Bearer <root-token>" http://localhost/api/option/ | jq '.data."<键名>"'`，确认别名键与规范键值一致。
3. **行为验证**：按键的“消费”列验证前台行为；显示类键（QuotaPerUnit/汇率/显示方式/名称/Logo）前端会自动失效 `status` 缓存。
4. **直接改库时**：需 `php artisan cache:clear` 或调用 `OptionService::clearCache()`。

---

## 开发者：如何新增设置键

1. **后端**：`OptionService::DEFAULTS` 增加键与默认值，并按类型加入 `BOOL_KEYS`/`INT_KEYS`/`FLOAT_KEYS`/`JSON_KEYS`（密钥加入 `SECRET_KEYS`）。
2. **前端命名不一致时**：在 `ALIASES` 增加 `'前端键' => '规范键'`（数据库只存规范键）。
3. **点号组且后端暂无消费者**：将前缀加入 `EXTENSION_PREFIXES`，并在「预留/回显」表登记。
4. **消费**：业务代码统一 `OptionService::get('键', 默认值)`，不要直连 `Option` 模型。
5. **文档**：更新本文档对应表，并在 [运维手册](operations-guide.md) 补充操作影响。

