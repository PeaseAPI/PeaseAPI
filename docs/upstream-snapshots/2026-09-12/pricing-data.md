# 上游官方数据源快照 — 2026-09-12

> 用途：①「预存校对」基准（每 6 小时抓取结果与此 diff）；②P1 适配器解析器的 fixture。抓取环境：macOS，国内直连 + 代理 `http://127.0.0.1:7890`（OpenAI/Google/xAI/Anthropic 走代理）。本文件记录**原始数值**，禁止凭记忆修改——再抓取后以新快照追加，不改旧快照。

---

## 1. DeepSeek（✅ 2026-09-12 抓取，api-docs.deepseek.com/zh-cn/quick_start/pricing/）

模型：`deepseek-flash`（DeepSeek-V4.1-Flash）、`deepseek-v4-pro`（DeepSeek-V4-Pro-0813）。上下文 1M，输出最大 384K。
Base URL：OpenAI 格式 `https://api.deepseek.com`；Anthropic 格式 `https://api.deepseek.com/anthropic`。

**时段定义：高峰=北京时间周一至五 9:00–12:00、14:00–18:00；其余为空闲；空闲价=高峰价的一半（单位：元/百万 tokens）**

| 模型 | 缓存命中 空闲 | 缓存命中 高峰 | 缓存未命中 空闲 | 缓存未命中 高峰 | 输出 空闲 | 输出 高峰 |
|---|---|---|---|---|---|---|
| deepseek-flash | 0.02 | 0.04 | 1.0 | 2.0 | 4.0 | 8.0 |
| deepseek-v4-pro | 0.15 | 0.30 | 4.5 | 9.0 | 13.5 | 27.0 |

扣费：费用=token×单价，优先扣赠送余额。旧名 `deepseek-v4-flash` / `deepseek-v4-flash-vision-exp` 仍可调用，自动路由到 flash 计价。
事件：**2026-09-14 后继续提供 V4 Pro API**（官方已宣布延续，计费不变）。并发：flash 2500 / v4-pro 500。

## 2. OpenAI（✅ 2026-09-12 代理抓取，platform.openai.com/docs/pricing.md —— .md 后缀拿 Markdown）

标准价（USD/百万 tokens，Short context；括号=Long context）：

| 模型 | 输入 | 缓存读 | 缓存写 | 输出 |
|---|---|---|---|---|
| gpt-6-astra | 10.00（20.00） | 1.00（2.00） | 12.50（25.00） | 50.00（75.00） |
| gpt-5.6-sol | 4.00（8.00） | 0.40（0.80） | 5.00（10.00） | 20.00（30.00） |
| gpt-5.6-terra | 2.00（4.00） | 0.20（0.40） | 2.50（5.00） | 12.00（18.00） |
| gpt-5.6-luna | 0.20（0.40） | 0.02（0.04） | 0.25（0.50） | 1.20（1.80） |
| gpt-5.5 | 5.00（10.00） | 0.50（1.00） | - | 30.00（45.00） |
| gpt-5.5-pro | 30.00（60.00） | - | - | 180.00（270.00） |
| gpt-5.4 | 2.50（5.00） | 0.25（0.50） | - | 15.00（22.50） |
| gpt-5.4-mini / nano | 0.75 / 0.20 | 0.075 / 0.02 | - | 4.50 / 1.25 |
| gpt-5.4-pro | 30.00（60.00） | - | - | 180.00（270.00） |
| gpt-5.2 / gpt-5.2-pro | 1.75 / 21.00 | 0.175 / - | - | 14.00 / 168.00 |
| gpt-5.1 / gpt-5 | 1.25 | 0.125 | - | 10.00 |
| gpt-5-mini / nano | 0.25 / 0.05 | 0.025 / 0.005 | - | 2.00 / 0.40 |
| gpt-5-pro | 15.00 | - | - | 120.00 |
| gpt-4.1 / mini / nano | 2.00 / 0.40 / 0.10 | 0.50 / 0.10 / 0.025 | - | 8.00 / 1.60 / 0.40 |
| gpt-4o / gpt-4o-mini | 2.50 / 0.15 | 1.25 / 0.075 | - | 10.00 / 0.60 |
| o1 / o3 / o4-mini / o3-mini | 15.00 / 2.00 / 1.10 / 1.10 | 7.50 / 0.50 / 0.275 / 0.55 | - | 60.00 / 8.00 / 4.40 / 4.40 |
| o1-pro / o3-pro | 150.00 / 20.00 | - | - | 600.00 / 80.00 |
| gpt-3.5-turbo | 0.50 | - | - | 1.50 |

