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
import { History, Plus, RotateCcw, Trash2 } from 'lucide-react'
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
import { Skeleton } from '@/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

import {
  createAccount,
  deleteAccount,
  getAccountUsage,
  getAccounts,
  getVendors,
  resetAccountUsage,
  updateAccount,
} from '../api'
import {
  BILLING_MODE_CREDIT,
  BILLING_MODE_PER_REQUEST,
  type CodingPlanAccount,
} from '../types'
import { t } from 'i18next'
import { useTranslation } from 'react-i18next'

const STATUS_MAP: Record<
  number,
  { label: string; variant: 'default' | 'secondary' | 'destructive' }
> = {
  0: { label: 'Disabled', variant: 'destructive' },
  1: { label: 'Enabled', variant: 'default' },
  2: { label: 'Exhausted', variant: 'secondary' },
}

function statusBadge(status: number) {
  const s = STATUS_MAP[status] ?? STATUS_MAP[0]
  return <Badge variant={s.variant}>{t(s.label)}</Badge>
}

type FormState = {
  vendor: string
  billing_mode: string
  unit_name: string
  unit_exchange_rate: string
  account_name: string
  channel_id: string
  api_key: string
  base_url: string
  quota_5h: string
  quota_weekly: string
  quota_monthly: string
  monthly_usage_threshold: string
  priority: string
  expires_at: string
  status: string
  remark: string
}

const EMPTY_FORM: FormState = {
  vendor: '',
  billing_mode: String(BILLING_MODE_PER_REQUEST),
  unit_name: '',
  unit_exchange_rate: '0',
  account_name: '',
  channel_id: '0',
  api_key: '',
  base_url: '',
  quota_5h: '0',
  quota_weekly: '0',
  quota_monthly: '0',
  monthly_usage_threshold: '80',
  priority: '100',
  expires_at: '',
  status: '1',
  remark: '',
}

function accountToForm(a: CodingPlanAccount): FormState {
  return {
    vendor: a.vendor,
    billing_mode: String(a.billing_mode),
    unit_name: a.unit_name ?? '',
    unit_exchange_rate: String(a.unit_exchange_rate ?? '0'),
    account_name: a.account_name,
    channel_id: String(a.channel_id ?? 0),
    api_key: '',
    base_url: a.base_url ?? '',
    quota_5h: String(a.quota_5h ?? 0),
    quota_weekly: String(a.quota_weekly ?? 0),
    quota_monthly: String(a.quota_monthly ?? 0),
    monthly_usage_threshold: String(a.monthly_usage_threshold ?? 80),
    priority: String(a.priority ?? 100),
    expires_at: a.expires_at ? String(a.expires_at) : '',
    status: String(a.status),
    remark: a.remark ?? '',
  }
}

