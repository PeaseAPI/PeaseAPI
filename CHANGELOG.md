# PeaseAPI 更新日志（CHANGELOG）

> 每轮工作明细见 `docs/TASKS.md`（任务账本）；本文件只保留里程碑。
> 格式参考 Keep a Changelog；日期为轮次完成日。

## [1.0.0] — 2026-09-13（生产首部署）

### 生产上线（五十一~五十五）
- 生产服务器 8.210.89.190（宝塔 nginx + fpm 8.3 + 阿里云 RDS）首部署：快进至 QA 基线第二十一轮代码（3400125f）+ 迁移 000020~24 + xai/minimax pricing_source_url 语义修正；在线复检全绿（verify 24 家 0 stale/0 change/0 failure、前台 200、sync 冒烟通过）。
- **第 8 个真 bug**：生产 crontab 缺 `schedule:run`（全部调度从未自动运行）——修复并以心跳端到端实证（last_heartbeat 每分钟自动更新）。
- system_instances 种子行补插（实例监控盲点修复）；redis/队列/缓存链路确认（PONG、QUEUE=CACHE=redis）。
- 15 家官方源服务器连通矩阵：**13/15 直连全通**（google-token 意外可直连），仅 anthropic/openai-token 需代理；pending 数与本地巡检一致（交叉验证）。
- 性能：config/route/view 三缓存落盘 + **opcache 开启**（踩坑：ionCube 必须为第一个 zend_extension）——前台本机 0.26s。
- 安全：.env/composer.json/*.sql/.git/runtime 等封锁 404、http→https 301、HSTS、TLS1.1~1.3。
- 巡检补全 14 项（APP_DEBUG/redis 绑定/vendor 完整性/时区/failed_jobs 定性等），历史错误全部定性为旧代码时代噪音。

### 修复的真 bug（累计 8 个）
1. 官方源 vendor 改绑导致的假新增（解析退化判别体系由此建立）
2. `--vendor` 过滤语义（sync 命令）
3. xai pricing URL 覆盖错误
4. STATUS_EXPIRED 状态缺失
5. remind-promotions 调度缺失（代码层）
6. pricing_source_url 语义错误三实例（zhipu/minimax/xai，含生产库修正）
7. provider 复检暴露的结构性失败（anthropic 快照修复）
8. 生产 crontab 调度缺失（运维层，见上）

### 平台能力（P0~P9 全系收官）
- Coding Plan 账本：15 家厂商官方源同步管线（抓取→快照→解析→diff→人工校对流水）、双池与转换 API、remind-promotions 促销提醒、使用量 5 分钟重置。
- 智能成本路由（cost-routing）+ 故障转移自愈（cost-failover/cooldown 31 断言）+ 时段折扣（28 断言）+ 多币种结算（30 断言，汇率驱动）。
- 官方源健康告警、管理端可观测与在线自检（checks 流水）、验证基线 verify-ratios（24 家在线比对）。
- 渠道与模型 Key 管理、令牌（API Key）体系、订阅/充值/支付网关、工单客服、MJ/Suno/视频任务中继、新闻聚合。
- openai 官方 models.md 改版适配：parseCatalog 双模式重写（链接路径主提取 + 反引号兜底），目录 93 款。
- 全量巡检基线：24 家 sync 0 failure；QA 本地基线第二十一轮全绿（php -l 0 错、pint PASS 432、7 条验收命令全绿）。

### 已知阻塞（外部依赖）
- 管理员核对：服务器库 anthropic 13 行 status=0 启用；各家目录扩充流水；google pricing new 3。
- 服务器代理补配：anthropic/openai-token 两家 sync 需代理出口（.env PEASE_API_HTTP_PROXY）。
- 数据/凭据：P3-2 腾讯 TokenHub 无逐档价、P3-3 百度无积分系数表（等官方数据）、R16-1 Google CSE key。

## [0.x] — 2026-09 早期
- 自 New-API 思路重写：多渠道模型网关 + 用户/分组/令牌体系（详单见 TASKS 一~五十轮）。
