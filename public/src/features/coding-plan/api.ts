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
import { api } from '@/lib/api'

import type {
  ApiResponse,
  CodingPlanAccount,
  CodingPlanAdminPlan,
  CodingPlanCatalogApplyResult,
  CodingPlanCatalogTemplate,
  CodingPlanChecks,
  CodingPlanModelRatio,
  CodingPlanPromotion,
  CodingPlanRates,
  CodingPlanStats,
  CodingPlanUsageLog,
  CodingPlanVendor,
  CodingPlanVendorModels,
  CodingPlanVendorTier,
  PaginatedResponse,
} from './types'

const BASE = '/api/coding_plan'

// ============================================================================
// Accounts
// ============================================================================

export async function getAccounts(): Promise<
  PaginatedResponse<CodingPlanAccount>
> {
  const res = await api.get(`${BASE}/accounts`, { params: { per_page: 100 } })
  return res.data
}

export type AccountPayload = Record<string, unknown>

export async function createAccount(
  data: AccountPayload
): Promise<ApiResponse<CodingPlanAccount>> {
  const res = await api.post(`${BASE}/accounts`, data)
  return res.data
}

export async function updateAccount(
  id: number,
  data: AccountPayload
): Promise<ApiResponse<CodingPlanAccount>> {
  const res = await api.put(`${BASE}/accounts/${id}`, data)
  return res.data
}

export async function deleteAccount(id: number): Promise<ApiResponse> {
  const res = await api.delete(`${BASE}/accounts/${id}`)
  return res.data
}

export async function resetAccountUsage(
  id: number,
  period: string
): Promise<ApiResponse<CodingPlanAccount>> {
  const res = await api.post(`${BASE}/accounts/${id}/reset_usage`, { period })
  return res.data
}

export async function getAccountUsage(
  id: number,
  params?: { page?: number; per_page?: number }
): Promise<PaginatedResponse<CodingPlanUsageLog>> {
  const res = await api.get(`${BASE}/accounts/${id}/usage`, { params })
  return res.data
}

// ============================================================================
// Vendors
// ============================================================================

export async function getVendors(): Promise<ApiResponse<CodingPlanVendor[]>> {
  const res = await api.get(`${BASE}/vendors`)
  return res.data
}

export type VendorPayload = Record<string, unknown>

export async function createVendor(
  data: VendorPayload
): Promise<ApiResponse<CodingPlanVendor>> {
  const res = await api.post(`${BASE}/vendors`, data)
  return res.data
}

export async function updateVendor(
  id: number,
  data: VendorPayload
): Promise<ApiResponse<CodingPlanVendor>> {
  const res = await api.put(`${BASE}/vendors/${id}`, data)
  return res.data
}

export async function deleteVendor(id: number): Promise<ApiResponse> {
  const res = await api.delete(`${BASE}/vendors/${id}`)
  return res.data
}

// ============================================================================
// Model Ratios
// ============================================================================

export async function getRatios(): Promise<
  PaginatedResponse<CodingPlanModelRatio>
> {
  const res = await api.get(`${BASE}/ratios`, { params: { per_page: 200 } })
  return res.data
}

export type RatioPayload = Record<string, unknown>

export async function createRatio(
  data: RatioPayload
): Promise<ApiResponse<CodingPlanModelRatio>> {
  const res = await api.post(`${BASE}/ratios`, data)
  return res.data
}

export async function updateRatio(
  id: number,
  data: RatioPayload
): Promise<ApiResponse<CodingPlanModelRatio>> {
  const res = await api.put(`${BASE}/ratios/${id}`, data)
  return res.data
}

export async function deleteRatio(id: number): Promise<ApiResponse> {
  const res = await api.delete(`${BASE}/ratios/${id}`)
  return res.data
}

// ============================================================================
// Vendor Tiers（供应商官方套餐档位：个人版/团队版等）
// ============================================================================

export async function getTiers(params?: {
  vendor_code?: string
}): Promise<ApiResponse<CodingPlanVendorTier[]>> {
  const res = await api.get(`${BASE}/tiers`, { params })
  return res.data
}

export type TierPayload = Record<string, unknown>

export async function createTier(
  data: TierPayload
): Promise<ApiResponse<CodingPlanVendorTier>> {
  const res = await api.post(`${BASE}/tiers`, data)
  return res.data
}

export async function updateTier(
  id: number,
  data: TierPayload
): Promise<ApiResponse<CodingPlanVendorTier>> {
  const res = await api.put(`${BASE}/tiers/${id}`, data)
  return res.data
}

export async function deleteTier(id: number): Promise<ApiResponse> {
  const res = await api.delete(`${BASE}/tiers/${id}`)
  return res.data
}

// ============================================================================
// Stats
// ============================================================================

export async function getStats(): Promise<ApiResponse<CodingPlanStats>> {
  const res = await api.get(`${BASE}/stats`)
  return res.data
}

