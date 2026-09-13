# 分步指南 02：Coding Plan 池从零到上线（Coding Pool A→Z）

> 场景：你手里有若干「订阅制上游账号」（如 DeepSeek/X.ai/Glm Coding Plan——按次或按积分计费、带 5h/周/月用量窗口），想把它们池化成对外售卖的 OpenAI 兼容 API。
> 操作入口：管理后台 → **Coding Plan**（`/admin/coding-plan`）；所有管理 API 挂在 `/api/coding_plan/*`（Root 鉴权）。
> 概念区分：**供应商 Vendor**＝上游产品线（一个官方站）；**账号池 Account**＝你购入的具体账号（含 Key 与配额窗口）；**倍率 Ratio**＝把上游消耗折算成平台积分的价目。

## 第 1 步：登记供应商（Vendor）

管理后台 → Coding Plan → **供应商** → 新增（或 `POST /api/coding_plan/vendors`）：

| 字段 | 必填 | 说明 |
|---|---|---|
| code | ✅ | 唯一标识（`deepseek`/`zhipu`/`xai`…），账号池与比率表都引用它 |
| name | ✅ | 显示名 |
| billing_mode | ✅ | **1=按次提交**（每次调用固定扣减）**2=按积分折算**（上游用点/积分，按汇率换算）|
| plan_kind | ✅ | 1=订阅制 Coding Plan；2=按量 Token Plan |
| unit_name | 按积分时填 | 计数单位显示名（次/点/积分）|
| unit_exchange_rate | 按积分时填 | 上游 1 单位 = 平台多少积分 |
| currency | 建议 | 官方计价币种（ISO 4217，缺省 CNY；官方价是 USD 就填 USD）|
| docs_url / pricing_source_url | 建议 | 官方文档与价目页——**这是同步管线的抓取源**；填了才能自动比对 |
| logo / status / sort / remark | 可选 | 展示与排序；status 1=启用 |

> 系统已预置 15 家厂商目录（deepseek/minimax/moonshot/xai/zhipu/tencent/baidu/siliconflow/volcengine/cmcc/scnet/unicom/anthropic/openai-token/google-token），一般只需核对启用，不必从零新增。

## 第 2 步：录入账号池（Account）

供应商下 **新增账号**（`POST /api/coding_plan/accounts`）：

| 字段 | 必填 | 说明 |
|---|---|---|
| vendor | ✅ | 上面登记的 code |
| account_name | ✅ | 账号备注名（如 `ds01-主力`）|
| api_key | ✅实际使用 | 上游账号的 API Key（转换 API 中继时用它请求上游）|
| base_url | 按需 | 上游网关地址（留空走该厂商默认）|
| billing_mode / unit_name / unit_exchange_rate | 可覆盖 | 留空继承供应商默认 |
| quota_5h / quota_weekly / quota_monthly | ✅ | 三窗口配额（上游套餐限额；按积分制可小数）|
| used_5h / used_weekly / used_monthly | 初始 0 | 已用量（也可跑自动重置）|
| monthly_usage_threshold | 建议 | 月用量告警阈值百分比，默认 80 |
| priority | 默认 100 | 多账号取用顺序（小值优先）|
| expires_at | 有到期就填 | 支持 Unix 秒或日期串 |
| status | 默认启用 | 0=停用 1=启用 2=冷却 |
| channel_id / remark | 可选 | 关联渠道与备注 |

窗口语义：**5 小时滚动窗口 / 自然周 / 自然月**，由调度 `pease:reset-coding-plan` 自动重置（也可对单账号 `POST /api/coding_plan/accounts/{id}/reset_usage` 手动重置）。

## 第 3 步：同步官方源，核对目录与价目

```bash
# 全量 15 家（每 6 小时调度自动跑；手动执行）：
php artisan coding-plan:sync-official
# 只同步一家：
php artisan coding-plan:sync-official --vendor=deepseek
```

- 每次同步先落**快照**（storage + `docs/upstream-snapshots/` 镜像），再解析出「官方现价」与库内比对；
- 差异进**校对流水**（管理端 Coding Plan 页可见）：new/missing/变更**不会自动改库**，由你核对后点「应用」（`applyCatalog`）；
- **大量 pending ≠ 解析坏了**：先看明细是不是上游真扩目录（new 41/missing 15 那种就是官方大改版日），逐条人工判断；
- 在售系数比对另跑 `php artisan coding-plan:verify-ratios`（24 家，0 stale/0 change/0 failure 为健康基线），异常会写告警。

## 第 4 步：设置模型倍率（对外卖价）

供应商行 → **倍率**（`POST /api/coding_plan/storeRatio`，表 `coding_plan_model_ratios`）：为该 vendor 的每个模型定义「上游消耗 → 平台积分」的换算（含模型单价、时段折扣叠加等）。此表与第 3 步的 verify-ratios 联动——上游改价而倍率没跟上就会 stale 告警。

## 第 5 步：促销与提醒（可选）

- **促销**（`storePromotion`）：限时折扣/赠送，面向 `coding_plan/public_promotions` 公开端点展示；
- **到期提醒**：`pease:coding-plan-remind-promotions`（每日 9 点）扫描将到期账号/促销生成提醒记录（PromotionReminder）。

## 第 6 步：发布给客户（转换 API）

客户拿普通令牌（见 [03](03-setup-customers-tokens.md)）调用 OpenAI 兼容端点：

```bash
curl https://your-domain.com/v1/chat/completions \
  -H "Authorization: Bearer sk-客户令牌" \
  -d '{"model":"<池内模型名>","messages":[...]}'
```

路由顺序：显式渠道 → 智能成本路由 → **Coding Plan 池**（按 vendor→账号 priority 取用，写 `coding_plan_usage_logs`，扣三窗口配额并按倍率折算平台积分）。公开查询端点：`/api/coding_plan/offers`（在售套餐）、`/api/coding_plan/pricing_source/{code}`（价目来源）。

## 日常运维

- **账号冷却/熔断**：上游 429/失败会把账号置 2=冷却（cooldown_until 到点自动恢复，TestCooldownRecovery 31 断言保证）；
- **用量监控**：`GET /api/coding_plan/accounts/{id}/usage` 或管理页直看三窗口进度条与阈值告警；
- **健康自检**：官方源失败/快照缺失/比率 stale 都会进管理端 checks 与告警（source-health-verify 覆盖）；
- **已知边界**：P3-2 腾讯 TokenHub 官方无逐档价、P3-3 百度官方无积分系数表——这两家目前人工维护价目。
