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
import { useTranslation } from 'react-i18next'

const TABS = [
  { value: 'accounts', label: 'Account pools', node: <AccountsTab /> },
  { value: 'plans', label: 'Plan binding', node: <PlansTab /> },
  { value: 'vendors', label: 'Vendors', node: <VendorsTab /> },
  { value: 'tiers', label: 'Plan tiers', node: <TiersTab /> },
  { value: 'rates', label: 'Exchange rates', node: <RatesTab /> },
  { value: 'promotions', label: 'Vendor promotions', node: <PromotionsTab /> },
  { value: 'models', label: 'Model listing', node: <ModelsTab /> },
  { value: 'ratios', label: 'Conversion ratios', node: <RatiosTab /> },
  { value: 'sync', label: 'Official sync', node: <SyncTab /> },
  { value: 'stats', label: 'Usage stats', node: <StatsTab /> },
]

export function CodingPlan() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('accounts')

  return (
    <div className='flex flex-col gap-4 p-4 md:p-6'>
      <div className='flex flex-wrap items-start justify-between gap-4'>
        <div>
          <h1 className='text-xl font-semibold'>{t('Coding Plan Credits Management')}</h1>
          <p className='text-muted-foreground text-sm'>
            {t('Manage subscription account pools, vendors and usage conversion rules')}
          </p>
        </div>
        <SubscriptionGateCard />
      </div>
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab.value} value={tab.value}>
              {t(tab.label)}
            </TabsTrigger>
          ))}
        </TabsList>
        {TABS.map((tab) => (
          <TabsContent key={tab.value} value={tab.value} className='mt-3'>
            {tab.node}
          </TabsContent>
        ))}
      </Tabs>
    </div>
  )
}
