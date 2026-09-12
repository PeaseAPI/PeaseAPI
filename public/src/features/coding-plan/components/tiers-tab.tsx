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
  createTier,
  deleteTier,
  getTiers,
  getVendors,
  updateTier,
} from '../api'
import type { CodingPlanVendorTier } from '../types'
import { useTranslation } from 'react-i18next'

type TierForm = {
  vendor_code: string
  name: string
  price: string
  price_note: string
  period: string
  quota: string
  quota_unit: string
  quota_note: string
  status: string
  sort: string
  remark: string
}

const EMPTY: TierForm = {
  vendor_code: '',
  name: '',
  price: '',
  price_note: '',
  period: '月',
  quota: '',
  quota_unit: '',
  quota_note: '',
  status: '1',
  sort: '0',
  remark: '',
}

export function TiersTab() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<CodingPlanVendorTier | null>(null)
  const [form, setForm] = useState<TierForm>(EMPTY)
  const [vendorFilter, setVendorFilter] = useState('all')

  const tiersQuery = useQuery({
    queryKey: ['coding-plan-tiers', vendorFilter],
    queryFn: () =>
      getTiers(vendorFilter === 'all' ? undefined : { vendor_code: vendorFilter }),
  })
  const vendorsQuery = useQuery({
    queryKey: ['coding-plan-vendors'],
    queryFn: getVendors,
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        vendor_code: form.vendor_code,
        name: form.name,
        price: form.price === '' ? null : Number(form.price),
        price_note: form.price_note,
        period: form.period,
        quota: form.quota === '' ? null : Number(form.quota),
        quota_unit: form.quota_unit,
        quota_note: form.quota_note,
        status: Number(form.status),
        sort: Number(form.sort || 0),
        remark: form.remark,
      }
      if (editing) return updateTier(editing.id, payload)
      return createTier(payload)
    },
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Saved'))
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-tiers'] })
      } else {
        toast.error(res.message ?? t('Failed to save'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteTier(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Deleted'))
        qc.invalidateQueries({ queryKey: ['coding-plan-tiers'] })
      } else {
        toast.error(res.message ?? t('Failed to delete'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const tiers = tiersQuery.data?.data ?? []
  const vendors = vendorsQuery.data?.data ?? []
  const vendorName = (code: string) =>
    vendors.find((v) => v.code === code)?.name ?? code
  const set = (key: keyof TierForm, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }))
  const priceLabel = (tier: CodingPlanVendorTier) => {
    if (tier.price === null || tier.price === undefined || tier.price === '') {
      return tier.price_note || t('Pending check')
    }
    return `¥${Number(tier.price)}${tier.price_note ? `（${tier.price_note}）` : ''}`
  }
  const quotaLabel = (tier: CodingPlanVendorTier) => {
    const quota =
      tier.quota === null || tier.quota === undefined || tier.quota === ''
        ? '—'
        : Number(tier.quota).toLocaleString()
    const unit = tier.quota_unit ? ` ${tier.quota_unit}` : ''
    return `${quota}${unit}${tier.quota_note ? ` · ${tier.quota_note}` : ''}`
  }

  return (
    <div className='flex flex-col gap-3'>
      <div className='flex flex-wrap items-center justify-between gap-2'>
        <p className='text-muted-foreground text-sm'>
          {t('Official tiers per vendor (personal/team/seat/usage packs); only "Visible" tiers appear on the public intro page')}
          {t('/coding-plan; empty price means "see official site". Presets come from official docs (migration 2026_09_11_000003).')}
        </p>
        <div className='flex items-center gap-2'>
          <Select
            value={vendorFilter}
            onValueChange={(v) => setVendorFilter(v ?? 'all')}
          >
            <SelectTrigger className='w-44'>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value='all'>{t('All vendors')}</SelectItem>
              {vendors.map((v) => (
                <SelectItem key={v.code} value={v.code}>
                  {v.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button
            size='sm'
            onClick={() => {
              setEditing(null)
              setForm({
                ...EMPTY,
                vendor_code: vendorFilter === 'all' ? '' : vendorFilter,
              })
              setOpen(true)
            }}
          >
            <Plus className='mr-1 size-4' /> {t('Add plan tier')}
          </Button>
        </div>
      </div>
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Vendor')}</TableHead>
              <TableHead>{t('Tier')}</TableHead>
              <TableHead>{t('Price')}</TableHead>
              <TableHead>{t('Quota')}</TableHead>
              <TableHead>{t('Status')}</TableHead>
              <TableHead className='text-right'>{t('Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {tiersQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={6} className='py-8 text-center'>
                  {t('Loading…')}
                </TableCell>
              </TableRow>
            ) : tiers.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={6}
                  className='text-muted-foreground py-8 text-center'
                >
                  {t('No plan tiers yet (presets are written by migration; verify against official sites and fill in)')}
                </TableCell>
              </TableRow>
            ) : (
              tiers.map((tier) => (
                <TableRow key={tier.id}>
                  <TableCell className='font-mono text-xs'>
                    {vendorName(tier.vendor_code)}
                  </TableCell>
                  <TableCell className='text-xs'>{tier.name}</TableCell>
                  <TableCell className='text-xs'>{priceLabel(tier)}</TableCell>
                  <TableCell className='text-xs'>{quotaLabel(tier)}</TableCell>
                  <TableCell>
                    {Number(tier.status) === 1 ? (
                      <Badge>{t('Visible')}</Badge>
                    ) : (
                      <Badge variant='secondary'>{t('Hidden')}</Badge>
                    )}
                  </TableCell>
                  <TableCell className='text-right'>
                    <div className='flex justify-end gap-1'>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          setEditing(tier)
                          setForm({
                            vendor_code: tier.vendor_code,
                            name: tier.name,
                            price:
                              tier.price === null || tier.price === undefined
                                ? ''
                                : String(tier.price),
                            price_note: tier.price_note ?? '',
                            period: tier.period ?? '',
                            quota:
                              tier.quota === null || tier.quota === undefined
                                ? ''
                                : String(tier.quota),
                            quota_unit: tier.quota_unit ?? '',
                            quota_note: tier.quota_note ?? '',
                            status: String(tier.status ?? 1),
                            sort: String(tier.sort ?? 0),
                            remark: tier.remark ?? '',
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
                          if (window.confirm(t('Delete tier "{{name}}"?', { name: tier.name }))) {
                            deleteMutation.mutate(tier.id)
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
        <DialogContent className='max-h-[85vh] max-w-xl overflow-y-auto'>
          <DialogHeader>
            <DialogTitle>{editing ? t('Edit plan tier') : t('Add plan tier')}</DialogTitle>
          </DialogHeader>
          <div className='flex flex-col gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Vendor')}</Label>
                <Select
                  value={form.vendor_code}
                  onValueChange={(v) => set('vendor_code', v ?? '')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {vendors.map((v) => (
                      <SelectItem key={v.code} value={v.code}>
                        {v.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Tier name')}</Label>
                <Input
                  value={form.name}
                  onChange={(e) => set('name', e.target.value)}
                  placeholder={t('Personal · Lite / Team · Standard seat')}
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Price (CNY)')}</Label>
                <Input
                  type='number'
                  step='0.01'
                  value={form.price}
                  onChange={(e) => set('price', e.target.value)}
                  placeholder={t('Leave empty = pending check')}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Price note')}</Label>
                <Input
                  value={form.price_note}
                  onChange={(e) => set('price_note', e.target.value)}
                  placeholder={t('Original price ¥60/month')}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Period')}</Label>
                <Input
                  value={form.period}
                  onChange={(e) => set('period', e.target.value)}
                  placeholder='month / seat/month'
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Quota')}</Label>
                <Input
                  type='number'
                  value={form.quota}
                  onChange={(e) => set('quota', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Quota unit')}</Label>
                <Input
                  value={form.quota_unit}
                  onChange={(e) => set('quota_unit', e.target.value)}
                  placeholder={t('Credits / resource points')}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Quota note')}</Label>
                <Input
                  value={form.quota_note}
                  onChange={(e) => set('quota_note', e.target.value)}
                  placeholder={t('2,500 Credits per 7 days')}
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Status')}</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='1'>{t('Visible')}</SelectItem>
                    <SelectItem value='0'>{t('Hidden')}</SelectItem>
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
          </div>
          <DialogFooter>
            <Button variant='outline' onClick={() => setOpen(false)}>
              {t('Cancel')}
            </Button>
            <Button
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending || !form.vendor_code || !form.name}
            >
              {t('Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
