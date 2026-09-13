# 分步 05 · 管理员日常运维 SOP（节奏化清单）

> 定位：[运维手册](../operations-guide.md)按主题组织原理与细节，本篇按**时间节奏**（每日 / 每周 / 每月 / 按需）重排成可打勾的巡检清单，并补充对账、备份、升级三个 operations-guide 未展开的实操块。所有命令均对齐仓库实况（`app/Console/Commands/` 18 个命令签名逐一核对）。

## 每日巡检（约 5 分钟）

- [ ] **服务探活**：`curl -fsS -o /dev/null -w '%{http_code}' https://你的域名/` 返回 `200`；中转探针发一条最小请求确认 relay 链路（见 [01 篇端到端验证](01-setup-channels-models-keys.md)）。
- [ ] **队列健康**：`php artisan queue:failed | head` ——failed_jobs 无新增即健康；持续增长说明有坏任务，先 `php artisan queue:failed` 看异常栈，修复后 `php artisan queue:retry all`（确认废弃才 `queue:flush`）。
- [ ] **调度心跳**：`php artisan schedule:list` 核对任务注册齐全；`storage/logs/laravel.log` 无 schedule 相关报错。多实例部署确认共享缓存驱动（`onOneServer` 依赖锁）。
- [ ] **订单面**：管理后台 → 订阅订单（`/admin/subscription-orders`）——「待支付」卡片是否异常堆积（正常应接近 10 分钟超时取消节奏的滚动值）、已支付曲线是否符合日常。
- [ ] **官方源红点**：管理后台侧栏若出现红色计数徽章，进 Coding Plan 池「官方同步」页查看告警源（连续 ≥2 次失败才升级告警，成功一次自动复位）。
- [ ] **工单**：`/admin/tickets` 处理「待处理」队列。
- [ ] **Coding 账号池**：`/admin/coding-plan` 扫一眼冷却中账号数与窗口用量（80% 阈值熔断机制见 [02 篇第 7 步](02-setup-coding-pool.md)）。

## 每周

- [ ] **额度对账**（口径实证于生产：`Δuser_quota == Δlog_sum`）：

  ```sql
  -- t1 → t2 区间（unix 时间戳）消费侧
  SELECT SUM(quota) FROM logs WHERE type = 2 AND created_at BETWEEN {t1} AND {t2};
  -- 总账侧：t1、t2 两个时点各执行一次，做差
  SELECT SUM(used_quota) FROM users;
  ```

  两值相等即账目闭环；小额偏差属正常（预扣未回冲等边界），**持续放大**才需排查（常见：直改库未走 QuotaService、回滚路径异常）。
- [ ] **日志体积**：`du -sh storage/logs/`；日志保留策略核对（`logs:clean` 默认 `--days=30`，调度每日 03:00 自动执行；深度清理走后台维护页异步任务）。
- [ ] **快照体积**：`du -sh storage/app/private/coding-plan-snapshots/*/` ——每源自动滚动保留 10 份，异常膨胀说明归档逻辑异常。
- [ ] **备份 + 恢复演练**（见下节「备份要点」）；只备份不演练 = 没有备份。
- [ ] **校对清单清零**：`/admin/coding-plan` 官方同步页把 `coding_plan_ratio_checks` 待确认项应用/忽略完毕，避免价格漂移长期挂账。

## 备份要点

```bash
# 数据库（--single-transaction 保证 InnoDB 一致性，不锁表）
mysqldump -u{USER} -p --single-transaction --routines {DB_NAME} | gzip > backup-$(date +%F).sql.gz

# 快照归档（官方定价源历史快照，含审计价值）
tar czf snapshots-$(date +%F).tgz storage/app/private/coding-plan-snapshots

# 环境配置（含密钥，注意保管权限）
cp .env .env.backup-$(date +%F)
```

> 库名/路径按实际 `.env` 调整；异地存放；每月至少一次把最近备份灌进临时库验证可恢复。

## 每月

- [ ] **依赖体检**：`composer outdated`（重点看 laravel/framework 大版本）+ `composer audit`（安全通告）；升级前先看 CHANGELOG 与本仓库 `docs/development.md` 的已知陷阱备忘。
- [ ] **自检全家桶**（全部事务内运行、零残留，输出 ALL PASS 即健康）：

  ```bash
  php artisan coding-plan:test-currency
  php artisan coding-plan:test-time-discounts
  php artisan coding-plan:test-cooldown-recovery
  php artisan coding-plan:test-cost-routing
  php artisan coding-plan:test-cost-failover
  ```

- [ ] **性能复盘**：`/admin/performance` 导出/截图存档后 reset，对比上月曲线（慢查询、内存 GC 频率）。
- [ ] **账号安全**：管理员密码轮换；`.env` 密钥项核对无 `******` 误覆盖残留（保存时被跳过不会清空，但要人工确认）。

## 按需（事件驱动 SOP）

