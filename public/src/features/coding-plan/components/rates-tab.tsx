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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

import { destroyRate, getRates, storeRate } from '../api'
import type { CurrencyRate } from '../types'

type RateForm = {
  code: string
  rate: string
  source: string
  remark: string
}

const EMPTY: RateForm = { code: '', rate: '', source: 'manual', remark: '' }

export function RatesTab() {
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<CurrencyRate | null>(null)
  const [form, setForm] = useState<RateForm>(EMPTY)

  const ratesQuery = useQuery({
    queryKey: ['coding-plan-rates'],
    queryFn: getRates,
  })

  const saveMutation = useMutation({
    mutationFn: async () =>
      storeRate({
        code: form.code,
        rate: Number(form.rate),
        source: form.source,
        remark: form.remark,
      }),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '汇率已保存')
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-rates'] })
      } else {
        toast.error(res.message ?? '保存失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (code: string) => destroyRate(code),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? '已删除')
        qc.invalidateQueries({ queryKey: ['coding-plan-rates'] })
      } else {
        toast.error(res.message ?? '删除失败')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const rates = ratesQuery.data?.data?.rates ?? []
  const hints = ratesQuery.data?.data?.hints ?? []
  const set = (key: keyof RateForm, value: string) =>
    setForm((prev) => ({ ...prev, [key]: value }))

  return (
    <div className='flex flex-col gap-3'>
      <div className='flex items-center justify-between'>
        <p className='text-muted-foreground text-sm'>
          1 单位该币种 = rate 人民币；CNY 为基准恒为 1，无需维护。档位未单独设置币种时继承厂商行。
        </p>
        <Button
          size='sm'
          onClick={() => {
            setEditing(null)
            setForm(EMPTY)
            setOpen(true)
          }}
        >
          <Plus className='mr-1 size-4' /> 新增汇率
        </Button>
      </div>
      {hints.length > 0 && (
        <div className='flex flex-wrap gap-2'>
          {hints.map((h) => (
            <Badge key={h.code} variant='outline'>
              {h.code} 生效 {h.effective_rate}
              {h.fallback_option
                ? `（Option ${h.fallback_option} 兜底）`
                : '（基准）'}
            </Badge>
          ))}
        </div>
      )}
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>币种</TableHead>
              <TableHead>汇率（=×人民币）</TableHead>
              <TableHead>生效值</TableHead>
              <TableHead>来源</TableHead>
              <TableHead>更新时间</TableHead>
              <TableHead>备注</TableHead>
              <TableHead className='text-right'>操作</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {ratesQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={7} className='py-8 text-center'>
                  加载中…
                </TableCell>
              </TableRow>
            ) : rates.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className='text-muted-foreground py-8 text-center'
                >
                  暂无维护条目；USD 未维护时自动回落 Option 兜底值
                </TableCell>
              </TableRow>
            ) : (
              rates.map((r) => (
                <TableRow key={r.code}>
                  <TableCell className='font-mono text-xs'>{r.code}</TableCell>
                  <TableCell>×{Number(r.rate)}</TableCell>
                  <TableCell>×{r.effective_rate ?? Number(r.rate)}</TableCell>
                  <TableCell>
                    <Badge variant='outline'>{r.source}</Badge>
                  </TableCell>
                  <TableCell>
                    {dayjs.unix(Number(r.updated_at)).format('YYYY-MM-DD HH:mm')}
                  </TableCell>
                  <TableCell className='text-muted-foreground max-w-[16rem] truncate text-xs'>
                    {r.remark || '-'}
                  </TableCell>
                  <TableCell className='text-right'>
                    <div className='flex justify-end gap-1'>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          setEditing(r)
                          setForm({
                            code: r.code,
                            rate: String(r.rate),
                            source: r.source ?? 'manual',
                            remark: r.remark ?? '',
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
                              `确认删除 ${r.code} 汇率？删除后该币种无表值（USD 仍走 Option 兜底）。`
                            )
                          ) {
                            deleteMutation.mutate(r.code)
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
        <DialogContent className='sm:max-w-sm'>
          <DialogHeader>
            <DialogTitle>
              {editing ? `编辑 ${editing.code} 汇率` : '新增汇率'}
            </DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>币种代码</Label>
                <Input
                  value={form.code}
                  onChange={(e) => set('code', e.target.value.toUpperCase())}
                  placeholder='USD'
                  disabled={!!editing}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>汇率（1 单位 = ×人民币）</Label>
                <Input
                  type='number'
                  step='0.0001'
                  value={form.rate}
                  onChange={(e) => set('rate', e.target.value)}
                  placeholder='7.3'
                />
              </div>
            </div>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>来源</Label>
                <Select
                  value={form.source}
                  onValueChange={(v) => set('source', v ?? 'manual')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='manual'>manual（手工）</SelectItem>
                    <SelectItem value='api'>api（同步）</SelectItem>
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
              disabled={saveMutation.isPending || !form.code || !form.rate}
            >
              保存
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

