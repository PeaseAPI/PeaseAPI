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
import dayjs from 'dayjs'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'

import {
  destroyPromotion,
  getPromotions,
  getVendors,
  storePromotion,
} from '../api'
import {
  PROMOTION_KINDS,
  PROMOTION_STATES,
  type CodingPlanPromotion,
} from '../types'
import { useTranslation } from 'react-i18next'

type PromotionForm = {
  vendor: string
  kind: string
  title: string
  description: string
  discount: string
  starts_at: string
  ends_at: string
  source_url: string
  status: string
  remind_days: string
  sort: string
  remark: string
}

const EMPTY: PromotionForm = {
  vendor: '',
  kind: 'discount',
  title: '',
  description: '',
  discount: '',
  starts_at: '',
  ends_at: '',
  source_url: '',
  status: '1',
  remind_days: '7',
  sort: '0',
  remark: '',
}

/** datetime-local 值 → Unix 秒；空串返回 0（官方未公布） */
function toUnix(value: string): number {
  if (!value) return 0
  const ms = new Date(value).getTime()
  return Number.isNaN(ms) ? 0 : Math.round(ms / 1000)
}

/** Unix 秒 → datetime-local 值（本地时区）；0/空返回 '' */
function fromUnix(value?: number | null): string {
  if (!value) return ''
  return dayjs.unix(Number(value)).format('YYYY-MM-DDTHH:mm')
}
export function PromotionsTab() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<CodingPlanPromotion | null>(null)
  const [showAll, setShowAll] = useState(true)
  const [form, setForm] = useState<PromotionForm>(EMPTY)

  const promotionsQuery = useQuery({
    queryKey: ['coding-plan-promotions', showAll],
    queryFn: () => getPromotions({ all: showAll }),
  })
  const vendorsQuery = useQuery({
    queryKey: ['coding-plan-vendors'],
    queryFn: getVendors,
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        id: editing?.id,
        vendor: form.vendor,
        kind: form.kind,
        title: form.title,
        description: form.description,
        discount: form.discount === '' ? null : Number(form.discount),
        starts_at: toUnix(form.starts_at),
        ends_at: toUnix(form.ends_at),
        source_url: form.source_url,
        status: Number(form.status),
        remind_days: Number(form.remind_days || 7),
        sort: Number(form.sort || 0),
        remark: form.remark,
      }
      return storePromotion(payload)
    },
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Promotion saved'))
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-promotions'] })
      } else {
        toast.error(res.message ?? t('Failed to save'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => destroyPromotion(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Deleted'))
        qc.invalidateQueries({ queryKey: ['coding-plan-promotions'] })
      } else {
        toast.error(res.message ?? t('Failed to delete'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const promotions = promotionsQuery.data?.data?.promotions ?? []
  const vendors = vendorsQuery.data?.data ?? []
  const vendorCodes = [
    ...new Set([
      ...promotions.map((p) => p.vendor),
      ...vendors.map((v) => v.code),
    ]),
  ]
  const set = (key: keyof PromotionForm, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }))

  const stateBadge = (state?: string) => {
    if (state === 'ongoing') return <Badge>{t('Ongoing')}</Badge>
    if (state === 'scheduled') return <Badge variant='outline'>{t('Not started')}</Badge>
    if (state === 'expired') return <Badge variant='destructive'>{t('Ended')}</Badge>
    return (
      <Badge variant='outline'>
        {PROMOTION_STATES.find((s) => s.value === state)?.label ?? '-'}
      </Badge>
    )
  }

  const countdown = (p: CodingPlanPromotion) => {
    if (p.remaining_seconds === null || p.remaining_seconds === undefined)
      return t('Long-term / not announced')
    const days = Math.floor(Number(p.remaining_seconds) / 86400)
    const hours = Math.floor((Number(p.remaining_seconds) % 86400) / 3600)
    return days > 0 ? t('{{days}} days {{hours}} hours left', { days, hours }) : t('{{hours}} hours left', { hours })
  }

  return (
    <div className='flex flex-col gap-3'>
      <div className='flex flex-wrap items-center justify-between gap-2'>
        <p className='text-muted-foreground text-sm'>
          {t('Official vendor promotions (limited-time prices / time-window discounts /')}
          {t('model retirements). Every day at 09:00 the system reminds about promotions expiring within remind_days')}
          {t('days (site notice + subscriber email) and marks them ended automatically.')}
        </p>
        <div className='flex items-center gap-2'>
          <span className='text-muted-foreground text-xs'>{t('Include disabled/expired')}</span>
          <Switch checked={showAll} onCheckedChange={setShowAll} />
          <Button
            size='sm'
            onClick={() => {
              setEditing(null)
              setForm(EMPTY)
              setOpen(true)
            }}
          >
            <Plus className='mr-1 size-4' /> {t('Add promotion')}
          </Button>
        </div>
      </div>

      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Vendor')}</TableHead>
              <TableHead>{t('Type')}</TableHead>
              <TableHead>{t('Title')}</TableHead>
              <TableHead>{t('Discount')}</TableHead>
              <TableHead>{t('Deadline')}</TableHead>
              <TableHead>{t('Status')}</TableHead>
              <TableHead>{t('Countdown')}</TableHead>
              <TableHead>{t('Reminder')}</TableHead>
              <TableHead>{t('On/Off')}</TableHead>
              <TableHead className='text-right'>{t('Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {promotionsQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={10} className='py-8 text-center'>
                  {t('Loading…')}
                </TableCell>
              </TableRow>
            ) : promotions.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={10}
                  className='text-muted-foreground py-8 text-center'
                >
                  {t('No promotions yet; migration 000015 has preset known vendor promotions, click "Edit" to adjust')}
                </TableCell>
              </TableRow>
            ) : (
              promotions.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className='font-mono text-xs'>{p.vendor}</TableCell>
                  <TableCell>
                    <Badge variant='outline'>
                      {PROMOTION_KINDS.find((k) => k.value === p.kind)?.label ??
                        p.kind}
                    </Badge>
                  </TableCell>
                  <TableCell className='max-w-[14rem]'>
                    <div className='truncate' title={p.description ?? ''}>
                      {p.title}
                    </div>
                  </TableCell>
                  <TableCell>
                    {p.discount !== null && p.discount !== undefined
                      ? t('{{value}}% off', { value: Math.round(Number(p.discount) * 1000) / 10 })
                      : '-'}
                  </TableCell>
                  <TableCell className='text-xs'>
                    {p.ends_at
                      ? dayjs.unix(Number(p.ends_at)).format('MM-DD HH:mm')
                      : t('Not announced')}
                  </TableCell>
                  <TableCell>{stateBadge(p.state)}</TableCell>
                  <TableCell className='text-muted-foreground text-xs'>
                    {countdown(p)}
                  </TableCell>
                  <TableCell className='text-muted-foreground text-xs'>
                    {t('{{days}} days', { days: p.remind_days })}
                  </TableCell>
                  <TableCell>
                    {Number(p.status) === 1 ? (
                      <Badge>{t('Enabled')}</Badge>
                    ) : (
                      <Badge variant='destructive'>{t('Disabled')}</Badge>
                    )}
                  </TableCell>
                  <TableCell className='text-right'>
                    <div className='flex justify-end gap-1'>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          setEditing(p)
                          setForm({
                            vendor: p.vendor,
                            kind: p.kind,
                            title: p.title,
                            description: p.description ?? '',
                            discount:
                              p.discount === null || p.discount === undefined
                                ? ''
                                : String(p.discount),
                            starts_at: fromUnix(p.starts_at),
                            ends_at: fromUnix(p.ends_at),
                            source_url: p.source_url ?? '',
                            status: String(p.status ?? 1),
                            remind_days: String(p.remind_days ?? 7),
                            sort: String(p.sort ?? 0),
                            remark: p.remark ?? '',
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
                          if (window.confirm(t('Delete promotion "{{title}}"?', { title: p.title }))) {
                            deleteMutation.mutate(p.id)
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
        <DialogContent className='sm:max-w-lg'>
          <DialogHeader>
            <DialogTitle>{editing ? t('Edit promotion') : t('Add promotion')}</DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Vendor code')}</Label>
                <Input
                  value={form.vendor}
                  onChange={(e) => set('vendor', e.target.value.toLowerCase())}
                  placeholder='zhipu'
                  list='coding-plan-vendor-codes'
                />
                <datalist id='coding-plan-vendor-codes'>
                  {vendorCodes.map((c) => (
                    <option key={c} value={c} />
                  ))}
                </datalist>
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Type')}</Label>
                <Select value={form.kind} onValueChange={(v) => set('kind', v ?? 'discount')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PROMOTION_KINDS.map((k) => (
                      <SelectItem key={k.value} value={k.value}>
                        {k.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className='grid gap-1.5'>
              <Label>{t('Title')}</Label>
              <Input
                value={form.title}
                onChange={(e) => set('title', e.target.value)}
                placeholder={t('Overnight unlimited (daily 23:00–09:00)')}
              />
            </div>
            <div className='grid gap-1.5'>
              <Label>{t('Description')}</Label>
              <Textarea
                value={form.description}
                onChange={(e) => set('description', e.target.value)}
                rows={2}
                placeholder={t('User-facing description shown on the intro page and in site announcements')}
              />
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Discount multiplier (optional)')}</Label>
                <Input
                  type='number'
                  step='0.01'
                  min='0.01'
                  max='0.99'
                  value={form.discount}
                  onChange={(e) => set('discount', e.target.value)}
                  placeholder='0.5'
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Starts at (optional)')}</Label>
                <Input
                  type='datetime-local'
                  value={form.starts_at}
                  onChange={(e) => set('starts_at', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Deadline (empty = not announced)')}</Label>
                <Input
                  type='datetime-local'
                  value={form.ends_at}
                  onChange={(e) => set('ends_at', e.target.value)}
                />
              </div>
            </div>
            <div className='grid gap-1.5'>
              <Label>{t('Official source URL (optional)')}</Label>
              <Input
                value={form.source_url}
                onChange={(e) => set('source_url', e.target.value)}
                placeholder='https://...'
              />
            </div>
            <div className='grid grid-cols-4 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Status')}</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '1')}>
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
                <Label>{t('Remind days in advance')}</Label>
                <Input
                  type='number'
                  min='0'
                  max='90'
                  value={form.remind_days}
                  onChange={(e) => set('remind_days', e.target.value)}
                />
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
                <Label>{t('Remark (internal)')}</Label>
                <Input
                  value={form.remark}
                  onChange={(e) => set('remark', e.target.value)}
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant='outline' onClick={() => setOpen(false)}>
              {t('Cancel')}
            </Button>
            <Button
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending || !form.vendor || !form.title}
            >
              {t('Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