export function AccountsTab() {
  const { t } = useTranslation()

  const qc = useQueryClient()
  const [editing, setEditing] = useState<CodingPlanAccount | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [usageAccount, setUsageAccount] = useState<CodingPlanAccount | null>(
    null
  )

  const accountsQuery = useQuery({
    queryKey: ['coding-plan-accounts'],
    queryFn: getAccounts,
  })
  const vendorsQuery = useQuery({
    queryKey: ['coding-plan-vendors'],
    queryFn: getVendors,
  })
  const usageQuery = useQuery({
    queryKey: ['coding-plan-account-usage', usageAccount?.id],
    queryFn: () => getAccountUsage(usageAccount!.id, { per_page: 50 }),
    enabled: usageAccount !== null,
  })

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['coding-plan-accounts'] })
    qc.invalidateQueries({ queryKey: ['coding-plan-stats'] })
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        vendor: form.vendor,
        billing_mode: Number(form.billing_mode),
        unit_name: form.unit_name,
        unit_exchange_rate: Number(form.unit_exchange_rate || 0),
        account_name: form.account_name,
        channel_id: Number(form.channel_id || 0),
        base_url: form.base_url,
        quota_5h: Number(form.quota_5h || 0),
        quota_weekly: Number(form.quota_weekly || 0),
        quota_monthly: Number(form.quota_monthly || 0),
        monthly_usage_threshold: Number(form.monthly_usage_threshold || 80),
        priority: Number(form.priority || 100),
        status: Number(form.status),
        remark: form.remark,
      }
      if (form.api_key) payload.api_key = form.api_key
      // 始终携带 expires_at：留空表示清除到期时间（后端归一化为 0）
      payload.expires_at = form.expires_at
      if (editing) return updateAccount(editing.id, payload)
      return createAccount(payload)
    },
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Saved'))
        setOpen(false)
        invalidate()
      } else {
        toast.error(res.message ?? t('Failed to save'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteAccount(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Deleted'))
        invalidate()
      } else {
        toast.error(res.message ?? t('Failed to delete'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const resetMutation = useMutation({
    mutationFn: (id: number) => resetAccountUsage(id, 'all'),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Reset'))
        invalidate()
      } else {
        toast.error(res.message ?? t('Failed to reset'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const openCreate = () => {
    setEditing(null)
    setForm(EMPTY_FORM)
    setOpen(true)
  }

  const openEdit = (a: CodingPlanAccount) => {
    setEditing(a)
    setForm(accountToForm(a))
    setOpen(true)
  }

  const accounts = accountsQuery.data?.data?.items ?? []
  const vendors = vendorsQuery.data?.data ?? []
  const loading = accountsQuery.isLoading

  const set = (key: keyof FormState, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }))


  return (
    <div className='flex flex-col gap-3'>
      <div className='flex items-center justify-between'>
        <p className='text-muted-foreground text-sm'>
          {t('Account pools rotate by priority; only successful requests count toward usage; credits mode bills via the conversion ratio table.')}
        </p>
        <Button size='sm' onClick={openCreate}>
          <Plus className='mr-1 size-4' /> {t('Add account')}
        </Button>
      </div>

      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Vendor')}</TableHead>
              <TableHead>{t('Name')}</TableHead>
              <TableHead>{t('Billing')}</TableHead>
              <TableHead>{t('Monthly used/quota')}</TableHead>
              <TableHead>{t('Exchange rates')}</TableHead>
              <TableHead>{t('Priority')}</TableHead>
              <TableHead>{t('Status')}</TableHead>
              <TableHead className='text-right'>{t('Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              Array.from({ length: 3 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell colSpan={8}>
                    <Skeleton className='h-6 w-full' />
                  </TableCell>
                </TableRow>
              ))
            ) : accounts.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className='text-muted-foreground py-8 text-center'
                >
                  {t('No accounts')}
                </TableCell>
              </TableRow>
            ) : (
              accounts.map((a) => (
                <TableRow key={a.id}>
                  <TableCell className='font-medium'>{a.vendor}</TableCell>
                  <TableCell>{a.account_name}</TableCell>
                  <TableCell>
                    {Number(a.billing_mode) === BILLING_MODE_CREDIT ? (
                      <Badge variant='secondary'>
                        {t('Credits{{suffix}}', { suffix: a.unit_name ? ` · ${a.unit_name}` : '' })}
                      </Badge>
                    ) : (
                      <Badge variant='outline'>{t('Per request')}</Badge>
                    )}
                  </TableCell>
                  <TableCell>
                    {Number(a.used_monthly)} / {Number(a.quota_monthly)}
                  </TableCell>
                  <TableCell>
                    {Number(a.unit_exchange_rate) > 0
                      ? `×${Number(a.unit_exchange_rate)}`
                      : t('Default')}
                  </TableCell>
                  <TableCell>{a.priority}</TableCell>
                  <TableCell>{statusBadge(Number(a.status))}</TableCell>
                  <TableCell className='text-right'>
                    <div className='flex justify-end gap-1'>
                      <Button variant='ghost' size='sm' onClick={() => openEdit(a)}>
                        {t('Edit')}
                      </Button>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => setUsageAccount(a)}
                      >
                        <History className='size-4' />
                      </Button>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => resetMutation.mutate(a.id)}
                      >
                        <RotateCcw className='size-4' />
                      </Button>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          if (
                            window.confirm(
                              t('Delete account "{{name}}"?', { name: a.account_name })
                            )
                          ) {
                            deleteMutation.mutate(a.id)
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
        <DialogContent className='max-h-[85vh] overflow-y-auto sm:max-w-lg'>
          <DialogHeader>
            <DialogTitle>{editing ? t('Edit account') : t('Add account')}</DialogTitle>
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
                <Label>{t('Account name')}</Label>
                <Input
                  value={form.account_name}
                  onChange={(e) => set('account_name', e.target.value)}
                  placeholder={t('e.g. claude-max-01')}
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Billing mode')}</Label>
                <Select
                  value={form.billing_mode}
                  onValueChange={(v) => set('billing_mode', v ?? '')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={String(BILLING_MODE_PER_REQUEST)}>
                      {t('Per-request submits')}
                    </SelectItem>
                    <SelectItem value={String(BILLING_MODE_CREDIT)}>
                      {t('Credits conversion')}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Count unit (optional)')}</Label>
                <Input
                  value={form.unit_name}
                  onChange={(e) => set('unit_name', e.target.value)}
                  placeholder={t('times / points / credits')}
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Unit rate (0 = follow vendor)')}</Label>
                <Input
                  type='number'
                  step='0.0001'
                  value={form.unit_exchange_rate}
                  onChange={(e) => set('unit_exchange_rate', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Linked channel ID')}</Label>
                <Input
                  type='number'
                  value={form.channel_id}
                  onChange={(e) => set('channel_id', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>API Key{editing ? t('(leave empty to keep unchanged)') : ''}</Label>
                <Input
                  type='password'
                  value={form.api_key}
                  onChange={(e) => set('api_key', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Base URL (optional)')}</Label>
                <Input
                  value={form.base_url}
                  onChange={(e) => set('base_url', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('5h quota')}</Label>
                <Input
                  type='number'
                  value={form.quota_5h}
                  onChange={(e) => set('quota_5h', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Weekly quota')}</Label>
                <Input
                  type='number'
                  value={form.quota_weekly}
                  onChange={(e) => set('quota_weekly', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Monthly quota')}</Label>
                <Input
                  type='number'
                  value={form.quota_monthly}
                  onChange={(e) => set('quota_monthly', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Monthly threshold %')}</Label>
                <Input
                  type='number'
                  value={form.monthly_usage_threshold}
                  onChange={(e) => set('monthly_usage_threshold', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Priority')}</Label>
                <Input
                  type='number'
                  value={form.priority}
                  onChange={(e) => set('priority', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Status')}</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='1'>{t('Enabled')}</SelectItem>
                    <SelectItem value='0'>{t('Disabled')}</SelectItem>
                    <SelectItem value='2'>{t('Exhausted')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Expires at (optional, Unix seconds or date)')}</Label>
                <Input
                  value={form.expires_at}
                  onChange={(e) => set('expires_at', e.target.value)}
                  placeholder={t('e.g. 2026-12-31')}
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
              disabled={
                saveMutation.isPending || !form.vendor || !form.account_name
              }
            >
              {t('Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog
        open={usageAccount !== null}
        onOpenChange={(o) => {
          if (!o) setUsageAccount(null)
        }}
      >
        <DialogContent className='sm:max-w-3xl'>
          <DialogHeader>
            <DialogTitle>
              {t('Usage logs — {{name}}', { name: usageAccount?.account_name ?? '' })}
            </DialogTitle>
          </DialogHeader>
          <div className='max-h-[60vh] overflow-y-auto rounded-md border'>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('Time')}</TableHead>
                  <TableHead>{t('Model')}</TableHead>
                  <TableHead>Tokens</TableHead>
                  <TableHead>{t('Units')}</TableHead>
                  <TableHead>{t('Credits')}</TableHead>
                  <TableHead>{t('Status')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {usageQuery.isLoading ? (
                  <TableRow>
                    <TableCell colSpan={6} className='py-8 text-center'>
                      {t('Loading…')}
                    </TableCell>
                  </TableRow>
                ) : (usageQuery.data?.data?.items ?? []).length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={6}
                      className='text-muted-foreground py-8 text-center'
                    >
                      {t('No usage records')}
                    </TableCell>
                  </TableRow>
                ) : (
                  (usageQuery.data?.data?.items ?? []).map((log) => (
                    <TableRow key={log.id}>
                      <TableCell className='font-mono text-xs'>
                        {new Date(Number(log.created_at) * 1000).toLocaleString()}
                      </TableCell>
                      <TableCell className='font-mono text-xs'>
                        {log.model ?? '-'}
                      </TableCell>
                      <TableCell className='font-mono text-xs'>
                        {log.prompt_tokens}/{log.completion_tokens}
                      </TableCell>
                      <TableCell className='font-mono text-xs'>
                        {Number(log.units).toFixed(2)}
                      </TableCell>
                      <TableCell className='font-mono text-xs'>
                        {Number(log.credits).toFixed(2)}
                      </TableCell>
                      <TableCell>
                        {log.success ? (
                          <Badge>{t('Success')}</Badge>
                        ) : (
                          <Badge
                            variant='destructive'
                            title={log.error ?? undefined}
                          >
                            {t('Failed')}
                          </Badge>
                        )}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
