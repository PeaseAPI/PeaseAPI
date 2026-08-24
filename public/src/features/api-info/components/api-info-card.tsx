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
import { ExternalLink } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { useApiInfo } from '@/features/dashboard/hooks/use-status-data'
import type { ApiInfoItem } from '@/features/dashboard/types'
import { getBgColorClass } from '@/lib/colors'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { IconBadge } from '@/components/ui/icon-badge'
import { Badge } from '@/components/ui/badge'
import { Globe } from 'lucide-react'

export function ApiInfoCard() {
  const { t } = useTranslation()
  const { items, loading } = useApiInfo()

  if (loading) {
    return (
      <Card data-card-hover='false' className='gap-0 overflow-hidden py-0'>
        <CardHeader className='border-b p-3 !pb-3 sm:p-5 sm:!pb-5'>
          <div className='flex items-center gap-3'>
            <IconBadge tone='info' size='title'>
              <Globe />
            </IconBadge>
            <div className='min-w-0'>
              <CardTitle className='text-lg tracking-tight sm:text-xl'>
                {t('API Info')}
              </CardTitle>
              <CardDescription className='text-xs sm:text-sm'>
                {t('API endpoint shortcuts configured by the administrator')}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className='p-3 sm:p-5'>
          <div className='flex items-center justify-center py-8'>
            <div className='text-muted-foreground text-sm'>
              {t('Loading...')}
            </div>
          </div>
        </CardContent>
      </Card>
    )
  }

  if (items.length === 0) {
    return (
      <Card data-card-hover='false' className='gap-0 overflow-hidden py-0'>
        <CardHeader className='border-b p-3 !pb-3 sm:p-5 sm:!pb-5'>
          <div className='flex items-center gap-3'>
            <IconBadge tone='info' size='title'>
              <Globe />
            </IconBadge>
            <div className='min-w-0'>
              <CardTitle className='text-lg tracking-tight sm:text-xl'>
                {t('API Info')}
              </CardTitle>
              <CardDescription className='text-xs sm:text-sm'>
                {t('API endpoint shortcuts configured by the administrator')}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className='p-3 sm:p-5'>
          <div className='flex items-center justify-center py-8'>
            <div className='text-muted-foreground text-sm'>
              {t('No API info configured yet')}
            </div>
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-card-hover='false' className='gap-0 overflow-hidden py-0'>
      <CardHeader className='border-b p-3 !pb-3 sm:p-5 sm:!pb-5'>
        <div className='flex items-center gap-3'>
          <IconBadge tone='info' size='title'>
            <Globe />
          </IconBadge>
          <div className='min-w-0'>
            <CardTitle className='text-lg tracking-tight sm:text-xl'>
              {t('API Info')}
            </CardTitle>
            <CardDescription className='text-xs sm:text-sm'>
              {t('API endpoint shortcuts configured by the administrator')}
            </CardDescription>
          </div>
        </div>
      </CardHeader>
      <CardContent className='p-3 sm:p-5'>
        <div className='grid gap-3 sm:grid-cols-2'>
          {items.map((item: ApiInfoItem) => (
            <a
              key={item.url + item.route}
              href={item.url}
              target='_blank'
              rel='noopener noreferrer'
              className='bg-background/60 hover:bg-muted/50 flex items-center gap-3 rounded-lg border p-3 transition-colors'
            >
              <Badge
                variant='outline'
                className={`shrink-0 ${getBgColorClass(item.color)} text-white border-0`}
              >
                {item.route}
              </Badge>
              <div className='min-w-0 flex-1'>
                <p className='truncate text-sm font-medium'>
                  {item.description}
                </p>
                <p className='text-muted-foreground truncate text-xs'>
                  {item.url}
                </p>
              </div>
              <ExternalLink className='text-muted-foreground h-4 w-4 shrink-0' />
            </a>
          ))}
        </div>
      </CardContent>
    </Card>
  )
}
