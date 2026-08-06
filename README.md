# PeaseAPI

> 🚀 **100% PHP 重写的新一代多模型 AI API 网关** —— 基于 Laravel 11，将 OpenAI、Claude、Gemini、Midjourney、Suno 等 30+ 上游 AI 服务商统一为 OpenAI 兼容 API，内置完整的用户体系、令牌管理、订阅计费、Coding Plan 账号池与后台管理。

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![100% PHP](https://img.shields.io/badge/100%25-PHP%20Rewritten-blue.svg)](#与原版-new-api-的对比)

---

## 目录

- [产品简介](#产品简介)
- [与原版 New-API 的对比](#与原版-new-api-的对比)
- [核心特性](#核心特性)
- [技术架构](#技术架构)
- [支持的模型与渠道](#支持的模型与渠道)
- [快速开始](#快速开始)
- [环境要求](#环境要求)
- [安装部署](#安装部署)
- [配置说明](#配置说明)
- [使用指南](#使用指南)
- [Coding Plan 账号池](#coding-plan-账号池)
- [API 文档](#api-文档)
- [项目结构](#项目结构)
- [开发指南](#开发指南)
- [常见问题](#常见问题)
- [开源协议](#开源协议)

---

## 产品简介

PeaseAPI 是一个开箱即用的 **AI API 网关平台**，帮助您快速搭建自己的 AI API 分发与计费系统。

> ⚠️ **重要声明：** 本项目是开源项目 [New-API](https://github.com/Calcium-Ion/new-api)（Go 语言版）的 **100% PHP 完整重写版**。我们没有对原项目做简单的语言包装或接口转发，而是基于 Laravel 11 框架从零重写了**全部后端逻辑**——包括路由、控制器、服务层、数据模型、中继引擎、计费体系、订阅系统等，共计 **30+ 控制器、20+ 服务、30+ 数据表、40+ 渠道适配器**。所有业务代码均为原生 PHP/Laravel 实现，无任何 Go 二进制依赖。

### 为什么用 PHP 重写？

原版 New-API 采用 Go + Gin + GORM + React SPA 技术栈，虽然性能优异，但在**二次开发门槛、部署复杂度、服务器生态适配**上存在一定痛点。PeaseAPI 选择 Laravel 11 重写，带来以下核心价值：

| 维度 | 原版 New-API (Go) | PeaseAPI (PHP/Laravel) |
|------|-------------------|------------------------|
| **语言生态** | Go 开发者相对稀缺 | PHP 是 Web 领域最普及语言，开发者基数大 |
| **二次开发** | 需掌握 Go + Gin + GORM + React | 只需会 PHP/Laravel，Blade 模板即前后端 |
| **部署运维** | 需编译二进制 + 独立前端构建 | 标准 PHP-FPM + Nginx，宝塔面板一键部署 |
| **服务器适配** | 对宝塔/虚拟主机不友好 | 完美适配宝塔、1Panel、cPanel 等主流面板 |
| **调试体验** | Go 编译重启周期长 | PHP 热重载，修改即生效 |
| **扩展包生态** | Go 生态相对年轻 | Composer 拥有海量成熟包 |
| **数据库迁移** | GORM AutoMigrate 黑盒 | Laravel Migration 可控可回滚 |
| **前端方案** | React SPA 需独立构建 | Blade 服务端渲染 + Alpine.js，部署零构建 |

### 适用场景

- 🏢 **AI API 分发平台**：对外提供统一格式的 AI API，支持多用户、多令牌、配额计费
- 🏭 **企业 AI 网关**：统一接入管理企业内所有 AI 模型调用，支持权限控制与用量审计
- 👨‍💻 **个人 AI 代理**：聚合多个上游 API Key，实现负载均衡与故障自动切换
- 💰 **订阅制 SaaS**：结合订阅计划与充值系统，构建 AI 服务平台
- 🤖 **Coding Plan 分发**：将 Claude Code / Cursor 等编程订阅账号池化，按套餐分发（原版无此功能）

---

## 与原版 New-API 的对比

### 功能增强（PeaseAPI 新增）

PeaseAPI 在完整复刻原版功能的基础上，新增了以下能力：

| 功能模块 | 说明 | 原版是否支持 |
|---------|------|-------------|
| **🆕 Coding Plan 账号池** | 将 Claude Code / Cursor 等编程订阅账号池化管理，支持 5h/周/月滚动窗口配额、自动切换、优先级调度，并与订阅套餐绑定 | ❌ 原版无 |
| **🆕 Web 安装向导** | 浏览器访问 `/install` 即可完成环境检测、数据库配置、管理员创建，无需命令行 | ❌ 原版需手动改配置 |
| **🆕 宝塔面板适配** | 适配宝塔默认禁用 `proc_open`/`putenv` 的安全策略，移除 Composer 自动脚本依赖，开箱即装 | ⚠️ 原版需解禁函数 |
| **🆕 手机号短信注册** | 支持阿里云短信验证码注册/登录，适配国内场景 | ⚠️ 原版仅邮箱 |
| **🆕 支付宝/微信支付** | 原生集成支付宝与微信支付，适配国内付费场景 | ⚠️ 原版以 Stripe 为主 |
| **🆕 Blade 前端模板** | 采用 Laravel Blade + Tailwind + Alpine.js，无需 Node.js 构建前端 | ❌ 原版需构建 React |
| **🆕 性能监控面板** | 内置 PerfMetric 采集与可视化，支持 P99 延迟分析 | ⚠️ 原版基础统计 |
| **🆕 系统信息面板** | 实时查看服务器 CPU、内存、磁盘、PHP 环境信息 | ⚠️ 原版无独立面板 |
| **🆕 头像上传** | 用户可上传自定义头像，支持本地存储 | ⚠️ 原版依赖外部 |
| **🆕 一键安装命令** | `php artisan pease:install` 完成全部初始化 | ❌ 原版无 |

### 完整复刻的功能

以下功能与原版 New-API **完全对齐**，数据表结构与 API 协议保持兼容：

- ✅ **40+ 渠道适配器**：OpenAI、Claude、Gemini、AWS Bedrock、Vertex AI、阿里通义、火山豆包、Moonshot、DeepSeek、Mistral、Cohere、Groq、xAI 等
- ✅ **OpenAI 兼容 API**：`/v1/chat/completions`、`/v1/embeddings`、`/v1/images/generations`、`/v1/audio/*` 等
- ✅ **格式互转**：OpenAI ↔ Claude ↔ Gemini 请求/响应自动转换
- ✅ **SSE 流式响应**：完整支持 Stream 输出
- ✅ **渠道分发**：基于 Ability 表的优先级 + 权重调度，支持跨组重试
- ✅ **计费体系**：Token 计费、模型倍率、分组倍率、预扣退款
- ✅ **令牌管理**：多 API Key、模型限制、IP 白名单、过期时间、配额
- ✅ **用户体系**：角色权限（普通/管理员/超管）、邀请返佣、2FA、Passkey
- ✅ **订阅系统**：周期订阅、配额重置（日/周/月/自定义）、自动续费
- ✅ **兑换码**：批量生成、配额充值、分组升级
- ✅ **签到系统**：每日签到送配额
- ✅ **日志审计**：完整请求日志，支持多维度筛选
- ✅ **Midjourney/Suno/视频任务**：异步任务提交与状态查询
- ✅ **OAuth 登录**：GitHub、Discord
- ✅ **多实例支持**：数据库锁协调的后台任务调度

### 技术栈对比

| 层级 | 原版 New-API | PeaseAPI |
|------|-------------|----------|
| 后端语言 | Go 1.22+ | PHP 8.2+ |
| Web 框架 | Gin | Laravel 11 |
| ORM | GORM v2 | Eloquent |
| 数据库 | MySQL/PostgreSQL/SQLite | MySQL 8.0+ / SQLite |
| 缓存 | Redis | Redis（推荐）/ Database |
| 队列 | Go goroutine + channel | Laravel Queue（Redis/Database） |
| 前端 | React 18/19 SPA（需构建） | Blade + Tailwind + Alpine.js（零构建） |
| 依赖管理 | Go Modules | Composer |
| 部署方式 | 编译二进制 + Nginx 反代 | PHP-FPM + Nginx（标准 PHP 部署） |
| 认证 | 手动 JWT | Laravel Sanctum + Session |
| OAuth | 手动实现 | Laravel Socialite |
| 支付 | Stripe 为主 | Stripe + 支付宝 + 微信支付 |

---

## 核心特性

### 🔄 多模型统一网关
- **OpenAI 兼容协议**：所有模型统一为 `/v1/chat/completions` 格式，客户端无需修改
- **30+ 上游渠道**：覆盖国内外主流大模型与图片/视频/音乐生成服务
- **智能路由**：基于能力（Ability）的渠道自动选择，支持权重与优先级
- **负载均衡**：多渠道负载分发，自动故障转移与健康检查
- **流式响应**：完整支持 SSE 流式输出（Stream）
- **格式互转**：OpenAI ↔ Claude ↔ Gemini 自动转换

### 👤 完整用户体系
- **多方式注册登录**：邮箱密码、手机号短信验证码、GitHub OAuth、Discord OAuth
- **安全增强**：Two-Factor Authentication (2FA)、WebAuthn/Passkey 无密码登录
- **个人中心**：头像上传、昵称/邮箱/手机号管理、密码修改
- **邀请返佣**：邀请码体系，支持邀请人数统计与返佣配额

### 🔑 令牌（API Key）管理
- **多令牌**：每个用户可创建多个 API Key，独立计费与限流
- **细粒度权限**：令牌可绑定模型分组、设置配额上限与过期时间
- **用量追踪**：按令牌维度统计请求数、Token 消耗与费用
- **只读令牌**：支持创建仅查询用量的只读 Token
- **IP 白名单**：令牌可设置 IP 白名单，增强安全性

### 💰 计费与支付
- **灵活计费**：按 Token 计费（文本模型）、按次计费（图片/任务模型）、分组倍率
- **充值系统**：支持 Stripe（国际）、支付宝、微信支付三种支付渠道
- **兑换码**：支持生成兑换码进行配额充值
- **订阅计划**：周期性订阅，支持自动续费与配额重置（日/周/月）

### 🤖 Coding Plan 账号池（独家功能）
- **账号池化**：将 Claude Code、Cursor 等编程订阅账号统一池化管理
- **滚动窗口配额**：支持 5 小时 / 周 / 月三档滚动窗口配额控制
- **自动切换**：账号配额耗尽自动切换到下一个可用账号
- **优先级调度**：支持按优先级排序账号使用顺序
- **套餐绑定**：与订阅套餐绑定，按套餐分发对应供应商的账号额度
- **使用流水**：完整记录每次使用，支持按账号/时间/成功状态查询
- **统计概览**：各供应商账号池实时概览与 7 天使用趋势

### 📊 后台管理
- **仪表盘**：实时统计请求数、Token 消耗、收入与用户增长
- **用户管理**：用户列表、状态管理、配额调整、密码重置
- **渠道管理**：渠道增删改查、密钥管理、模型映射、健康检测
- **日志审计**：完整的请求日志，支持按模型/用户/状态筛选
- **系统设置**：运行参数可视化配置，无需修改代码
- **性能监控**：内置性能指标采集（PerfMetric），支持 P99 延迟分析
- **系统信息**：实时查看服务器 CPU、内存、磁盘、PHP 环境信息

### ⚡ 性能与可靠性
- **Redis 缓存**：能力列表、渠道配置、用户信息多级缓存
- **队列任务**：Midjourney/Suno 等异步任务基于队列处理
- **速率限制**：全局限流 + 令牌限流 + 模型限流三层防护
- **系统任务**：定时清理、统计聚合等后台 SystemTask 调度
- **多实例协调**：数据库锁保证多实例部署时后台任务不重复执行

---

## 技术架构

```
┌─────────────────────────────────────────────────────────┐
│                      客户端 / SDK                        │
│              (OpenAI 兼容格式 / 原生格式)                 │
└──────────────────────┬──────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────┐
│                   Laravel 11 路由层                      │
│   /v1/* (Relay)  /api/* (API)  /web-api/* (Web API)     │
└──────────────────────┬──────────────────────────────────┘
                       │
            ┌──────────┼──────────┐
            ▼          ▼          ▼
     ┌──────────┐ ┌──────────┐ ┌──────────┐
     │ 中间件层  │ │ 控制器层  │ │ Relay层   │
     │ Auth     │ │ Dashboard│ │ Handler  │
     │ RateLimit│ │ Admin    │ │ Adapter  │
     │ Cors     │ │ Auth     │ │          │
     └──────────┘ └──────────┘ └────┬─────┘
                                  │
                      ┌───────────┼───────────┐
                      ▼           ▼           ▼
                ┌─────────┐ ┌─────────┐ ┌─────────┐
                │ 文本模型  │ │ 任务模型  │ │ 嵌入模型 │
                │ OpenAI   │ │ Midjourney│ │ Embedding│
                │ Claude   │ │ Suno     │ │          │
                │ Gemini   │ │ Video    │ │          │
                └─────┬─────┘ └────┬─────┘ └────┬─────┘
                      │            │            │
                      ▼            ▼            ▼
                ┌─────────────────────────────────────┐
                │          40+ 渠道适配器              │
                │  OpenAI│Claude│Gemini│AWS│Vertex    │
                │  Ali│Volcengine│DeepSeek│Moonshot  │
                │  Groq│Mistral│Cohere│Stability   │
                └────────────────┬────────────────────┘
                                 │
                      ┌──────────┼──────────┐
                      ▼          ▼          ▼
                ┌─────────┐ ┌─────────┐ ┌─────────┐
                │ MySQL   │ │ Redis   │ │ Queue   │
                │ 数据存储  │ │ 缓存/锁  │ │ 异步任务 │
                └─────────┘ └─────────┘ └─────────┘
```

### 请求处理流程

1. **客户端请求**：以 OpenAI 兼容格式发送到 `/v1/chat/completions`
2. **认证鉴权**：`TokenAuth` 中间件校验 API Key，加载用户与令牌
3. **限流检查**：全局限流 → 令牌限流 → 模型限流
4. **渠道选择**：`ChannelSelectService` 基于 Ability 表选择可用渠道
5. **请求转发**：对应 `Adapter` 转换格式并转发到上游
6. **响应处理**：流式（SSE）或一次性返回，格式转回 OpenAI 兼容
7. **计费扣费**：`BillingService` 按 Token/次数计算费用并扣减配额
8. **日志记录**：`LogService` 异步记录请求日志与性能指标

---

## 支持的模型与渠道

### 文本对话模型

| 渠道 | 支持模型示例 | 格式 |
|------|-------------|------|
| OpenAI | GPT-4o, GPT-4 Turbo, GPT-3.5 Turbo, o1 | OpenAI |
| Claude | Claude 3.5 Sonnet, Claude 3 Opus, Claude 3 Haiku | Claude → OpenAI |
| Gemini | Gemini 2.0 Flash, Gemini 1.5 Pro | Gemini → OpenAI |
| AWS Bedrock | Claude (AWS), Titan, Llama (AWS) | AWS → OpenAI |
| Vertex AI | Claude (Vertex), Gemini (Vertex), Llama (Vertex) | Vertex → OpenAI |
| DeepSeek | DeepSeek-V3, DeepSeek-R1 | OpenAI 兼容 |
| Moonshot | Moonshot-v1-8k/32k/128k | OpenAI 兼容 |
| Mistral | Mistral Large, Codestral | OpenAI 兼容 |
| Cohere | Command R+, Command R | Cohere → OpenAI |
| Groq | Llama 3.1 70B (Groq) | OpenAI 兼容 |
| 阿里通义 | Qwen-Max, Qwen-Plus, Qwen-Turbo | Ali → OpenAI |
| 火山豆包 | Doubao-Pro, Doubao-Lite | OpenAI 兼容 |
| 中国移动 | 移动智聊 | OpenAI 兼容 |
| 中国联通 | 联通元景 | OpenAI 兼容 |

### 图像生成模型

| 渠道 | 支持模型 |
|------|---------|
| OpenAI DALL-E | DALL-E 3, DALL-E 2 |
| Midjourney | Midjourney V6, Niji V6 |
| Stability AI | Stable Diffusion 3, SDXL |
| 金山 | 金山图像生成 |
| YimgCloud | Yimg 云端图像 |
| Ashmoon | Ashmoon 图像 |
| Sanlian | 三联图像 |

### 视频/音乐/任务模型

| 类型 | 渠道 | 支持模型 |
|------|------|---------|
| 视频 | Kling | 可灵视频生成 |
| 视频 | Sora | OpenAI Sora |
| 视频 | Vidu | Vidu 视频生成 |
| 视频 | Hailuo | 海螺视频生成 |
| 视频 | Jimeng | 即梦视频生成 |
| 音乐 | Suno | Suno V3.5, V4 |
| 嵌入 | OpenAI | Text-Embedding-3 |
| 嵌入 | Vertex | Gecko Embedding |
| 嵌入 | Cohere | Embed V3 |

---

## 快速开始

### 最快方式：Docker

```bash
docker run -d \
  --name peaseapi \
  -p 8080:80 \
  -e DB_HOST=mysql \
  -e DB_DATABASE=peaseapi \
  -e DB_USERNAME=root \
  -e DB_PASSWORD=secret \
  -e REDIS_HOST=redis \
  peaseapi/peaseapi:latest
```

访问 `http://localhost:8080/install` 完成安装向导。

### 最简方式：Web 安装向导

```bash
# 1. 克隆项目
git clone https://github.com/peaseapi/peaseapi.git
cd peaseapi

# 2. 安装依赖
composer install --no-dev --optimize-autoloader

# 3. 启动开发服务器
php artisan serve
```

访问 `http://localhost:8000/install`，按向导完成配置。

---

## 环境要求

| 组件 | 最低版本 | 推荐版本 |
|------|---------|---------|
| PHP | 8.2 | 8.3+ |
| Laravel | 11.0 | 11.x |
| MySQL | 8.0 | 8.1+ |
| Redis | 6.0 | 7.0+（推荐） |
| Nginx | 1.18 | 1.24+ |
| Composer | 2.6 | 2.7+ |

### PHP 扩展要求

- `pdo_mysql` / `pdo_sqlite`
- `redis`
- `gmp`（WebAuthn/Passkey 需要）
- `mbstring`
- `xml`
- `curl`
- `zip`
- `fileinfo`
- `openssl`
- `bcmath`

---

## 安装部署

PeaseAPI 提供三种部署方式，详见 **[部署文档](docs/deployment.md)**：

1. **[独立服务器部署](docs/deployment.md#独立服务器部署)** -- 手动配置 Nginx + PHP-FPM + MySQL + Redis
2. **[宝塔面板部署](docs/deployment.md#宝塔面板部署)** -- 适合国内用户，图形化操作
3. **[Docker 部署](docs/deployment.md#docker-部署)** -- 最快部署方式，适合容器化环境

---

## 配置说明

### 环境变量（.env）

核心配置项详见 `.env.example`，关键配置：

```env
APP_NAME=PeaseAPI
APP_ENV=production
APP_KEY=                    # 安装时自动生成
APP_DEBUG=false
APP_URL=https://api.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=peaseapi
DB_USERNAME=root
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_STORE=redis
```

### 系统设置

部署后，在后台 **设置 → 系统设置** 中可视化配置：

- **通用设置**：站点名称、Logo、注册开关、邮箱验证
- **模型设置**：默认模型倍率、分组倍率
- **支付设置**：Stripe / 支付宝 / 微信支付密钥
- **SMTP 设置**：邮件服务器配置
- **短信设置**：阿里云短信 AccessKey
- **OAuth 设置**：GitHub / Discord Client ID
- **安全设置**：Turnstile 验证码、2FA 强制开启

---

## 使用指南

详细使用文档请见 **[使用指南](docs/usage-guide.md)**，涵盖：

- [管理员设置](docs/usage-guide.md#管理员设置)
- [添加渠道与模型 Key](docs/usage-guide.md#添加渠道与模型-key)
- [令牌（API Key）管理](docs/usage-guide.md#令牌管理)
- [用户与分组管理](docs/usage-guide.md#用户与分组管理)
- [订阅与充值](docs/usage-guide.md#订阅与充值)
- [Coding Plan 账号池配置](docs/usage-guide.md#coding-plan-账号池配置)
- [Coding Plan 转换 API 输出](docs/usage-guide.md#coding-plan-转换-api-输出)
- [Midjourney/Suno 任务](docs/usage-guide.md#异步任务)

---

## API 文档

### OpenAI 兼容接口

所有文本模型统一使用以下接口：

```bash
curl https://api.example.com/v1/chat/completions \
  -H "Authorization: Bearer sk-your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}],
    "stream": true
  }'
```

主要端点：

| 端点 | 说明 |
|------|------|
| `POST /v1/chat/completions` | 对话补全（文本模型） |
| `POST /v1/embeddings` | 文本嵌入 |
| `POST /v1/images/generations` | 图像生成 |
| `POST /v1/audio/speech` | 文字转语音 |
| `POST /v1/audio/transcriptions` | 语音转文字 |
| `GET /v1/models` | 模型列表 |
| `POST /v1/messages` | Claude 原生格式 |
| `POST /mj/submit/imagine` | Midjourney 任务 |
| `POST /suno/submit/music` | Suno 音乐任务 |

### 管理后台 API

后台管理 API 基于 `/api` 前缀，需 Admin/Root Token 认证，详见 [API 使用文档](docs/usage-guide.md#api-调用示例)。

---

## 项目结构

```
new-api-php/
├── app/
│   ├── Console/Commands/      # 命令行（pease:install 等）
│   ├── Enums/                 # 枚举（ChannelType, UserRole 等）
│   ├── Helpers/               # 辅助函数
│   ├── Http/
│   │   ├── Controllers/       # 30+ 控制器
│   │   │   └── Api/           # API 控制器
│   │   └── Middleware/        # 认证/限流/CORS 等
│   ├── Mail/                  # 邮件模板
│   ├── Models/                # 30+ Eloquent 模型
│   ├── Providers/             # 服务提供者
│   ├── Relay/                 # 中继引擎核心
│   │   ├── Channel/           # 40+ 渠道适配器
│   │   │   ├── OpenAI/        # OpenAI 适配器
│   │   │   ├── Claude/        # Claude 适配器
│   │   │   ├── Gemini/        # Gemini 适配器
│   │   │   └── Task/          # 异步任务适配器
│   │   ├── Common/            # RelayHandler, RelayInfo
│   │   └── Constant/          # RelayFormat, RelayMode
│   ├── Services/              # 20+ 业务服务
│   └── Setting/               # 设置管理
├── bootstrap/
├── config/                    # 配置文件
├── database/
│   └── migrations/            # 数据库迁移
├── lang/                      # 多语言
├── public/                    # 入口文件
├── resources/views/           # Blade 模板
│   ├── admin/                 # 后台页面
│   ├── auth/                  # 登录注册
│   ├── dashboard/             # 用户面板
│   ├── emails/                # 邮件模板
│   └── layouts/               # 布局
├── routes/
│   ├── api.php                # API 路由
│   ├── relay.php              # 中继路由
│   ├── web.php                # Web 路由
│   └── console.php            # 定时任务
└── .env.example               # 环境配置模板
```

---

## 开发指南

### 添加新渠道适配器

1. 在 `app/Relay/Channel/` 下新建目录，如 `MyVendor/`
2. 创建 `MyVendorAdapter.php`，实现 `ChannelAdapterInterface`
3. 文本模型可继承 `OpenAICompatibleTrait` 快速实现
4. 在 `ChannelType` 枚举注册新渠道类型
5. 运行 `php artisan migrate` 更新数据库

### 本地开发

```bash
# 安装依赖
composer install

# 初始化环境
php artisan pease:install

# 启动开发服务器 + 队列
composer dev
```

### 代码规范

项目使用 Laravel Pint 进行代码格式化：

```bash
./vendor/bin/pint
```

---

## 常见问题

<details>
<summary><b>Q: 与原版 New-API 数据库兼容吗？</b></summary>

数据表结构保持兼容，理论上可以直接导入原版数据库。但建议在新数据库上安装后，通过数据迁移脚本导入。
</details>

<details>
<summary><b>Q: 宝塔面板部署需要解禁 proc_open 吗？</b></summary>

不需要。PeaseAPI 已适配宝塔安全策略，移除了 Composer 自动脚本中对 `proc_open`/`putenv` 的依赖，开箱即装。
</details>

<details>
<summary><b>Q: 性能相比 Go 版原版如何？</b></summary>

PHP-FPM 在常规 API 网关场景下性能完全够用。由于 AI API 的瓶颈主要在上游模型响应时间（通常 1-10 秒），语言本身的性能差异可以忽略。建议开启 OPcache 与 Redis 缓存获得最佳性能。
</details>

<details>
<summary><b>Q: 支持 PostgreSQL 吗？</b></summary>

当前版本以 MySQL 8.0+ 为主，SQLite 可用于开发测试。PostgreSQL 支持在规划中。
</details>

<details>
<summary><b>Q: 前端需要 Node.js 构建吗？</b></summary>

不需要。PeaseAPI 使用 Laravel Blade + Tailwind（CDN）+ Alpine.js，是服务端渲染方案，部署时无需任何前端构建步骤。
</details>

---

## 开源协议

PeaseAPI 基于 [MIT License](LICENSE) 开源。

---

## 致谢

- [New-API](https://github.com/Calcium-Ion/new-api) -- 原版项目，感谢其出色的产品设计
- [Laravel](https://laravel.com/) -- 优雅的 PHP 框架
- [Tailwind CSS](https://tailwindcss.com/) -- 实用优先的 CSS 框架
- [Alpine.js](https://alpinejs.dev/) -- 轻量级 JavaScript 框架
