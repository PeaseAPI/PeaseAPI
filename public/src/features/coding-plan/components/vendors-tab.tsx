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

type VendorForm = {
  code: string
  name: string
  plan_kind: string
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
  docs_url: '',
  pricing_source_url: '',
  unit_name: '',
  unit_exchange_rate: '1',
  sort: '0',
  status: '1',
  remark: '',
}

export function VendorsTab() {

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
        toast.success(res.message ?? '已保存')
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-vendors'] })
      } else {
        toast.error(res.message ?? '保存失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteVendor(id),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已删除')
        qc.invalidateQueries({ queryKey: ['coding-plan-vendors'] })
      } else {
        toast.error(res.message ?? '删除失败')
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
          供应商定义默认计费单位与汇率；账号未单独设置汇率时使用此处默认值。
        </p>
        <Button
          size='sm'
          onClick={() => {
            setEditing(null)
            setForm(EMPTY)
            setOpen(true)
          }}
        >
          <Plus className='mr-1 size-4' /> 新增供应商
        </Button>
      </div>
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>标识</TableHead>
              <TableHead>名称</TableHead>
              <TableHead>类型</TableHead>
              <TableHead>默认单位</TableHead>
              <TableHead>默认汇率</TableHead>
              <TableHead>账号池</TableHead>
              <TableHead>状态</TableHead>
              <TableHead className='text-right'>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {vendorsQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={8} className='py-8 text-center'>
                  加载中…
                </TableCell>
              </TableRow>
            ) : vendors.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className='text-muted-foreground py-8 text-center'
                >
                  暂无供应商；迁移已预置主流厂商目录（默认停用），可直接「编辑」启用
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
                        ? '按量'
                        : '订阅制'}
                    </Badge>
                  </TableCell>
                  <TableCell>{v.unit_name || '-'}</TableCell>
                  <TableCell>×{Number(v.unit_exchange_rate)}</TableCell>
                  <TableCell>
                    {v.accounts_active ?? 0} / {v.accounts_total ?? 0}
                  </TableCell>
                  <TableCell>
                    {Number(v.status) === 1 ? (
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
                          setEditing(v)
                          setForm({
                            code: v.code,
                            name: v.name,
                            plan_kind: String(v.plan_kind ?? PLAN_KIND_CODING),
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
                        编辑
                      </Button>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          if (
                            window.confirm(
                              `确认删除供应商「${v.name}」？其比率配置将一并删除；${v.accounts_total ?? 0} 个账号（活跃 ${v.accounts_active ?? 0} 个）会保留，需改绑其他供应商后才能继续中转。`
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
            <DialogTitle>{editing ? '编辑供应商' : '新增供应商'}</DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>标识（唯一）</Label>
                <Input
                  value={form.code}
                  onChange={(e) => set('code', e.target.value)}
                  placeholder='anthropic'
                  disabled={!!editing}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>名称</Label>
                <Input
                  value={form.name}
                  onChange={(e) => set('name', e.target.value)}
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>套餐类型</Label>
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
              <Label>定价源 URL（可选，JSON 价格清单，供每 6 小时自动校对比对）</Label>
              <Input
                value={form.pricing_source_url}
                onChange={(e) => set('pricing_source_url', e.target.value)}
                placeholder='https://vendor.example/prices.json'
              />
            </div>
            <div className='grid grid-cols-3 gap-3'>
              <div className='grid gap-1.5'>
                <Label>默认单位</Label>
                <Input
                  value={form.unit_name}
                  onChange={(e) => set('unit_name', e.target.value)}
                  placeholder='次'
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>默认汇率</Label>
                <Input
                  type='number'
                  step='0.0001'
                  value={form.unit_exchange_rate}
                  onChange={(e) => set('unit_exchange_rate', e.target.value)}
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
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>状态</Label>
                <Select value={form.status} onValueChange={(v) => set('status', v ?? '')}>
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
              disabled={saveMutation.isPending || !form.code || !form.name}
            >
              保存
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
