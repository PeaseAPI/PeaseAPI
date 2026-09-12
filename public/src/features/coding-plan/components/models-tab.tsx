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
import { PackageCheck } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
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
  applyCatalogChanges,
  batchUpdateModelStatus,
  getVendorModels,
  getVendors,
  ignoreCheckChange,
} from '../api'
import type { CodingPlanVendorModelRow, OfficialModelState } from '../types'

const OFFICIAL_BADGE: Record<
  OfficialModelState,
  { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }
> = {
  in_catalog: { label: '官方在列', variant: 'secondary' },
  missing: { label: '官方未列', variant: 'destructive' },
  new: { label: '官方新增', variant: 'default' },
  unknown: { label: '目录未知', variant: 'outline' },
}

function costText(row: CodingPlanVendorModelRow): string {
  if (row.cost_mode === 'per_token_parts') {
    return `分段 ${row.input_rate}/${row.cached_rate}/${row.output_rate}`
  }
  if (row.cost_mode === 'per_1k_tokens') return `千token ${row.unit_cost}`
  if (row.cost_mode === 'per_request') return `按次 ${row.unit_cost}`

  return '—'
}

export function ModelsTab() {
  const qc = useQueryClient()
  const [vendor, setVendor] = useState('')
  const [selected, setSelected] = useState<number[]>([])

  const vendorsQuery = useQuery({
    queryKey: ['coding-plan-vendors'],
    queryFn: getVendors,
  })
  const vendors = vendorsQuery.data?.data ?? []

  const modelsQuery = useQuery({
    queryKey: ['coding-plan-vendor-models', vendor],
    queryFn: () => getVendorModels(vendor),
    enabled: vendor !== '',
  })
  const models = modelsQuery.data?.data?.models ?? []
  const summary = modelsQuery.data?.data?.summary
  const selectable = models.filter((m) => m.id !== null)
  const allSelected =
    selectable.length > 0 && selected.length === selectable.length

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['coding-plan-vendor-models', vendor] })
    qc.invalidateQueries({ queryKey: ['coding-plan-ratios'] })
  }

  const batchMutation = useMutation({
    mutationFn: ({ status }: { status: 0 | 1 }) =>
      batchUpdateModelStatus(vendor, selected, status),
    onSuccess: (res) => {
      toast.success(res.message ?? '操作成功')
      setSelected([])
      invalidate()
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const applyMutation = useMutation({
    mutationFn: (input: { action: 'new' | 'missing'; models: string[] }) =>
      applyCatalogChanges({ vendor, ...input }),
    onSuccess: (res) => {
      toast.success(res.message ?? '已应用')
      invalidate()
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const ignoreMutation = useMutation({
    mutationFn: (key: string) => ignoreCheckChange(vendor, key, false),
    onSuccess: (res) => {
      toast.success(res.message ?? '已忽略该变更提醒')
      invalidate()
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const busy =
    batchMutation.isPending ||
    applyMutation.isPending ||
    ignoreMutation.isPending ||
    modelsQuery.isFetching

  const toggleOne = (id: number) => {
    setSelected((prev) =>
      prev.includes(id) ? prev.filter((v) => v !== id) : [...prev, id]
    )
  }
  const toggleAll = () => {
    setSelected(allSelected ? [] : selectable.map((m) => m.id as number))
  }

  return (
    <div className='flex flex-col gap-4'>
      <Card>
        <CardHeader>
          <CardTitle className='flex items-center gap-2'>
            <PackageCheck className='h-4 w-4' />
            模型上架流
          </CardTitle>
          <CardDescription>
            选择厂商查看模型清单（库内比率 × 官方目录快照），勾选批量启用/停用；官方目录新增/下架可一键应用或忽略
          </CardDescription>
        </CardHeader>
        <CardContent className='flex flex-col gap-3'>
          <div className='flex flex-wrap items-center gap-2'>
            <Select
              value={vendor}
              onValueChange={(v) => {
                setVendor(v ?? '')
                setSelected([])
              }}
            >
              <SelectTrigger className='w-64'>
                <SelectValue placeholder='选择厂商' />
              </SelectTrigger>
              <SelectContent>
                {vendors.map((v) => (
                  <SelectItem key={v.code} value={v.code}>
                    {v.name}（{v.code}）
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {summary && (
              <div className='flex flex-wrap items-center gap-1.5 text-xs'>
                <Badge variant='secondary'>启用 {summary.enabled}</Badge>
                <Badge variant='outline'>停用 {summary.disabled}</Badge>
                <Badge variant='default'>官方新增 {summary.official_new}</Badge>
                <Badge variant='destructive'>
                  官方未列 {summary.official_missing}
                </Badge>
                <span className='text-muted-foreground'>
                  目录 {summary.catalog_total ?? '—'} 个
                  {summary.catalog_fetched_at
                    ? `，快照 ${new Date(summary.catalog_fetched_at * 1000).toLocaleString()}`
                    : '，无目录快照'}
                </span>
              </div>
            )}
          </div>
          {vendor !== '' && selected.length > 0 && (
            <div className='flex items-center gap-2 rounded-md border px-3 py-2'>
              <span className='text-sm'>已选 {selected.length} 个模型</span>
              <Button
                size='sm'
                disabled={busy}
                onClick={() => batchMutation.mutate({ status: 1 })}
              >
                批量启用
              </Button>
              <Button
                size='sm'
                variant='outline'
                disabled={busy}
                onClick={() => batchMutation.mutate({ status: 0 })}
              >
                批量停用
              </Button>
              <Button
                size='sm'
                variant='ghost'
                disabled={busy}
                onClick={() => setSelected([])}
              >
                取消选择
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      {vendor !== '' && (
        <Card>
          <CardContent className='pt-6'>
            {models.length === 0 ? (
              <div className='text-muted-foreground text-sm'>
                该厂商暂无模型清单。
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className='w-10'>
                      <Checkbox
                        checked={allSelected}
                        onCheckedChange={toggleAll}
                        aria-label='全选'
                      />
                    </TableHead>
                    <TableHead>模型</TableHead>
                    <TableHead>匹配</TableHead>
                    <TableHead>口径 / 系数</TableHead>
                    <TableHead>状态</TableHead>
                    <TableHead>官方目录</TableHead>
                    <TableHead className='text-right'>操作</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {models.map((row) => (
                    <ModelRowItem
                      key={`${row.model}-${row.match_type}-${row.id ?? 'new'}`}
                      row={row}
                      selected={selected.includes(row.id as number)}
                      busy={busy}
                      onToggle={toggleOne}
                      onApply={applyMutation.mutate}
                      onIgnore={ignoreMutation.mutate}
                    />
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}

function ModelRowItem(props: {
  row: CodingPlanVendorModelRow
  selected: boolean
  busy: boolean
  onToggle: (id: number) => void
  onApply: (input: { action: 'new' | 'missing'; models: string[] }) => void
  onIgnore: (key: string) => void
}) {
  const { row, selected, busy, onToggle, onApply, onIgnore } = props
  const badge = OFFICIAL_BADGE[row.official]
  const changeKey =
    row.catalog_change?.key ?? `model_catalog|${row.model.toLowerCase()}|`
  const highlight =
    (row.official === 'new' || row.official === 'missing') &&
    !row.change_ignored

  return (
    <TableRow
      className={highlight ? 'bg-amber-50/60 dark:bg-amber-950/20' : undefined}
    >
      <TableCell>
        {row.id !== null ? (
          <Checkbox
            checked={selected}
            onCheckedChange={() => onToggle(row.id as number)}
            aria-label={`选择 ${row.model}`}
          />
        ) : null}
      </TableCell>
      <TableCell className='font-medium'>
        {row.model}
        {row.stale && (
          <Badge variant='outline' className='ml-1.5'>
            待复核
          </Badge>
        )}
      </TableCell>
      <TableCell>{row.match_type === 'prefix' ? '前缀' : '精确'}</TableCell>
      <TableCell>{costText(row)}</TableCell>
      <TableCell>
        {row.status === null ? (
          <Badge variant='outline'>未落地</Badge>
        ) : row.status === 1 ? (
          <Badge variant='secondary'>已提供</Badge>
        ) : (
          <Badge variant='outline'>已停用</Badge>
        )}
      </TableCell>
      <TableCell>
        <div className='flex flex-wrap items-center gap-1'>
          <Badge variant={badge.variant}>{badge.label}</Badge>
          {row.change_ignored && <Badge variant='outline'>已忽略</Badge>}
        </div>
      </TableCell>
      <TableCell className='text-right'>
        {row.id === null ? (
          <div className='flex justify-end gap-1'>
            <Button
              size='sm'
              variant='outline'
              disabled={busy || row.change_ignored}
              onClick={() => onApply({ action: 'new', models: [row.model] })}
            >
              落地（停用态）
            </Button>
            <Button
              size='sm'
              variant='ghost'
              disabled={busy || row.change_ignored}
              onClick={() => onIgnore(changeKey)}
            >
              忽略
            </Button>
          </div>
        ) : row.official === 'missing' && !row.change_ignored ? (
          <div className='flex justify-end gap-1'>
            <Button
              size='sm'
              variant='outline'
              disabled={busy}
              onClick={() =>
                onApply({ action: 'missing', models: [row.model] })
              }
            >
              下架（停用）
            </Button>
            <Button
              size='sm'
              variant='ghost'
              disabled={busy}
              onClick={() => onIgnore(changeKey)}
            >
              忽略
            </Button>
          </div>
        ) : null}
      </TableCell>
    </TableRow>
  )
}
