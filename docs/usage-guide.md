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
