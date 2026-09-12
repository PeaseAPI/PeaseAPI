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
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

import {
  createRatio,
  deleteRatio,
  getRatios,
  getVendors,
  updateRatio,
} from '../api'
import {
  COST_MODES,
  COST_PER_TOKEN_PARTS,
  MATCH_EXACT,
  MATCH_TYPES,
  type CodingPlanModelRatio,
} from '../types'
import { t } from 'i18next'
import { useTranslation } from 'react-i18next'

// 分时段折扣窗口 JSON 前端校验（仅提示，不阻断保存——服务端会最终规范化并丢弃非法条目）
function validateTimeDiscountsJson(raw: string): string | null {
  const text = raw.trim()
  if (text === '' || text === 'null') return null
  let parsed: unknown
  try {
    parsed = JSON.parse(text)
  } catch {
    return t('JSON syntax error: check quotes/commas/brackets')
  }
  if (!Array.isArray(parsed)) return t('Top level must be an array [...]')
  for (let i = 0; i < parsed.length; i++) {
    const w = parsed[i] as Record<string, unknown>
    if (typeof w !== 'object' || w === null) {
      return t('Entry {{index}} is not an object', { index: i + 1 })
    }
    const discount = Number(w.discount)
    if (!(discount > 0 && discount < 1)) {
      return t('Entry {{index}} discount must be in (0,1), e.g. 0.5 = 50% off', { index: i + 1 })
    }
    for (const key of ['start', 'end'] as const) {
      if (!/^\d{1,2}:\d{2}$/.test(String(w[key] ?? ''))) {
        return t('Entry {{index}} {{key}} must be in HH:MM format, e.g. 22:00', { index: i + 1, key })
      }
    }
  }
  return null
}

type RatioForm = {
  vendor: string
  model: string
  /** exact | prefix */
  match_type: string
  /** per_request | per_1k_tokens | per_token_parts */
  cost_mode: string
  unit_cost: string
  input_rate: string
  cached_rate: string
  output_rate: string
  /** 分时段折扣窗口 JSON 文本（空=全时段原价） */
  time_discounts: string
  status: string
  sort: string
  remark: string
}

const EMPTY: RatioForm = {
  vendor: '',
  model: '',
  match_type: MATCH_EXACT,
  cost_mode: COST_MODES[0].value,
  unit_cost: '1',
  input_rate: '',
  cached_rate: '',
  output_rate: '',
  time_discounts: '',
  status: '1',
  sort: '0',
  remark: '',
}

