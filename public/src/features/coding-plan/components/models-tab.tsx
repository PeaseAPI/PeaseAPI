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
import { t } from 'i18next'
import { useTranslation } from 'react-i18next'

const OFFICIAL_BADGE: Record<
  OfficialModelState,
  { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }
> = {
  in_catalog: { label: 'In official catalog', variant: 'secondary' },
  missing: { label: 'Missing from catalog', variant: 'destructive' },
  new: { label: 'New in catalog', variant: 'default' },
  unknown: { label: 'Catalog unknown', variant: 'outline' },
}

function costText(row: CodingPlanVendorModelRow): string {
  if (row.cost_mode === 'per_token_parts') {
    return t('tiered {{input}}/{{cached}}/{{output}}', { input: row.input_rate, cached: row.cached_rate, output: row.output_rate })
  }
  if (row.cost_mode === 'per_1k_tokens') return t('per 1k tokens {{cost}}', { cost: row.unit_cost })
  if (row.cost_mode === 'per_request') return t('per request {{cost}}', { cost: row.unit_cost })

  return '—'
}

export function ModelsTab() {
  const { t } = useTranslation()
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
      toast.success(res.message ?? t('Operation successful'))
      setSelected([])
      invalidate()
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const applyMutation = useMutation({
    mutationFn: (input: { action: 'new' | 'missing'; models: string[] }) =>
      applyCatalogChanges({ vendor, ...input }),
    onSuccess: (res) => {
      toast.success(res.message ?? t('Applied'))
      invalidate()
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const ignoreMutation = useMutation({
    mutationFn: (key: string) => ignoreCheckChange(vendor, key, false),
    onSuccess: (res) => {
      toast.success(res.message ?? t('Change reminder ignored'))
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
            {t('Model listing flow')}
          </CardTitle>
          <CardDescription>
            {t('Select a vendor to view its model list (library ratios × official catalog snapshot); tick to batch enable/disable; official additions/removals can be applied or ignored in one click')}
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
                <SelectValue placeholder={t('Select a vendor')} />
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
                <Badge variant='secondary'>{t('{{count}} enabled', { count: summary.enabled })}</Badge>
                <Badge variant='outline'>{t('{{count}} disabled', { count: summary.disabled })}</Badge>
                <Badge variant='default'>{t('{{count}} new in catalog', { count: summary.official_new })}</Badge>
                <Badge variant='destructive'>
                  {t('{{count}} missing from catalog', { count: summary.official_missing })}
                </Badge>
                <span className='text-muted-foreground'>
                  {t('Catalog {{count}} items', { count: summary.catalog_total ?? '—' })}
                  {summary.catalog_fetched_at
                    ? t(', snapshot {{time}}', { time: new Date(summary.catalog_fetched_at * 1000).toLocaleString() })
                    : t(', no catalog snapshot')}
                </span>
              </div>
            )}
          </div>
          {vendor !== '' && selected.length > 0 && (
            <div className='flex items-center gap-2 rounded-md border px-3 py-2'>
              <span className='text-sm'>{t('{{count}} models selected', { count: selected.length })}</span>
              <Button
                size='sm'
                disabled={busy}
                onClick={() => batchMutation.mutate({ status: 1 })}
              >
                {t('Enable selected')}
              </Button>
              <Button
                size='sm'
                variant='outline'
                disabled={busy}
                onClick={() => batchMutation.mutate({ status: 0 })}
              >
                {t('Disable selected')}
              </Button>
              <Button
                size='sm'
                variant='ghost'
                disabled={busy}
                onClick={() => setSelected([])}
              >
                {t('Clear selection')}
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
                {t('No model list for this vendor.')}
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className='w-10'>
                      <Checkbox
                        checked={allSelected}
                        onCheckedChange={toggleAll}
                        aria-label={t('Select all')}
                      />
                    </TableHead>
                    <TableHead>{t('Model')}</TableHead>
                    <TableHead>{t('Match')}</TableHead>
                    <TableHead>{t('Mode / factor')}</TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead>{t('Official catalog')}</TableHead>
                    <TableHead className='text-right'>{t('Actions')}</TableHead>
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
  const { t } = useTranslation()
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
            aria-label={t('Select {{model}}', { model: row.model })}
          />
        ) : null}
      </TableCell>
      <TableCell className='font-medium'>
        {row.model}
        {row.stale && (
          <Badge variant='outline' className='ml-1.5'>
            {t('Pending re-check')}
          </Badge>
        )}
      </TableCell>
      <TableCell>{row.match_type === 'prefix' ? t('Prefix') : t('Exact')}</TableCell>
      <TableCell>{costText(row)}</TableCell>
      <TableCell>
        {row.status === null ? (
          <Badge variant='outline'>{t('Not created')}</Badge>
        ) : row.status === 1 ? (
          <Badge variant='secondary'>{t('Offered')}</Badge>
        ) : (
          <Badge variant='outline'>{t('Disabled')}</Badge>
        )}
      </TableCell>
      <TableCell>
        <div className='flex flex-wrap items-center gap-1'>
          <Badge variant={badge.variant}>{t(badge.label)}</Badge>
          {row.change_ignored && <Badge variant='outline'>{t('Ignored')}</Badge>}
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
              {t('Create (disabled)')}
            </Button>
            <Button
              size='sm'
              variant='ghost'
              disabled={busy || row.change_ignored}
              onClick={() => onIgnore(changeKey)}
            >
              {t('Ignore')}
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
              {t('Delist (disable)')}
            </Button>
            <Button
              size='sm'
              variant='ghost'
              disabled={busy}
              onClick={() => onIgnore(changeKey)}
            >
              {t('Ignore')}
            </Button>
          </div>
        ) : null}
      </TableCell>
    </TableRow>
  )
}
