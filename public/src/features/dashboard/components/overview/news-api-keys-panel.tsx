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
import { Link } from '@tanstack/react-router'
import { ArrowRight, Key } from 'lucide-react'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { IconBadge } from '@/components/ui/icon-badge'

import { useNewsKeys } from '@/features/api-info/hooks/use-news-api-keys'
import type { NewsKeysMasked } from '@/features/api-info/types'

import { PanelWrapper } from '../ui/panel-wrapper'

type KeyStatus = {
  key: keyof NewsKeysMasked
  label: string
  configured: boolean
}

function getKeyStatuses(masked: NewsKeysMasked | undefined, t: (key: string) => string): KeyStatus[] {
  const keys: { key: keyof NewsKeysMasked; label: string }[] = [
    { key: 'news_google_key', label: t('Google') },
    { key: 'news_newsapi_key', label: t('NewsAPI') },
    { key: 'news_tavily_key', label: t('Tavily') },
    { key: 'news_exa_key', label: t('Exa') },
  ]
  return keys.map(({ key, label }) => ({
    key,
    label,
    configured: Boolean(masked?.[key] && masked[key].length > 0 && !masked[key].includes('•')),
  }))
}

export function NewsApiKeysPanel() {
  const { t } = useTranslation()
  const { data, isLoading } = useNewsKeys()

  const keyStatuses = useMemo(
    () => getKeyStatuses(data?.news_keys_masked, t),
    [data?.news_keys_masked, t]
  )

  const configuredCount = keyStatuses.filter((k) => k.configured).length

  return (
    <PanelWrapper
      title={
        <span className='flex items-center gap-2'>
          <IconBadge tone='warning' size='sm'>
            <Key />
          </IconBadge>
          {t('News Search API Keys')}
        </span>
      }
      description={t('Your news search provider API key status')}
      loading={isLoading}
      empty={false}
      headerActions={
        <Link
          to='/api-info'
          className='text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs font-medium transition-colors'
        >
          {t('Configure')}
          <ArrowRight className='size-3' />
        </Link>
      }
    >
      <div className='flex flex-col gap-2.5'>
        {keyStatuses.map((item) => (
          <div
            key={item.key}
            className='flex items-center justify-between gap-2'
          >
            <span className='text-sm font-medium'>{item.label}</span>
            <span
              className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${
                item.configured
                  ? 'bg-success/10 text-success'
                  : 'bg-muted text-muted-foreground'
              }`}
            >
              <span
                className={`size-1.5 rounded-full ${
                  item.configured ? 'bg-success' : 'bg-muted-foreground/40'
                }`}
              />
              {item.configured ? t('Configured') : t('Not set')}
            </span>
          </div>
        ))}
        <div className='border-t pt-2.5'>
          <div className='text-muted-foreground text-xs'>
            {t('{{configured}} of {{total}} keys configured', {
              configured: configuredCount,
              total: keyStatuses.length,
            })}
          </div>
        </div>
      </div>
    </PanelWrapper>
  )
}
