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
        toast.success(res.message ?? '活动已保存')
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-promotions'] })
      } else {
        toast.error(res.message ?? '保存失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => destroyPromotion(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已删除')
        qc.invalidateQueries({ queryKey: ['coding-plan-promotions'] })
      } else {
        toast.error(res.message ?? '删除失败')
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
    if (state === 'ongoing') return <Badge>进行中</Badge>
    if (state === 'scheduled') return <Badge variant='outline'>未开始</Badge>
    if (state === 'expired') return <Badge variant='destructive'>已结束</Badge>
    return (
      <Badge variant='outline'>
        {PROMOTION_STATES.find((s) => s.value === state)?.label ?? '-'}
      </Badge>
    )
  }

  const countdown = (p: CodingPlanPromotion) => {
    if (p.remaining_seconds === null || p.remaining_seconds === undefined)
      return '长期 / 未公布'
    const days = Math.floor(Number(p.remaining_seconds) / 86400)
    const hours = Math.floor((Number(p.remaining_seconds) % 86400) / 3600)
    return days > 0 ? `剩 ${days} 天 ${hours} 小时` : `剩 ${hours} 小时`
  }

  return (
    <div className='flex flex-col gap-3'>
      <div className='flex flex-wrap items-center justify-between gap-2'>
        <p className='text-muted-foreground text-sm'>
          官方厂商活动（限时价 / 时段折扣 /
          模型退市）。每日 09:00 自动提醒 remind_days
          内到期的活动（站内公告 + 订阅用户邮件），到期后自动标记已结束。
        </p>
        <div className='flex items-center gap-2'>
          <span className='text-muted-foreground text-xs'>含停用/过期</span>
          <Switch checked={showAll} onCheckedChange={setShowAll} />
          <Button
            size='sm'
            onClick={() => {
              setEditing(null)
              setForm(EMPTY)
              setOpen(true)
            }}
          >
            <Plus className='mr-1 size-4' /> 新增活动
          </Button>
        </div>
      </div>

      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>厂商</TableHead>
              <TableHead>类型</TableHead>
              <TableHead>标题</TableHead>
              <TableHead>折扣</TableHead>
              <TableHead>截止</TableHead>
              <TableHead>状态</TableHead>
              <TableHead>倒计时</TableHead>
              <TableHead>提醒</TableHead>
              <TableHead>启停</TableHead>
              <TableHead className='text-right'>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {promotionsQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={10} className='py-8 text-center'>
                  加载中…
                </TableCell>
              </TableRow>
            ) : promotions.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={10}
                  className='text-muted-foreground py-8 text-center'
                >
                  暂无活动；迁移 000015 已预置已知厂商活动，可「编辑」调整
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
                      ? `${Number(p.discount) * 10} 折`
                      : '-'}
                  </TableCell>
                  <TableCell className='text-xs'>
                    {p.ends_at
                      ? dayjs.unix(Number(p.ends_at)).format('MM-DD HH:mm')
                      : '未公布'}
                  </TableCell>
                  <TableCell>{stateBadge(p.state)}</TableCell>
                  <TableCell className='text-muted-foreground text-xs'>
                    {countdown(p)}
                  </TableCell>
                  <TableCell className='text-muted-foreground text-xs'>
                    {p.remind_days} 天
                  </TableCell>
                  <TableCell>
                    {Number(p.status) === 1 ? (
                      <Badge>启用</Badge>
                    ) : (
                      <Badge variant='destructive'>停用</Badge>
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
                        编辑
                      </Button>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          if (window.confirm(`确认删除活动「${p.title}」？`)) {
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
            <DialogTitle>{editing ? '编辑活动' : '新增活动'}</DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>厂商代码</Label>
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
                <Label>类型</Label>
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
              <Label>标题</Label>
              <Input
                value={form.title}
                onChange={(e) => set('title', e.target.value)}
                placeholder='夜间畅用（每日 23:00–次日 09:00）'
              />
            </div>
            <div className='grid gap-1.5'>
              <Label>说明</Label>
              <Textarea
                value={form.description}
                onChange={(e) => set('description', e.target.value)}
                rows={2}
                placeholder='面向用户的活动说明，展示在介绍页与站内公告'
              />
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>折扣乘数（可选）</Label>
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
                <Label>开始时间（可选）</Label>
                <Input
                  type='datetime-local'
                  value={form.starts_at}
                  onChange={(e) => set('starts_at', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>截止（留空=未公布）</Label>
                <Input
                  type='datetime-local'
                  value={form.ends_at}
                  onChange={(e) => set('ends_at', e.target.value)}
                />
              </div>
            </div>
            <div className='grid gap-1.5'>
              <Label>官方来源 URL（可选）</Label>
              <Input
                value={form.source_url}
                onChange={(e) => set('source_url', e.target.value)}
                placeholder='https://...'
              />
            </div>
            <div className='grid grid-cols-4 gap-3'>
              <div className='grid gap-1.5'>
                <Label>状态</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '1')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='1'>启用</SelectItem>
                    <SelectItem value='0'>停用</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className='grid gap-1.5'>
                <Label>提前提醒（天）</Label>
                <Input
                  type='number'
                  min='0'
                  max='90'
                  value={form.remind_days}
                  onChange={(e) => set('remind_days', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>排序</Label>
                <Input
                  type='number'
                  value={form.sort}
                  onChange={(e) => set('sort', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>备注（内部）</Label>
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
              disabled={saveMutation.isPending || !form.vendor || !form.title}
            >
              保存
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

