# 分步指南 04：订阅套餐·支付收款·工单客服（Subscriptions · Payments · Tickets）

> 场景：把「按量计费」升级为「订阅制套餐」（月付含额度/自动续费），接入收款（易支付已完整支持），并开启工单客服。
> 入口：管理后台 → **订阅管理**（套餐）、**支付设置**（收款）、**工单**（客服）。深度 SOP 见 [运维手册 §2-§3](../operations-guide.md)。
> 前置：已完成 [03 客户·令牌·分组](03-setup-customers-tokens.md)（订阅面向用户开通）。

## 第 1 步：创建订阅套餐

管理后台 → 订阅管理 → **新增套餐**（字段同 `subscription_plans` 表）：

| 字段 | 必填 | 说明 |
|---|---|---|
| name / description | ✅ | 套餐名与介绍（如「专业版月付」）|
| price + currency | ✅ | 售价与币种（decimal 2 位）|
| quota | ✅ | 每周期赠送额度（积分，`QuotaPerUnit` 换算 USD 面额）|
| duration + duration_unit | ✅ | 周期时长与单位（如 1 + month）|
| reset_period | 可选 | 额度重置周期（配合 subscription:reset）|
| features | 可选 | 卖点清单（数组，前端展示）|
| status / sort | 默认启用 | 上下架与排序 |
| auto_renew 支持 | 可选 | 套餐是否允许用户开自动续费 |
| stripe_price_id / creem_product_id / waffo_product_id | 预留 | 网关产品 ID 列（当前履约链路未启用，勿配）|

**Coding Plan 类型套餐**（订阅才能用某厂商池子）：`plan_type` 设为 coding 类，绑定 `coding_vendor`（厂商 code）、`coding_submits_per_request`（每次请求扣次数）、`coding_quota`（周期次数/积分额度）。配合 `CodingPlanRequireSubscription=true`（见第 4 步联动）。

> 改套餐**不影响已生效订阅**：新购买按新配置，老订阅到期自然切换。

## 第 2 步：接入易支付收款（Epay，当前唯一完整支持）

管理后台 → **支付设置**，逐项填入：

| 键 | 填什么 |
|---|---|
| `PayAddress` | 易支付提交页地址（如 `https://pay.example.com`）|
| `EpayId` | 商户 PID |
| `EpayKey` | 商户 MD5 密钥（密钥项，保存后不回显）|
| `Price` | 额度单价：充值金额 = 额度 × Price（元）|
| `MinTopUpAmount` / `TopUpMaxAmount` | 单笔限额（前端限制+后端校验）|
| `PayMethods` / `PayMethod1-4*` | 支付宝/微信等展示项 |

**首次启用前必须完成合规确认**（否则前台钱包锁定）：`POST /api/option/payment_compliance`（`acknowledged=true` 或 `confirmed=true`），写入后 `GET /api/user/topup/info` 会带 `payment_compliance_confirmed`。

**链路自检（验收清单，逐项打勾）：**

1. `GET /api/user/topup/info` 返回的支付方式列表含易支付项；
2. 小额真实下单：`topup` 表出现 `status=0`（充值，trade_no 前缀 `TU`）或 `subscription_orders`（订阅，前缀 `SE`/续费 `RENEW`），跳转支付成功；
3. 回调 `GET/POST /api/user/epay/notify` 验签 → 订单原子置 `status 0/2→1`（条件 UPDATE 幂等）→ 入账/履约；
4. **手工重放同一回调**：应返回成功且不重复入账；
5. 超时取消：`orders:cancel-expired`（每 10 分钟）把窗口外 `status=0` 置 2（窗口默认 1440 分钟，`PEASE_API_ORDER_TIMEOUT_MINUTES` 可调）；**取消 ≠ 拒付**——已取消订单收到有效签名回调仍照常履约（迟到支付兜底），无需人工补偿；
6. 余额支付（`payWithBalance`）：扣款+订单+履约一事务，失败自动回滚。

> ⚠️ Stripe/Creem/Waffo/微信 V3/支付宝：配置键已预留、`topup/info` 会展示入口，但**后端履约为占位实现，勿在生产开启**（§8 已知边界）。

## 第 3 步：订阅订单与每日重置

- **订单查询**：管理后台暂无订阅订单界面，临时 SQL：
  `SELECT id,user_id,plan_id,trade_no,status,created_at FROM subscription_orders ORDER BY id DESC LIMIT 50;`（status：0=pending / 1=paid / 2=cancelled，取消时间见 `cancelled_at`）；
- **每日重置与自动续费**：`subscription:reset`（每日 00:00，withoutOverlapping+onOneServer）依次：到期订阅置过期回收权益 → `auto_renew=1` 且余额充足扣费续期（生成 `RENEW` 前缀续费单）→ 余额不足顺延过期并通知用户；手动执行 `php artisan subscription:reset`。

## 第 4 步：订阅 × Coding Plan 联动（可选）

`CodingPlanRequireSubscription=true`：用户必须持有对应厂商（`coding_vendor`）的有效订阅，其请求才会被路由进该厂商的 Coding 池——即「**卖订阅解锁池子**」模式。配置见 [02 Coding 池](02-setup-coding-pool.md) 第 6 步。

## 第 5 步：开启工单客服

用户侧在 `/dashboard` 提交工单（subject/category/priority），管理侧自动生效：

- **列表**：`/admin/tickets`（按状态/优先级筛选）；
- **处理**：工单详情 → 回复（`POST /api/ticket/{id}/reply`，写 `ticket_replies` 并刷新 `last_reply_at`）→ 改状态（`/status`：待处理/处理中/已关闭）。

运营建议：把工单入口写进站点页脚；「无可用渠道」「余额不足」类问题在 [01 篇常见报错](01-setup-channels-models-keys.md) 与 [03 篇常见问题](03-setup-customers-tokens.md) 已有排查表，可直接复制回复模板。

## 系列导航
[00 安装到初始化](00-install-to-init.md) ｜ [01 渠道·模型·Key](01-setup-channels-models-keys.md) ｜ [02 Coding 池](02-setup-coding-pool.md) ｜ [03 客户·令牌·分组](03-setup-customers-tokens.md) ｜ **04 订阅·支付·工单（本篇）**