- **Batch（异步批处理）统一 5 折**（如 gpt-5.6-sol 输入 2.00 / 输出 10.00）。
- **活动：GPT-5.6 Sol 促销价至少持续至 2026-11-21**。
- 区域处理（data residency）端点对 2026-03-05 后发布的模型加价 10%。
- Priority processing 已于 2026-07-30 更名 Fast mode（`service_tier: "fast"`）。

## 3. Google Gemini（✅ 2026-09-12 代理抓取，ai.google.dev/gemini-api/docs/pricing）

⚠️ **全表促销价「through December 31, 2026」，2027-01-01 起恢复原价（×2）**（适配器需记录双价与切换日）。

| 模型 | 输入 | 输出(含思考) | 缓存 | 档位 |
|---|---|---|---|---|
| gemini-3.8-flash | 0.75 → 2027: 1.50 | 3.75 → 7.50 | 0.0375 → 0.075；存储 $0.50/1M tokens·时 → 1.00 | Standard |
| gemini-3.8-flash (Flex) | 0.375 → 0.75 | 1.875 → 3.75 | 同上 | Flex |
| gemini-3.8-flash (Priority) | 1.35 → 2.70 | 6.75 → 13.50 | 0.135 → 0.27 | Priority |
| gemini-3.7-flash | 0.75 → 1.50 | 3.75 → 7.50 | 0.075 → 0.15 | Standard |
| gemini-3.7-flash (Batch/Flex) | 0.375 → 0.75 | 1.875 → 3.75 | 同上 | Batch/Flex |
| gemini-3.7-flash (Priority) | 1.35 → 2.70 | 6.75 → 13.50 | 0.135 → 0.27 | Priority |

- Batch API = 50% 成本降低；Grounding with Google Search：3.x 共享每月 5,000 次免费后 $14/1,000 次。

## 4. xAI Grok（✅ 2026-09-12 代理抓取，docs.x.ai/docs/models）

- **grok-4.6**（旗舰，500K 上下文）：输入 $2.00 / 1M，输出 $6.00 / 1M。
- Imagine：图像 1K/2K 起 $0.02/张；视频 480p/720p/1080p 起 $0.05/秒。
- 语音：Agent $0.05/分起；TTS $15.00/1M 字符；STT batch $0.10/时、streaming $0.20/时。
- **⚠️ 退役公告：Imagine 图像质量相关 Retirement Nov 2, 2026；另一 Model Retirement May 15, 2026**（适配器应抓详情页并生成 model_retirement 活动）。
- 知识截止：grok-4.6 = 2026-02-01。别名机制：`<model>` / `<model>-latest` 自动迁移。

## 5. 智谱 GLM（✅ 2026-09-12 抓取，docs.bigmodel.cn/cn/coding-plan/overview）

**积分公式：消耗积分 =（输入 Token×Input 系数 + 缓存命中 Token×Cached 系数 + 输出 Token×Output 系数）/ 10000；MCP 积分 = 次数 × Output 系数**

| 模型 | Input | Cached Input | Output |
|---|---|---|---|
| GLM-5.3 | 6.9 | 1.7 | 24 |
| GLM-5.3-Flash（含视觉 MCP） | 2.3 | 0.56 | 8 |
| MCP：联网搜索 / 网页读取 / 开源仓库 | - | - | 1.2 |

**时段折扣：高峰=周一至五 14:00–18:00（UTC+8）按 1 倍；其余时段按基础积分 50% 抵扣。**
套餐（5 小时积分 / 周积分）：Lite 2,000 / 10,000；Pro 12,000 / 60,000；Max 28,000 / 140,000。
5 小时积分动态刷新（请求消耗 5 小时后重置）；周积分自下单起 7 天一周期。
**活动：夜间畅用 每日 23:00–次日 09:00**——ZCode 端 GLM-5.3-Flash 无限畅用，其他 Agent 额度翻倍（结束时间未公布）。
模型路由：GLM-5.2/5.1 自动切 GLM-5.3；GLM-5-Turbo/GLM-4.7 自动切 GLM-5.3-Flash。
额度参考（缓存命中率 95–98%）：GLM-5.3 Lite 0.48–1.04 亿 tokens/周；Pro 2.90–5.95；Max 6.76–14.63 亿/周。
OpenClaw 采用次级调度与尽力交付（Coding Agent 任务优先）。

