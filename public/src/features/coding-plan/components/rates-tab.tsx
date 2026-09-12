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
import { useTranslation } from 'react-i18next'

type RateForm = {
  code: string
  rate: string
  source: string
  remark: string
}

const EMPTY: RateForm = { code: '', rate: '', source: 'manual', remark: '' }

export function RatesTab() {
  const { t } = useTranslation()
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
        toast.success(res.message ?? t('Exchange rate saved'))
        setOpen(false)
        qc.invalidateQueries({ queryKey: ['coding-plan-rates'] })
      } else {
        toast.error(res.message ?? t('Failed to save'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })

  const deleteMutation = useMutation({
    mutationFn: (code: string) => destroyRate(code),
    onSuccess: (res) => {
      if (res.success) {
        toast.success(res.message ?? t('Deleted'))
        qc.invalidateQueries({ queryKey: ['coding-plan-rates'] })
      } else {
        toast.error(res.message ?? t('Failed to delete'))
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
          {t('1 unit of the currency = rate in CNY; CNY is the base and always 1, no maintenance needed. Tiers inherit the vendor currency unless set individually.')}
        </p>
        <Button
          size='sm'
          onClick={() => {
            setEditing(null)
            setForm(EMPTY)
            setOpen(true)
          }}
        >
          <Plus className='mr-1 size-4' /> {t('Add exchange rate')}
        </Button>
      </div>
      {hints.length > 0 && (
        <div className='flex flex-wrap gap-2'>
          {hints.map((h) => (
            <Badge key={h.code} variant='outline'>
              {t('{{code}} effective {{rate}}', { code: h.code, rate: h.effective_rate })}
              {h.fallback_option
                ? t(' (Option {{option}} fallback)', { option: h.fallback_option })
                : t('(base)')}
            </Badge>
          ))}
        </div>
      )}
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Currency')}</TableHead>
              <TableHead>{t('Rate (=×CNY)')}</TableHead>
              <TableHead>{t('Effective')}</TableHead>
              <TableHead>{t('Source')}</TableHead>
              <TableHead>{t('Updated at')}</TableHead>
              <TableHead>{t('Remark')}</TableHead>
              <TableHead className='text-right'>{t('Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {ratesQuery.isLoading ? (
              <TableRow>
                <TableCell colSpan={7} className='py-8 text-center'>
                  {t('Loading…')}
                </TableCell>
              </TableRow>
            ) : rates.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className='text-muted-foreground py-8 text-center'
                >
                  {t('No manual entries; USD falls back to the Option value automatically when unset')}
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
                        {t('Edit')}
                      </Button>
                      <Button
                        variant='ghost'
                        size='sm'
                        onClick={() => {
                          if (
                            window.confirm(
                              t('Delete {{code}} exchange rate? The currency will have no table value (USD still falls back to the Option).', { code: r.code })
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
              {editing ? t('Edit {{code}} exchange rate', { code: editing.code }) : t('Add exchange rate')}
            </DialogTitle>
          </DialogHeader>
          <div className='grid gap-3'>
            <div className='grid grid-cols-2 gap-3'>
              <div className='grid gap-1.5'>
                <Label>{t('Currency code')}</Label>
                <Input
                  value={form.code}
                  onChange={(e) => set('code', e.target.value.toUpperCase())}
                  placeholder='USD'
                  disabled={!!editing}
                />
              </div>
              <div className='grid gap-1.5'>
                <Label>{t('Rate (1 unit = ×CNY)')}</Label>
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
                <Label>{t('Source')}</Label>
                <Select
                  value={form.source}
                  onValueChange={(v) => set('source', v ?? 'manual')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value='manual'>{t('manual (manual)')}</SelectItem>
                    <SelectItem value='api'>{t('api (sync)')}</SelectItem>
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
              disabled={saveMutation.isPending || !form.code || !form.rate}
            >
              {t('Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

