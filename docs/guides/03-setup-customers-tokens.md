# 分步指南 03：给客户开户、发令牌、配额度（Customers · Tokens · Groups）

> 场景：渠道与 Coding 池就绪后，把服务交付给客户：开户 → 归组 → 充值/兑换 → 发令牌 → 客户用 `sk-...` 调用。
> 用户侧入口 `/dashboard`（令牌/兑换/订阅/工单），管理侧入口 `/admin/users`、`/admin/tokens`、`/admin/redemptions`。

## 第 1 步：客户注册 / 管理员代建

- **自助注册**：`/register`（受「注册与登录」设置控制：邮箱验证、邀请码等开关见 settings-reference；OAuth GitHub/Discord/OIDC/LinuxDO/微信在 `/oauth/*` 预留）；
- **管理员代建**：管理后台 → 用户管理 → 新增（代填邮箱/密码；R14 修复了注册必 500 与用户名唯一校验，旧版本请先升级）。

## 第 2 步：理解分组（Group）并给客户归组

分组是「**计费倍率 × 可见模型范围**」的载体：

- 渠道侧 `group` 决定「哪些渠道服务哪些组」；令牌侧 `group` 决定「这个令牌以哪个组计费/路由」；
- 分组倍率 `GroupRatio` 在系统设置里配（如 `default:1, vip:0.8`，vip 8 折）；
- 取值顺序：**令牌 group 优先，否则用户 group**；令牌可开 `cross_group_retry` 允许跨组重试路由；
- 角色等级：1=普通用户、10=管理员、100=Root（R14 起鉴权比较 backing value，历史 role=2 已迁移）。

## 第 3 步：给客户发令牌（API Key）

用户自助（`/dashboard/tokens/create`）或管理员代建，字段全解（`POST /api/token/`）：

| 字段 | 必填 | 说明 |
|---|---|---|
| name | ✅ | 令牌备注（客户名-用途）|
| remain_quota | 二选一 | 剩余额度（积分单位，`QuotaPerUnit` 积分=1 美元默认）|
| unlimited_quota | 二选一 | true=不限额度（内部/信任客户）|
| expired_time | 可选 | 过期 Unix 秒；-1/空=永不过期 |
| group | 可选 | 覆盖用户组（如把 vip 客户的某个令牌指到更低折扣组）|
| cross_group_retry | 可选 | 主组无可用渠道时是否允许跨组重试 |
| model_limits_enabled + model_limits | 可选 | 令牌级模型白名单（逗号分隔，max 2000）——**卖「单模型包」就用它** |
| allow_ips | 可选 | IP 白名单（逗号分隔）|
| status | 默认启用 | 1=启用 2=手动停用 |

创建后展示一次完整 `sk-...`（服务端只存摘要，丢失只能 regenerate 重置）。调试用 Playground：管理端自带 `chat/completions` 调试台（playgroundChat 路由）。

## 第 4 步：充值 / 兑换 / 订阅（三选一或组合）

| 方式 | 入口 | 说明 |
|---|---|---|
| 管理员调整余额 | 用户管理 → 调整余额 | `POST /api/users/{id}/balance`，正负均可，写资金流水 |
| 兑换码 | 管理端生成（`/admin/redemptions`），客户在 `/dashboard/redeem` 核销 | 批量生成注意 R14 修复的唯一键问题；支持额度码/订阅码 |
| 在线充值 | `/dashboard` → 充值 | 支付网关（Epay/Stripe 等）配置见 operations-guide §2；Stripe webhook 已带验签+幂等 |
| 订阅 | `/dashboard` → 订阅 | 套餐管理见 operations-guide §3；`orders:cancel-expired` 每 10 分钟取消超时单，迟到回调幂等履约 |

额度单位换算：账单 `quota` 为积分，`QuotaPerUnit`（默认 500000）积分 = 1 单位货币；多币种报价经汇率结算（currency 体系 30 断言基线）。

## 第 5 步：客户用量监控与风控

- **日志**：`/admin/logs` 按 用户/令牌/模型/渠道 过滤，`quota` 为真实计费值（R14 起），`other.cache_ratio` 记录缓存折扣（R15 起）；
- **敏感词**：`SensitiveWordEnabled` 开启后请求/响应内容检查（命中 400 不扣费；StopOn 模式可截断流式输出），词表热更新；
- **限流**：令牌级与全局（ApiRateLimit，redis 实现）；
- **余额不足**：请求前预扣 `PreConsumedQuota`（默认 500，≤0 关闭），不足直接 429 `insufficient_balance`（OpenAI 错误结构），上游失败自动退款。

## 常见问题

| 现象 | 处理 |
|---|---|
| 客户说「无可用渠道」 | 查令牌 group 与渠道 group 交集；查模型是否在 `model_limits` 白名单；查渠道是否被 auto_ban |
| 客户额度没扣/扣多了 | 核对 ModelRatio/GroupRatio 变更时间与日志时间戳；流式看 SSE usage 是否携带（上游需 include_usage）|
| 令牌泄露 | 管理端立即停用（status=2）或 regenerate；配合 allow_ips 收敛 |
| 客户要单模型包 | 建独立令牌开 `model_limits_enabled` + 白名单，归专属分组配 GroupRatio |

## 系列导航
[00 安装到初始化](00-install-to-init.md) ｜ [01 渠道·模型·Key](01-setup-channels-models-keys.md) ｜ **03 客户·令牌·分组（本篇）** ｜ 深入概念见 [usage-guide](../usage-guide.md) 与 [features](../features.md)
