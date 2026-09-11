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
        toast.success(res.message ?? '已保存')
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-tiers'] })
      } else {
        toast.error(res.message ?? '保存失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteTier(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已删除')
        qc.invalidateQueries({ queryKey: ['coding-plan-tiers'] })
      } else {
        toast.error(res.message ?? '删除失败')
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
  const priceLabel = (t: CodingPlanVendorTier) => {
    if (t.price === null || t.price === undefined || t.price === '') {
      return t.price_note || '待核对'
    }
    return `¥${Number(t.price)}${t.price_note ? `（${t.price_note}）` : ''}`
  }
  const quotaLabel = (t: CodingPlanVendorTier) => {
    const quota =
      t.quota === null || t.quota === undefined || t.quota === ''
        ? '—'
        : Number(t.quota).toLocaleString()
    const unit = t.quota_unit ? ` ${t.quota_unit}` : ''
    return `${quota}${unit}${t.quota_note ? ` · ${t.quota_note}` : ''}`
  }

  return (
    <div className='flex flex-col gap-3'>
      <div className='flex flex-wrap items-center justify-between gap-2'>
        <p className='text-muted-foreground text-sm'>
          各厂商官方套餐档位（个人版/团队版/坐席/用量包），仅「展示中」档位会出现在公开介绍页
          /coding-plan；价格留空表示以官网为准。预置档位来自官方文档（迁移 2026_09_11_000003）。
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
              <SelectItem value='all'>全部厂商</SelectItem>
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
            <Plus className='mr-1 size-4' /> 新增档位
          </Button>
        </div>
      </div>
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>厂商</TableHead>
              <TableHead>档位</TableHead>
              <TableHead>价格</TableHead>
              <TableHead>额度</TableHead>
              <TableHead>状态</TableHead>
              <TableHead className='text-right'>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {tiersQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={6} className='py-8 text-center'>
                  加载中…
                </TableCell>
              </TableRow>
            ) : tiers.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={6}
                  className='text-muted-foreground py-8 text-center'
                >
                  暂无套餐档位（预置档位随迁移写入，可在官网核对价格后补充）
                </TableCell>
              </TableRow>
            ) : (
              tiers.map((t) => (
                <TableRow key={t.id}>
                  <TableCell className='font-mono text-xs'>
                    {vendorName(t.vendor_code)}
                  </TableCell>
                  <TableCell className='text-xs'>{t.name}</TableCell>
                  <TableCell className='text-xs'>{priceLabel(t)}</TableCell>
                  <TableCell className='text-xs'>{quotaLabel(t)}</TableCell>
                  <TableCell>
                    {Number(t.status) === 1 ? (
                      <Badge>展示中</Badge>
                    ) : (
                      <Badge variant='secondary'>隐藏</Badge>
                    )}
                  </TableCell>
                  <TableCell className='text-right'>
                    <div className='flex justify-end gap-1'>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          setEditing(t)
                          setForm({
                            vendor_code: t.vendor_code,
                            name: t.name,
                            price:
                              t.price === null || t.price === undefined
                                ? ''
                                : String(t.price),
                            price_note: t.price_note ?? '',
                            period: t.period ?? '',
                            quota:
                              t.quota === null || t.quota === undefined
                                ? ''
                                : String(t.quota),
                            quota_unit: t.quota_unit ?? '',
                            quota_note: t.quota_note ?? '',
                            status: String(t.status ?? 1),
                            sort: String(t.sort ?? 0),
                            remark: t.remark ?? '',
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
                          if (window.confirm(`确认删除档位「${t.name}」？`)) {
                            deleteMutation.mutate(t.id)
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
            <DialogTitle>{editing ? '编辑套餐档位' : '新增套餐档位'}</DialogTitle>
          </DialogHeader>
          <div className='flex flex-col gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>厂商</Label>
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
                <Label>档位名称</Label>
                <Input
                  value={form.name}
                  onChange={(e) => set('name', e.target.value)}
                  placeholder='个人版 · Lite / 团队版 · 标准坐席'
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>价格（元）</Label>
                <Input
                  type='number'
                  step='0.01'
                  value={form.price}
                  onChange={(e) => set('price', e.target.value)}
                  placeholder='留空 = 待核对'
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>价格备注</Label>
                <Input
                  value={form.price_note}
                  onChange={(e) => set('price_note', e.target.value)}
                  placeholder='原价 60 元/月'
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>周期</Label>
                <Input
                  value={form.period}
                  onChange={(e) => set('period', e.target.value)}
                  placeholder='月 / 月/座席'
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>额度</Label>
                <Input
                  type='number'
                  value={form.quota}
                  onChange={(e) => set('quota', e.target.value)}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>额度单位</Label>
                <Input
                  value={form.quota_unit}
                  onChange={(e) => set('quota_unit', e.target.value)}
                  placeholder='Credits / 资源点'
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>额度说明</Label>
                <Input
                  value={form.quota_note}
                  onChange={(e) => set('quota_note', e.target.value)}
                  placeholder='每 7 天限额 2,500 Credits'
                />
              </div>
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>状态</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '')}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='1'>展示中</SelectItem>
                    <SelectItem value='0'>隐藏</SelectItem>
                  </SelectContent>
                </Select>
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
              disabled={saveMutation.isPending || !form.vendor_code || !form.name}
            >
              保存
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
