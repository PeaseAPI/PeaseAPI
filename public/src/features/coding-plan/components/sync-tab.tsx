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
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Download } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'

import {
  applyCatalogTemplate,
  createRatio,
  getCatalog,
  getChecks,
  getRatios,
  getVendors,
  ignoreCheckChange,
  updateRatio,
} from '../api'
import type {
  CodingPlanCheckItem,
  CodingPlanPendingChange,
} from '../types'

/** 待确认变更的应用动作（missing=下架走 status=0，changed=改系数，new=按源新增停用行） */
type ApplyKind = 'new' | 'changed' | 'missing'

export function SyncTab() {
  const qc = useQueryClient()
  const [busy, setBusy] = useState<string | null>(null)

  const checksQuery = useQuery({ queryKey: ['coding-plan-checks'], queryFn: getChecks })
  const catalogQuery = useQuery({ queryKey: ['coding-plan-catalog'], queryFn: getCatalog })
  const vendorsQuery = useQuery({ queryKey: ['coding-plan-vendors'], queryFn: getVendors })
  // 应用变更需要比率表现状（定位已存在行 / 组装新增 payload）
  const ratiosQuery = useQuery({
    queryKey: ['coding-plan-ratios', 'all'],
    queryFn: () => getRatios(),
  })

  const invalidateAll = () => {
    qc.invalidateQueries({ queryKey: ['coding-plan-checks'] })
    qc.invalidateQueries({ queryKey: ['coding-plan-catalog'] })
    qc.invalidateQueries({ queryKey: ['coding-plan-ratios'] })
    qc.invalidateQueries({ queryKey: ['coding-plan-tiers'] })
    qc.invalidateQueries({ queryKey: ['coding-plan-vendors'] })
  }

  const applyMutation = useMutation({
    mutationFn: async ({
      item,
      kind,
      vendor,
    }: {
      item: CodingPlanPendingChange
      kind: ApplyKind
      vendor: string
    }) => {
      const matchType = item.match_type === 'prefix' ? 'prefix' : 'exact'
      const rows = ratiosQuery.data?.data?.items ?? []
      const existing = rows.find(
        (r) => r.vendor === vendor && r.model === item.model && r.match_type === matchType
      )
      if (kind === 'missing') {
        if (!existing) throw new Error(`找不到现有比率行：${item.model}`)
        return updateRatio(existing.id, { status: 0 })
      }
      if (kind === 'changed') {
        if (!existing) throw new Error(`找不到现有比率行：${item.model}`)
        const payload: Record<string, unknown> = item.split_rates
          ? {
              input_rate: item.to_parts?.[0],
              cached_rate: item.to_parts?.[1],
              output_rate: item.to_parts?.[2],
            }
          : { unit_cost: item.to }
        return updateRatio(existing.id, payload)
      }
      // new：按源新增（一律停用，管理员核对汇率后启用）
      return createRatio({
        vendor,
        model: item.model,
        match_type: matchType,
        cost_mode: item.cost_mode || 'per_1k_tokens',
        unit_cost: item.unit_cost ?? 1,
        input_rate: item.input_rate ?? null,
        cached_rate: item.cached_rate ?? null,
        output_rate: item.output_rate ?? null,
        status: 0,
      })
    },
    onSuccess: (_res, vars) => {
      toast.success(
        vars.kind === 'missing'
          ? `${vars.item.model} 已停用（老模型下架）`
          : `${vars.item.model} 已${vars.kind === 'new' ? '添加（停用态）' : '按源更新'}`
      )
      invalidateAll()
    },
    onError: (err: Error) => toast.error(err.message || '应用失败'),
  })

  const ignoreMutation = useMutation({
    mutationFn: ({ vendor, key, undo }: { vendor: string; key: string; undo?: boolean }) =>
      ignoreCheckChange(vendor, key, undo),
    onSuccess: (res) => toast.success(res.message || '已更新忽略清单'),
    onError: () => toast.error('操作失败'),
  })

  const catalogMutation = useMutation({
    mutationFn: ({ code, activate }: { code: string; activate?: boolean }) =>
      applyCatalogTemplate(code, { activate_vendor: activate }),
    onSuccess: (res) => {
      toast.success(res.message || '模板已应用')
      invalidateAll()
    },
    onError: () => toast.error('模板应用失败'),
  })

  const run = (id: string, fn: () => void) => {
    setBusy(id)
    fn()
    // mutation 状态由 react-query 管理，busy 仅防重复点击
    setTimeout(() => setBusy(null), 800)
  }

  const vendorName = (code: string) =>
    vendorsQuery.data?.data?.find((v) => v.code === code)?.name ?? code

  const items = checksQuery.data?.data?.items ?? []
  const templates = catalogQuery.data?.data ?? []

  return renderSyncTab({
    items,
    templates,
    busy,
    vendorName,
    onApply: (vendor, kind, change) =>
      run(`apply-${vendor}-${change.key ?? change.model}`, () =>
        applyMutation.mutate({ item: change, kind, vendor })
      ),
    onIgnore: (vendor, change, undo) => {
      if (!change.key) return
      ignoreMutation.mutate({ vendor, key: change.key, undo })
    },
    onApplyTemplate: (code, activate) =>
      run(`tpl-${code}-${activate ? 'a' : 'n'}`, () =>
        catalogMutation.mutate({ code, activate })
      ),
  })
}

