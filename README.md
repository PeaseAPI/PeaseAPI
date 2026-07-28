# PeaseAPI

> 基于 Laravel 11 的多模型 AI API 网关与分发管理平台，支持 OpenAI、Claude、Gemini、Midjourney、Suno 等 30+ 上游服务商的统一接入、计费与管理。

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?logo=php&logoColor=white)](https://php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## 目录

- [产品简介](#产品简介)
- [核心特性](#核心特性)
- [技术架构](#技术架构)
- [支持的模型与渠道](#支持的模型与渠道)
- [快速开始](#快速开始)
- [环境要求](#环境要求)
- [安装部署](#安装部署)
- [配置说明](#配置说明)
- [使用指南](#使用指南)
- [API 文档](#api-文档)
- [项目结构](#项目结构)
- [开发指南](#开发指南)
- [常见问题](#常见问题)
- [开源协议](#开源协议)

---

## 产品简介

PeaseAPI 是一个开箱即用的 **AI API 网关平台**，帮助您快速搭建自己的 AI API 分发与计费系统。它将 OpenAI、Anthropic Claude、Google Gemini、阿里通义、火山引擎、Moonshot、DeepSeek 等数十种大模型 API 统一为 OpenAI 兼容格式，同时内置完整的用户体系、令牌管理、订阅计费、渠道健康监控与后台管理功能。

无论您是 **AI 服务商**需要对外分发 API、**企业内部**需要统一管理多模型调用，还是 **开发者**想要搭建个人 AI 网关，PeaseAPI 都能提供完整的一站式解决方案。

### 适用场景

- 🏢 **AI API 分发平台**：对外提供统一格式的 AI API，支持多用户、多令牌、配额计费
- 🏭 **企业 AI 网关**：统一接入管理企业内所有 AI 模型调用，支持权限控制与用量审计
- 👨‍💻 **个人 AI 代理**：聚合多个上游 API Key，实现负载均衡与故障自动切换
- 💰 **订阅制 SaaS**：结合订阅计划与充值系统，构建 AI 服务平台

---

## 核心特性

### 🔄 多模型统一网关
- **OpenAI 兼容协议**：所有模型统一为 `/v1/chat/completions` 格式，客户端无需修改
- **30+ 上游渠道**：覆盖国内外主流大模型与图片/视频/音乐生成服务
- **智能路由**：基于能力（Ability）的渠道自动选择，支持权重与优先级
- **负载均衡**：多渠道负载分发，自动故障转移与健康检查
- **流式响应**：完整支持 SSE 流式输出（Stream）

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

### 💰 计费与支付
- **灵活计费**：按 Token 计费（文本模型）、按次计费（图片/任务模型）、分组倍率
- **充值系统**：支持 Stripe（国际）、支付宝、微信支付三种支付渠道
- **兑换码**：支持生成兑换码进行配额充值
- **订阅计划**：周期性订阅，支持自动续费与配额重置（日/周/月）

### 📊 后台管理
- **仪表盘**：实时统计请求数、Token 消耗、收入与用户增长
- **用户管理**：用户列表、状态管理、配额调整、密码重置
- **渠道管理**：渠道增删改查、密钥管理、模型映射、健康检测
- **日志审计**：完整的请求日志，支持按模型/用户/状态筛选
- **系统设置**：运行参数可视化配置，无需修改代码

### ⚡ 性能与可靠性
- **Redis 缓存**：能力列表、渠道配置、用户信息多级缓存
- **队列任务**：Midjourney/Suno 等异步任务基于队列处理
- **速率限制**：全局限流 + 令牌限流 + 模型限流三层防护
- **性能监控**：内置性能指标采集（PerfMetric），支持 P99 延迟分析
- **系统任务**：定时清理、统计聚合等后台 SystemTask 调度

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
           ┌───────────┼───────────┐
           ▼           ▼           ▼
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
               │ Gemini   │ │ Sora     │ └─────────┘
               │ ...      │ │ Kling    │
               └─────────┘ └─────────┘
                                 │
                                 ▼
               ┌──────────────────────────────────┐
               │          数据存储层                │
               │  MySQL/SQLite · Redis · Storage   │
               └──────────────────────────────────┘
```

### 技术栈

| 层级 | 技术 |
|------|------|
| **后端框架** | Laravel 11 (PHP 8.2+) |
| **数据库** | MySQL 8.0+ / SQLite |
| **缓存** | Redis（推荐）/ Database |
| **队列** | Database / Redis |
| **前端** | Blade 模板 + Tailwind CSS + Alpine.js |
| **支付** | Stripe PHP SDK / 支付宝 SDK / 微信支付 |
| **认证** | Laravel Sanctum + Socialite + WebAuthn |
| **依赖管理** | Composer |

---

## 支持的模型与渠道

### 文本对话模型

| 渠道 | 支持模型 | 协议 |
|------|---------|------|
| **OpenAI** | GPT-4o / GPT-4 / GPT-3.5-turbo / o1 系列 | OpenAI 兼容 |
| **Anthropic Claude** | Claude 3.5 Sonnet / Opus / Haiku | Claude 原生 -> OpenAI |
| **Google Gemini** | Gemini 2.0 / 1.5 Pro / Flash | Gemini 原生 -> OpenAI |
| **Google Vertex** | 同 Gemini（企业版） | Vertex AI |
| **DeepSeek** | DeepSeek-V3 / DeepSeek-R1 | OpenAI 兼容 |
| **Moonshot** | Moonshot-v1 / Kimi | OpenAI 兼容 |
| **Mistral** | Mistral-Large / Codestral | OpenAI 兼容 |
| **Cohere** | Command-R / Command-R+ | Cohere -> OpenAI |
| **阿里通义** | Qwen-Max / Qwen-Plus / Qwen-Turbo | OpenAI 兼容 |
| **火山引擎** | Doubao-Pro / Doubao-Lite | OpenAI 兼容 |
| **Groq** | Llama / Mixtral（极速推理） | OpenAI 兼容 |
| **AWS Bedrock** | Claude / Llama / Titan | AWS -> OpenAI |
| **中国移动** | 自研模型 | OpenAI 兼容 |
| **中国联通** | 自研模型 | OpenAI 兼容 |

### 图片/视频/音乐生成模型

| 渠道 | 支持模型 | 类型 |
|------|---------|------|
| **Midjourney** | MJ V6 / Niji V6 | 图片生成 |
| **Stability AI** | SD3 / SDXL | 图片生成 |
| **Suno** | Suno V3 / V4 | 音乐生成 |
| **Sora** | Sora | 视频生成 |
| **Kling（可灵）** | Kling Text-to-Video | 视频生成 |
| **Vidu** | Vidu 视频生成 | 视频生成 |
| **Hailuo（海螺）** | 海螺视频 | 视频生成 |
| **Jimeng（即梦）** | 即梦图片/视频 | 图片/视频 |
| **阿里 Wanx** | 通义万相 | 图片生成 |
| **金山** | 金山图片 | 图片生成 |
| **Step** | 阶跃星辰 | 图片生成 |
| **Ashmoon** | Ashmoon | 图片生成 |
| **Sanlian** | 三联模型 | 图片生成 |
| **YimgCloud** | YimgCloud | 图片生成 |

> 💡 新增渠道只需实现 `ChannelAdapterInterface` 接口，参考 `app/Relay/Channel/` 下的现有适配器。

---

## 快速开始

### 环境要求

- **PHP** >= 8.2（需安装 `ext-gmp`、`ext-pdo`、`ext-mbstring`、`ext-redis`）
- **Composer** >= 2.x
- **MySQL** 8.0+ 或 **SQLite**（开发环境）
- **Redis**（推荐，用于缓存与队列）
- **Node.js** >= 18（仅前端资源编译需要）

### 安装部署

#### 方式一：本地开发部署

```bash
# 1. 克隆项目
git clone https://github.com/PeaseAPI/PeaseAPI.git
cd PeaseAPI

# 2. 安装 PHP 依赖
composer install

# 3. 配置环境变量
cp .env.example .env
php artisan key:generate

# 4. 编辑 .env，配置数据库与 Redis
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_DATABASE=peaseapi
#    DB_USERNAME=root
#    DB_PASSWORD=your_password
#
#    REDIS_HOST=127.0.0.1
#    REDIS_PASSWORD=null
#    REDIS_PORT=6379

# 5. 创建数据库软链接
php artisan storage:link

# 6. 执行数据库迁移
php artisan migrate

# 7. 启动开发服务器
php artisan serve
# 访问 http://localhost:8000 进入安装向导
```

#### 方式二：Docker 部署

```bash
# 1. 克隆项目
git clone https://github.com/PeaseAPI/PeaseAPI.git
cd PeaseAPI

# 2. 使用 Docker Compose（需自行编写 docker-compose.yml）
docker build -t pease-api .
docker run -d -p 8000:80 \
  -v $(pwd)/.env:/var/www/html/.env \
  -v $(pwd)/storage:/var/www/html/storage \
  pease-api
```

#### 方式三：生产环境部署

```bash
# 1. 部署代码到服务器
git clone https://github.com/PeaseAPI/PeaseAPI.git /var/www/peaseapi
cd /var/www/peaseapi

# 2. 安装依赖（优化自动加载）
composer install --no-dev --optimize-autoloader

# 3. 配置环境
cp .env.example .env
php artisan key:generate
# 编辑 .env，设置 APP_ENV=production, APP_DEBUG=false

# 4. 迁移数据库
php artisan migrate --force

# 5. 缓存优化
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

# 6. 设置 Nginx 指向 public/ 目录
# 7. 配置 Supervisor 管理队列 worker
#    php artisan queue:work --tries=3 --max-time=3600
```

### 安装向导

首次访问网站会自动跳转到安装向导页面（`/install`），按提示完成：
1. 环境检测（PHP 版本、扩展、目录权限）
2. 数据库配置
3. 创建管理员账号
4. 初始化系统设置

---

## 配置说明

### 核心环境变量

```env
# 应用配置
APP_NAME=PeaseAPI
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_TIMEZONE=Asia/Shanghai
APP_LOCALE=zh-CN

# 数据库
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=peaseapi
DB_USERNAME=root
DB_PASSWORD=secret

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# 缓存与队列
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database

# 文件存储
FILESYSTEM_DISK=public

# 邮件
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@example.com
MAIL_PASSWORD=secret
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

### 支付配置

支付相关配置在管理后台 **系统设置** 页面可视化配置，无需修改 `.env`：

- **Stripe**：API Key、Webhook Secret
- **支付宝**：App ID、私钥、公钥
- **微信支付**：Mch ID、API Key、证书路径

### 短信配置

短信验证码用于手机号注册/登录/修改，在后台配置：

- **阿里云短信**：AccessKey、Sign Name、Template Code

### OAuth 登录配置

在后台 **系统设置 -> OAuth** 中配置：

- **GitHub**：Client ID / Client Secret
- **Discord**：Client ID / Client Secret

---

## 使用指南

### 管理员快速上手

1. **添加渠道**：进入 `管理后台 -> 渠道管理`，添加上游 API 渠道，填入密钥与支持的模型
2. **配置模型能力**：系统会自动根据渠道配置生成能力列表（Abilities），也可手动调整
3. **设置模型倍率**：在 `系统设置 -> 模型倍率` 中配置每个模型的计费倍率
4. **创建用户分组**：在 `分组管理` 中创建不同分组，设置分组倍率与可用模型

### 用户使用流程

1. **注册登录**：访问首页，通过邮箱/手机号/OAuth 注册
2. **创建令牌**：进入 `控制台 -> 令牌`，创建 API Key，设置配额与模型权限
3. **调用 API**：使用创建的令牌调用 OpenAI 兼容接口：

```bash
curl https://your-domain.com/v1/chat/completions \
  -H "Authorization: Bearer sk-your-token-here" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}],
    "stream": false
  }'
```

4. **充值/订阅**：在 `钱包` 页面充值或购买订阅计划
5. **查看用量**：在 `使用日志` 页面查看详细的调用记录与费用

### 个人信息管理

访问 `控制台 -> 个人信息`（`/profile`）可以：
- 📷 **上传/更换头像**
- ✏️ **修改用户名、昵称、邮箱**
- 📱 **绑定/修改手机号**（需短信验证码）
- 🔒 **修改登录密码**

> 登录后点击右上角头像或用户名也可快速进入此页面。

---

## API 文档

### OpenAI 兼容接口

所有模型调用统一使用 OpenAI 格式，客户端只需将 `base_url` 指向您的 PeaseAPI 实例：

| 接口 | 方法 | 路径 | 说明 |
|------|------|------|------|
| Chat Completions | POST | `/v1/chat/completions` | 对话补全（支持流式） |
| Embeddings | POST | `/v1/embeddings` | 文本向量化 |
| Models | GET | `/v1/models` | 可用模型列表 |
| Midjourney - Imagine | POST | `/mj/submit/imagine` | 提交 MJ 绘图任务 |
| Midjourney - Action | POST | `/mj/submit/action` | MJ 变换/放大 |
| Midjourney - Status | GET | `/mj/task/{id}/fetch` | 查询任务状态 |
| Suno - Generate | POST | `/suno/submit/generation` | 提交音乐生成 |
| Suno - Status | GET | `/suno/get?ids={id}` | 查询音乐状态 |
| Video - Generate | POST | `/v1/videos/generations` | 视频生成（Sora/Kling等） |

**调用示例（Python）：**

```python
from openai import OpenAI

client = OpenAI(
    api_key="sk-your-peaseapi-token",
    base_url="https://your-domain.com/v1"
)

response = client.chat.completions.create(
    model="gpt-4o",
    messages=[{"role": "user", "content": "你好，PeaseAPI！"}],
    stream=True
)

for chunk in response:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="")
```

### 管理后台 API

管理后台提供完整的 RESTful API（前缀 `/api`），使用 Session 认证：

- `GET /api/user/self` - 获取当前用户信息
- `GET /api/dashboard` - 仪表盘统计数据
- `GET /api/channel` - 渠道列表
- `POST /api/channel` - 创建渠道
- `GET /api/token` - 令牌列表
- `POST /api/token` - 创建令牌
- `GET /api/log` - 日志列表

> 详细接口参数请参考代码中的 `routes/api.php` 与控制器实现。

---

## 项目结构

```
PeaseAPI/
├── app/
│   ├── Console/              # Artisan 命令
│   ├── Enums/                # 枚举类（ChannelType, ApiType, UserRole 等）
│   ├── Helpers/              # 全局辅助函数
│   ├── Http/
│   │   ├── Controllers/      # 控制器
│   │   │   ├── Api/          # API 控制器（返回 JSON）
│   │   │   └── ...           # Web/Admin 控制器
│   │   └── Middleware/       # 中间件（认证、限流、CORS 等）
│   ├── Jobs/                 # 队列任务
│   ├── Mail/                 # 邮件模板
│   ├── Models/               # Eloquent 模型
│   ├── Providers/            # 服务提供者
│   ├── Relay/                # 🔑 核心：API 转发引擎
│   │   ├── Channel/          # 渠道适配器
│   │   │   ├── OpenAI/       # OpenAI 适配器
│   │   │   ├── Claude/       # Claude 适配器
│   │   │   ├── Gemini/       # Gemini 适配器
│   │   │   ├── Task/         # 异步任务适配器（MJ/Suno/Sora等）
│   │   │   └── ...           # 其他渠道适配器
│   │   ├── Common/           # 转发公共组件（Handler, Info）
│   │   └── Constant/         # 转发常量（Format, Mode）
│   ├── Services/             # 业务服务层
│   │   ├── RelayService.php  # 转发主服务
│   │   ├── BillingService.php # 计费服务
│   │   ├── ChannelService.php # 渠道服务
│   │   ├── QuotaService.php  # 配额服务
│   │   └── ...
│   └── Setting/              # 系统设置类
├── bootstrap/                # 框架引导
├── config/                   # 配置文件
├── database/
│   ├── migrations/           # 数据库迁移
│   ├── factories/            # 模型工厂
│   └── seeders/              # 数据填充
├── lang/                     # 多语言文件
├── public/                   # Web 入口与静态资源
├── resources/
│   └── views/                # Blade 模板
│       ├── admin/            # 管理后台页面
│       ├── auth/             # 登录注册页面
│       ├── dashboard/        # 用户控制台页面
│       ├── emails/           # 邮件模板
│       └── layouts/          # 布局模板
├── routes/
│   ├── api.php               # API 路由
│   ├── web.php               # Web 路由
│   ├── relay.php             # 转发路由（/v1, /mj, /suno 等）
│   └── console.php           # 控制台命令路由
├── storage/                  # 文件存储（日志、缓存、上传）
├── .env.example              # 环境配置示例
├── composer.json             # Composer 配置
└── artisan                   # Laravel CLI
```

---

## 开发指南

### 新增渠道适配器

1. 在 `app/Relay/Channel/` 下创建新目录，例如 `Xxx/`
2. 创建适配器类 `XxxAdapter.php`，实现 `ChannelAdapterInterface` 接口
3. 在 `app/Enums/ChannelType.php` 中注册新的渠道类型枚举
4. 在 `config/pease-api.php` 中注册适配器与渠道类型的映射

```php
<?php
// app/Relay/Channel/Xxx/XxxAdapter.php

namespace App\Relay\Channel\Xxx;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\ChannelAdapterInterface;

class XxxAdapter extends BaseAdapter implements ChannelAdapterInterface
{
    public function sendRequest(): \Psr\Http\Message\ResponseInterface
    {
        // 实现请求转发逻辑
    }

    public function formatResponse(): array
    {
        // 将上游响应转换为 OpenAI 兼容格式
    }
}
```

### 代码规范

项目遵循 PSR-12 代码规范，使用 Laravel Pint 进行格式化：

```bash
# 格式化代码
./vendor/bin/pint

# 检查格式（不修改）
./vendor/bin/pint --test
```

### 数据库迁移

```bash
# 创建新迁移
php artisan make:migration create_xxx_table

# 执行迁移
php artisan migrate

# 回滚
php artisan migrate:rollback
```

---

## 常见问题

### Q: 安装后访问页面报 500 错误？

**A:** 请检查：
1. `.env` 文件是否已创建并配置正确的 `APP_KEY`
2. `storage/` 和 `bootstrap/cache/` 目录是否有写入权限
3. 数据库连接是否正常
4. 查看 `storage/logs/laravel.log` 中的错误日志

### Q: 如何配置 Midjourney 代理？

**A:** PeaseAPI 的 Midjourney 接口兼容上游 MJ Proxy API 格式。在 `渠道管理` 中添加渠道，选择类型为 `Midjourney`，填入上游 API 地址与密钥即可。

### Q: 流式响应（Stream）不工作？

**A:** 请确保：
1. Nginx 配置关闭了 `proxy_buffering`（`proxy_buffering off;`）
2. PHP 配置关闭了输出缓冲（`output_buffering = Off`）
3. 未启用 Gzip 压缩流式响应

### Q: 如何重置管理员密码？

**A:** 通过 Artisan 命令：
```bash
php artisan tinker
>>> $user = \App\Models\User::where('role', 'root')->first();
>>> $user->password = bcrypt('new-password');
>>> $user->save();
```

### Q: 支持多数据库吗？

**A:** 生产环境推荐 MySQL 8.0+。开发环境可使用 SQLite（`DB_CONNECTION=sqlite`）。Redis 强烈推荐用于缓存和队列。

### Q: 如何升级到新版本？

**A:**
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Q: 宝塔面板安装时提示需要取消 `proc_open` / `putenv` 函数禁用？

**A:** 宝塔面板默认禁用 `proc_open`、`putenv` 等函数。这些函数仅被 **Composer 的 scripts 机制** 用于在安装完成后启动子进程执行 `php artisan package:discover` 等命令；**PeaseAPI 运行时并不依赖这些函数**，因此无需为运行安全而解禁它们。

本项目提供了 `pease:install` 命令来替代 Composer 的 scripts，使用方式：

```bash
# 1. 使用 --no-scripts 跳过 Composer 的 scripts（不会触发 proc_open）
composer install --no-scripts

# 2. 用 pease:install 完成等效初始化（环境检测 / .env / APP_KEY / 包发现 / 迁移 / storage 链接）
php artisan pease:install
```

> 这样既无需在宝塔面板取消 `proc_open` / `putenv` 的禁用（保持服务器安全配置），也能正常完成安装。安装向导页面同样会在「环境检测」步骤中检测并提示这一情况。

---

## 开源协议

本项目基于 [MIT License](LICENSE) 开源协议。

---

## 致谢

- [Laravel](https://laravel.com/) - 优雅的 PHP 框架
- [OpenAI](https://openai.com/) - GPT 系列 API
- [Anthropic](https://anthropic.com/) - Claude 系列 API
- [Google](https://ai.google.dev/) - Gemini API
- 以及所有支持的上游 AI 服务商

---

<p align="center">
  PeaseAPI © 2024-2026. Made with ❤️ by PeaseAPI Team.
</p>
