/*
Copyright (C) 2023-2026 QuantumNous

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as
published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.

For commercial licensing, please contact support@quantumnous.com
*/

export interface ApiResponse<T = unknown> {
  success: boolean
  message?: string
  data?: T
}

export interface PaginatedData<T> {
  items: T[]
  per_page: number
  current_page: number
  last_page: number
  total: number
}

export type PaginatedResponse<T> = ApiResponse<PaginatedData<T>>

/** 计费模式：按次提交 */
export const BILLING_MODE_PER_REQUEST = 1
/** 计费模式：按积分折算 */
export const BILLING_MODE_CREDIT = 2

/** 产品类型：订阅制 Coding Plan（包月/包量） */
export const PLAN_KIND_CODING = 1
/** 产品类型：按量 Token Plan（按 token 用量折算） */
export const PLAN_KIND_TOKEN = 2

export const PLAN_KINDS: { value: string; label: string }[] = [
  { value: String(PLAN_KIND_CODING), label: '订阅制 Coding Plan' },
  { value: String(PLAN_KIND_TOKEN), label: '按量 Token Plan' },
]

/** 比率匹配模式：精确 */
export const MATCH_EXACT = 'exact'
/** 比率匹配模式：前缀 */
export const MATCH_PREFIX = 'prefix'

/** 计费口径：每次请求 */
export const COST_PER_REQUEST = 'per_request'
/** 计费口径：每千 token */
export const COST_PER_1K_TOKENS = 'per_1k_tokens'
/** 计费口径：分段折算（输入/缓存命中/输出三段独立千 token 系数，如智谱 GLM、阿里云 Credits） */
export const COST_PER_TOKEN_PARTS = 'per_token_parts'

export const MATCH_TYPES: { value: string; label: string }[] = [
  { value: MATCH_EXACT, label: '精确匹配' },
  { value: MATCH_PREFIX, label: '前缀匹配' },
]

export const COST_MODES: { value: string; label: string }[] = [
  { value: COST_PER_REQUEST, label: '每次请求' },
  { value: COST_PER_1K_TOKENS, label: '每千 token' },
  { value: COST_PER_TOKEN_PARTS, label: '分段（输入/缓存/输出）' },
]

export interface CodingPlanAccount {
  id: number
  vendor: string
  billing_mode: number
  unit_name: string
  unit_exchange_rate: number | string
  account_name: string
  channel_id: number
  base_url?: string
  quota_5h: number | string
  quota_weekly: number | string
  quota_monthly: number | string
  used_5h: number | string
  used_weekly: number | string
  used_monthly: number | string
  monthly_usage_threshold: number
  priority: number
  expires_at: number
  status: number
  remark?: string
}

export interface CodingPlanVendor {
  id: number
  /** 供应商标识（coding_plan_vendors.code，对应账号表 vendor 字段） */
  code: string
  name: string
  logo?: string | null
  billing_mode: number
  /** 1=订阅制 Coding Plan 2=按量 Token Plan */
  plan_kind?: number
  unit_name?: string | null
  unit_exchange_rate: number | string
  docs_url?: string | null
  /** 结构化定价源 URL（JSON 清单，供 coding-plan:verify-ratios 比对） */
  pricing_source_url?: string | null
  status: number
  sort: number
  remark?: string | null
  accounts_total?: number
  accounts_active?: number
}

export interface CodingPlanModelRatio {
  id: number
  vendor: string
  /** 模型名：exact 全等 / prefix 前缀匹配（前缀最长优先） */
  model: string
  /** exact | prefix */
  match_type: string
  /** per_request | per_1k_tokens | per_token_parts */
  cost_mode: string
  /** 每次请求（或每千 token）消耗的供应商单位数 */
  unit_cost: number | string
  /** 分段折算（cost_mode=per_token_parts）：输入/缓存命中/输出的千 token 折算系数 */
  input_rate?: number | string
  cached_rate?: number | string
  output_rate?: number | string
  status: number
  sort: number
  remark?: string | null
  /** 超过核对窗口（CodingPlanRatioStaleDays，默认 7 天）未人工复核，后端计算 */
  stale?: boolean
}

/** 供应商官方套餐档位（个人版/团队版/坐席/用量包，GET /coding_plan/tiers） */
export interface CodingPlanVendorTier {
  id: number
  vendor_code: string
  name: string
  /** 官方价格（null = 待核对，仅展示 price_note） */
  price?: number | string | null
  price_note?: string | null
  /** 计费周期（月 / 月/座席 等） */
  period?: string | null
  /** 套餐额度（供应商原生单位） */
  quota?: number | string | null
  quota_unit?: string | null
  quota_note?: string | null
  status: number
  sort: number
  remark?: string | null
}

