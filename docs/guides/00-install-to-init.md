# 分步指南 00：从安装到可服务（Install → Init）

> 目标：一台干净服务器/本机，从零到「管理员登录 + 首次 API 调用成功」。
> 部署方式选择见 [docs/deployment.md](../deployment.md)（独立服务器 / 宝塔 / Docker）。本指南假设已按部署文档装好 LNMP/Laravel 依赖。
> 后续步骤：[01 渠道·模型·Key](01-setup-channels-models-keys.md) → [02 Coding 池](02-setup-coding-pool.md) → [03 客户·令牌·分组](03-setup-customers-tokens.md)

## 第 1 步：获取代码与依赖

```bash
git clone https://github.com/<org>/PeaseAPI.git && cd PeaseAPI
composer install --no-dev -o        # 生产；本地开发去掉 --no-dev
npm install && npm run build        # 前端资产（若使用构建产物部署可跳过）
cp .env.example .env
php artisan key:generate
```

## 第 2 步：配置 `.env` 数据库与缓存

最少必改项（其余见 [settings-reference](../settings-reference.md)）：

```dotenv
APP_NAME=PeaseAPI
APP_ENV=production            # 本地开发用 local
APP_DEBUG=false               # 生产必须 false
APP_URL=https://your-domain.com
DB_HOST=127.0.0.1  DB_DATABASE=peaseapi  DB_USERNAME=...  DB_PASSWORD=...
CACHE_STORE=redis             # Laravel 12 键名（非 CACHE_DRIVER）
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
SESSION_DRIVER=file           # 多实例部署改 redis
PEASE_API_HTTP_PROXY=         # 大陆环境抓官方源必填，如 http://127.0.0.1:7890；海外服务器可留空
APP_TIMEZONE=Asia/Shanghai    # 调度与快照时间戳基准
```

## 第 3 步：运行安装向导（二选一）

**方式 A：Web 向导**（推荐新手）——浏览器打开 `https://your-domain.com/install`：

| 步骤 | 页面内容 | 操作 |
|---|---|---|
| step 1 | 环境自检 + 数据库连接 | 填 DB 主机/库名/账号密码，提交即写入 `.env` |
| migrating | 自动执行 `php artisan migrate` | 等待完成（失败时看页面报错与 `storage/logs/laravel.log`）|
| step 2 | 管理员账号 | 设置 root 用户名/密码（此账号等级 100，拥有全部权限）|
| step 3 | 完成 | 提示删除/禁用 install 入口，跳转登录 |

**方式 B：CLI**（适合脚本化）：

```bash
php artisan pease:install     # 引导式：建表 + 管理员 + 默认渠道，交互按提示回车/输入
# 或手工：php artisan migrate --force && php artisan db:seed（如有种子）
```

## 第 4 步：登录管理后台并核对

1. 访问 `/login`，用 step 2 建的 root 账号登录；
2. 进入 **管理后台 → 仪表盘**（`/admin/dashboard`）：确认版本/数据表/实例心跳（`system-info` 页应有本机一行，心跳时间为最近一分钟——没有则先补 crontab，见第 5 步）；
3. 进入 **系统设置**（`/admin/system-settings`）：逐项核对站点名、注册开关、充值方式、通知方式（详细键位见 settings-reference）。

## 第 5 步：挂上调度（生产必做，漏了所有定时任务不会跑）

```bash
crontab -e
# 追加一行（路径换成你的项目绝对路径，php 用绝对路径最稳）：
* * * * * cd /var/www/peaseapi && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

验证：等 1 分钟后 `system-info` 页心跳刷新，或 `php artisan schedule:run` 手动跑一次退出码 0。心跳首次需要有本机行：多实例/新机器访问 `system-info` 页用「清理无效实例」+ 重新登记，或手工插入 `system_instances(node_name, ip, capabilities, last_heartbeat)`（node_name 必须等于 `config('app.name')`）。

## 第 6 步：队列 worker（有异步任务需求时）

MJ/Suno/视频任务轮询、统计聚合走队列（QUEUE_CONNECTION=redis）：

```bash
# 推荐 Supervisor 常驻（完整配置见 deployment.md §队列与定时任务）
php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
```

## 第 7 步：生产加固清单（逐项打勾）

- [ ] `APP_DEBUG=false`、`APP_ENV=production`
- [ ] crontab `schedule:run` 已挂
- [ ] 三缓存：`php artisan config:cache && php artisan route:cache && php artisan view:cache`（改 .env 后记得重跑 config:cache）
- [ ] opcache 已启用且为 ionCube 之后（宝塔专坑，见 deployment.md §性能优化）
- [ ] nginx 封锁 `.env`/`.git`/`storage`（deployment.md §Nginx 配置参考的 location 已含）
- [ ] redis `bind 127.0.0.1`（建议再设 requirepass 并同步 .env REDIS_PASSWORD）
- [ ] https + HSTS
- [ ] 自检跑一遍：`php artisan coding-plan:verify-ratios`（0/0/0 为基线）

## 下一步

- 要接上游模型、给客户发 Key → [01 渠道·模型·Key](01-setup-channels-models-keys.md)
- 要上 Coding Plan 账号池（订阅制上游池化）→ [02 Coding 池](02-setup-coding-pool.md)
- 要给客户开户/发令牌/充值 → [03 客户·令牌·分组](03-setup-customers-tokens.md)
