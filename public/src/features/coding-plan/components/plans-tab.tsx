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
import { Link2Off, Plus } from 'lucide-react'
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
import { getAdminPlans } from '@/features/subscriptions/api'
import type { SubscriptionPlan } from '@/features/subscriptions/types'

import { attachPlan, detachPlan, getPlans, getVendors } from '../api'
import type { CodingPlanAdminPlan } from '../types'

type AttachFormState = {
  plan_id: string
  vendor: string
  coding_submits_per_request: string
  coding_quota: string
}

const EMPTY_ATTACH: AttachFormState = {
  plan_id: '',
  vendor: '',
  coding_submits_per_request: '1',
  coding_quota: '0',
}

/** 可绑定的普通订阅套餐（后端返回扁平模型，plan_type 仅绑定时存在） */
type AttachCandidate = {
  plan: SubscriptionPlan & { plan_type?: string }
}

export function PlansTab() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<AttachFormState>(EMPTY_ATTACH)

  const plansQuery = useQuery({
    queryKey: ['coding-plan-plans'],
    queryFn: () => getPlans({ per_page: 100 }),
  })
  const vendorsQuery = useQuery({
    queryKey: ['coding-plan-vendors'],
    queryFn: getVendors,
  })
  const candidatesQuery = useQuery({
    queryKey: ['coding-plan-attach-candidates'],
    queryFn: getAdminPlans,
    // 仅在打开绑定对话框时拉取候选套餐
    enabled: open,
  })

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['coding-plan-plans'] })
    qc.invalidateQueries({ queryKey: ['coding-plan-stats'] })
  }

  const attachMutation = useMutation({
    mutationFn: () =>
      attachPlan(Number(form.plan_id), {
        vendor: form.vendor,
        coding_submits_per_request: Number(
          form.coding_submits_per_request || 1
        ),
        coding_quota: Number(form.coding_quota || 0),
      }),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已绑定')
        setOpen(false)
        invalidate()
      } else {
        toast.error(res.message ?? '绑定失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const detachMutation = useMutation({
    mutationFn: ({ id, force }: { id: number; force: boolean }) =>
      detachPlan(id, force),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已解绑')
        invalidate()
      } else {
        toast.error(res.message ?? '解绑失败')
      }
    },
    onError: (e: unknown, vars) => {
      const err = e as {
        response?: { status?: number; data?: { message?: string } }
      }
      const message = err?.response?.data?.message ?? '解绑失败'
      toast.error(message)
      // 后端 422：仍有活跃订阅 → 引导强制解绑（force=1，保留订阅记录以便重绑）
      if (!vars.force && err?.response?.status === 422) {
        if (
          window.confirm(
            `${message}\n\n是否强制解绑？订阅记录将保留，重新绑定后可继续使用。`
          )
        ) {
          detachMutation.mutate({ id: vars.id, force: true })
        }
      }
    },
  })

  const plans = plansQuery.data?.data?.items ?? []
  const vendors = vendorsQuery.data?.data ?? []
  const candidates = ((candidatesQuery.data?.data?.items ?? []) as unknown as
    | AttachCandidate[]
    | undefined)
    ?.filter((c) => c.plan.plan_type !== 'coding_plan')
    .sort((a, b) => a.plan.id - b.plan.id)
  const loading = plansQuery.isLoading

  const set = (key: keyof AttachFormState, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }))

  const openAttach = () => {
    setForm(EMPTY_ATTACH)
    setOpen(true)
  }

  return (
    <div className='flex flex-col gap-3'>
      <div className='flex items-center justify-between'>
        <p className='text-muted-foreground text-sm'>
          将订阅套餐绑定到对应厂商的账号池；用户购买套餐即获得该厂商的
          Coding Plan 用量（0 表示不限）。
        </p>
        <Button size='sm' onClick={openAttach}>
          <Plus className='mr-1 size-4' /> 绑定套餐
        </Button>
      </div>

      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>套餐</TableHead>
              <TableHead>厂商</TableHead>
              <TableHead>每次提交数</TableHead>
              <TableHead>月配额</TableHead>
              <TableHead>账号池概览</TableHead>
              <TableHead className='text-right'>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              Array.from({ length: 3 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell colSpan={6}>
                    <Skeleton className='h-6 w-full' />
                  </TableCell>
                </TableRow>
              ))
            ) : plans.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={6}
                  className='text-muted-foreground py-8 text-center'
                >
                  暂无绑定的套餐，点击右上角「绑定套餐」创建
                </TableCell>
              </TableRow>
            ) : (
              plans.map((p: CodingPlanAdminPlan) => (
                <TableRow key={p.id}>
                  <TableCell className='font-medium'>
                    {p.title}
                    {p.enabled === false ? (
                      <Badge variant='outline' className='ml-2'>
                        已下架
                      </Badge>
                    ) : null}
                  </TableCell>
                  <TableCell>
                    {p.coding_vendor ? (
                      <Badge variant='secondary'>{p.coding_vendor}</Badge>
                    ) : (
                      '—'
                    )}
                  </TableCell>
                  <TableCell>{p.coding_submits_per_request ?? '—'}</TableCell>
                  <TableCell>
                    {Number(p.coding_quota ?? 0) > 0
                      ? Number(p.coding_quota)
                      : '不限'}
                  </TableCell>
                  <TableCell>
                    {p.pool_overview
                      ? `总数 ${p.pool_overview.total} · 可用 ${p.pool_overview.active} · 耗尽 ${p.pool_overview.exhausted}`
                      : '—'}
                  </TableCell>
                  <TableCell className='text-right'>
                    <Button
                      variant='ghost'
                      size='sm'
                      onClick={() => {
                        if (
                          window.confirm(
                            `解绑套餐「${p.title}」？解绑后 plan_type 将还原为 quota，该厂商的 Coding Plan 中转将无法匹配到此套餐。`
                          )
                        ) {
                          detachMutation.mutate({ id: p.id, force: false })
                        }
                      }}
                    >
                      <Link2Off className='size-4' />
                    </Button>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* 绑定对话框 */}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className='sm:max-w-md'>
          <DialogHeader>
            <DialogTitle>绑定套餐到账号池</DialogTitle>
          </DialogHeader>
          <div className='flex flex-col gap-3'>
            <div className='flex flex-col gap-1.5'>
              <Label>订阅套餐</Label>
              <Select
                value={form.plan_id}
                onValueChange={(v) => set('plan_id', v ?? '')}
              >
                <SelectTrigger className='w-full'>
                  <SelectValue placeholder='选择普通订阅套餐' />
                </SelectTrigger>
                <SelectContent>
                  {(candidates ?? []).map((c) => (
                    <SelectItem key={c.plan.id} value={String(c.plan.id)}>
                      {c.plan.title}（#{c.plan.id}）
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {candidates && candidates.length === 0 ? (
                <p className='text-muted-foreground text-xs'>
                  暂无可绑定的普通套餐，请先在订阅管理中创建
                </p>
              ) : null}
            </div>
            <div className='flex flex-col gap-1.5'>
              <Label>厂商</Label>
              <Select
                value={form.vendor}
                onValueChange={(v) => set('vendor', v ?? '')}
              >
                <SelectTrigger className='w-full'>
                  <SelectValue placeholder='选择厂商' />
                </SelectTrigger>
                <SelectContent>
                  {vendors.map((v) => (
                    <SelectItem key={v.id} value={v.code}>
                      {v.name || v.code}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className='flex flex-col gap-1.5'>
              <Label>每次请求提交数</Label>
              <Input
                type='number'
                min={1}
                value={form.coding_submits_per_request}
                onChange={(e) =>
                  set('coding_submits_per_request', e.target.value)
                }
              />
            </div>
            <div className='flex flex-col gap-1.5'>
              <Label>月配额（0 表示不限）</Label>
              <Input
                type='number'
                min={0}
                value={form.coding_quota}
                onChange={(e) => set('coding_quota', e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant='outline' onClick={() => setOpen(false)}>
              取消
            </Button>
            <Button
              onClick={() => attachMutation.mutate()}
              disabled={
                !form.plan_id || !form.vendor || attachMutation.isPending
              }
            >
              {attachMutation.isPending ? '绑定中…' : '绑定'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
