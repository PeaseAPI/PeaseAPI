# PeaseAPI 使用指南

本指南详细说明 PeaseAPI 的各项功能使用方法，涵盖系统设置、渠道与模型 Key 配置、令牌管理、Coding Plan 账号池与转换 API、用户与分组、订阅与充值等。

---

## 目录

- [快速开始](#快速开始)
- [系统设置](#系统设置)
- [渠道与模型 Key 配置](#渠道与模型-key-配置)
- [令牌（API Key）管理](#令牌api-key管理)
- [Coding Plan 账号池与转换 API](#coding-plan-账号池与转换-api)
- [用户与分组管理](#用户与分组管理)
- [订阅与充值](#订阅与充值)
- [日志与监控](#日志与监控)
- [Midjourney / Suno / 视频任务](#midjourney--suno--视频任务)
- [Playground 在线调试](#playground-在线调试)
- [API 调用示例](#api-调用示例)
- [常见问题](#常见问题)

---

## 快速开始

### 1. 登录管理后台

安装完成后，使用创建的管理员账号登录：

- **用户前台**：`https://你的域名/`
- **管理后台**：`https://你的域名/admin`

### 2. 完成初始化配置

按以下顺序完成基础配置：

1. **系统设置** → 配置站点名称、通知方式
2. **添加渠道** → 填入上游 API Key
3. **设置分组与倍率** → 控制用户计费
4. **创建令牌** → 生成给用户使用的 API Key

> **设置引导教程**：管理员登录后，控制台首页会自动展开「设置引导」卡片，按
> 「添加上游渠道 → 确保模型可用 → 创建 API Key → 充值余额 → 发起首次请求」
> 的顺序逐项打勾，每一步都直达对应页面。全部完成后引导自动收起；也可以点
> 「跳过设置引导」不再显示（状态保存在服务端）。跳过后如需重新开启，可在
> 折叠卡片上点「显示设置引导」手动展开继续。

### 3. 首次调用测试

```bash
curl https://你的域名/v1/chat/completions \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}]
  }'
```

---

## 系统设置

进入 **管理后台 → 系统设置**，配置以下模块：

### 通用设置

| 配置项 | 说明 |
|--------|------|
| 站点名称 | 显示在首页和邮件中 |
| 站点地址 | 用于邮件链接和 OAuth 回调 |
| 服务器时区 | 默认 `Asia/Shanghai` |
| 备案信息 | 国内站点需填写 |

### 注册与登录

| 配置项 | 说明 |
|--------|------|
| 允许注册 | 是否开放新用户注册 |
| 邮箱验证 | 注册后需验证邮箱 |
| Turnstile 验证 | Cloudflare 人机验证 |
| 手机号注册 | 短信验证码注册（需阿里云短信） |
| OAuth 登录 | 支持 GitHub、LinuxDo 等第三方登录 |

### 令牌设置

| 配置项 | 说明 |
|--------|------|
| 令牌名称前缀 | 新建令牌自动添加前缀 |
| 默认配额限制 | 新令牌默认可用配额 |
| 允许无限令牌 | 是否允许不限额令牌 |
| 令牌有效期 | 默认过期时间 |

### 额度与计费

| 配置项 | 说明 |
|--------|------|
| 倍率精度 | 计费小数位数 |
| 最低充值金额 | 充值下限 |
| 货币单位 | 显示货币符号 |
| 兑换比例 | 美元与额度单位换算 |

### 通知设置

#### 邮件通知（SMTP）

```
SMTP 服务器: smtp.example.com
端口: 465
加密: SSL
用户名: noreply@example.com
密码: 你的邮箱密码
发件人: PeaseAPI <noreply@example.com>
```

#### 短信通知（阿里云短信）

```
AccessKey ID: 你的AK
AccessKey Secret: 你的SK
短信签名: PeaseAPI
验证码模板: SMS_xxxxx
```

---

## 渠道与模型 Key 配置

渠道（Channel）是上游 AI 服务的配置，包含 API Key、模型列表、计费倍率等。

### 添加渠道

进入 **管理后台 → 渠道管理 → 添加渠道**：

#### 基本信息填写

| 字段 | 说明 | 示例 |
|------|------|------|
| 渠道名称 | 标识用途 | `OpenAI 官方` |
| 渠道类型 | 选择对应服务商 | OpenAI / Claude / Gemini |
| Base URL | 上游 API 地址 | `https://api.openai.com` |
| API Key | 上游密钥 | `sk-xxxxxxxx` |
| 支持模型 | 填入模型名，逗号分隔 | `gpt-4o,gpt-4o-mini` |
| 模型重定向 | 模型名映射 | `gpt4→gpt-4o` |
| 分组 | 使用该渠道的用户组 | `default,vip` |
| 优先级 | 数字越大越优先 | `100` |
| 权重 | 同优先级负载均衡权重 | `1` |

#### 渠道类型支持

PeaseAPI 支持以下上游渠道类型：

**文本/对话类**：
- OpenAI（含 Azure）
- Anthropic Claude
- Google Gemini（含 Vertex AI）
- DeepSeek
- Moonshot（月之暗面）
- Mistral
- Cohere
- Groq
- 阿里通义千问（Ali）
- 火山引擎（Volcengine / 豆包）
- 中国移动 / 中国联通
- Step（阶跃星辰）

**图像/多媒体类**：
- Midjourney
- Stability AI
- 金山（Jinshan）
- Ashmoon / Sanlian / YimgCloud

**任务类（异步）**：
- Suno（音乐）
- Kling（可灵视频）
- Sora
- Vidu
- Hailuo（海螺）
- Jimeng（即梦）
- Doubao（豆包视频）
- Ali Task / Gemini Task / Vertex Task

### 配置模型计费倍率

每个渠道可为不同模型设置独立的计费倍率：

- **模型倍率**：相对基准价格的倍数（如 `gpt-4o: 2.5` 表示 2.5 倍计费）
- **补全倍率**：输出 Token 相对输入 Token 的倍数

进入 **管理后台 → 倍率同步**，可从预设模板一键导入主流模型的官方倍率。

### 渠道健康检测

PeaseAPI 内置 `ChannelHealthService`，自动检测渠道可用性：

- **自动禁用**：连续失败达阈值自动禁用渠道
- **自动恢复**：定时重试，恢复后自动启用
- **手动测试**：渠道列表点击「测试」按钮

### 渠道能力（Abilities）

系统自动维护「模型-渠道」映射表（abilities），用于请求分发：

- 进入 **管理后台 → 模型能力** 查看所有可用组合
- 当某模型对应多个渠道时，按优先级 + 权重 + 健康状态选择
- 新增/修改渠道后自动刷新能力表

---

## 令牌（API Key）管理

令牌是用户调用 API 的凭证。每个令牌可独立设置配额、模型限制、有效期。

### 创建令牌

**用户侧**：进入 **控制台 → 令牌 → 创建令牌**

| 字段 | 说明 |
|------|------|
| 令牌名称 | 便于识别 |
| 配额限制 | 该令牌可用额度（0=不限） |
| 过期时间 | 到期自动失效 |
| 允许模型 | 限制可调用的模型（留空=全部） |
| 允许 IP | IP 白名单 |
| 分组 | 覆盖用户默认分组 |

令牌格式为 `sk-xxxxxxxxxxxxxxxx`，兼容 OpenAI SDK。

### 令牌权限模型

- **普通令牌**：可调用所有允许的 API
- **只读令牌**：仅可查询用量，不可调用计费接口
- **管理员令牌**：拥有管理 API 权限

### 令牌用量监控

进入 **控制台 → 令牌**，可查看：

- 已用额度 / 剩余额度
- 调用次数
- 最近调用时间
- 详细日志（点击令牌查看）

---

## Coding Plan 账号池与转换 API

PeaseAPI 独有的 **Coding Plan 账号池**功能，可将多个 Coding 类订阅账号（如 Claude Pro/Max、Cursor 等）聚合为统一 API，支持滚动配额管理与自动切换。

### 核心概念

| 概念 | 说明 |
|------|------|
| **账号池（Pool）** | 同一供应商（vendor）的多个账号集合 |
| **供应商（Vendor）** | 账号来源，如 `claude`、`cursor` |
| **滚动窗口** | 三层配额：5 小时 / 周 / 月，到期自动重置 |
| **优先级** | 选号时优先使用高优先级账号 |
| **月使用率阈值** | 超过阈值（如 80%）时降级，优先用其他账号 |
| **自动切换** | 账号配额耗尽或过期时自动切换下一个 |

### 第一步：创建 Coding Plan 账号

**方式一：管理后台界面**

进入 **管理后台 → Coding Plan → 账号管理 → 添加账号**：

| 字段 | 说明 | 示例 |
|------|------|------|
| 供应商 | 账号来源标识 | `claude` |
| 账号名称 | 便于识别 | `claude-pro-01` |
| 关联渠道 | 调用时使用的渠道 ID | 选填 |
| API Key | 账号凭证 | `sk-ant-xxxxx` |
| Base URL | 自定义上游地址 | 选填 |
| 5h 配额 | 5 小时滚动窗口上限 | `50` |
| 周配额 | 周滚动窗口上限 | `500` |
| 月配额 | 月滚动窗口上限 | `2000` |
| 月使用率阈值 | 0-100，默认 80 | `80` |
| 优先级 | 数字越小越优先 | `100` |
| 到期时间 | 账号有效期 | `2026-12-31` |
| 状态 | 启用/禁用/已耗尽 | `启用` |
| 备注 | 说明信息 | `采购于 2026-08` |

**方式二：API 创建**

```bash
curl -X POST https://你的域名/api/coding_plan/accounts \
  -H "Authorization: Bearer 管理员令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "vendor": "claude",
    "account_name": "claude-pro-01",
    "api_key": "sk-ant-xxxxxxxx",
    "quota_5h": 50,
    "quota_weekly": 500,
    "quota_monthly": 2000,
    "monthly_usage_threshold": 80,
    "priority": 100,
    "expires_at": "2026-12-31",
    "status": 1
  }'
```

### 第二步：创建 Coding Plan 订阅套餐

将一个订阅套餐（SubscriptionPlan）绑定为 Coding Plan 类型：

```bash
curl -X POST https://你的域名/api/coding_plan/plans/{plan_id}/attach \
  -H "Authorization: Bearer 管理员令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "vendor": "claude",
    "coding_submits_per_request": 1,
    "coding_quota": 100
  }'
```

参数说明：

| 参数 | 说明 |
|------|------|
| `vendor` | 绑定的供应商（账号池） |
| `coding_submits_per_request` | 每次请求消耗的提交次数（默认 1） |
| `coding_quota` | 该套餐包含的总提交次数 |

绑定后，用户购买此套餐即获得对应 Coding Plan 额度。

### 第三步：使用转换 API

用户通过标准 OpenAI 兼容接口调用，系统自动从账号池选号并转发：

```bash
curl https://你的域名/v1/chat/completions \
  -H "Authorization: Bearer sk-用户令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet-4-20250514",
    "messages": [{"role": "user", "content": "帮我写一段 PHP 代码"}],
    "stream": true
  }'
```

**调度逻辑**：

1. 根据 Token 找到用户订阅 → 确认 Coding Plan 套餐与 vendor
2. `CodingPlanPoolService::pickAccount()` 选号：
   - 过滤禁用/过期/配额耗尽的账号
   - 优先选月使用率未超阈值的账号
   - 同条件按月使用率升序（用得少的优先）
   - 再按优先级、ID 排序
3. 调用上游 API，成功后 `recordUsage()` 原子递增计数器
4. 写入 `CodingPlanUsageLog` 流水
5. 若账号配额耗尽，标记为 `STATUS_EXHAUSTED`，下次自动切换

### 第四步：监控与运维

#### 查看账号池概览

```bash
curl https://你的域名/api/coding_plan/stats \
  -H "Authorization: Bearer 管理员令牌"
```

返回各供应商账号池的实时状态：

```json
{
  "vendors": [
    {
      "vendor": "claude",
      "total": 5,
      "active": 3,
      "exhausted": 1,
      "disabled": 1,
      "accounts": [...]
    }
  ],
  "daily_usage_7d": {...}
}
```

#### 手动重置窗口

某账号需要临时清零计数时：

```bash
curl -X POST https://你的域名/api/coding_plan/accounts/{id}/reset_usage \
  -H "Authorization: Bearer 管理员令牌" \
  -H "Content-Type: application/json" \
  -d '{"period": "5h"}'
```

`period` 可选：`5h` / `weekly` / `monthly` / `all`

#### 查看使用流水

```bash
curl "https://你的域名/api/coding_plan/accounts/{id}/usage?per_page=20" \
  -H "Authorization: Bearer 管理员令牌"
```

#### 自动重置机制

系统定时任务（每分钟执行）自动处理：

- **5h 窗口到期**：`used_5h` 清零，下次重置时间 +5h
- **周窗口到期**：`used_weekly` 清零，下次重置时间 +7 天
- **月窗口到期**：`used_monthly` 清零，耗尽账号恢复启用
- **账号过期**：标记为禁用

相关命令（可手动执行）：

```bash
php artisan coding_plan:reset-usage
```

### Coding Plan API 端点汇总

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/coding_plan/accounts` | 账号列表 |
| POST | `/api/coding_plan/accounts` | 创建账号 |
| PUT | `/api/coding_plan/accounts/{id}` | 更新账号 |
| DELETE | `/api/coding_plan/accounts/{id}` | 删除账号 |
| POST | `/api/coding_plan/accounts/{id}/reset_usage` | 重置计数器 |
| GET | `/api/coding_plan/accounts/{id}/usage` | 使用流水 |
| GET | `/api/coding_plan/plans` | Coding Plan 套餐列表 |
| POST | `/api/coding_plan/plans/{id}/attach` | 绑定套餐到账号池 |
| POST | `/api/coding_plan/plans/{id}/detach` | 解绑套餐 |
| GET | `/api/coding_plan/stats` | 全局统计概览 |
| GET | `/api/coding_plan/offers` | **公开**抵扣介绍数据（无需登录，缓存 300s） |
| GET | `/coding-plan` | **公开**抵扣产品介绍页（Blade，展示各厂商折算规则与校对状态） |

### 抵扣介绍页与比率自动校对

供应商（如火山引擎）常在新增模型时推出限时优惠，管理员手工维护的折算比率可能过时。
平台通过以下机制把偏差风险降到最低（**只发现偏差、不自动改价**——改错比率等于资损，变更须人工确认）：

1. **公开介绍页 `/coding-plan`**：展示每个供应商的两级折算口径（模型 → 供应商单位 → 平台积分）、
   启用中的比率表（含「已收录 N 个模型」徽章）、最近校对时间与「待复核」标记，并在页首明示
   「选择模型提供商即自动预载官方套餐档位与现有模型清单、随官方同步定期更新」。数据与
   `GET /api/coding_plan/offers` 同源（缓存 300s，比率/供应商任何写操作即时失效）。
2. **每 6 小时自动校对 `php artisan coding-plan:verify-ratios`**（可在 `CodingPlanRatioVerifyEnabled=false` 关闭）：
   - **stale 检测**：启用比率超过 `CodingPlanRatioStaleDays`（默认 7 天）未人工复核 → 公开页与管理端
     （Coding Plan 积分管理 → 折算比率，「待复核」徽标）标记；
   - **定价源 diff**：在「供应商」管理中为厂商配置 `pricing_source_url`（可选）后，校对任务每 6 小时拉取该
     JSON 并与比率表比对，发现**新增模型 / unit_cost 变化 / 模型下架**写入 `coding_plan_ratio_checks.changes`，
     公开页显示「N 处上游变更待确认」。管理员确认后在控制台手工更新比率。
3. 校对流水保留 90 天，可随时 `php artisan coding-plan:verify-ratios` 手动执行。

定价源约定格式（HTTP 200 + JSON，`match_type` 缺省 `exact`，非法条目自动跳过）：

```json
{
  "models": [
    {"model": "doubao-lite-32k", "unit_cost": 0.5, "match_type": "exact"},
    {"model": "doubao-pro-32k", "unit_cost": 1, "cost_mode": "per_request"}
  ]
}
```

### 预置厂商目录与产品类型

供应商分两类产品形态（`plan_kind`，公开介绍页按此分组展示）：

| plan_kind | 类型 | 计费方式 | 预置厂商（迁移 `2026_09_11_000002`，默认**停用**） |
|---|---|---|---|
| 1 | 订阅制 Coding Plan | 包月/包量，按请求次数或资源点折算（比率 `cost_mode=per_request`） | 火山引擎、中国联通、中国移动、智谱 GLM |
| 2 | 按量 Token Plan | 按 token 用量折算扣减（比率 `cost_mode=per_1k_tokens`） | 阿里云百炼、腾讯混元（TokenHub）、百度千帆、火山方舟（按量）、DeepSeek 开放平台、Moonshot Kimi；后续迁移新增：联通 Token Plan（`unicom-token`）、腾讯 TokenHub 企业版（`tencent-team`）、移动 Token Plan 个人版/团队版（`cmcc-token`/`cmcc-token-team`） |

接入新厂商的标准流程（控制台 → Coding Plan 积分管理）：

1. **供应商**：预置行已存在但停用，点「编辑」→ 设置**汇率**（供应商单位 → 平台积分）与定价源 URL（可选）→ 状态改「启用」；预置目录外的厂商直接「新增供应商」。
2. **折算比率**：预置了主流模型族的前缀模板（`doubao-`/`qwen-`/`hunyuan-`/`ernie-`/`glm-`/`kimi-`/`deepseek-` 等，
   均为**停用**状态、`unit_cost=1` 占位），逐条核对价格后启用；模板未覆盖的模型按「新增比率」补充。
3. **账号**：添加该厂商的上游账号（ billing_mode 建议保持「按积分折算」，让比率表生效），绑定渠道后即可参与调度。
4. 两种产品形态共用同一套账号池、订阅校验（`subscription_plans.plan_type=coding_plan` + `coding_vendor`）与介绍页展示，
   引擎无需区分——差异全部由比率表 `cost_mode` 表达。

> ⚠️ 预置行不携带任何真实价格（错误价格 = 资损）：启用前必须自行核对 `unit_exchange_rate` 与各比率 `unit_cost`。

#### 官方套餐档位与分段折算标准（迁移 `2026_09_11_000003`）

**套餐档位（`coding_plan_vendor_tiers`，管理端「套餐档位」页维护）**：记录各厂商官方订阅档位（个人版/团队版/坐席/用量包），
公开介绍页按厂商展示（仅 `status=1`），价格留空表示以官网为准。预置数据（2026-09 据官方文档核对）：

| 厂商 | 档位 | 价格 | 额度 |
|---|---|---|---|
| 阿里云 Token Plan（个人版） | Lite / Standard / Pro | 限时 ¥39 / ¥139 / ¥499 每月 | 1 万 / 4 万 Credits（7 天限额 2500 / 10000）；Pro 无 7 天限额 |
| 阿里云 Token Plan（个人版） | 用量包 | ¥100/个/月 | 2 万 Credits（最多同时持有 5 个，需有效订阅） |
| 阿里云 Token Plan（团队版） | 标准座席 / 高级座席 / 尊享座席 | ¥150 / ¥550 / ¥1398 每座席每月 | 2.5 万 / 10 万 / 25 万 Credits（月总额度制，无 7 天窗口） |
| 阿里云 Token Plan（团队版） | 共享用量包 | ¥5000/个/月 | 62.5 万 Credits（跨坐席共享，有效期 1 个月） |
| 智谱 GLM Coding Plan | 个人版 Lite / Pro / Max | 以官网为准 | 每 5 小时 2000 / 12000 / 28000 积分；每周 1 万 / 6 万 / 14 万 |
| 智谱 GLM Coding Plan（团队版） | 标准版 / 高级版（每席位，2 席位起购） | 以官网为准（售前咨询） | 每席位每 5 小时 15000 / 35000；每周 66000 / 155000；超额可按量（API 刊例 9 折） |
| 腾讯 TokenHub（迁移 `000004` 预置、`000007` 补系数） | 通用 Lite/Standard/Pro/Max | ¥39 / ¥99 / ¥299 / ¥599 每月 | 780 / 1980 / 5980 / 11980 积分每订阅月（新逻辑模型三率积分价已预置，见下文） |
| 腾讯 TokenHub（迁移 `000004` 预置、`000007` 补系数） | Hy Lite/Standard/Pro/Max | ¥28 / ¥78 / ¥238 / ¥468 每月 | 560 / 1560 / 4760 / 9360 积分每订阅月（混元 Hy3/Hy4 专用） |
| 腾讯 TokenHub 企业版（迁移 `000007` 预置） | 专业套餐 · 自定义积分 | ¥500 每月起（刊例 5 万积分/月，1 积分=0.01 元官方明示） | 最低 5 万积分/月，支持按月自定义（周期末未用作废；另有轻享套餐自定义 Token 池 ≥5,000 万 tokens/¥100 每月，1:1 抵扣） |
| 火山方舟 Agent Plan（迁移 `000005` 预置） | Small / Medium / Large / Max | ¥40 / ¥200 / ¥500 / ¥1000 每月 | 2 万 / 10 万 / 25 万 / 50 万 AFP（周额度 7000/35000/87500/175000；Small 限时活动价 ¥9.9 起） |
| 百度千帆 Token Plan（迁移 `000005` 预置、`000007` 补折算） | 个人版 Mini / Lite / Pro / Max | ¥9.9 / ¥40 / ¥200 / ¥600 每月（首购五折 4.9/19.9/99.9/299.9 限量秒杀） | 双轨同价：Token 制 1000 万 / 4200 万 / 2.3 亿 / 7 亿 tokens ⇄ 1400 / 6600 / 4.5 万 / 16.5 万 积分每订阅月（Token 制 1:1 生效，积分制官方「即将支持」） |
| 联通 Coding Plan（迁移 `000006` 预置） | Lite / Pro | ¥40 / ¥200 每月 | 18,000 / 90,000 次请求每订阅月（按模型调用次数扣减；另有 5 小时/周限额） |
| 联通 Token Plan 个人版（迁移 `000006` 预置） | Lite / Pro / Max | ¥15 / ¥30 / ¥45 每月 | 600 / 1,200 / 1,800 万 tokens（1:1 扣减；仅 V4-Flash / M2.5） |
| 联通 Token Plan 团队版（迁移 `000006` 预置） | Lite / Pro / Max | ¥198 / ¥698 / ¥1398 每月 | 25,000 / 100,000 / 250,000 credits（≈1 credit=0.01 元；仅贵阳二区，独享 V4-Pro） |

**分段折算（`cost_mode=per_token_parts`）**：真实上游（智谱 GLM、阿里云百炼）按「输入 / 缓存命中 / 输出」三段独立系数计量，
比率表统一存储为**每千 token 系数**：

```
units = (输入 token × input_rate + 缓存命中 × cached_rate + 输出 token × output_rate) / 1000
```

- 智谱官方公式：积分 =（入×6.9 + 缓存×1.7 + 出×24）/ 10000 → 存为 `glm-5.3`：`0.69 / 0.17 / 2.4`（÷10 换算到千 token）；
  `glm-5.3-flash`：`0.23 / 0.056 / 0.8`。非高峰时段（工作日 14:00–18:00 以外）官方按 50% 折扣计收，
  已通过 `time_discounts` 时段窗口预置（见下），引擎按计费时刻自动套折扣。
- 阿里云官方示例 `qwen3.6-plus`：输入 8349 token → 1.67 Credits、缓存 40794 → 0.82、输出 573 → 0.69，
  对应 `0.2 / 0.02 / 1.2`（千 token 口径，可与官方示例逐段对账）。
- 缓存命中与输入**分开计量**（智谱与阿里官方口径一致）；OpenAI 协议下 `cached_tokens` 是 prompt 子集，
  命中段会同时计入输入与缓存两段（对平台保守，不产生资损）。
- 三段系数全为 0 视为配置缺失，自动回退按次计费（防止静默按 0 扣减）。
- 预置分段标准行默认**停用**（`status=0`，remark 前缀「官方折算标准」），管理员核对 `unit_exchange_rate` 后启用。
- `coding-plan:verify-ratios` 定价源 diff 支持 `per_token_parts` 条目：源中携带
  `cost_mode:"per_token_parts"` + `input_rate`/`cached_rate`/`output_rate`（`unit_cost` 可省略），三率任一变化都会计入「待确认变更」。
- **分时段折扣自动化**（迁移 `2026_09_12_000009`）：比率行 `time_discounts` 字段存时段窗口数组，
  引擎按**计费时刻**（请求完成时刻）自动命中并乘到 units 上，未配置/未命中 = 原价。窗口格式：
  ```json
  [{"name": "夜间5折", "days": [1,2,3,4,5,6,7], "start": "22:00", "end": "08:00", "discount": 0.5}]
  ```
  - `days`：ISO 周几 1–7（1=周一，省略=每天）；`start`/`end`："HH:MM"，**end < start 表示跨零点**（如夜间 22:00–08:00）；
  - `discount`：折扣乘数（0.5=半价），服务端强制位于 (0,1)；规则按顺序匹配，首条命中生效；
  - 已预置官方窗口：**智谱** glm-5.3/flash（工作日 14:00–18:00 以外 5 折）、**DeepSeek** flash/v4-pro（高峰=周一至五 9:00–12:00、14:00–18:00，其余减半）；
    阿里云夜间 5 折指定模型（qwen3.8-max 等，模板未预置三率）由管理员录入行后在比率表配置；
  - 管理端「折算比率」表单可编辑窗口 JSON（列表有「时段」徽标），定价源条目携带 `time_discounts` 时官方调整折扣也会进入待确认变更（kind=`time_discounts`）；
  - 计费流水 `meta` 快照记录 `time_discount`/`time_window`，可审计每笔是否打折。

> 阿里云 Token Plan 与火山方舟 Agent Plan 分别以 Credits / AFP 统一计量，虽然按月订阅，但其扣减语义是
> 「按用量折算 Credits/AFP」，因此归入 plan_kind=2（按量 Token Plan）、计数单位为 Credits/AFP；
> 火山引擎 Coding Plan / 联通 Coding Plan / 移动 Coding Plan / 智谱的订阅制资源包维持 plan_kind=1
> （联通 Token Plan、腾讯 TokenHub 企业版、移动 Token Plan 个人版/团队版等按用量折算的产品线为 plan_kind=2）。

#### 官方模板目录与定时同步工作流（迁移 `2026_09_11_000004`）

为解决「各厂商分企业版/团队版/个人版、抵扣比率各不相同、官方随时改价上下架模型」的维护难题，
系统内置**官方模板目录**（`App\Services\CodingPlanCatalog`，随代码发布维护的单一事实源）+
**定时校对确认工作流**（`coding-plan:verify-ratios` 每 6 小时 + 管理端「官方同步」页）。

**模板目录**（`GET /api/coding_plan/catalog`，2026-09-11 据官方文档核对）：

| 模板 code | 厂商 / 产品线 | plan_kind | 官方单位 | 档位 | 折算标准 | 核对状态 |
|---|---|---|---|---|---|---|
| `aliyun` | 阿里云百炼 Token Plan（个人+团队） | 2 | Credits | 8 | 1（qwen3.6-plus 分段） | ✅ 官方文档核对 |
| `zhipu` | 智谱 GLM Coding Plan（个人+团队） | 1 | 资源点 | 5 | 2（glm-5.3 / flash 分段） | ✅ 官方文档核对 |
| `tencent` | 腾讯云 TokenHub Token Plan（通用+Hy） | 2 | 积分 | 8 | 14（新逻辑 6 exact + Auto + Hy 系 3 + 旧逻辑 prefix 4；官方三率积分价 ÷1000） | ✅ 官方文档核对（1823/133811，2026-09-10 更新） |
| `tencent-team` | 腾讯云 TokenHub Token Plan 企业版（专业套餐） | 2 | 积分 | 1 | 20（广州区全量 Model ID 积分价；DeepSeek 取高峰价，空闲/新加坡差异见模板注释） | ✅ 官方文档核对（1823/130659，1 积分=0.01 元官方明示） |
| `deepseek` | DeepSeek 开放平台（纯 API 按量） | 2 | 元 | 0 | 2（高峰口径分段参考价） | ✅ 官方文档核对 |
| `volcengine-ark` | 火山方舟 Agent Plan（Small/Medium/Large/Max） | 2 | AFP | 4 | 13（官方 AFP 系数 2026-09-01 起，含 auto 活动系数） | ✅ 官方文档核对 |
| `moonshot` | Moonshot Kimi 开放平台（纯 API 按量） | 2 | 元 | 0 | 4（kimi-k3 / k2.7-code(-highspeed) / k2.6 分段） | ✅ 官方文档核对 |
| `baidu` | 百度千帆 Token Plan（Mini/Lite/Pro/Max） | 2 | 积分 | 4 | 4（Token 制 1:1：deepseek-/glm-/kimi- 前缀 + deepseek-v4-pro-0813 exact 1.8 倍抵扣；积分制官方「即将支持」不预置） | ✅ 官方文档核对（doc/qianfan/s/Dmrabu8b6，2026-09-10 更新） |
| `volcengine` | 火山引擎 Coding Plan（Lite/Pro） | 1 | 点 | 2 | 3（前缀占位） | ⚠️ 额度数值官方页未公布，价格以购买页为准 |
| `unicom` | 联通 Coding Plan（Lite/Pro） | 1 | 次 | 2 | 6（per_request 1:1：auto 路由 exact + 5 个模型前缀） | ✅ 官方文档核对（按调用次数扣减） |
| `unicom-token` | 联通 Token Plan（个人版+团队版） | 2 | 千token | 6 | 5（个人版 1:1 前缀兜底 + 团队版 credits exact 0.93/0.07/0.11） | ✅ 官方文档核对（团队版系数由官方线性示例导出） |
| `cmcc` | 移动 Coding Plan（Lite/Pro） | 1 | 次请求 | 2 | 2（per_request 1:1：MiniMax-M2.5 + Auto 路由 cm-code-latest，每请求扣 1 次） | ✅ 官方文档核对（ART 98320/98337/98322，CMS API 逆向抓取） |
| `cmcc-token` | 移动 Token Plan 个人版（算力豆计量） | 2 | 算力豆 | 11 | 11（豆/千 token=1000÷官方兑换率 exact：GLM-5.1 0.6667 → V4-Flash 0.0909 → Auto 0.1，全量 token 统一折算不分段） | ✅ 官方文档核对（ART 100224） |
| `cmcc-token-team` | 移动 Token Plan 团队版（折算 tokens 计量） | 2 | 千折算tokens | 2 | 12（官方系数 N 全量 exact：MiniMax-M2.5 1 → Kimi-K2.7-code 7 → Qwen3.7-Max 10；unit_cost 即 N） | ✅ 官方文档核对（ART 99471） |

> 迁移 `2026_09_11_000005/000006/000007/000008` 将上述新核对的官方数据幂等落地到预置厂商（`volcengine-ark` 更名
> 「火山方舟 Agent Plan」、单位修正为 AFP/元/积分；联通由壳模板升级为全量档位 + 折算标准；腾讯补全个人版/
> 企业版逐模型积分价并新增 `tencent-team` 厂商，百度补全 Token 制 1:1 折算与双轨额度文案；移动由壳模板升级为
> Coding Plan 2 档 + Token Plan 个人版 11 档（新增 `cmcc-token` 拆分）+ 团队版 2 档（新增 `cmcc-token-team` 厂商，
> 折算 tokens 与算力豆两种计量口径分开建厂商防汇率混用））。全量比率行仍为
> `status=0`，启用流程不变。腾讯旧预置占位行 `hunyuan-` 与百度 `ernie-` 前缀在官方 TokenHub/千帆 Token Plan
> 模型清单中已无对应产品（status=0 无风险），可由管理员自行清理；百度旧占位行 `deepseek-` 已被 `000007`
> 就地升级为官方口径（unit_cost=1 与官方 1:1 一致）。

**应用模板（`POST /api/coding_plan/catalog/{code}/apply`）是幂等的**：新建厂商一律停用态；
档位/折算标准按模板预置（折算标准全部 `status=0`）；重新应用只更新仍是「官方档位/官方折算标准」备注的行，
**不覆盖管理员改过的数据、不翻转 status（已启用的行不会被关闭）**。`body: {"activate_vendor": true}`
可顺带启用厂商（仍要求自行设置 `unit_exchange_rate` 并启用比率，否则不会实际计费）。

**五步工作流（管理端「官方同步」页全流程可视）**：

1. **添加**：从模板目录点「从模板添加」（或「添加并启用厂商」）→ 厂商 + 全部官方档位 + 折算标准一键落地；
2. **核价启用**：为厂商设置 `unit_exchange_rate`（供应商单位 → 平台积分汇率），核对折算标准后逐条启用；
3. **定时监测**：调度任务每 6 小时运行 `coding-plan:verify-ratios`（开关 `CodingPlanRatioVerifyEnabled`），
   对配置了 `pricing_source_url` 的厂商拉取结构化定价源做 diff，检测**新增模型（new）/ 抵扣率变化（changed，
   含 unit_cost 与三段系数两种口径）/ 老模型下架（missing，源中消失的启用行）**，写入校对流水
   `coding_plan_ratio_checks`（`changes` + 固化键 `pending_keys`）；stale 检测（默认 7 天未复核）并行进行；
4. **确认**：管理端「官方同步」页展示每厂商待确认清单，逐条「应用」——changed → 按源改价；
   new → 按源新增停用行；missing → 老模型停用（保留历史）。应用后下次 diff 自然消失，无需手工消单；
5. **忽略**：不认可定价源的变更点「忽略」（键存 option `CodingPlanRatioIgnoreKeys`，仍展示但标已忽略、
   不计入待确认数），随时可「恢复提醒」。

**定价源 JSON 约定**（`pricing_source_url`，HTTP 200 + JSON，支持 `{"models":[...]}` 包裹或直接数组）：

```json
{"models":[
  {"model":"glm-5.3","match_type":"exact","cost_mode":"per_token_parts",
   "input_rate":0.69,"cached_rate":0.17,"output_rate":2.4},
  {"model":"doubao-pro","unit_cost":0.5}
]}
```

无法自建定价源的厂商，可把 `pricing_source_url` 指向任何能反映官方价格的 JSON 端点；
diff 只报告不落库，全部变更须管理端人工确认（改错比率等于资损）。命令行支持
`php artisan coding-plan:verify-ratios --vendor=zhipu,aliyun` 单独校对指定厂商。

**内置官方定价源**（无需自建 JSON）：`GET /api/coding_plan/pricing_source/{厂商code}` 把模板目录
（`App\Services\CodingPlanCatalog`，随代码发布维护的单一事实源）的折算标准发布为上述约定的 JSON。
管理端「供应商」编辑框点「使用内置官方源」即可填入 `{站点地址}/api/coding_plan/pricing_source/{code}`。
工作方式：官方改价 → 更新目录并随代码发布 → 6 小时内校对生成待确认变更；管理员手工改动预置行
（与目录不一致）同样会被检出。注意：diff 只对比**启用**的比率行，请先启用再配置（否则未启用模型
会持续以「新增」出现）；当前全部 14 个模板均已内置折算标准（占位性质的行以模板注释为准）。

**各厂商官方口径速查（2026-09-11 抓取，改价以上游为准 —— 模板与监测的依据）**：

| 厂商 | 版本差异 | 抵扣口径要点 | 时段折扣 | 模型上下架信号 |
|---|---|---|---|---|
| 阿里云百炼 | 个人版（3 档+用量包）/ 团队版（3 座席档+共享包）；同主体限购 1 份个人版，可同时购团队版 | Credits 统一计量，官方未公布逐模型系数表，以控制台用量详情为准；官方示例 qwen3.6-plus 0.2/0.02/1.2 可对账 | 夜间 22:00–08:00 指定模型（qwen3.8-max、deepseek-v4-pro-0813、deepseek-v4-flash-0731）5 折——模板未预置这三行，录入后配 `time_discounts`（22:00→08:00 跨零点写法）自动生效 | qwen3.8-max-preview 已下线并自动路由到 qwen3.8-max |
| 智谱 GLM | 个人 Lite/Pro/Max + 团队标准/高级（2 席位起购，超额按 API 刊例 9 折） | 每万 token 系数：GLM-5.3 = 6.9/1.7/24、GLM-5.3-Flash = 2.3/0.56/8（÷10 存千 token）；MCP 工具 1.2/次 | 高峰=周一至五 14:00–18:00 按 1 倍，非高峰 5 折——✅ 已预置 `time_discounts`，计费时刻自动生效；另有夜间畅用活动 | GLM-5.2/5.1 → 自动路由 GLM-5.3；GLM-5-Turbo/4.7 → Flash |
| 腾讯 TokenHub | 个人版通用（多模型）+ Hy（混元专用）各 4 档；企业版专业套餐自定义积分 / 轻享套餐自定义 Token 池；每主账号各 1 个、仅升配、不退订 | 积分制（2026-08-31 起）：新逻辑模型三率积分价（积分/百万 tokens）glm-5.3 160/40/560、glm-5.3-flash 16/4.6/56、kimi-k3 400/40/2000、kimi-k2.7-code 130/26/540、minimax-m3 42/8.4/168（>512k 翻倍）、hy4-preview 120/6/360；Auto/旧逻辑模型与 hy3 按档位统一价（模板取 Standard 口径）；企业版 1 积分=0.01 元（广州区另表） | 9 月限时活动（至 9/30）：tc-code-latest 11/11/11、kimi-k2.7-code 65/270/13、minimax-m3 21/84/4.2、hy4-preview 100/200/4、glm-5.3 136/476/34、hy3-202608 10/40/2.5、kimi-k3 380/1900/38（95 折）——不自动参与引擎计费 | glm-5 / glm-5.1 / glm-5-turbo 公告 2026-10-09 下线；deepseek-v4-flash 2026-09-29 下线（个人版）；模型库动态更新以公告为准 |
| 百度千帆 | 个人版 Mini/Lite/Pro/Max 双轨同价（Token 制额度 1000 万/4200 万/2.3 亿/7 亿 tokens ⇄ 积分制 1400/6600/4.5 万/16.5 万）；企业版（席位+共享积分包）价格未公布 | Token 制 1:1 扣减（当前生效，不区分输入/输出/缓存）；deepseek-v4-pro-0813 按 1.8 倍抵扣（仅 Token 制）；积分制「即将支持」，逐模型系数未公布（仅示例 V4-Pro 输入 853 tokens≈1 积分） | 指定模型闲时低至 0.5 折 | deepseek-v4-flash（-0731）与 kimi-k2.6 公告 2026-09-29 下线 |
| DeepSeek | 无套餐档位，充值余额直接按量扣费 | 元/百万 token：flash 输入未命中 2（命中 0.04）输出 8；v4-pro 9（0.30）/27 | 高峰=周一至五 9:00–12:00、14:00–18:00，空闲全部减半——✅ 已预置 `time_discounts`，计费时刻自动生效 | deepseek-v4-flash 等旧名自动路由 flash 并按 Flash 价计费 |
| 火山引擎 | Agent Plan（AFP 抵扣+超额后付费）与 Coding Plan 双产品线 | 逐档价格/系数官方页 JS 渲染不可核证，需人工录入 | 存在「指定模型抵扣系数限时折扣」活动（临时） | 官方有「模型抵扣系数调整公告」「模型上线/下线公告」——监测重点 |
| 联通 | Coding Plan 与 Token Plan（个人版+团队版）两条产品线；不退款、仅升配 | Coding Plan 按「模型调用次数」扣减（1 次调用=1 次额度，Agent 任务 5-30+ 次；Lite 40 元 1.8 万次/Pro 200 元 9 万次每月）；Token Plan 个人版 tokens 1:1（600/1,200/1,800 万，15/30/45 元），团队版 credits≈0.01 元（25,000/100,000/250,000 credits → V4-Pro 0.93 / V4-Flash 0.07 / M2.5 0.11 每千 token） | — | 个人版仅 V4-Flash/M2.5（贵阳二区/武汉四区/广州一区），团队版独享 V4-Pro（仅贵阳二区）；高峰易限流建议切换模型 |
| 移动 | Coding Plan（Lite/Pro）+ Token Plan 个人版（月包 7 档+次包 3 档+尝鲜包）+ 团队版（Lite/标准）；不退款，Coding Plan 不可叠加、Token Plan 月包仅升配、团队版不可升降配 | Coding Plan 按「模型调用次数」扣减（每请求 1 次，仅 MiniMax-M2.5 192K，40/200 元 → 1.8万/9万 次/月）；个人版「算力豆」计量：1 豆按模型兑换 tokens（GLM-5.1 1500、V4-Flash 11000、Auto 10000 等，即豆/千 token=1000÷兑换率，5~500 元月包 → 200~35,000 豆）；团队版「折算 tokens」倍率：消耗 M 扣 M×N（N=系数，1000/5000 元 → 10 亿/55 亿折算 tokens） | 首订活动 Lite 7.9 元/Pro 39.9 元（至 2026-12-31，续订 5 折券 1 次；标准价 40/200 元） | MiniMax-M2.5/V4-Flash 资源有限易限流；官方可能不定期下调豆率/系数（以官网文档为准）；Coding Plan 严禁编程工具外 API 直调 |

> 模板落地后，公开介绍页（`/coding-plan`）仅展示 `status=1` 的档位；折算标准行需管理员
> 核对 `unit_exchange_rate` 后手动启用。官方分时段折扣（智谱非高峰 5 折、DeepSeek 空闲减半）
> 已通过 `time_discounts` 预置并**自动参与引擎计费**；其余官方折扣（腾讯 9 月限时活动、
> 阿里夜间指定模型等）仍需管理员在对应行配置窗口或下调系数后跟投。

---

## 用户与分组管理

### 用户管理

进入 **管理后台 → 用户管理**：

- 查看所有用户、余额、用量
- 修改用户余额、分组、状态
- 封禁/解封用户
- 重置密码

### 分组（Group）

分组决定用户可用的渠道和计费倍率：

1. 进入 **管理后台 → 分组管理**
2. 创建分组（如 `default`、`vip`、`enterprise`）
3. 为分组设置可用的渠道
4. 设置分组倍率（如 `vip` 组 0.8 倍计费）

用户可在控制台切换自己的默认分组（需管理员允许）。

### 角色权限

PeaseAPI 支持以下用户角色：

| 角色 | 权限 |
|------|------|
| 普通用户 | 使用 API、查看自己的数据 |
| 分销商 | 可发展下级用户 |
| 管理员 | 管理渠道、用户、系统设置 |
| 超级管理员 | 全部权限，包括部署管理 |

---

## 订阅与充值

### 订阅套餐管理

进入 **管理后台 -> 订阅管理 -> 套餐管理**：

#### 创建套餐

| 字段 | 说明 | 示例 |
|------|------|------|
| 套餐名称 | 显示给用户 | `Claude Pro 月度` |
| 套餐类型 | `quota`（按量）或 `coding_plan` | `coding_plan` |
| 价格 | 售价 | `99.00` |
| 时长 | 订阅有效期 | `30 天` |
| 配额 | 包含额度 | `100 次提交` |
| 重置周期 | 配额重置频率 | `monthly` |
| 关联供应商 | Coding Plan 专用 | `claude` |

#### 套餐类型说明

- **quota 类型**：按量计费，每次调用扣减额度
- **coding_plan 类型**：绑定 Coding Plan 账号池，按提交次数计费

### 用户订阅流程

1. 用户进入 **控制台 -> 订阅**，选择套餐
2. 选择支付方式（支付宝/微信/余额支付）
3. 支付成功后自动开通
4. 到期后自动失效或续费

### 充值（钱包）

进入 **控制台 -> 钱包**：

1. 选择充值金额
2. 选择支付方式（支付宝/微信支付）
3. 支付成功后余额自动到账
4. 余额可用于购买订阅或按量调用

### 兑换码（Redemption）

管理员可生成兑换码：

1. 进入 **管理后台 -> 兑换码管理 -> 生成**
2. 设置面额、数量、有效期
3. 分发给用户
4. 用户在 **控制台 -> 兑换** 输入码兑换

### 签到（Checkin）

进入 **控制台 -> 签到**：

- 管理员可开启每日签到奖励
- 用户每日签到获得额度
- 连续签到额外奖励

---

## 日志与监控

### 调用日志

进入 **管理后台 -> 日志管理** 或 **控制台 -> 日志**：

| 字 | 说明 |
|------|------|
| 时间 | 调用时间 |
| 用户 | 调用者 |
| 令牌 | 使用的 API Key |
| 模型 | 调用的模型 |
| 渠道 | 命中的上游渠道 |
| 提示 Token | 输入 Token 数 |
| 补全 Token | 输出 Token 数 |
| 额度 | 消耗额度 |
| 状态 | 成功/失败 |
| 耗时 | 响应时间 |

### 性能监控

进入 **管理后台 -> 性能监控**：

- QPS 趋势图
- 平均响应时间
- 错误率
- 渠道延迟对比
- 模型使用分布

### 系统信息

进入 **管理后台 -> 系统信息**：

- 服务器 CPU / 内存 / 磁盘
- PHP 版本与扩展
- 数据库状态
- Redis 连接状态
- 队列任务积压

---

## Midjourney / Suno / 视频任务

### Midjourney

PeaseAPI 兼容 Midjourney API 格式：

```bash
# 提交绘图任务
curl -X POST https://你的域名/mj/submit/imagine \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "botType": "MID_JOURNEY",
    "prompt": "a cute cat --ar 16:9"
  }'

# 查询任务状态
curl https://你的域名/mj/task/{task_id}/fetch \
  -H "Authorization: Bearer sk-你的令牌"
```

支持的 Midjourney 操作：

| 接口 | 说明 |
|------|------|
| `/mj/submit/imagine` | 文生图 |
| `/mj/submit/action` | 变换（V1/V2/U1 等） |
| `/mj/submit/modal` | 自定义变换 |
| `/mj/submit/change` | 图生图 |
| `/mj/submit/describe` | 图反推 |
| `/mj/submit/blend` | 图混合 |
| `/mj/submit/shorten` | 提示词精简 |
| `/mj/submit/video` | 生成视频 |
| `/mj/insight-face/swap` | 换脸 |
| `/mj/task/{id}/fetch` | 查询任务 |
| `/mj/task/{id}/image-seed` | 获取种子值 |

### Suno（音乐生成）

```bash
# 提交音乐生成
curl -X POST https://你的域名/suno/submit/music \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "轻快的钢琴曲",
    "make_instrumental": false
  }'

# 查询结果
curl https://你的域名/suno/fetch/{id} \
  -H "Authorization: Bearer sk-你的令牌"
```

### 视频生成

支持多种视频模型，统一 API 格式：

```bash
# 通用视频生成
curl -X POST https://你的域名/v1/video/generations \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "kling",
    "prompt": "一只猫在奔跑"
  }'

# 可灵专属接口
curl -X POST https://你的域名/kling/v1/videos/text2video \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "一只猫在奔跑",
    "duration": 5
  }'

# 即梦（透传）
curl -X POST https://你的域名/jimeng/v1/video/generate \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{ ... }'
```

支持的视频模型：

| 模型 | 接口前缀 | 说明 |
|------|---------|------|
| Kling | `/kling/v1` | 可灵视频 |
| Sora | `/v1/videos` | OpenAI Sora |
| Vidu | `/v1/videos` | Vidu 视频 |
| Hailuo | `/v1/videos` | 海螺视频 |
| Jimeng | `/jimeng` | 即梦（透传） |
| Doubao | `/v1/videos` | 豆包视频 |

---

## Playground 在线调试

PeaseAPI 内置 Playground，方便测试模型：

1. 进入 **控制台 -> Playground**
2. 选择模型
3. 输入系统提示和用户消息
4. 调整参数（Temperature、Max Tokens 等）
5. 点击发送，查看流式输出
6. 右侧显示 Token 消耗和费用

Playground 使用专用端点 `/pg/chat/completions`，计费方式与正式 API 一致。

---

## API 调用示例

### Python（OpenAI SDK）

```python
from openai import OpenAI

client = OpenAI(
    api_key="sk-你的令牌",
    base_url="https://你的域名/v1"
)

response = client.chat.completions.create(
    model="gpt-4o",
    messages=[
        {"role": "system", "content": "你是一个助手"},
        {"role": "user", "content": "你好"}
    ],
    stream=True
)

for chunk in response:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="")
```

### Node.js（OpenAI SDK）

```javascript
import OpenAI from "openai";

const client = new OpenAI({
  apiKey: "sk-你的令牌",
  baseURL: "https://你的域名/v1",
});

const response = await client.chat.completions.create({
  model: "claude-sonnet-4-20250514",
  messages: [{ role: "user", content: "你好" }],
});

console.log(response.choices[0].message.content);
```

### cURL（流式）

```bash
curl https://你的域名/v1/chat/completions \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "写一首诗"}],
    "stream": true
  }'
```

### Claude 格式调用

PeaseAPI 兼容 Anthropic 原生 API：

```bash
curl https://你的域名/v1/messages \
  -H "x-api-key: sk-你的令牌" \
  -H "anthropic-version: 2023-06-01" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "claude-sonnet-4-20250514",
    "max_tokens": 1024,
    "messages": [{"role": "user", "content": "你好"}]
  }'
```

### Gemini 格式调用

兼容 Google Gemini 原生 API：

```bash
curl "https://你的域名/v1beta/models/gemini-pro:generateContent?key=sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "contents": [{"parts": [{"text": "你好"}]}]
  }'
```

### 获取模型列表

```bash
curl https://你的域名/v1/models \
  -H "Authorization: Bearer sk-你的令牌"
```

### 嵌入向量

```bash
curl https://你的域名/v1/embeddings \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "text-embedding-3-small",
    "input": "这是一段文本"
  }'
```

### 图像生成

```bash
curl https://你的域名/v1/images/generations \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "dall-e-3",
    "prompt": "一只可爱的猫",
    "n": 1,
    "size": "1024x1024"
  }'
```

### 语音合成

```bash
curl https://你的域名/v1/audio/speech \
  -H "Authorization: Bearer sk-你的令牌" \
  -H "Content-Type: application/json" \
  -o speech.mp3 \
  -d '{
    "model": "tts-1",
    "input": "你好，世界",
    "voice": "alloy"
  }'
```

---

## 常见问题

### 1. 调用返回 401 Unauthorized

- 检查令牌是否正确：`Authorization: Bearer sk-xxxx`
- 检查令牌是否已过期或被禁用
- 检查令牌是否设置了 IP 白名单

### 2. 调用返回 403 模型不可用

- 检查令牌是否限制了允许模型
- 检查用户分组是否有对应渠道权限
- 检查渠道是否被禁用

### 3. 调用返回 429 余额不足

- 用户余额耗尽，需充值
- 令牌配额用完，需调整配额或创建新令牌

### 4. 流式响应不生效

确保 Nginx 配置了 `fastcgi_buffering off;`，详见 [部署文档](deployment.md#nginx-配置参考)。

### 5. Coding Plan 账号全部不可用

- 检查账号是否过期（`expires_at`）
- 检查账号是否被禁用（`status`）
- 检查所有账号配额是否已耗尽（5h/周/月窗口）
- 使用 `php artisan coding_plan:reset-usage` 手动重置检查
- 在管理后台查看账号池概览

### 6. Midjourney 任务一直等待

- 检查队列是否运行：`php artisan queue:work redis --once`
- 检查上游 Midjourney 渠道是否正常
- 查看 `storage/logs/laravel.log` 是否有错误

### 7. 如何添加新的渠道类型

1. 在 `app/Relay/Channel/` 下创建新适配器目录
2. 实现适配器类（继承 `BaseAdapter` 或实现 `ChannelAdapterInterface`）
3. 在 `app/Enums/ChannelType.php` 添加类型枚举
4. 在渠道管理中选择新类型即可使用

### 8. 如何修改计费倍率

- **全局倍率**：系统设置 -> 额度与计费
- **渠道倍率**：渠道编辑 -> 模型倍率
- **分组倍率**：分组管理 -> 倍率设置

最终倍率 = 模型倍率 × 渠道倍率 × 分组倍率

### 9. 如何配置 OAuth 登录

1. 在 GitHub/LinuxDo 创建 OAuth App
2. 系统设置 -> OAuth 配置，填入 Client ID 和 Secret
3. 回调地址填写 `https://你的域名/oauth/callback/github`

### 10. 如何开启 Passkey 无密码登录

1. 确保服务器已安装 `gmp` 扩展
2. 确保 HTTPS 已启用（Passkey 要求安全上下文）
3. 用户在 **控制台 -> 个人资料 -> 安全设置** 绑定 Passkey

---

## 相关文档

- [产品介绍 README](../README.md)
- [部署文档](deployment.md)

---

> 💬 如有更多问题，请前往 [GitHub Issues](https://github.com/peaseapi/peaseapi/issues) 反馈。
