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
import { useQuery } from '@tanstack/react-query'
import { CreditCard, Users, Zap } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

import { getStats } from '../api'
import type { CodingPlanDailyUsage } from '../types'
import { useTranslation } from 'react-i18next'

const pct = (used: number, quota: number) =>
  quota > 0 ? Math.min(100, (used / quota) * 100) : 0

function QuotaBar({
  used,
  quota,
  unit = '',
  decimals = 0,
}: {
  used: number
  quota: number
  unit?: string
  decimals?: number
}) {
  return (
    <div className='flex items-center gap-2'>
      <Progress value={pct(used, quota)} className='h-2' />
      <span className='text-muted-foreground shrink-0 text-xs'>
        {used.toFixed(decimals)}/{quota.toFixed(decimals)}
        {unit ? ` ${unit}` : ''}
      </span>
    </div>
  )
}

export function StatsTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({
    queryKey: ['coding-plan-stats'],
    queryFn: getStats,
  })

  if (isLoading) {
    return (
      <div className='grid gap-3'>
        <Skeleton className='h-24 w-full' />
        <Skeleton className='h-64 w-full' />
      </div>
    )
  }

  const vendors = data?.data?.vendors ?? []
  const daily: CodingPlanDailyUsage[] = Object.values(
    data?.data?.daily_usage_7d ?? {},
  )
  const totals = daily.reduce(
    (acc, d) => ({
      submits: acc.submits + Number(d.submits ?? 0),
      credits: acc.credits + Number(d.credits ?? 0),
    }),
    { submits: 0, credits: 0 },
  )

  return (
    <div className='flex flex-col gap-4'>
      <div className='grid gap-3 sm:grid-cols-3'>
        <Card>
          <CardHeader className='pb-2'>
            <CardTitle className='flex items-center gap-2 text-sm font-medium'>
              <Users className='size-4' /> {t('Total accounts')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className='text-2xl font-semibold'>
              {vendors.reduce((s, v) => s + (v.total ?? 0), 0)}
            </p>
            <p className='text-muted-foreground mt-1 text-xs'>
              {t('Enabled')} {vendors.reduce((s, v) => s + (v.active ?? 0), 0)} · {t('Exhausted')}{' '}
              {vendors.reduce((s, v) => s + (v.exhausted ?? 0), 0)}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className='pb-2'>
            <CardTitle className='flex items-center gap-2 text-sm font-medium'>
              <Zap className='size-4' /> {t('Submits (7d)')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className='text-2xl font-semibold'>{totals.submits}</p>
            <p className='text-muted-foreground mt-1 text-xs'>
              {t('Successful requests')}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className='pb-2'>
            <CardTitle className='flex items-center gap-2 text-sm font-medium'>
              <CreditCard className='size-4' /> {t('Spend (7d)')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className='text-2xl font-semibold'>
              {totals.credits.toFixed(2)}
            </p>
            <p className='text-muted-foreground mt-1 text-xs'>credits</p>
          </CardContent>
        </Card>
      </div>
      <div className='rounded-md border'>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Vendor')}</TableHead>
              <TableHead>{t('Accounts (active/total)')}</TableHead>
              <TableHead>{t('Monthly usage / quota')}</TableHead>
              <TableHead>{t('Credits used / total')}</TableHead>
              <TableHead>{t('Remaining')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {vendors.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={5}
                  className='text-muted-foreground py-8 text-center'
                >
                  {t('No data')}
                </TableCell>
              </TableRow>
            ) : (
              vendors.map((v) => (
                <TableRow key={v.vendor}>
                  <TableCell className='font-mono text-xs'>
                    {v.vendor}
                  </TableCell>
                  <TableCell>
                    {v.active ?? 0} / {v.total ?? 0}
                  </TableCell>
                  <TableCell className='w-48'>
                    <QuotaBar
                      used={Number(v.monthly_used ?? 0)}
                      quota={Number(v.monthly_quota ?? 0)}
                      unit={v.unit_name ?? ''}
                    />
                  </TableCell>
                  <TableCell className='w-48'>
                    <QuotaBar
                      used={Number(v.credits_used ?? 0)}
                      quota={Number(v.credits_quota ?? 0)}
                      decimals={2}
                    />
                  </TableCell>
                  <TableCell>
                    {Number(v.credits_remaining ?? 0) > 0 ? (
                      <Badge variant='secondary'>
                        {Number(v.credits_remaining ?? 0).toFixed(2)}
                      </Badge>
                    ) : (
                      <Badge variant='destructive'>{t('Exhausted')}</Badge>
                    )}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
