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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

import { createVendor, deleteVendor, getVendors, updateVendor } from '../api'
import {
  PLAN_KIND_CODING,
  PLAN_KIND_TOKEN,
  PLAN_KINDS,
  type CodingPlanVendor,
} from '../types'

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useTranslation } from 'react-i18next'

type VendorForm = {
  code: string
  name: string
  plan_kind: string
  currency: string
  docs_url: string
  pricing_source_url: string
  unit_name: string
  unit_exchange_rate: string
  sort: string
  status: string
  remark: string
}

const EMPTY: VendorForm = {
  code: '',
  name: '',
  plan_kind: String(PLAN_KIND_CODING),
  currency: 'CNY',
  docs_url: '',
  pricing_source_url: '',
  unit_name: '',
  unit_exchange_rate: '1',
  sort: '0',
  status: '1',
  remark: '',
}

export function VendorsTab() {
  const { t } = useTranslation()

  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<CodingPlanVendor | null>(null)
  const [form, setForm] = useState<VendorForm>(EMPTY)

  const vendorsQuery = useQuery({
    queryKey: ['coding-plan-vendors'],
    queryFn: getVendors,
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        code: form.code,
        name: form.name,
        plan_kind: Number(form.plan_kind),
        currency: form.currency,
        docs_url: form.docs_url,
        pricing_source_url: form.pricing_source_url,
        unit_name: form.unit_name,
        unit_exchange_rate: Number(form.unit_exchange_rate || 1),
        sort: Number(form.sort || 0),
        status: Number(form.status),
        remark: form.remark,
      }
      if (editing) return updateVendor(editing.id, payload)
      return createVendor(payload)
    },
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Saved'))
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-vendors'] })
      } else {
        toast.error(res.message ?? t('Failed to save'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteVendor(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Deleted'))
        qc.invalidateQueries({ queryKey: ['coding-plan-vendors'] })
      } else {
        toast.error(res.message ?? t('Failed to delete'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const vendors = vendorsQuery.data?.data ?? []
  const set = (key: keyof VendorForm, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }))


  return (
    <div className='flex flex-col gap-3'>
      <div className='flex items-center justify-between'>
        <p className='text-muted-foreground text-sm'>
          {t('Vendors define default billing unit and exchange rate; accounts inherit them unless set individually.')}
        </p>
        <Button
          size='sm'
          onClick={() => {
            setEditing(null)
            setForm(EMPTY)
            setOpen(true)
          }}
        >
          <Plus className='mr-1 size-4' /> {t('Add vendor')}
        </Button>
      </div>
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Identifier')}</TableHead>
              <TableHead>{t('Name')}</TableHead>
              <TableHead>{t('Type')}</TableHead>
              <TableHead>{t('Currency')}</TableHead>
              <TableHead>{t('Default unit')}</TableHead>
              <TableHead>{t('Default rate')}</TableHead>
              <TableHead>{t('Account pools')}</TableHead>
              <TableHead>{t('Status')}</TableHead>
              <TableHead className='text-right'>{t('Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {vendorsQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={9} className='py-8 text-center'>
                  {t('Loading…')}
                </TableCell>
              </TableRow>
            ) : vendors.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={9}
                  className='text-muted-foreground py-8 text-center'
                >
                  {t('No vendors yet; migration has preset major vendor catalogs (disabled by default), click "Edit" to enable')}
                </TableCell>
              </TableRow>
            ) : (
              vendors.map((v) => (
                <TableRow key={v.id}>
                  <TableCell className='font-mono text-xs'>{v.code}</TableCell>
                  <TableCell>{v.name}</TableCell>
                  <TableCell>
                    <Badge variant='outline'>
                      {Number(v.plan_kind) === PLAN_KIND_TOKEN
                        ? t('Pay-as-you-go')
                        : t('Subscription')}
                    </Badge>
                  </TableCell>
                  <TableCell className='font-mono text-xs'>
                    {v.currency || 'CNY'}
                  </TableCell>
                  <TableCell>{v.unit_name || '-'}</TableCell>
                  <TableCell>×{Number(v.unit_exchange_rate)}</TableCell>
                  <TableCell>
                    {v.accounts_active ?? 0} / {v.accounts_total ?? 0}
                  </TableCell>
                  <TableCell>
                    {Number(v.status) === 1 ? (
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
                          setEditing(v)
                          setForm({
                            code: v.code,
                            name: v.name,
                            plan_kind: String(v.plan_kind ?? PLAN_KIND_CODING),
                            currency: v.currency ?? 'CNY',
                            docs_url: v.docs_url ?? '',
                            pricing_source_url: v.pricing_source_url ?? '',
                            unit_name: v.unit_name ?? '',
                            unit_exchange_rate: String(v.unit_exchange_rate ?? 1),
                            sort: String(v.sort ?? 0),
                            status: String(v.status ?? 1),
                            remark: v.remark ?? '',
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
                          if (
                            window.confirm(
                              t('Delete vendor "{{name}}"? Its ratio config is deleted too; {{total}} accounts ({{active}} active) are kept and must be re-bound to another vendor before relaying resumes.', { name: v.name, total: v.accounts_total ?? 0, active: v.accounts_active ?? 0 })
                            )
                          ) {
                            deleteMutation.mutate(v.id)
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
            <DialogTitle>{editing ? t('Edit vendor') : t('Add vendor')}</DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Identifier (unique)')}</Label>
                <Input
                  value={form.code}
                  onChange={(e) => set('code', e.target.value)}
                  placeholder='anthropic'
                  disabled={!!editing}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Name')}</Label>
                <Input
                  value={form.name}
                  onChange={(e) => set('name', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Plan type')}</Label>
                <Select
                  value={form.plan_kind}
                  onValueChange={(v) => set('plan_kind', v ?? '')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PLAN_KINDS.map((k) => (
                      <SelectItem key={k.value} value={k.value}>
                        {k.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>Docs URL</Label>
                <Input
                  value={form.docs_url}
                  onChange={(e) => set('docs_url', e.target.value)}
                />
              </div>
            </div>
            <div className='grid gap-1.5'>
              <Label>{t('Pricing source URL (optional, JSON price list for 6-hour auto comparison)')}</Label>
              <Input
                value={form.pricing_source_url}
                onChange={(e) => set('pricing_source_url', e.target.value)}
                placeholder='https://vendor.example/prices.json'
              />
              <div className='flex items-center gap-2'>
                <Button
                  type='button'
                  variant='outline'
                  size='sm'
                  disabled={!form.code.trim()}
                  onClick={() =>
                    set(
                      'pricing_source_url',
                      `${window.location.origin}/api/coding_plan/pricing_source/${form.code.trim()}`
                    )
                  }
                >
                  {t('Use built-in official source')}
                </Button>
                <span className='text-muted-foreground text-xs'>
                  {t('Points to the JSON generated by the built-in official catalog (fill in the vendor code first); official price changes flow into the pending list automatically')}
                </span>
              </div>
            </div>
            <div className='grid grid-cols-4 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Currency')}</Label>
                <Select value={form.currency} onValueChange={(v) => set('currency', v ?? 'CNY')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {['CNY', 'USD', 'HKD', 'EUR', 'JPY', 'GBP', 'SGD'].map((c) => (
                      <SelectItem key={c} value={c}>
                        {c}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Default unit')}</Label>
                <Input
                  value={form.unit_name}
                  onChange={(e) => set('unit_name', e.target.value)}
                  placeholder={t('times')}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Default rate')}</Label>
                <Input
                  type='number'
                  step='0.0001'
                  value={form.unit_exchange_rate}
                  onChange={(e) => set('unit_exchange_rate', e.target.value)}
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
            </div>
            <div className='grid grid-cols-2 gap-3'>
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
              disabled={saveMutation.isPending || !form.code || !form.name}
            >
              {t('Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