const KIND_LABEL: Record<string, string> = {
  new: '新增模型',
  changed: '抵扣变化',
  missing: '模型下架',
}

function fmt(n: number | undefined | null): string {
  return typeof n === 'number' ? String(Number(n.toFixed(6))) : '-'
}

function fmtParts(parts: [number, number, number] | undefined): string {
  if (!parts) return '-'
  return parts.map((p) => Number(p.toFixed(6))).join(' / ')
}

type SyncTabViewProps = {
  items: CodingPlanCheckItem[]
  templates: {
    code: string
    name: string
    unit_name: string
    docs_url?: string | null
    verified_at?: string | null
    notes?: string | null
    tier_count: number
    ratio_count: number
    vendor_exists: boolean
    db_tier_count: number
    db_ratio_count: number
  }[]
  busy: string | null
  vendorName: (code: string) => string
  onApply: (vendor: string, kind: ApplyKind, change: CodingPlanPendingChange) => void
  onIgnore: (vendor: string, change: CodingPlanPendingChange, undo: boolean) => void
  onApplyTemplate: (code: string, activate?: boolean) => void
}

function renderSyncTab(props: SyncTabViewProps) {
  const { items, templates, busy, vendorName, onApply, onIgnore, onApplyTemplate } = props

  return (
    <div className='flex flex-col gap-4'>
      <Alert>
        <CheckCircle2 className='h-4 w-4' />
        <AlertTitle>官方同步工作流</AlertTitle>
        <AlertDescription className='text-muted-foreground text-sm'>
          ① 从下方模板一键添加厂商（档位+折算标准全部停用态预置）→ ② 设置汇率并启用比率 →
          ③ 定时任务每 6 小时比对定价源，发现「新增模型 / 抵扣变化 / 模型下架」生成待确认清单 →
          ④ 逐条「应用」或「忽略」。应用后 diff 自动消失，无需额外确认动作。
        </AlertDescription>
      </Alert>

      {items.length === 0 ? (
        <Card>
          <CardContent className='text-muted-foreground py-8 text-center text-sm'>
            暂无校对记录 —— 配置供应商 pricing_source_url 后，定时校对（每 6 小时）会在此生成待确认变更。
          </CardContent>
        </Card>
      ) : (
        items.map((item) => (
          <CheckCard
            key={item.vendor}
            item={item}
            vendorName={vendorName(item.vendor)}
            busy={busy}
            onApply={onApply}
            onIgnore={onIgnore}
          />
        ))
      )}

      <Card>
        <CardHeader>
          <CardTitle className='flex items-center gap-2'>
            <Download className='h-4 w-4' /> 官方模板目录
          </CardTitle>
          <CardDescription>
            内置各厂商官方套餐档位与折算标准（随版本维护）。应用 = 幂等落地：新建厂商（停用态）、
            预置档位与折算标准；仅覆盖官方预置行，绝不自动启用、不影响你改过的数据。
          </CardDescription>
        </CardHeader>
        <CardContent className='grid gap-3 md:grid-cols-2'>
          {templates.map((t) => (
            <div key={t.code} className='flex flex-col gap-2 rounded-lg border p-3'>
              <div className='flex items-center justify-between gap-2'>
                <div className='font-medium'>{t.name}</div>
                <Badge variant={t.vendor_exists ? 'secondary' : 'outline'}>
                  {t.vendor_exists ? '已存在' : '未添加'}
                </Badge>
              </div>
              <div className='text-muted-foreground text-xs'>
                单位：{t.unit_name} ｜ 档位 {t.tier_count} 条（库内 {t.db_tier_count}）｜ 折算标准{' '}
                {t.ratio_count} 条（库内 {t.db_ratio_count}）｜
                {t.verified_at ? ` 模板核对 ${t.verified_at}` : ' 待人工核对'}
              </div>
              {t.notes && (
                <div className='text-muted-foreground text-xs leading-relaxed'>{t.notes}</div>
              )}
              {t.docs_url && (
                <a
                  href={t.docs_url}
                  target='_blank'
                  rel='noreferrer'
                  className='text-xs text-blue-500 hover:underline'
                >
                  官方文档 ↗
                </a>
              )}
              <div className='mt-1 flex gap-2'>
                <Button
                  size='sm'
                  disabled={busy !== null}
                  onClick={() => onApplyTemplate(t.code)}
                >
                  {t.vendor_exists ? '同步模板数据' : '从模板添加'}
                </Button>
                {!t.vendor_exists && (
                  <Button
                    size='sm'
                    variant='outline'
                    disabled={busy !== null}
                    onClick={() => onApplyTemplate(t.code, true)}
                  >
                    添加并启用厂商
                  </Button>
                )}
              </div>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

type CheckCardProps = {
  item: CodingPlanCheckItem
  vendorName: string
  busy: string | null
  onApply: (vendor: string, kind: ApplyKind, change: CodingPlanPendingChange) => void
  onIgnore: (vendor: string, change: CodingPlanPendingChange, undo: boolean) => void
}

const SOURCE_LABEL: Record<number, string> = {
  0: '未配置定价源',
  1: '源比对完成',
  2: '源拉取失败',
}

function CheckCard({ item, vendorName, busy, onApply, onIgnore }: CheckCardProps) {
  const groups: { kind: ApplyKind; rows: CodingPlanPendingChange[] }[] = (
    ['new', 'changed', 'missing'] as ApplyKind[]
  ).map((kind) => ({ kind, rows: item.changes?.[kind] ?? [] }))

  return (
    <Card>
      <CardHeader>
        <CardTitle className='flex flex-wrap items-center gap-2 text-base'>
          {vendorName}
          <Badge variant={item.source_status === 1 ? 'secondary' : 'outline'}>
            {SOURCE_LABEL[item.source_status] ?? '未知'}
          </Badge>
          <Badge variant={item.change_count > 0 ? 'destructive' : 'secondary'}>
            待确认 {item.change_count}
          </Badge>
          {item.stale_count > 0 && (
            <Badge variant='outline'>待复核 {item.stale_count}</Badge>
          )}
          <span className='text-muted-foreground ml-auto text-xs font-normal'>
            核对时间：{item.checked_at > 0 ? new Date(item.checked_at * 1000).toLocaleString() : '-'}
          </span>
        </CardTitle>
      </CardHeader>
      {item.change_count === 0 && (item.changes?.new?.length ?? 0) + (item.changes?.changed?.length ?? 0) + (item.changes?.missing?.length ?? 0) === 0 ? (
        <CardContent className='text-muted-foreground text-sm'>与定价源一致，无待确认变更。</CardContent>
      ) : (
        <CardContent className='flex flex-col gap-3'>
          {groups.map(({ kind, rows }) =>
            rows.length === 0 ? null : (
              <div key={kind} className='flex flex-col gap-1'>
                <div className='text-xs font-semibold'>{KIND_LABEL[kind]}</div>
                {rows.map((change) => {
                  const detail = change.split_rates
                    ? `三段系数 ${fmtParts(change.from_parts)} → ${fmtParts(change.to_parts)}`
                    : kind === 'changed'
                      ? `单位成本 ${fmt(change.from)} → ${fmt(change.to)}`
                      : kind === 'new'
                        ? `新模型（${change.cost_mode ?? 'per_1k_tokens'}）`
                        : '定价源中已消失（老模型下架）'
                  return (
                    <div
                      key={change.key ?? `${kind}-${change.model}`}
                      className='flex flex-wrap items-center gap-2 rounded-md border px-2 py-1.5 text-xs'
                    >
                      <span className='font-medium'>{change.model}</span>
                      <span className='text-muted-foreground'>{detail}</span>
                      {change.ignored && <Badge variant='outline'>已忽略</Badge>}
                      <span className='ml-auto flex gap-1'>
                        {!change.ignored && (
                          <>
                            {kind !== 'new' && (
                              <Button
                                size='sm'
                                variant='outline'
                                disabled={busy !== null}
                                onClick={() => onApply(item.vendor, kind, change)}
                              >
                                {kind === 'missing' ? '下架（停用）' : '应用'}
                              </Button>
                            )}
                            {kind === 'new' && (
                              <Button
                                size='sm'
                                variant='outline'
                                disabled={busy !== null}
                                onClick={() => onApply(item.vendor, kind, change)}
                              >
                                添加（停用）
                              </Button>
                            )}
                            <Button
                              size='sm'
                              variant='ghost'
                              disabled={busy !== null}
                              onClick={() => onIgnore(item.vendor, change, false)}
                            >
                              忽略
                            </Button>
                          </>
                        )}
                        {change.ignored && (
                          <Button
                            size='sm'
                            variant='ghost'
                            disabled={busy !== null}
                            onClick={() => onIgnore(item.vendor, change, true)}
                          >
                            恢复提醒
                          </Button>
                        )}
                      </span>
                    </div>
                  )
                })}
              </div>
            )
          )}
        </CardContent>
      )}
    </Card>
  )
}