## 6. 阿里云百炼 Token Plan 个人版（✅ 2026-09-12 抓取，docs.bailian.console.aliyun.com/zh/model-studio/token-plan-personal-overview）

仅华北2（北京）。**限时价（结束时间未公布）**：

| 档位 | 原价 | 限时价 | 每 7 天限额 |
|---|---|---|---|
| Lite | 60 元/月 | **39 元/月** | 2,500 Credits |
| Standard | 180 元/月 | **139 元/月** | 10,000 Credits |
| Pro | 600 元/月 | **499 元/月** | 40,000 Credits |
| 用量包 | 100 元/个/月 | - | 20,000 Credits（最多同时持有 5 个） |

- 7 天窗口自「每周期首次调用」起算，触顶暂停服务，未用完不结转；含额度重置功能（重置次数由活动发放）。
- 并发 Agent：Lite 1–2 / Standard 3–4 / Pro 6–8。
- Harness 权益（AgentStudio 免费额度+后付费折扣）独立计量，不占 Credits。
- **限时夜间五折：每晚 22:00–次日 08:00** 调用 `qwen3.8-max`、`deepseek-v4-pro-0813`、`deepseek-v4-flash-0731` Credits 五折（官方未公布三模型 Credits 三率 → 对应任务 P3-1）。
- 支持模型：qwen3.8-max/-flash、qwen3.7-max/plus、qwen3.6-flash、qwen-image-3.0-pro、qwen-audio-3.0-*（tts-plus/realtime-plus/asr-flash）、wan2.7-image(-pro)、deepseek-v4-pro(-0813)、deepseek-v4-flash-0731、glm-5.2、happyhorse-1.1（i2v/t2v/r2v）。
- `qwen3.8-max-preview` 已下线，请求自动路由至 `qwen3.8-max`（Credits 按 qwen3.8-max 计）。
- 模型内置工具（Responses API 自动触发，按 Credits 抵扣）：web_search、t2i_search、i2i_search、web_extractor、code_interpreter。
- 升级折算公式：折算 Credits =（升级总差额 ÷ 30 天）× 实际剩余有效时长；个人版暂不支持退订。

## 7. 腾讯云 TokenHub Token Plan 个人版（✅ 2026-09-12 抓取，cloud.tencent.com/document/product/1823/130060）

**自 2026-08-31 17:00 起积分抵扣模式**（单位：积分/订阅月）：

| 档位 | 通用 Token Plan | Hy Token Plan |
|---|---|---|
| Lite | 780 积分 / 39 元 | 560 积分 / 28 元 |
| Standard | 1,980 积分 / 99 元 | 1,560 积分 / 78 元 |
| Pro | 5,980 积分 / 299 元 | 4,760 积分 / 238 元 |
| Max | 11,980 积分 / 599 元 | 9,360 积分 / 468 元 |

- 通用可用模型：Auto `tc-code-latest`；DeepSeek-V4-Flash `deepseek-v4-flash-202605`（原厂直供）；DeepSeek-V4-Pro `deepseek-v4-pro-202606`（原厂直供）；MiniMax-M2.7 / M3；**GLM-5 / GLM-5.1（2026-10-09 下线）**；GLM-5.2 / 5.3 / 5.3-Flash；Kimi K2.7 Code / K3；Hy4 preview。
- Hy 可用：hy3（hy3-preview 自动路由 hy3-202608）、hy4-preview（高峰限频）。
- URL：OpenAI 兼容 `https://api.lkeap.cloud.tencent.com/plan/v3`；Anthropic 兼容 `/plan/anthropic`。两系列共用 API Key。
- 限制：每主账号各系列 1 个（共 2 个）；支持升配、不支持降配/退订；到期后 API Key 失效、余量不结转；并发 Max > Pro > Standard > Lite。
- 预估轮次：Lite ≈70 轮问答/月、Standard ≈200 轮。
- 平台声明：套餐内模型动态调整，可能新增/替换/下线；重大调整提前公告（站内信/控制台提示）。

## 8. SiliconFlow 硅基流动（✅ 2026-09-12 抓取，siliconflow.cn/pricing —— 新厂商候选，¥/百万 tokens）

