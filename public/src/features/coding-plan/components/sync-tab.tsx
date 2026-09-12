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
import { t } from 'i18next'
import { useTranslation } from 'react-i18next'

/** 待确认变更的应用动作（missing=下架走 status=0，changed=改系数，new=按源新增停用行） */
type ApplyKind = 'new' | 'changed' | 'missing'

export function SyncTab() {
  const { t } = useTranslation()
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
        if (!existing) throw new Error(t('No existing ratio row: {{model}}', { model: item.model }))
        return updateRatio(existing.id, { status: 0 })
      }
      if (kind === 'changed') {
        if (!existing) throw new Error(t('No existing ratio row: {{model}}', { model: item.model }))
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
          ? t('{{model}} disabled (old model delisted)', { model: vars.item.model })
          : t('{{model}} {{action}}', { model: vars.item.model, action: vars.kind === 'new' ? t('added (disabled)') : t('updated from source') })
      )
      invalidateAll()
    },
    onError: (err: Error) => toast.error(err.message || t('Failed to apply')),
  })

  const ignoreMutation = useMutation({
    mutationFn: ({ vendor, key, undo }: { vendor: string; key: string; undo?: boolean }) =>
      ignoreCheckChange(vendor, key, undo),
    onSuccess: (res) => toast.success(res.message || t('Ignore list updated')),
    onError: () => toast.error(t('Operation failed')),
  })

  const catalogMutation = useMutation({
    mutationFn: ({ code, activate }: { code: string; activate?: boolean }) =>
      applyCatalogTemplate(code, { activate_vendor: activate }),
    onSuccess: (res) => {
      toast.success(res.message || t('Template applied'))
      invalidateAll()
    },
    onError: () => toast.error(t('Failed to apply template')),
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
  new: 'New models',
  changed: 'Ratio changes',
  missing: 'Model removals',
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
        <AlertTitle>{t('Official sync workflow')}</AlertTitle>
        <AlertDescription className='text-muted-foreground text-sm'>
          {t('① Add a vendor from a template below (tiers + ratios all preset as disabled) → ② set exchange rates and enable ratios →')}
          {t('③ the scheduled task compares pricing sources every 6 hours and queues "new models / ratio changes / model removals" for confirmation →')}
          {t('④ apply or ignore each entry. After applying, the diff disappears automatically — no extra confirmation needed.')}
        </AlertDescription>
      </Alert>

      {items.length === 0 ? (
        <Card>
          <CardContent className='text-muted-foreground py-8 text-center text-sm'>
            {t('No check records yet — configure a vendor pricing_source_url and scheduled checks (every 6 hours) will generate pending changes here.')}
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
            <Download className='h-4 w-4' /> {t('Official template catalog')}
          </CardTitle>
          <CardDescription>
            {t('Built-in official tiers and conversion standards per vendor (maintained with releases). Apply = idempotent: creates the vendor (disabled),')}
            {t('presets tiers and ratios; only overwrites official preset rows — never auto-enables or touches your edits.')}
          </CardDescription>
        </CardHeader>
        <CardContent className='grid gap-3 md:grid-cols-2'>
          {templates.map((tpl) => (
            <div key={tpl.code} className='flex flex-col gap-2 rounded-lg border p-3'>
              <div className='flex items-center justify-between gap-2'>
                <div className='font-medium'>{tpl.name}</div>
                <Badge variant={tpl.vendor_exists ? 'secondary' : 'outline'}>
                  {tpl.vendor_exists ? t('Exists') : t('Not added')}
                </Badge>
              </div>
              <div className='text-muted-foreground text-xs'>
                {t('Unit: {{unit}} | Tiers {{tiers}} ({{dbTiers}} in DB) | Ratios', { unit: tpl.unit_name, tiers: tpl.tier_count, dbTiers: tpl.db_tier_count })}{' '}
                {t('{{count}} ({{dbCount}} in DB) |', { count: tpl.ratio_count, dbCount: tpl.db_ratio_count })}
                {tpl.verified_at ? t(' template checked {{time}}', { time: tpl.verified_at }) : t(' pending manual check')}
              </div>
              {tpl.notes && (
                <div className='text-muted-foreground text-xs leading-relaxed'>{tpl.notes}</div>
              )}
              {tpl.docs_url && (
                <a
                  href={tpl.docs_url}
                  target='_blank'
                  rel='noreferrer'
                  className='text-xs text-blue-500 hover:underline'
                >
                  {t('Official docs ↗')}
                </a>
              )}
              <div className='mt-1 flex gap-2'>
                <Button
                  size='sm'
                  disabled={busy !== null}
                  onClick={() => onApplyTemplate(tpl.code)}
                >
                  {tpl.vendor_exists ? t('Sync template data') : t('Add from template')}
                </Button>
                {!tpl.vendor_exists && (
                  <Button
                    size='sm'
                    variant='outline'
                    disabled={busy !== null}
                    onClick={() => onApplyTemplate(tpl.code, true)}
                  >
                    {t('Add and enable vendor')}
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
  0: t('Pricing source not configured'),
  1: t('Source comparison completed'),
  2: t('Source fetch failed'),
}

function CheckCard({ item, vendorName, busy, onApply, onIgnore }: CheckCardProps) {
  const { t } = useTranslation()
  const groups: { kind: ApplyKind; rows: CodingPlanPendingChange[] }[] = (
    ['new', 'changed', 'missing'] as ApplyKind[]
  ).map((kind) => ({ kind, rows: item.changes?.[kind] ?? [] }))

  return (
    <Card>
      <CardHeader>
        <CardTitle className='flex flex-wrap items-center gap-2 text-base'>
          {vendorName}
          <Badge variant={item.source_status === 1 ? 'secondary' : 'outline'}>
            {t(SOURCE_LABEL[item.source_status] ?? 'Unknown')}
          </Badge>
          <Badge variant={item.change_count > 0 ? 'destructive' : 'secondary'}>
            {t('{{count}} pending', { count: item.change_count })}
          </Badge>
          {item.stale_count > 0 && (
            <Badge variant='outline'>{t('{{count}} to re-check', { count: item.stale_count })}</Badge>
          )}
          <span className='text-muted-foreground ml-auto text-xs font-normal'>
            {t('Checked at:')} {item.checked_at > 0 ? new Date(item.checked_at * 1000).toLocaleString() : '-'}
          </span>
        </CardTitle>
      </CardHeader>
      {item.change_count === 0 && (item.changes?.new?.length ?? 0) + (item.changes?.changed?.length ?? 0) + (item.changes?.missing?.length ?? 0) === 0 ? (
        <CardContent className='text-muted-foreground text-sm'>{t('In sync with the pricing source; no pending changes.')}</CardContent>
      ) : (
        <CardContent className='flex flex-col gap-3'>
          {groups.map(({ kind, rows }) =>
            rows.length === 0 ? null : (
              <div key={kind} className='flex flex-col gap-1'>
                <div className='text-xs font-semibold'>{t(KIND_LABEL[kind])}</div>
                {rows.map((change) => {
                  const detail = change.split_rates
                    ? t('Split rates {{from}} → {{to}}', { from: fmtParts(change.from_parts), to: fmtParts(change.to_parts) })
                    : kind === 'changed'
                      ? t('Unit cost {{from}} → {{to}}', { from: fmt(change.from), to: fmt(change.to) })
                      : kind === 'new'
                        ? t('New model ({{mode}})', { mode: change.cost_mode ?? 'per_1k_tokens' })
                        : t('Removed from pricing source (old model delisted)')
                  return (
                    <div
                      key={change.key ?? `${kind}-${change.model}`}
                      className='flex flex-wrap items-center gap-2 rounded-md border px-2 py-1.5 text-xs'
                    >
                      <span className='font-medium'>{change.model}</span>
                      <span className='text-muted-foreground'>{detail}</span>
                      {change.ignored && <Badge variant='outline'>{t('Ignored')}</Badge>}
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
                                {kind === 'missing' ? t('Delist (disable)') : t('Apply')}
                              </Button>
                            )}
                            {kind === 'new' && (
                              <Button
                                size='sm'
                                variant='outline'
                                disabled={busy !== null}
                                onClick={() => onApply(item.vendor, kind, change)}
                              >
                                {t('Add (disabled)')}
                              </Button>
                            )}
                            <Button
                              size='sm'
                              variant='ghost'
                              disabled={busy !== null}
                              onClick={() => onIgnore(item.vendor, change, false)}
                            >
                              {t('Ignore')}
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
                            {t('Resume reminders')}
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
