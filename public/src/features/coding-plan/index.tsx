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
import { useState } from 'react'

import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'

import { AccountsTab } from './components/accounts-tab'
import { ModelsTab } from './components/models-tab'
import { PlansTab } from './components/plans-tab'
import { PromotionsTab } from './components/promotions-tab'
import { RatesTab } from './components/rates-tab'
import { RatiosTab } from './components/ratios-tab'
import { StatsTab } from './components/stats-tab'
import { SubscriptionGateCard } from './components/subscription-gate-card'
import { SyncTab } from './components/sync-tab'
import { TiersTab } from './components/tiers-tab'
import { VendorsTab } from './components/vendors-tab'

const TABS = [
  { value: 'accounts', label: '账号池', node: <AccountsTab /> },
  { value: 'plans', label: '套餐绑定', node: <PlansTab /> },
  { value: 'vendors', label: '供应商', node: <VendorsTab /> },
  { value: 'tiers', label: '套餐档位', node: <TiersTab /> },
  { value: 'rates', label: '汇率', node: <RatesTab /> },
  { value: 'promotions', label: '厂商活动', node: <PromotionsTab /> },
  { value: 'models', label: '模型上架', node: <ModelsTab /> },
  { value: 'ratios', label: '折算比率', node: <RatiosTab /> },
  { value: 'sync', label: '官方同步', node: <SyncTab /> },
  { value: 'stats', label: '用量统计', node: <StatsTab /> },
]

export function CodingPlan() {
  const [tab, setTab] = useState('accounts')

  return (
    <div className='flex flex-col gap-4 p-4 md:p-6'>
      <div className='flex flex-wrap items-start justify-between gap-4'>
        <div>
          <h1 className='text-xl font-semibold'>Coding Plan 积分管理</h1>
          <p className='text-muted-foreground text-sm'>
            管理订阅账号池、供应商与用量折算规则
          </p>
        </div>
        <SubscriptionGateCard />
      </div>
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          {TABS.map((t) => (
            <TabsTrigger key={t.value} value={t.value}>
              {t.label}
            </TabsTrigger>
          ))}
        </TabsList>
        {TABS.map((t) => (
          <TabsContent key={t.value} value={t.value} className='mt-3'>
            {t.node}
          </TabsContent>
        ))}
      </Tabs>
    </div>
  )
}