export function RatiosTab() {
  const { t } = useTranslation()

  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<CodingPlanModelRatio | null>(null)
  const [form, setForm] = useState<RatioForm>(EMPTY)

  const ratiosQuery = useQuery({
    queryKey: ['coding-plan-ratios'],
    queryFn: getRatios,
  })
  const vendorsQuery = useQuery({
    queryKey: ['coding-plan-vendors'],
    queryFn: getVendors,
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        vendor: form.vendor,
        model: form.model,
        match_type: form.match_type,
        cost_mode: form.cost_mode,
        unit_cost: Number(form.unit_cost || 1),
        input_rate: form.cost_mode === COST_PER_TOKEN_PARTS ? Number(form.input_rate || 0) : 0,
        cached_rate: form.cost_mode === COST_PER_TOKEN_PARTS ? Number(form.cached_rate || 0) : 0,
        output_rate: form.cost_mode === COST_PER_TOKEN_PARTS ? Number(form.output_rate || 0) : 0,
        // 分时段折扣窗口：JSON 文本 → 数组（空/非法清空 = 全时段原价，服务端会再规范化）
        time_discounts: (() => {
          const raw = form.time_discounts.trim()
          if (raw === '' || raw === 'null') return null
          const parsed = JSON.parse(raw)
          if (!Array.isArray(parsed)) throw new Error(t('Time discounts must be a JSON array of windows'))
          return parsed
        })(),
        status: Number(form.status),
        sort: Number(form.sort || 0),
        remark: form.remark,
      }
      if (editing) return updateRatio(editing.id, payload)
      return createRatio(payload)
    },
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Saved'))
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-ratios'] })
      } else {
        toast.error(res.message ?? t('Failed to save'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteRatio(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Deleted'))
        qc.invalidateQueries({ queryKey: ['coding-plan-ratios'] })
      } else {
        toast.error(res.message ?? t('Failed to delete'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const ratios = ratiosQuery.data?.data?.items ?? []
  const vendors = vendorsQuery.data?.data ?? []
  const matchLabel = (t: string) =>
    MATCH_TYPES.find((m) => m.value === t)?.label ?? t
  const costLabel = (c: string) =>
    COST_MODES.find((m) => m.value === c)?.label ?? c
  const set = (key: keyof RatioForm, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }))


  return (
    <div className='flex flex-col gap-3'>
      <div className='flex items-center justify-between'>
        <p className='text-muted-foreground text-sm'>
          {t('credits = raw usage × unit_cost × vendor unit exchange rate; exact')}
          {t('takes precedence over prefix, longest prefix wins. The platform auto-checks every 6 hours; rules not re-checked within the window are marked "pending re-check".')}
        </p>
        <Button
          size='sm'
          onClick={() => {
            setEditing(null)
            setForm(EMPTY)
            setOpen(true)
          }}
        >
          <Plus className='mr-1 size-4' /> {t('Add rule')}
        </Button>
      </div>
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Vendor')}</TableHead>
              <TableHead>{t('Model')}</TableHead>
              <TableHead>{t('Match')}</TableHead>
              <TableHead>{t('Cost mode')}</TableHead>
              <TableHead>{t('Unit cost')}</TableHead>
              <TableHead>{t('Status')}</TableHead>
              <TableHead className='text-right'>{t('Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {ratiosQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={7} className='py-8 text-center'>
                  {t('Loading…')}
                </TableCell>
              </TableRow>
            ) : ratios.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className='text-muted-foreground py-8 text-center'
                >
                  {t('No conversion rules yet; account/vendor default rates apply')}
                </TableCell>
              </TableRow>
            ) : (
              ratios.map((r) => (
                <TableRow key={r.id}>
                  <TableCell className='font-mono text-xs'>{r.vendor}</TableCell>
                  <TableCell className='font-mono text-xs'>{r.model}</TableCell>
                  <TableCell>{matchLabel(r.match_type)}</TableCell>
                  <TableCell>{costLabel(r.cost_mode)}</TableCell>
                  <TableCell>
                    {r.cost_mode === COST_PER_TOKEN_PARTS
                      ? t('in {{input}} / cache {{cached}} / out {{output}}', { input: Number(r.input_rate ?? 0), cached: Number(r.cached_rate ?? 0), output: Number(r.output_rate ?? 0) })
                      : `×${Number(r.unit_cost)}`}
                  </TableCell>
                  <TableCell>
                    <div className='flex flex-wrap items-center gap-1'>
                      {Number(r.status) === 1 ? (
                        <Badge>{t('Enabled')}</Badge>
                      ) : (
                        <Badge variant='secondary'>{t('Disabled')}</Badge>
                      )}
                      {r.stale === true && (
                        <Badge
                          variant='outline'
                          className='border-amber-500/60 text-amber-500'
                          title={t('Not manually re-checked within the review window; the public intro page (/coding-plan) will mark it as "pending re-check"')}
                        >
                          {t('Pending re-check')}
                        </Badge>
                      )}
                      {(r.time_discounts?.length ?? 0) > 0 && (
                        <Badge
                          variant='outline'
                          className='border-sky-500/60 text-sky-500'
                          title={r.time_discounts!
                            .map(
                              (w) =>
                                `${w.name || t('Window')}：${t('week {{days}} {{start}}-{{end}} ×{{discount}}', { days: (w.days ?? [1, 2, 3, 4, 5, 6, 7]).join('/'), start: w.start, end: w.end, discount: w.discount })}`,
                            )
                            .join('\n') + '\n' + t('(applied automatically at billing time)')}
                        >
                          {t('{{count}} windows', { count: r.time_discounts!.length })}
                        </Badge>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className='text-right'>
                    <div className='flex justify-end gap-1'>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          setEditing(r)
                          setForm({
                            vendor: r.vendor,
                            model: r.model,
                            match_type: r.match_type,
                            cost_mode: r.cost_mode,
                            unit_cost: String(r.unit_cost ?? 1),
                            input_rate: String(r.input_rate ?? ''),
                            cached_rate: String(r.cached_rate ?? ''),
                            output_rate: String(r.output_rate ?? ''),
                            time_discounts: r.time_discounts
                              ? JSON.stringify(r.time_discounts, null, 2)
                              : '',
                            status: String(r.status ?? 1),
                            sort: String(r.sort ?? 0),
                            remark: r.remark ?? '',
                          })
                          setOpen(true)
                        }}
                      >
                        {t('Edit')}
                      </Button>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          if (window.confirm(t('Delete rule "{{model}}"?', { model: r.model }))) {
                            deleteMutation.mutate(r.id)
                          }
                        }}
                      >
                        <Trash2 className='text-destructive size-4' />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className='sm:max-w-md'>
          <DialogHeader>
            <DialogTitle>{editing ? t('Edit rule') : t('Add rule')}</DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Vendor')}</Label>
                <Select value={form.vendor} onValueChange={(v) => set('vendor', v ?? '')}>
                  <SelectTrigger>
                    <SelectValue placeholder={t('Select a vendor')} />
                  </SelectTrigger>
                  <SelectContent>
                    {vendors.map((v) => (
                      <SelectItem key={v.id} value={v.code}>
                        {v.name} ({v.code})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Match mode')}</Label>
                <Select
                  value={form.match_type}
                  onValueChange={(v) => set('match_type', v ?? '')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {MATCH_TYPES.map((m) => (
                      <SelectItem key={m.value} value={m.value}>
                        {m.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className='grid gap-1.5'>
              <Label>{t('Model (exact match / prefix)')}</Label>
              <Input
                value={form.model}
                onChange={(e) => set('model', e.target.value)}
                placeholder={t('claude-sonnet-4-5 or claude-')}
              />
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Cost mode')}</Label>
                <Select
                  value={form.cost_mode}
                  onValueChange={(v) => set('cost_mode', v ?? '')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {COST_MODES.map((m) => (
                      <SelectItem key={m.value} value={m.value}>
                        {m.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>
                  {form.cost_mode === COST_PER_TOKEN_PARTS
                    ? t('Unit cost (not used in tiered mode)')
                    : t('Unit cost')}
                </Label>
                <Input
                  type='number'
                  step='0.0001'
                  value={form.unit_cost}
                  onChange={(e) => set('unit_cost', e.target.value)}
                />
              </div>
            </div>
            {form.cost_mode === COST_PER_TOKEN_PARTS && (
              <div className='grid grid-cols-3 gap-3'>
                <div className='grid gap-1.5'>
                  <Label>{t('Input rate /1k tokens')}</Label>
                  <Input
                    type='number'
                    step='0.0001'
                    value={form.input_rate}
                    onChange={(e) => set('input_rate', e.target.value)}
                    placeholder={t('e.g. 0.69')}
                  />
                </div>
                <div className='grid gap-1.5'>
                  <Label>{t('Cache hit rate /1k tokens')}</Label>
                  <Input
                    type='number'
                    step='0.0001'
                    value={form.cached_rate}
                    onChange={(e) => set('cached_rate', e.target.value)}
                    placeholder={t('e.g. 0.17')}
                  />
                </div>
                <div className='grid gap-1.5'>
                  <Label>{t('Output rate /1k tokens')}</Label>
                  <Input
                    type='number'
                    step='0.0001'
                    value={form.output_rate}
                    onChange={(e) => set('output_rate', e.target.value)}
                    placeholder={t('e.g. 2.4')}
                  />
                </div>
              </div>
            )}
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Status')}</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='1'>{t('Enabled')}</SelectItem>
                    <SelectItem value='0'>{t('Disabled')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Sort')}</Label>
                <Input
                  type='number'
                  value={form.sort}
                  onChange={(e) => set('sort', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Remark')}</Label>
                <Input
                  value={form.remark}
                  onChange={(e) => set('remark', e.target.value)}
                />
              </div>
            </div>
            <div className='grid gap-1.5'>
              <Label>{t('Time discount windows (optional, JSON array)')}</Label>
              <Textarea
                className='font-mono text-xs'
                rows={4}
                value={form.time_discounts}
                onChange={(e) => set('time_discounts', e.target.value)}
                placeholder={`[{"name":"工作日空闲(00-09点)","days":[1,2,3,4,5],"start":"00:00","end":"09:00","discount":0.5}]\n跨零点窗口 end<start，如夜间 22:00-08:00 写 start:"22:00"、end:"08:00"`}
              />
              {(() => {
                const err = validateTimeDiscountsJson(form.time_discounts)
                return err ? <p className='text-destructive text-xs'>{err}</p> : null
              })()}
              <p className='text-muted-foreground text-xs'>
                {t('Discounts are applied automatically at billing time; official windows use Beijing time')}
                {t('(e.g. Zhipu off-peak 50% off, DeepSeek idle half price, Aliyun night 22:00-08:00 50% off).')}
                {t('Leave empty for full price all day.')}
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant='outline' onClick={() => setOpen(false)}>
              {t('Cancel')}
            </Button>
            <Button
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending || !form.vendor || !form.model}
            >
              {t('Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
