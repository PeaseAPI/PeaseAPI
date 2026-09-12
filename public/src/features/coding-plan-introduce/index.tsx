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
import dayjs from 'dayjs'
import { ExternalLink } from 'lucide-react'

import { api } from '@/lib/api'

import { Badge } from '@/components/ui/badge'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { t } from 'i18next'
import { useTranslation } from 'react-i18next'

// ---------------------------------------------------------------------------
// 公开数据结构（GET /api/coding_plan/offers 与 /api/coding_plan/public_promotions）
// ---------------------------------------------------------------------------

type OfferTier = {
  name: string
  price: number | null
  currency: string
  price_cny: number | null
  price_note: string | null
  period: string
  quota: number | null
  quota_unit: string
  quota_note: string | null
}

type OfferRatio = {
  model: string
  match_type: string
  cost_mode: string
  unit_cost: number
  input_rate: number
  cached_rate: number
  output_rate: number
  time_discounts: Array<{
    name: string
    start: string
    end: string
    discount: number
  }> | null
  stale: boolean
  updated_at: number
}

type OfferVendor = {
  code: string
  name: string
  logo: string | null
  billing_mode: number
  plan_kind: number
  unit_name: string
  unit_exchange_rate: number
  currency: string
  docs_url: string | null
  last_checked_at: number | null
  source_status: number
  stale_count: number
  change_count: number
  ratios: OfferRatio[]
  tiers: OfferTier[]
}

type PublicPromotion = {
  id: number
  vendor: string
  kind: string
  title: string
  description: string | null
  discount: number | null
  starts_at: number | null
  ends_at: number | null
  source_url: string | null
  state: string
  remaining_seconds: number | null
  sort: number
}

const KIND_LABEL: Record<string, string> = {
  discount: 'Limited-time offer',
  free: 'Free giveaway',
  price_change: 'Price change',
  model_retirement: 'Model retirement',
}

const COST_MODE_LABEL: Record<string, string> = {
  per_request: 'Per request',
  per_1k_tokens: 'Per 1k tokens',
  per_token_parts: 'Tiered (input/cache/output)',
}

const BILLING_LABEL: Record<number, string> = {
  1: 'Per-request billing',
  2: 'Credits billing',
}

/** Unix 秒 → 本地时间展示；0/空 → '-' */
function fmtUnix(value?: number | null): string {
  if (!value) return '-'
  return dayjs.unix(Number(value)).format('YYYY-MM-DD HH:mm')
}

/** 倒计时徽标文案与样式：72h 内红色、7 天内黄色警示、其余中性 */
function countdownBadge(p: PublicPromotion) {
  if (p.state === 'scheduled') {
    return { text: t('Not started'), variant: 'outline' as const }
  }
  if (p.remaining_seconds === null || p.remaining_seconds === undefined) {
    return { text: t('Long-term / no end date announced'), variant: 'outline' as const }
  }
  const seconds = Number(p.remaining_seconds)
  if (seconds <= 0) {
    return { text: t('Ending soon'), variant: 'destructive' as const }
  }
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const text = days > 0 ? t('{{days}} days {{hours}} hours left', { days, hours }) : t('{{hours}} hours left', { hours })
  if (seconds <= 72 * 3600) {
    return { text, variant: 'destructive' as const }
  }
  if (seconds <= 7 * 86400) {
    return { text, variant: 'secondary' as const }
  }

  return { text, variant: 'outline' as const }
}

/** 官方原币价 + 折算 CNY 双列展示 */