// ============================================================================
// Plans（订阅套餐绑定）
// ============================================================================

export async function getPlans(params?: {
  vendor?: string
  page?: number
  per_page?: number
}): Promise<PaginatedResponse<CodingPlanAdminPlan>> {
  const res = await api.get(`${BASE}/plans`, { params })
  return res.data
}

export type PlanAttachPayload = {
  vendor: string
  coding_submits_per_request?: number
  coding_quota?: number
}

export async function attachPlan(
  id: number,
  data: PlanAttachPayload
): Promise<ApiResponse<CodingPlanAdminPlan>> {
  const res = await api.post(`${BASE}/plans/${id}/attach`, data)
  return res.data
}

export async function detachPlan(
  id: number,
  force = false
): Promise<ApiResponse<CodingPlanAdminPlan>> {
  // skipErrorHandler：422（存在活跃订阅）由调用方捕获后引导「强制解绑」，
  // 避免全局拦截器先弹一次错误提示
  const res = await api.post(`${BASE}/plans/${id}/detach`, null, {
    params: force ? { force: 1 } : undefined,
    skipErrorHandler: true,
  } as Record<string, unknown>)
  return res.data
}

// ============================================================================
// Catalog（官方模板目录：预置档位/折算标准一键落地）
// ============================================================================

export async function getCatalog(): Promise<
  ApiResponse<CodingPlanCatalogTemplate[]>
> {
  const res = await api.get(`${BASE}/catalog`)
  return res.data
}

export async function applyCatalogTemplate(
  code: string,
  options?: { activate_vendor?: boolean }
): Promise<
  ApiResponse<{ result: CodingPlanCatalogApplyResult; vendor: unknown }>
> {
  const res = await api.post(`${BASE}/catalog/${code}/apply`, {
    activate_vendor: options?.activate_vendor ?? false,
  })
  return res.data
}

// ============================================================================
// Checks（定时校对结果 + 待确认变更：应用走 ratios CRUD，忽略走 ignore）
// ============================================================================

export async function getChecks(): Promise<ApiResponse<CodingPlanChecks>> {
  const res = await api.get(`${BASE}/checks`)
  return res.data
}

export async function ignoreCheckChange(
  vendor: string,
  key: string,
  undo = false
): Promise<ApiResponse<{ keys: string[]; ignored: boolean }>> {
  const res = await api.post(`${BASE}/checks/ignore`, {
    vendor,
    key,
    undo,
  })
  return res.data
}

// ============================================================================
// Rates（币种汇率维护，P7-2/P7-5）
// ============================================================================

export async function getRates(): Promise<ApiResponse<CodingPlanRates>> {
  const res = await api.get(`${BASE}/rates`)
  return res.data
}

export type RatePayload = {
  code: string
  rate: number
  source?: string
  remark?: string
}

export async function storeRate(data: RatePayload): Promise<ApiResponse> {
  const res = await api.post(`${BASE}/rates`, data)
  return res.data
}

export async function destroyRate(code: string): Promise<ApiResponse> {
  const res = await api.delete(`${BASE}/rates/${code}`)
  return res.data
}

// ============================================================================
// Promotions（厂商活动维护，P2-1/P2-3/P7-5）
// ============================================================================

export async function getPromotions(params?: {
  vendor?: string
  all?: boolean
}): Promise<ApiResponse<{ promotions: CodingPlanPromotion[]; now: number }>> {
  const res = await api.get(`${BASE}/promotions`, {
    params: params?.all ? { vendor: params.vendor, all: 1 } : { vendor: params?.vendor },
  })
  return res.data
}

export type PromotionPayload = Record<string, unknown>

export async function storePromotion(
  data: PromotionPayload
): Promise<ApiResponse<CodingPlanPromotion>> {
  const res = await api.post(`${BASE}/promotions`, data)
  return res.data
}

export async function destroyPromotion(id: number): Promise<ApiResponse> {
  const res = await api.delete(`${BASE}/promotions/${id}`)
  return res.data
}

// ============================================================================
// 厂商模型上架清单（P8 上架流：清单聚合 / 批量启停 / 目录变更应用）
// ============================================================================

export async function getVendorModels(
  code: string
): Promise<ApiResponse<CodingPlanVendorModels>> {
  const res = await api.get(`${BASE}/vendors/${code}/models`)
  return res.data
}

export async function batchUpdateModelStatus(
  code: string,
  ids: number[],
  status: 0 | 1
): Promise<ApiResponse<{ updated: number }>> {
  const res = await api.post(`${BASE}/vendors/${code}/models/batch_status`, {
    ids,
    status,
  })
  return res.data
}

export async function applyCatalogChanges(data: {
  vendor: string
  action: 'new' | 'missing'
  models: string[]
}): Promise<ApiResponse<{ applied: number; skipped: number }>> {
  const res = await api.post(`${BASE}/catalog_changes/apply`, data)
  return res.data
}
