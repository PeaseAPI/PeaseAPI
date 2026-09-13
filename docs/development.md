# PeaseAPI 开发文档（Development Guide）

> 面向开发者：环境搭建、测试体系、解析器开发、迁移与代码规范、Git 工作流、已知陷阱。
> 使用层面见 `docs/usage-guide.md`，运维层面见 `docs/operations-guide.md`，部署见 `docs/deployment.md`，设置键位见 `docs/settings-reference.md`。

## 目录
1. [技术栈与目录结构](#1-技术栈与目录结构)
2. [开发环境搭建](#2-开发环境搭建)
3. [测试体系](#3-测试体系)
4. [Coding Plan 解析器开发指南](#4-coding-plan-解析器开发指南)
5. [数据库迁移规范](#5-数据库迁移规范)
6. [代码风格与静态检查](#6-代码风格与静态检查)
7. [Git 工作流与任务账本](#7-git-工作流与任务账本)
8. [已知陷阱备忘](#8-已知陷阱备忘)

## 1. 技术栈与目录结构

| 层 | 技术 |
|---|---|
| 框架 | Laravel 12（PHP **8.3**，生产宝塔 fpm 8.3.33）|
| 前端 | Vue 3 + Vite（`npm run dev`，composer `dev` 脚本并发拉起）|
| 数据库 | MySQL 8（生产为阿里云 RDS）/ Redis（cache + queue + 调度锁）|
| 队列 | QUEUE_CONNECTION=redis |
| 安装器 | `pease:install`（20K 命令，引导建表/管理员/渠道）|

关键目录：

```
app/
  Console/Commands/          # 全部 artisan 命令（含 Test* 系列验收测试命令）
  Http/Controllers/          # Admin/Api/Web 三域控制器
  Services/
    CodingPlanOfficialSourceService.php   # 官方源抓取/快照/落库管线（同步核心）
    CodingPlanParsers/       # 15 家官方源解析器（见 §4）
  Models/                    # 含 Token/SystemInstance/CodingPlanVendor 等
routes/
  web.php                    # 92 条（安装/文档/登录/管理端页面）
  api.php                    # 392 条（OpenAI 兼容 + coding_plan + 用户/OAuth/管理端 API）
  console.php                # 调度注册（Schedule::command ... onOneServer）
tests/                       # 10 个端到端验收脚本（见 §3）
docs/                        # 本文档体系 + upstream-snapshots/ 快照镜像
scripts/                     # 演示与数据脚本
database/migrations/         # 000001~000024（见 §5）
```

## 2. 开发环境搭建

```bash
# 1. 克隆（GitHub / Gitee 双仓同源）
git clone https://github.com/<org>/PeaseAPI.git && cd PeaseAPI
# 2. 依赖
composer install
npm install
# 3. 环境文件
cp .env.example .env && php artisan key:generate
# 4. 引导安装（建库表 + 管理员 + 默认渠道）
php artisan pease:install
# 或手工：php artisan migrate --seed
# 5. 本地并发起服务（serve + queue:listen + pail + vite）
composer dev
```

本地访问 `http://localhost:8000`。管理端登录后进入设置/渠道/Coding Plan 账本各页。

### 本地出站代理（官方源 sync 必需）
官方源抓取在大陆网络需代理，`.env` 配置：

```dotenv
PEASE_API_HTTP_PROXY=http://127.0.0.1:7890
```

- anthropic / openai-token / google-token 在大陆直连 TLS 403（区域封锁），必须走代理；
- deepseek/minimax/moonshot/xai/zhipu 等其余 11 家直连可达；
- 服务器（阿里云国际新加坡）实测 **13/15 家直连全通**，仅 anthropic/openai-token 需代理（见 operations-guide §定时任务与官方源连通矩阵）。

## 3. 测试体系

两类验收手段（均为真实 HTTP/DB 驱动，非单元 mock）：

### 3.1 artisan 验收命令（QA 基线核心）
| 命令 | 覆盖 | 基线 |
|---|---|---|
| `php artisan coding-plan:test-fixture` | 15 家解析器 fixture 断言（快照回放）| 全绿 |
| `php artisan coding-plan:test-time-discounts` | 时段折扣 28 断言 | 全绿 |
| `php artisan coding-plan:test-cost-failover` | 成本路由故障转移 24 | 全绿 |
| `php artisan coding-plan:test-cost-routing` | 智能成本路由 24 | 全绿 |
| `php artisan coding-plan:test-cooldown-recovery` | 冷却自愈 31 | 全绿 |
| `php artisan coding-plan:test-currency` | 多币种结算 30 | 全绿 |
| `php artisan coding-plan:verify-ratios` | 24 家在售系数在线比对（0 stale/0 change/0 failure）| 全绿 |

### 3.2 tests/ 端到端脚本
`php tests/<name>.php` 直跑（内部拉起 HTTP/DB 断言）：

| 脚本 | 覆盖 |
|---|---|
| `coding-plan-parser-fixture.php` | 解析器 fixture 断言详情（含 new/missing 明细）|
| `coding-plan-remind-verify.php` | remind-promotions 促销提醒链路 |
| `crud-flow-verify.php` | 管理端 CRUD 全流程 |
| `full-render-verify.php` | 前台页面全渲染 |
| `news-upstream-verify.php` | 新闻/上游聚合 |
| `relay-adapters-verify.php` | 中继适配器（OpenAI 兼容协议）|
| `source-health-verify.php` | 官方源健康告警 |
| `ticket-verify.php` | 工单/在线客服 |
| `admin-observability-verify.php` | 管理端可观测与在线自检 |
| `mock-gemini-sse-server.php` | Gemini SSE 本地 mock 源（relay 联调用）|

## 4. Coding Plan 解析器开发指南

任务账本域：把 15 家「Coding Plan 厂商」的官方在售目录/价目抓取并落库为可校对的流水。

### 4.1 管线三段式
```
CodingPlanOfficialSourceService（编排：HTTP 抓取 → 快照落盘 → Parser 解析 → diff → 校对流水）
  └─ app/Services/CodingPlanParsers/*
       CodingPlanParserInterface          # 解析契约
       AbstractCodingPlanParser           # 公共骨架（HTML/MD 抓取、表格提取、diff 通用逻辑）
       ├─ DeepSeekParser / MiniMaxParser / MoonshotParser / BaiduParser / ZhipuMarkdownParser
       ├─ TencentTokenHubParser / VolcengineDocParser / SiliconflowParser / CmccParser
       ├─ ScnetParser / UnicomParser / XaiMarkdownParser
       ├─ AnthropicParser / OpenAiMarkdownParser / GoogleParser
```
- `*MarkdownParser` 后缀 = 官方源是 Markdown 站点（如 openai `/api/docs/models/<id>.md`、xai、zhipu）；
- 官方源改版时 Parser 需**双模式兼容**（例：openai parseCatalog 同时支持新版「链接路径提取 `- [Name](/api/docs/models/<id>.md)`」与旧版表格管道提取，另有反引号兜底）。

### 4.2 新增一家厂商的步骤
1. 迁移新增 `coding_plan_vendors` 行：`code`、官方目录/pricing URL、解析器类名、proxy 标记（是否必须代理）；
2. 实现 Parser：继承 `AbstractCodingPlanParser`，产出结构化目录（模型名/档位/币种/价目/时效）；
3. `storage` 快照落盘 + `docs/upstream-snapshots/` 留档镜像；
4. `tests/coding-plan-parser-fixture.php` 增加快照回放断言（防解析退化）；
5. `coding-plan:verify-ratios` 在线比对校准（0 stale/0 change/0 failure 为基线）；
6. 注册表 notes 更新（源 URL 特性/抓取注意事项）。

### 4.3 校对流水原则
diff 出的 new/missing **不自动写库**——落「校对流水」待管理员核对（上游目录扩充大日 new/missing 会很多，需人工判别真实变化 vs 解析退化；判别方法见 operations-guide）。price 档位价目同理走人工启用。

## 5. 数据库迁移规范

- **增量式**：生产 RDS 只 `php artisan migrate --force`，**严禁 fresh/refresh**；
- 命名 `YYYY_MM_DD_NNNNNN_snake_description.php`（NNNNNN 全局递增，当前至 000024）；
- 迁移与代码改动**同一 commit** 提交，保证 pull 后 migrate 即可运行；
- 涉及数据修正（如 UPDATE 语义修正）用迁移或文档化 SQL，操作前后留记录（见 TASKS 对应轮次）；
- timestamp 列写 Carbon 对象（`now()`），**不写 int 时间戳**（严格模式存 0000-00-00 或报错——heartbeat 教训）。

## 6. 代码风格与静态检查

- **Pint**（默认 laravel preset，无自定义 pint.json）：提交前 `vendor/bin/pint --test`（当前基线 432 文件 PASS）；
- **php -l** 全量语法 0 错；
- Laravel 12 现代写法：模型 `casts()` 方法式转换、`Schedule::command(...)->name(...)->onOneServer()` 调度注册、`CACHE_STORE`（非旧 CACHE_DRIVER）。

## 7. Git 工作流与任务账本

- **双远程同源**：`origin`（GitHub）+ `gitee`（国内镜像），每轮 `git push origin main && git push gitee main`；
- **任务账本** `docs/TASKS.md`：每轮工作在「任务记录」区追加一条 `- 日期（轮次）：…`，含变更/验证/遗留；commit message 用中文轮次标题（如 `五十四：生产性能安全加固——…`）；
- 轮次收尾三件套：TASKS 记录 → commit → 双推 → `git status` 确认干净；
- 生产与本地代码同步节奏：QA 基线绿 → push → 生产 `git pull` + `migrate --force`（见 deployment.md §宝塔生产部署）。

## 8. 已知陷阱备忘（真实踩坑）

| 陷阱 | 规避 |
|---|---|
| 宝塔 php.ini 的 **ionCube Loader 必须是第一个 zend_extension** | opcache 行放 ionCube 之后，否则 fpm fatal 502 |
| 宝塔 PHP 静态编译扩展 + php.ini 动态加载**重复** | mbstring 等报 already loaded 时注释 ini 中的 .so 行 |
| 本机 `curl 127.0.0.1` 命中 nginx **default server 假 200** | 带 `--resolve www.peaseapi.com:443:127.0.0.1 https://...` 验证真 vhost |
| composer.json/特殊字符文件的编辑器精确匹配失败 | 用临时 PHP 脚本按行读写替代（用后即删）|
| `sed` 的 `a` 命令与多表达式拼一行 | 行文本会吞并后续表达式——分开执行 |
| 队列 redis 服务名差异 | `systemctl is-active redis-server redis` 一个 inactive 属正常，以 `redis-cli ping` 为准 |
| onOneServer 调度「Skipping ... ran on another server」 | 分布锁正常工作的表现，不是故障 |
| 官方源 sync 大量 pending | 不一定是 bug——对照 new/missing 明细判别「上游目录扩充大日」（见 operations-guide）|

