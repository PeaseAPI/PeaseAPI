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

const STATUS_MAP: Record<
  number,
  { label: string; variant: 'default' | 'secondary' | 'destructive' }
> = {
  0: { label: '禁用', variant: 'destructive' },
  1: { label: '启用', variant: 'default' },
  2: { label: '已耗尽', variant: 'secondary' },
}

function statusBadge(status: number) {
  const s = STATUS_MAP[status] ?? STATUS_MAP[0]
  return <Badge variant={s.variant}>{s.label}</Badge>
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
        toast.success(res.message ?? '已保存')
        setOpen(false)
        invalidate()
      } else {
        toast.error(res.message ?? '保存失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteAccount(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已删除')
        invalidate()
      } else {
        toast.error(res.message ?? '删除失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const resetMutation = useMutation({
    mutationFn: (id: number) => resetAccountUsage(id, 'all'),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已重置')
        invalidate()
      } else {
        toast.error(res.message ?? '重置失败')
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
          账号池按优先级轮询，仅成功请求计入用量；积分模式按折算比率表计费。
        </p>
        <Button size='sm' onClick={openCreate}>
          <Plus className='mr-1 size-4' /> 新增账号
        </Button>
      </div>

      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>厂商</TableHead>
              <TableHead>名称</TableHead>
              <TableHead>计费</TableHead>
              <TableHead>月已用/配额</TableHead>
              <TableHead>汇率</TableHead>
              <TableHead>优先级</TableHead>
              <TableHead>状态</TableHead>
              <TableHead className='text-right'>操作</TableHead>
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
                  暂无账号
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
                        积分{a.unit_name ? ` · ${a.unit_name}` : ''}
                      </Badge>
                    ) : (
                      <Badge variant='outline'>按次</Badge>
                    )}
                  </TableCell>
                  <TableCell>
                    {Number(a.used_monthly)} / {Number(a.quota_monthly)}
                  </TableCell>
                  <TableCell>
                    {Number(a.unit_exchange_rate) > 0
                      ? `×${Number(a.unit_exchange_rate)}`
                      : '默认'}
                  </TableCell>
                  <TableCell>{a.priority}</TableCell>
                  <TableCell>{statusBadge(Number(a.status))}</TableCell>
                  <TableCell className='text-right'>
                    <div className='flex justify-end gap-1'>
                      <Button variant='ghost' size='sm' onClick={() => openEdit(a)}>
                        编辑
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
                              `确认删除账号「${a.account_name}」？`
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
            <DialogTitle>{editing ? '编辑账号' : '新增账号'}</DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>厂商</Label>
                <Select value={form.vendor} onValueChange={(v) => set('vendor', v ?? '')}>
                  <SelectTrigger>
                    <SelectValue placeholder='选择厂商' />
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
                <Label>账号名称</Label>
                <Input
                  value={form.account_name}
                  onChange={(e) => set('account_name', e.target.value)}
                  placeholder='例如 claude-max-01'
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>计费模式</Label>
                <Select
                  value={form.billing_mode}
                  onValueChange={(v) => set('billing_mode', v ?? '')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={String(BILLING_MODE_PER_REQUEST)}>
                      按次提交
                    </SelectItem>
                    <SelectItem value={String(BILLING_MODE_CREDIT)}>
                      按积分折算
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>计数单位（可选）</Label>
                <Input
                  value={form.unit_name}
                  onChange={(e) => set('unit_name', e.target.value)}
                  placeholder='次 / 点 / 积分'
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>单位汇率（0=跟随供应商）</Label>
                <Input
                  type='number'
                  step='0.0001'
                  value={form.unit_exchange_rate}
                  onChange={(e) => set('unit_exchange_rate', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>关联渠道 ID</Label>
                <Input
                  type='number'
                  value={form.channel_id}
                  onChange={(e) => set('channel_id', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>API Key{editing ? '（留空不修改）' : ''}</Label>
                <Input
                  type='password'
                  value={form.api_key}
                  onChange={(e) => set('api_key', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>Base URL（可选）</Label>
                <Input
                  value={form.base_url}
                  onChange={(e) => set('base_url', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>5h 配额</Label>
                <Input
                  type='number'
                  value={form.quota_5h}
                  onChange={(e) => set('quota_5h', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>周配额</Label>
                <Input
                  type='number'
                  value={form.quota_weekly}
                  onChange={(e) => set('quota_weekly', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>月配额</Label>
                <Input
                  type='number'
                  value={form.quota_monthly}
                  onChange={(e) => set('quota_monthly', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>月阈值 %</Label>
                <Input
                  type='number'
                  value={form.monthly_usage_threshold}
                  onChange={(e) => set('monthly_usage_threshold', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>优先级</Label>
                <Input
                  type='number'
                  value={form.priority}
                  onChange={(e) => set('priority', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>状态</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='1'>启用</SelectItem>
                    <SelectItem value='0'>禁用</SelectItem>
                    <SelectItem value='2'>已耗尽</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>到期时间（可选，Unix 秒或日期）</Label>
                <Input
                  value={form.expires_at}
                  onChange={(e) => set('expires_at', e.target.value)}
                  placeholder='例如 2026-12-31'
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>备注</Label>
                <Input
                  value={form.remark}
                  onChange={(e) => set('remark', e.target.value)}
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant='outline' onClick={() => setOpen(false)}>
              取消
            </Button>
            <Button
              onClick={() => saveMutation.mutate()}
              disabled={
                saveMutation.isPending || !form.vendor || !form.account_name
              }
            >
              保存
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
              使用流水 — {usageAccount?.account_name ?? ''}
            </DialogTitle>
          </DialogHeader>
          <div className='max-h-[60vh] overflow-y-auto rounded-md border'>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>时间</TableHead>
                  <TableHead>模型</TableHead>
                  <TableHead>Tokens</TableHead>
                  <TableHead>单位消耗</TableHead>
                  <TableHead>积分</TableHead>
                  <TableHead>状态</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {usageQuery.isLoading ? (
                  <TableRow>
                    <TableCell colSpan={6} className='py-8 text-center'>
                      加载中…
                    </TableCell>
                  </TableRow>
                ) : (usageQuery.data?.data?.items ?? []).length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={6}
                      className='text-muted-foreground py-8 text-center'
                    >
                      暂无流水
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
                          <Badge>成功</Badge>
                        ) : (
                          <Badge
                            variant='destructive'
                            title={log.error ?? undefined}
                          >
                            失败
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
