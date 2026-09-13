# 分步指南 01：添加渠道、模型与 Key（Channels · Models · Keys）

> 目标：接入一个上游 AI 服务商（如 OpenAI/DeepSeek/中转站），配好模型与计费倍率，测试通过并供客户调用。
> 操作入口：管理后台 → **渠道**（`/admin/channels`）。调用入口：OpenAI 兼容 `/v1/chat/completions` 等端点。

## 第 1 步：添加渠道

管理后台 → 渠道 → **添加渠道**，关键字段（完整定义同 `channels` 表，见 `app/Models/Channel.php`）：

| 字段 | 必填 | 说明与常见填法 |
|---|---|---|
| name | ✅ | 显示名，建议 `用途-供应商-序号`，如 `gpt-主力-openai01` |
| type | ✅ | 渠道类型枚举（OpenAI / Claude / Gemini / 中转 OpenAI 兼容等；决定适配器与请求格式）|
| key | ✅ | 上游密钥；多个 key 用换行分隔可实现轮询（兼容 new-api 习惯）|
| base_url | 按需 | 上游地址；官方 API 留空走默认，中转站填其 base（如 `https://api.xxx.com`，无需拼 `/v1`）|
| models | ✅ | 该渠道可用的**模型列表**，逗号分隔（如 `gpt-4o,gpt-4o-mini,deepseek-chat`）；也可用「填入全部模型」按钮按类型预填 |
| group | ✅ | 可用分组（逗号分隔），客户令牌的 group 必须与渠道 group 有交集才能路由到 |
| priority | 按需 | 路由优先级，数字越大越先用；同模型多渠道时做主备 |
| weight | 按需 | 同优先级下的加权随机 |
| model_mapping | 按需 | 模型名重定向（JSON），如 `{"gpt-4o":"gpt-4o-2024-11-20"}`——客户用短名，上游收全名 |
| status_code_mapping | 按需 | 上游状态码改写（JSON），处理非标准错误码 |
| auto_ban | 按需 | 上游连续失败时是否自动禁用渠道 |
| param_override / header_override / setting | 按需 | 参数/请求头覆盖与高级设置（流式、代理等），默认留空 |

提交后列表出现该渠道，状态默认启用。

## 第 2 步：配置模型计费倍率

管理后台 → **系统设置 → 倍率设置**（运营选项存储，详见 settings-reference）：

| 键 | 作用 | 客户账单公式 |
|---|---|---|
| ModelRatio | 模型倍率（每 1K token 基准）| `quota=(prompt×MR + completion×MR×CompletionRatio)×GroupRatio` |
| CompletionRatio | 补全倍率 | 缺省按模型族默认 |
| CacheRatio | 缓存命中折扣（接线于 R15）| 未配置 = 1.0 不打折 |
| ModelPrice | 按次固定价（优先于倍率）| `quota=单价×QuotaPerUnit` |
| GroupRatio | 分组倍率 | 取令牌组优先，其次用户组 |

新增模型：在 ModelRatio JSON 中追加 `"deepseek-chat": 0.27` 一类的键即可；改完保存即时生效（热路径 memo + 聚合缓存自动失效）。

## 第 3 步：测试渠道连通

- 单渠道：渠道列表 → **测试**（填 test_model 或用默认）；响应时间会写回列表；
- 批量：**健康检测全部**（`/api/channel/health/all`）——对所有启用渠道发探测请求并汇总；
- 失败排查顺序：key 是否有效 → base_url 是否多写了 `/v1` → 服务器出网是否被墙（海外源参考 operations-guide §9 连通矩阵）→ 模型名是否在上游真实存在。

## 第 4 步：给渠道挂能力（Abilities）

管理后台 → **渠道能力**（`/admin/abilities`）：把「文本/绘图/音频/实时」等能力与渠道-模型绑定，供按能力路由与展示。一般文本渠道自动可推断，特殊能力（绘图/搜索/新闻聚合上游）在此显式登记。

## 第 5 步：验证端到端调用

用第 03 篇创建的客户令牌（`sk-...`）：

```bash
curl https://your-domain.com/v1/chat/completions \
  -H "Authorization: Bearer sk-xxxx" -H "Content-Type: application/json" \
  -d '{"model":"deepseek-chat","messages":[{"role":"user","content":"ping"}]}'
```

成功后：**日志**（`/admin/logs`）出现该请求，渠道 `used_quota` 与用户额度同步扣减（计费公式见第 2 步；R14 起为真实计费，R15 起含缓存折扣与预扣结算）。

## 常见报错

| 现象 | 原因 |
|---|---|
| `无可用渠道`（无 available channel）| 模型名不在任何启用渠道的 models 里，或 group 无交集，或渠道被 auto_ban |
| 429 `insufficient_balance` | 用户/令牌余额小于预扣（PreConsumedQuota 默认 500）|
| 流式计费 0 | 上游未开 `stream_options.include_usage`（OpenAI 系），换流式解析见 R14 修复记录 |
| 渠道测试 401/403 | key 失效或 base_url 路径拼错 |

## 下一步

- 上游是「订阅制账号池」（按次/按积分、5h/周/月窗口）→ [02 Coding 池](02-setup-coding-pool.md)
- 开户/发令牌/分组/额度 → [03 客户·令牌·分组](03-setup-customers-tokens.md)
