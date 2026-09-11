# 上游数据源快照索引 — 2026-09-12

本目录是「预存校对」的官方数据基准存档。**抓取与校对协议见 `/Users/snails/Project/PeaseAPI/docs/TASKS.md`**（恢复协议 + 任务清单 P1 适配器 + 厂商数据源权威表 §5）。

## 本轮抓取结果（2026-09-12）

| 厂商 | 结果 | 详见 pricing-data.md |
|---|---|---|
| DeepSeek | ✅ 全表（含错峰时段） | §1 |
| OpenAI | ✅ 全表（代理 + `.md` 后缀技巧；Batch 半价；促销至 2026-11-21） | §2 |
| Google Gemini | ✅ 全表（促销价至 2026-12-31，2027 起恢复） | §3 |
| xAI Grok | ✅ grok-4.6 + 退役公告 | §4 |
| 智谱 GLM | ✅ 积分系数 + 夜间畅用活动 | §5 |
| 阿里云百炼 | ✅ Token Plan 个人版限时价 + 夜间五折 | §6 |
| 腾讯云 TokenHub | ✅ 通用/Hy 8 档 + GLM-5/5.1 下线预告 | §7 |
| SiliconFlow | ✅ 全量价目 + 时段价 | §8 |
| Kimi/Moonshot | ✅ K3 发布；价目表 SPA 待探测 | §9 |
| MiniMax | ⏳ SPA 待探测 | §10 |
| 火山/联通/移动 | ⏳ SPA 待探测（库内已有 2026-09-11 核对预置） | §11 |
| Anthropic | ⚠️ 区域封锁（代理下仍拦截正文），备选 `.md` | — |
| 百度千帆 | ⏳ 文档框架已抓，价格表待定位 | — |

## 快照文件约定

- 本目录按日期存放 Markdown 快照：`YYYY-MM-DD/pricing-data.md`。
- P1-4 落地后，机器可读快照将写入 `storage/app/coding-plan-snapshots/{vendor}/{Y-m-d-Hi}.json`（保留 10 份，运行时产物不入库）。
- 本目录的 Markdown 快照作为人读审计与解析器 fixture（P1-10 单元测试输入），**随代码库提交**。