function priceText(tier: OfferTier): { original: string; cny: string } {
  if (tier.price === null || tier.price === undefined) {
    return { original: t('Pending check'), cny: '-' }
  }
  const price = Number(tier.price)
  const symbol = tier.currency === 'CNY' ? '¥' : `${tier.currency} `
  const cny =
    tier.price_cny === null || tier.price_cny === undefined
      ? '-'
      : `≈ ¥${Number(tier.price_cny).toFixed(2)}`

  return { original: `${symbol}${price}`, cny }
}
export function CodingPlanIntroduce() {
  const { t } = useTranslation()
  const offersQuery = useQuery({
    queryKey: ['coding-plan-offers'],
    queryFn: async () => {
      const res = await api.get<{
        data: { vendors: OfferVendor[]; stale_days: number }
      }>('/api/coding_plan/offers')
      return res.data.data
    },
  })
  const promotionsQuery = useQuery({
    queryKey: ['coding-plan-public-promotions'],
    queryFn: async () => {
      const res = await api.get<{
        data: { promotions: PublicPromotion[]; now: number }
      }>('/api/coding_plan/public_promotions')
      return res.data.data
    },
  })

  const vendors = offersQuery.data?.vendors ?? []
  const promotions = promotionsQuery.data?.promotions ?? []
  const subscriptionVendors = vendors.filter((v) => v.plan_kind === 1)
  const usageVendors = vendors.filter((v) => v.plan_kind === 2)

  return (
    <div className='bg-background min-h-screen'>
      <div className='mx-auto flex max-w-6xl flex-col gap-8 px-4 py-10'>
        {/* 页头 */}
        <header className='flex flex-col gap-2'>
          <h1 className='text-3xl font-semibold'>
            {t('Coding Plan vendor tiers and official promotions')}
          </h1>
          <p className='text-muted-foreground max-w-3xl text-sm'>
            {t('Aggregates official Coding Plan tiers from every vendor (original-currency and CNY-converted prices), model conversion ratios and latest check status; official limited-time offers, price changes and model retirements sync automatically with countdown reminders. Check data refreshes every')}
            {t('6 hours')}
            {t('and the page is cached for about 5 minutes.')}
          </p>
        </header>

        {/* 活动公告（P4-1 倒计时徽标 + P2 上下架公告位） */}
        {promotions.length > 0 ? (
          <section className='flex flex-col gap-3'>
            <h2 className='text-xl font-semibold'>{t('Official promotions')}</h2>
            <div className='grid gap-3 md:grid-cols-2 lg:grid-cols-3'>
              {promotions.map((p) => {
                const badge = countdownBadge(p)
                return (
                  <Card key={p.id} className='gap-3'>
                    <CardHeader className='pb-0'>
                      <div className='flex flex-wrap items-center gap-1.5'>
                        <Badge variant='outline' className='font-mono text-xs'>
                          {p.vendor}
                        </Badge>
                        <Badge>{t(KIND_LABEL[p.kind] ?? p.kind)}</Badge>
                        <Badge variant={badge.variant}>{badge.text}</Badge>
                      </div>
                      <CardTitle className='text-base'>{p.title}</CardTitle>
                    </CardHeader>
                    <CardContent className='flex flex-1 flex-col gap-2 text-sm'>
                      {p.description ? (
                        <p className='text-muted-foreground line-clamp-3 whitespace-pre-line'>
                          {p.description}
                        </p>
                      ) : null}
                      <div className='text-muted-foreground mt-auto flex flex-wrap items-center justify-between gap-2 text-xs'>
                        <span>
                          {p.ends_at
                            ? t('Ends at {{time}}', { time: fmtUnix(p.ends_at) })
                            : t('No end date announced')}
                        </span>
                        {p.source_url ? (
                          <a
                            href={p.source_url}
                            target='_blank'
                            rel='noreferrer'
                            className='text-primary inline-flex items-center gap-1 hover:underline'
                          >
                            {t('Official announcement')} <ExternalLink className='size-3' />
                          </a>
                        ) : null}
                      </div>
                    </CardContent>
                  </Card>
                )
              })}
            </div>
          </section>
        ) : null}

        {/* 厂商卡片（订阅制 / 按量两组） */}
        {[
          { title: t('Subscription Coding Plan'), list: subscriptionVendors },
          { title: t('Pay-as-you-go Token Plan'), list: usageVendors },
        ].map((group) =>
          group.list.length > 0 ? (
            <section key={group.title} className='flex flex-col gap-3'>
              <h2 className='text-xl font-semibold'>{group.title}</h2>
              <div className='flex flex-col gap-4'>
                {group.list.map((v) => (
                  <VendorCard key={v.code} vendor={v} />
                ))}
              </div>
            </section>
          ) : null
        )}

        {/* 加载 / 空态 */}
        {offersQuery.isLoading ? (
          <p className='text-muted-foreground text-sm'>{t('Loading data…')}</p>
        ) : vendors.length === 0 ? (
          <p className='text-muted-foreground text-sm'>
            {t('No enabled vendors yet.')}
          </p>
        ) : null}

        <footer className='text-muted-foreground border-t pt-4 text-xs'>
          {t('Official tier and ratio data is checked automatically every 6 hours (changes are applied after manual confirmation); promotions and countdowns come from official announcements, deadlines are in Beijing time.')}
        </footer>
      </div>
    </div>
  )
}

