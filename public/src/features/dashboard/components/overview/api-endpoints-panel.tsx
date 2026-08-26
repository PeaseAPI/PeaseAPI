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
import { Check, Copy, Globe } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { IconBadge } from '@/components/ui/icon-badge'
import { useCopyToClipboard } from '@/hooks/use-copy-to-clipboard'
import { useStatus } from '@/hooks/use-status'

import { PanelWrapper } from '../ui/panel-wrapper'

type ApiProtocolEndpoint = {
  key: string
  label: string
  endpoint: string
  description: string
  protocol: string
}

export function ApiEndpointsPanel() {
  const { t } = useTranslation()
  const { status } = useStatus()
  const { copyToClipboard } = useCopyToClipboard({ notify: false })
  const [copiedKey, setCopiedKey] = useState<string | null>(null)

  const endpoints = (status?.api_protocol_endpoints ?? []) as ApiProtocolEndpoint[]
  const hasEndpoints = endpoints.length > 0 && endpoints.some((e) => e.endpoint)

  const handleCopy = async (text: string, key: string) => {
    await copyToClipboard(text)
    setCopiedKey(key)
    setTimeout(() => setCopiedKey(null), 2000)
  }

  return (
    <PanelWrapper
      title={
        <span className='flex items-center gap-2'>
          <IconBadge tone='info' size='sm'>
            <Globe />
          </IconBadge>
          {t('API Endpoints')}
        </span>
      }
      description={t('Protocol addresses for API access')}
      loading={false}
      empty={!hasEndpoints}
      emptyMessage={t('API endpoints not configured (set Server Address in System Settings)')}
      height='h-auto'
    >
      <div className='flex flex-col gap-2.5'>
        {endpoints
          .filter((e) => e.endpoint)
          .map((item) => (
            <div
              key={item.key}
              className='flex items-center gap-3 rounded-md border p-2.5'
            >
              <div className='flex flex-1 flex-col gap-0.5 min-w-0'>
                <div className='flex items-center gap-2'>
                  <span className='text-xs font-semibold uppercase tracking-wide text-muted-foreground'>
                    {item.protocol}
                  </span>
                  <span className='text-sm font-medium'>{item.label}</span>
                </div>
                <p className='text-muted-foreground truncate text-xs'>
                  {item.description}
                </p>
                <code className='bg-muted mt-1 rounded px-2 py-1 text-xs font-mono break-all'>
                  {item.endpoint}
                </code>
              </div>
              <button
                type='button'
                onClick={() => handleCopy(item.endpoint, item.key)}
                className='text-muted-foreground hover:text-foreground shrink-0 rounded-md p-1.5 transition-colors hover:bg-muted'
                title={t('Copy endpoint')}
              >
                {copiedKey === item.key ? (
                  <Check className='h-4 w-4 text-green-500' />
                ) : (
                  <Copy className='h-4 w-4' />
                )}
              </button>
            </div>
          ))}
      </div>
    </PanelWrapper>
  )
}