时段价样例（「费用发生时段」文本 → P1-2 适配器 time_discounts 映射）：
- `tencent/Hunyuan-A13B-Instruct`：9–18 点 1.00/4.00；0–9、18–24 点 0.80/3.20
- `deepseek-ai/DeepSeek-V4-Flash`：2–8 点 1.50/4.50/缓存 0.15；其余 3.00/9.00/0.30
- `tencent/Hy4-preview`：6.00/18.00/缓存 0.30

固定价样例：GLM-5.3 8/28/2；GLM-5.2 8/28/2；GLM-5.1(Pro) 分段 [0,32k) 6/24/1.3、[32k,∞) 8/28/2；DeepSeek-V4-Pro 12/24/1；DeepSeek-V3.2 4/6/0.4；LongCat-2.0 5/20/0.1；Kimi-K2.7-Code 6.5/27/1.3；Kimi-K2.6(Pro) 6.5/27/1.1；Qwen3.8-27B 3/12；Qwen3.5-122B-A10B 分段 [0,128k) 0.8/6.4、[128k,∞) 2/16；Qwen3.6-35B-A3B 1.8/10.8 等；bge 系列 embedding/reranker 免费。

## 9. Kimi / Moonshot（✅ 2026-09-12 抓取）

- **Kimi K3 已正式发布**（2.8T 参数、1M 上下文、视觉理解；reasoning_effort low/high/max，默认 max）。
- 兼容 OpenAI 与 Anthropic 格式；编程高速版 `kimi-k2.7-code-highspeed`；K2.7 Code 支持 256K 上下文与视频输入。
- 定价表页 `platform.moonshot.cn/docs/price/chat` 为 SPA，本轮未取到表格数值（价目已在迁移预置：kimi-k3/k2.7-code(-highspeed)/k2.6，单位=元）；适配器需探测 `/docs/llms.txt`。文件相关 API 暂免费。

## 10. MiniMax（⏳ 页面为 SPA，未取得表格）

模型矩阵：MiniMax M3（1M 上下文，Coding/Agentic，MSA 架构，2026-05 发布）、M2.7、M2.5；视频 H3（2026-07）；Speech 2.8、Music 3.0；产品线 MiniMax Code / Design；官网另有「API Token Plan」入口（详情待探测）。

## 11. 火山引擎 / 联通 / 移动（⏳ SPA 探测中；库内已有 2026-09-11 核对预置）

- 火山方舟：docs 为 doccenter SPA。已知预置：Agent Plan 4 档 40/200/500/1000 元 → 2万/10万/25万/50万 AFP；13 条官方 AFP 系数（doubao-mini 0.25 → kimi-k3 10）；**auto 活动系数 0.5 至 2026-11-08**（迁移 000005）。探测方向：doccenter XHR API（SPA garfish 配置可见）。
- 联通：响应 16MB Vue 骨架，正文数据内嵌（探测 `window.__INITIAL_STATE__` 或文档 API）。已知预置：Coding Plan 2 档 40/200 元 → 1.8万/9万 次；Token Plan 个人版 15/30/45 元 → 600/1200/1800 万 tokens；团队版 198/698/1398 元 → 2.5万/10万/25万 credits（迁移 000006，1 credit ≈ 0.01 元）。
- 移动：`cloud-cms-service-web` Vue SPA。已知预置：Coding Plan 2 档 40/200 元 → 1.8万/9万 次（MiniMax-M2.5）；Token Plan 个人版 11 档月包 5–500 元 → 200–35,000 算力豆 + 次包 3 档 + 尝鲜包 9.9 元 1200 豆；团队版 1000/5000 元 → 10 亿/55 亿折算 tokens（迁移 000008，拆 cmcc-token / cmcc-token-team 两厂商防汇率混用）。

---

### 与现库的初步 diff（人工核对项 → P3-7）
1. 阿里云 Token Plan 个人版档位：需补「限时价 39/139/499」price_note + 每周限额 2500/10000/40000 + 用量包 2 万 Credits。
2. 智谱高峰窗口与迁移 000009 预置一致（周一至五 14:00–18:00 之外 5 折）✅；新增「夜间畅用」活动 → P2-2。
3. DeepSeek 时段窗口与迁移 000009 预置一致（高峰=周一至五 9:00–12:00、14:00–18:00）✅；V4 Pro 延续声明 → 无需改价。
4. 腾讯 GLM-5/5.1 下线日期 2026-10-09 → P2-2 model_retirement；通用/Hy 8 档积分价与迁移 000007 口径核对。
5. OpenAI/Google/xAI 全表为新增（P3-6）；SiliconFlow/MiniMax 为新厂商（P3-5）。
