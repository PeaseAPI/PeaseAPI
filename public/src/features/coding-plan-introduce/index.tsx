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
  discount: '限时优惠',
  free: '免费活动',
  price_change: '价格调整',
  model_retirement: '模型退市',
}

const COST_MODE_LABEL: Record<string, string> = {
  per_request: '按次',
  per_1k_tokens: '千 Token',
  per_token_parts: '分段 Token',
}

const BILLING_LABEL: Record<number, string> = {
  1: '按次计费',
  2: '积分计费',
}

/** Unix 秒 → 本地时间展示；0/空 → '-' */
function fmtUnix(value?: number | null): string {
  if (!value) return '-'
  return dayjs.unix(Number(value)).format('YYYY-MM-DD HH:mm')
}

/** 倒计时徽标文案与样式：72h 内红色、7 天内黄色警示、其余中性 */
function countdownBadge(p: PublicPromotion) {
  if (p.state === 'scheduled') {
    return { text: '未开始', variant: 'outline' as const }
  }
  if (p.remaining_seconds === null || p.remaining_seconds === undefined) {
    return { text: '长期 / 未公布截止', variant: 'outline' as const }
  }
  const seconds = Number(p.remaining_seconds)
  if (seconds <= 0) {
    return { text: '即将截止', variant: 'destructive' as const }
  }
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const text = days > 0 ? `剩 ${days} 天 ${hours} 小时` : `剩 ${hours} 小时`
  if (seconds <= 72 * 3600) {
    return { text, variant: 'destructive' as const }
  }
  if (seconds <= 7 * 86400) {
    return { text, variant: 'secondary' as const }
  }

  return { text, variant: 'outline' as const }
}

/** 官方原币价 + 折算 CNY 双列展示 */
function priceText(t: OfferTier): { original: string; cny: string } {
  if (t.price === null || t.price === undefined) {
    return { original: '待核对', cny: '-' }
  }
  const price = Number(t.price)
  const symbol = t.currency === 'CNY' ? '¥' : `${t.currency} `
  const cny =
    t.price_cny === null || t.price_cny === undefined
      ? '-'
      : `≈ ¥${Number(t.price_cny).toFixed(2)}`

  return { original: `${symbol}${price}`, cny }
}
export function CodingPlanIntroduce() {
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
            Coding Plan 厂商套餐与官方活动
          </h1>
          <p className='text-muted-foreground max-w-3xl text-sm'>
            汇总各厂商官方 Coding Plan
            套餐档位（官方原币价与折算人民币价）、模型抵扣比率与最新核对状态；官方限时优惠、价格调整与模型退市活动自动同步并倒计时提醒。核对数据每
            6 小时刷新一次，本页缓存约 5 分钟。
          </p>
        </header>

        {/* 活动公告（P4-1 倒计时徽标 + P2 上下架公告位） */}
        {promotions.length > 0 ? (
          <section className='flex flex-col gap-3'>
            <h2 className='text-xl font-semibold'>官方活动</h2>
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
                        <Badge>{KIND_LABEL[p.kind] ?? p.kind}</Badge>
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
                            ? `截止 ${fmtUnix(p.ends_at)}`
                            : '官方未公布截止'}
                        </span>
                        {p.source_url ? (
                          <a
                            href={p.source_url}
                            target='_blank'
                            rel='noreferrer'
                            className='text-primary inline-flex items-center gap-1 hover:underline'
                          >
                            官方公告 <ExternalLink className='size-3' />
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
          { title: '订阅制 Coding Plan', list: subscriptionVendors },
          { title: '按量 Token Plan', list: usageVendors },
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
          <p className='text-muted-foreground text-sm'>数据加载中…</p>
        ) : vendors.length === 0 ? (
          <p className='text-muted-foreground text-sm'>
            暂无启用中的厂商数据。
          </p>
        ) : null}

        <footer className='text-muted-foreground border-t pt-4 text-xs'>
          官方套餐与比率数据由系统每 6 小时自动核对（人工确认后应用变更）；活动与倒计时来自官方公告，截止时间为北京时间。
        </footer>
      </div>
    </div>
  )
}

/** 单厂商卡片：核对状态 + 套餐档位（原币/折算双列）+ 模型抵扣比率 */
function VendorCard({ vendor }: { vendor: OfferVendor }) {
  const checkBadge = () => {
    if (vendor.source_status === 1) {
      return <Badge>核对一致</Badge>
    }
    if (vendor.source_status === 2) {
      return <Badge variant='destructive'>源异常</Badge>
    }

    return <Badge variant='outline'>未核对</Badge>
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
            {BILLING_LABEL[vendor.billing_mode] ?? '未知计费'}
          </Badge>
          {vendor.currency !== 'CNY' ? (
            <Badge variant='secondary'>官方计价 {vendor.currency}</Badge>
          ) : null}
          {vendor.stale_count > 0 ? (
            <Badge variant='secondary'>{vendor.stale_count} 条超期未复核</Badge>
          ) : null}
          {vendor.change_count > 0 ? (
            <Badge variant='secondary'>{vendor.change_count} 条待确认变更</Badge>
          ) : null}
          {vendor.docs_url ? (
            <a
              href={vendor.docs_url}
              target='_blank'
              rel='noreferrer'
              className='text-primary inline-flex items-center gap-1 text-sm hover:underline'
            >
              官方文档 <ExternalLink className='size-3' />
            </a>
          ) : null}
        </div>
        <p className='text-muted-foreground text-xs'>
          最近核对：
          {vendor.last_checked_at ? fmtUnix(vendor.last_checked_at) : '尚未核对'}
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
                  <TableHead>档位</TableHead>
                  <TableHead>官方价</TableHead>
                  <TableHead>折算人民币</TableHead>
                  <TableHead>周期</TableHead>
                  <TableHead>额度</TableHead>
                  <TableHead>备注</TableHead>
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
                  <TableHead>模型</TableHead>
                  <TableHead>计费</TableHead>
                  <TableHead>单位成本</TableHead>
                  <TableHead>输入/1k</TableHead>
                  <TableHead>缓存/1k</TableHead>
                  <TableHead>输出/1k</TableHead>
                  <TableHead>时段折扣</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {vendor.ratios.map((r) => (
                  <TableRow key={`${r.model}-${r.match_type}`}>
                    <TableCell className='font-mono text-xs'>
                      {r.model}
                      {r.stale ? (
                        <Badge variant='outline' className='ml-1.5 text-[10px]'>
                          超期
                        </Badge>
                      ) : null}
                    </TableCell>
                    <TableCell className='text-xs'>
                      {COST_MODE_LABEL[r.cost_mode] ?? r.cost_mode}
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