export interface CodingPlanVendorOverview {
  vendor: string
  unit_name: string
  total: number
  active: number
  exhausted: number
  disabled: number
  monthly_quota: number
  monthly_used: number
  credits_quota: number
  credits_used: number
  credits_remaining: number
}

/** 管理端 Coding 套餐（GET /coding_plan/plans，已附加账号池实时概览） */
export interface CodingPlanAdminPlan {
  id: number
  title: string
  subtitle?: string | null
  price_amount: number
  currency?: string
  duration_unit?: string
  duration_value?: number
  /** 是否上架 */
  enabled?: boolean
  /** quota = 普通套餐；coding_plan = 已绑定账号池 */
  plan_type?: string
  coding_vendor?: string | null
  /** 每次请求提交数 */
  coding_submits_per_request?: number
  /** 月配额，0 表示不限 */
  coding_quota?: number
  /** 绑定后附加的账号池概览 */
  pool_overview?: CodingPlanVendorOverview | null
}

/** 最近 7 天每日消耗（success=1 口径） */
export interface CodingPlanDailyUsage {
  day: string
  submits: number | string
  units: number | string
  credits: number | string
}

export interface CodingPlanStats {
  vendors: CodingPlanVendorOverview[]
  daily_usage_7d: Record<string, CodingPlanDailyUsage>
}

/** 账号使用流水（GET /coding_plan/accounts/{id}/usage） */
export interface CodingPlanUsageLog {
  id: number
  account_id: number
  vendor: string
  user_id: number
  channel_id: number
  model?: string | null
  /** 按次口径的提交数（积分模式为 0） */
  count: number | string
  /** 供应商原生单位消耗 */
  units: number | string
  /** 折算后的平台积分 */
  credits: number | string
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  request_id?: string | null
  success: boolean | number
  error?: string | null
  /** 比率快照（积分模式） */
  meta?: Record<string, unknown> | null
  created_at: number
}

// ============================================================================
// 官方模板目录 + 定时校对待确认变更（官方同步页）
// ============================================================================

/** 官方模板目录条目（GET /coding_plan/catalog，App\Services\CodingPlanCatalog 只读视图） */
export interface CodingPlanCatalogTemplate {
  code: string
  name: string
  plan_kind: number
  billing_mode: number
  unit_name: string
  docs_url?: string | null
  /** 模板数据核对日期（null = 官方页无法程序化核对，需人工补全） */
  verified_at?: string | null
  notes?: string | null
  tier_count: number
  ratio_count: number
  /** 库内是否已存在该厂商 */
  vendor_exists: boolean
  db_tier_count: number
  db_ratio_count: number
}

/** 模板应用结果（POST /coding_plan/catalog/{code}/apply） */
export interface CodingPlanCatalogApplyResult {
  vendor_created: boolean
  tiers: { inserted: number; updated: number; skipped: number }
  ratios: { inserted: number; updated: number; skipped: number }
}

/** 待确认变更条目（定价源 diff：新增模型 / 抵扣率变化 / 老模型下架） */
export interface CodingPlanPendingChange {
  model: string
  match_type: string
  /** 应用/忽略用：kind|model|match_type */
  key?: string
  /** 已被管理员忽略（不认可定价源） */
  ignored?: boolean
  cost_mode?: string | null
  /** 源给的口径与系数（new 条目直接用于创建停用行） */
  unit_cost?: number | null
  input_rate?: number | null
  cached_rate?: number | null
  output_rate?: number | null
  /** changed（单位成本）：from → to */
  from?: number
  to?: number
  /** changed（分段系数）：[input, cached, output] from → to */
  split_rates?: boolean
  from_parts?: [number, number, number]
  to_parts?: [number, number, number]
}

/** 每供应商最近一次校对结果（GET /coding_plan/checks） */
export interface CodingPlanCheckItem {
  id: number
  vendor: string
  checked_at: number
  stale_count: number
  change_count: number
  source_status: number
  changes: {
    new?: CodingPlanPendingChange[]
    changed?: CodingPlanPendingChange[]
    missing?: CodingPlanPendingChange[]
  } | Record<string, never>
  pending_keys: string[]
}

/** GET /coding_plan/checks 响应体 */
export interface CodingPlanChecks {
  pending_total: number
  items: CodingPlanCheckItem[]
}