/** 单厂商卡片：核对状态 + 套餐档位（原币/折算双列）+ 模型抵扣比率 */
function VendorCard({ vendor }: { vendor: OfferVendor }) {
  const { t } = useTranslation()
  const checkBadge = () => {
    if (vendor.source_status === 1) {
      return <Badge>{t('In sync')}</Badge>
    }
    if (vendor.source_status === 2) {
      return <Badge variant='destructive'>{t('Source error')}</Badge>
    }

    return <Badge variant='outline'>{t('Not checked')}</Badge>
  }

  return (
    <Card>
      <CardHeader>
        <div className='flex flex-wrap items-center gap-2'>
          <CardTitle className='text-lg'>
            {vendor.name}
            <span className='text-muted-foreground ml-2 font-mono text-xs font-normal'>
              {vendor.code}
            </span>
          </CardTitle>
          <Badge variant='outline'>
            {t(BILLING_LABEL[vendor.billing_mode] ?? 'Unknown billing')}
          </Badge>
          {vendor.currency !== 'CNY' ? (
            <Badge variant='secondary'>{t('Official pricing {{currency}}', { currency: vendor.currency })}</Badge>
          ) : null}
          {vendor.stale_count > 0 ? (
            <Badge variant='secondary'>{t('{{count}} entries overdue for re-check', { count: vendor.stale_count })}</Badge>
          ) : null}
          {vendor.change_count > 0 ? (
            <Badge variant='secondary'>{t('{{count}} entries pending confirmation', { count: vendor.change_count })}</Badge>
          ) : null}
          {vendor.docs_url ? (
            <a
              href={vendor.docs_url}
              target='_blank'
              rel='noreferrer'
              className='text-primary inline-flex items-center gap-1 text-sm hover:underline'
            >
              {t('Official docs')} <ExternalLink className='size-3' />
            </a>
          ) : null}
        </div>
        <p className='text-muted-foreground text-xs'>
          {t('Last checked:')}
          {vendor.last_checked_at ? fmtUnix(vendor.last_checked_at) : t('Not checked yet')}
          <span className='ml-2 inline-flex items-center gap-1 align-middle'>
            {checkBadge()}
          </span>
        </p>
      </CardHeader>
      <CardContent className='flex flex-col gap-4'>

        {/* 套餐档位：官方原币价 + 折算 CNY 双列 */}
        {vendor.tiers.length > 0 ? (
          <div className='rounded-md border'>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('Tier')}</TableHead>
                  <TableHead>{t('Official price')}</TableHead>
                  <TableHead>{t('CNY converted')}</TableHead>
                  <TableHead>{t('Period')}</TableHead>
                  <TableHead>{t('Quota')}</TableHead>
                  <TableHead>{t('Remark')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {vendor.tiers.map((t) => {
                  const price = priceText(t)
                  return (
                    <TableRow key={t.name}>
                      <TableCell className='font-medium'>{t.name}</TableCell>
                      <TableCell>
                        {price.original}
                        {t.price !== null &&
                        t.price !== undefined &&
                        t.currency !== 'CNY' ? (
                          <span className='text-muted-foreground ml-1 text-xs'>
                            /{t.currency}
                          </span>
                        ) : null}
                      </TableCell>
                      <TableCell className='text-muted-foreground'>
                        {price.cny}
                      </TableCell>
                      <TableCell className='text-xs'>{t.period}</TableCell>
                      <TableCell className='text-xs'>
                        {t.quota === null || t.quota === undefined
                          ? '—'
                          : `${Number(t.quota)} ${t.quota_unit}`}
                      </TableCell>
                      <TableCell className='text-muted-foreground max-w-[16rem] truncate text-xs'>
                        {t.price_note ?? t.quota_note ?? '—'}
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </div>
        ) : null}

        {/* 模型抵扣比率 */}
        {vendor.ratios.length > 0 ? (
          <div className='max-h-96 overflow-auto rounded-md border'>
            <Table>
              <TableHeader className='bg-background sticky top-0'>
                <TableRow>
                  <TableHead>{t('Model')}</TableHead>
                  <TableHead>{t('Billing')}</TableHead>
                  <TableHead>{t('Unit cost')}</TableHead>
                  <TableHead>{t('Input/1k')}</TableHead>
                  <TableHead>{t('Cache/1k')}</TableHead>
                  <TableHead>{t('Output/1k')}</TableHead>
                  <TableHead>{t('Time discounts')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {vendor.ratios.map((r) => (
                  <TableRow key={`${r.model}-${r.match_type}`}>
                    <TableCell className='font-mono text-xs'>
                      {r.model}
                      {r.stale ? (
                        <Badge variant='outline' className='ml-1.5 text-[10px]'>
                          {t('Stale')}
                        </Badge>
                      ) : null}
                    </TableCell>
                    <TableCell className='text-xs'>
                      {t(COST_MODE_LABEL[r.cost_mode] ?? r.cost_mode)}
                    </TableCell>
                    <TableCell className='text-xs'>{r.unit_cost}</TableCell>
                    <TableCell className='text-xs'>
                      {r.input_rate || '—'}
                    </TableCell>
                    <TableCell className='text-xs'>
                      {r.cached_rate || '—'}
                    </TableCell>
                    <TableCell className='text-xs'>
                      {r.output_rate || '—'}
                    </TableCell>
                    <TableCell className='text-muted-foreground text-xs'>
                      {(r.time_discounts ?? [])
                        .map((w) => `${w.start}-${w.end} ×${w.discount}`)
                        .join('；') || '—'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        ) : null}
      </CardContent>
    </Card>
  )
}