### A. 改了 `.env` 或直接改库

1. `php artisan config:clear`（改 `.env` 后）；
2. 直接改库的系统设置：`php artisan cache:clear`（Option 单键缓存）；
3. 改了 blade 视图：`php artisan view:clear`；
4. 生产 opcache `validate_timestamps=0` 时必须 `systemctl reload php-fpm`；
5. 改动涉及后台命令/队列逻辑：`systemctl restart pease-queue pease-schedule`（队列 worker 启动即载入新代码）。

### B. 版本升级（git pull 部署序列）

```bash
cd /path/to/peaseapi
git pull
composer install --no-dev --optimize-autoloader   # composer.lock 有变更时
php artisan migrate --force                        # git log 出现 database/migrations 新文件时
php artisan config:clear && php artisan view:clear
systemctl reload php-fpm                           # opcache validate_timestamps=0 场景必须
systemctl restart pease-queue pease-schedule       # 有后台逻辑改动时
```

> 回滚：`git reset --hard {上一个验证过的 tag/commit}` + 恢复对应备份；migrate 回滚慎用（优先前滚修复）。

### C. Relay 全挂 / 无可用渠道

按 [01 篇排查表](01-setup-channels-models-keys.md)走：渠道列表确认未被 auto_ban 连坐 → `php artisan channel:sync-cache` 强刷能力缓存 → `php artisan ability:fix` 修复渠道能力不一致 → 单渠道「测试」验证连通 → 复核最近请求日志的错误分布（`/admin/logs`）。

### D. 支付未入账

1. 管理后台 → 订阅订单（筛「待支付」+ 按用户定位该用户的订单流水号）；
2. 商户后台核对回调地址是否为 `/api/user/epay/notify`、密钥是否与 `EpayKey` 一致（日志搜 `epay notify sign error`）；
3. 已取消但用户称已付：直接重放有效回调即自动履约（幂等，勿手工改状态），核对订单最终 `status=1`；
4. 细节见[运维手册 §6 支付行](../operations-guide.md)。

### E. 官方源同步失败

`php artisan coding-plan:sync-official --vendor={code}` 单源复跑定位；境外源确认 `PEASE_API_HTTP_PROXY`（改后重启调度/队列进程）；国内源直连不受代理影响。详见[运维手册 §6 末行](../operations-guide.md)。

### F. 邮件/队列堆积

`php artisan queue:failed` 看失败栈 → `queue:retry all`；改 SMTP 配置后必须 `queue:restart`（worker 按启动时配置缓存连接，见[运维手册](../operations-guide.md)邮件链路说明）。

## 附录 A · 命令速查（对齐 `app/Console/Commands/`）

| 命令 | 用途 |
|------|------|
| `subscription:reset` | 订阅每日重置/自动续费（手动执行同调度逻辑） |
| `orders:cancel-expired {--minutes=}` | 超时取消未支付订单（缺省读窗口配置） |
| `ability:fix` | 修复渠道能力不一致（事务内重建） |
| `channel:sync-cache` | 强刷渠道能力缓存 |
| `task:poll` | 手动轮询 MJ/Suno/视频异步任务 |
| `pricing:refresh` | 刷新定价缓存 |
| `logs:clean {--days=30}` | 清理过期日志 |
| `pease:clean-avatar {--dry-run}` | 头像数据清理（先预览） |
| `coding-plan:sync-official {--vendor=} {--snapshot-only}` | 官方源同步（先落快照再 diff） |
| `coding-plan:verify-ratios` | 折算比率校对（产出待确认清单） |
| `coding-plan:remind-promotions {--dry-run}` | 活动到期提醒（零写入预览） |
| `coding-plan:reset` | Coding Plan 用量窗口重置/过期禁用 |
| `coding-plan:test-*`（5 个） | 自检：货币/时段折扣/冷却/成本路由/故障转移 |
| `pease:install` | 安装向导（幂等，可重复执行） |

## 附录 B · 巡检页签索引

| 页面 | 用途 |
|------|------|
| `/admin/dashboard` | 总量与最近动态 |
| `/admin/subscription-orders` | 订单四态筛选 + 已支付合计 |
| `/admin/tickets` | 工单队列 |
| `/admin/coding-plan` | 账号池/同步红点/校对清单 |
| `/admin/logs` | 全局请求与记账日志 |
| `/admin/performance` | 性能指标与 GC |
| `/admin/system-info` | 实例心跳/清理 |

## 系列导航
[00 安装到初始化](00-install-to-init.md) ｜ [01 渠道·模型·Key](01-setup-channels-models-keys.md) ｜ [02 Coding 池](02-setup-coding-pool.md) ｜ [03 客户·令牌·分组](03-setup-customers-tokens.md) ｜ [04 订阅·支付·工单](04-setup-subscriptions-payments.md) ｜ **05 日常运维 SOP（本篇）**
