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
import { Switch } from '@/components/ui/switch'
import { useSystemOptions } from '@/features/system-settings/hooks/use-system-options'
import { useUpdateOption } from '@/features/system-settings/hooks/use-update-option'

/**
 * Coding Plan 强制订阅校验开关（OptionService: CodingPlanRequireSubscription）。
 * 开启后 RelayInfo::resolveCodingPlanSubscription 会拒绝未持有对应厂商套餐的用户。
 */
export function SubscriptionGateCard() {
  const { data, isLoading } = useSystemOptions()
  const updateOption = useUpdateOption()

  const enabled = data?.data?.CodingPlanRequireSubscription === true

  return (
    <div className='flex items-center justify-between gap-4 rounded-md border p-4 md:w-96'>
      <div className='min-w-0'>
        <p className='text-sm font-medium'>强制订阅校验</p>
        <p className='text-muted-foreground text-xs'>
          开启后，未持有对应厂商套餐的用户调用 Coding Plan 中转将被拒绝
        </p>
      </div>
      <Switch
        checked={enabled}
        disabled={isLoading || updateOption.isPending}
        onCheckedChange={(value) =>
          updateOption.mutate({
            key: 'CodingPlanRequireSubscription',
            value,
          })
        }
      />
    </div>
  )
}
