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
import { Eye, EyeOff, Key, Loader2, Save } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { IconBadge } from '@/components/ui/icon-badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'

import { useNewsKeys, useUpdateNewsKeys } from '../hooks/use-news-api-keys'
import type { NewsKeyFieldDef, UpdateNewsKeysRequest } from '../types'

function useNewsKeyFields(): NewsKeyFieldDef[] {
  const { t } = useTranslation()
  return [
    {
      key: 'news_google_key',
      label: t('Google News Key'),
      description: t('API key for Google News search provider'),
      placeholder: 'AIza...',
    },
    {
      key: 'news_newsapi_key',
      label: t('NewsAPI Key'),
      description: t('API key for NewsAPI.org search provider'),
      placeholder: 'abc123...',
    },
    {
      key: 'news_tavily_key',
      label: t('Tavily Key'),
      description: t('API key for Tavily search provider'),
      placeholder: 'tvly-...',
    },
    {
      key: 'news_exa_key',
      label: t('Exa Key'),
      description: t('API key for Exa search provider'),
      placeholder: 'exa-...',
    },
  ]
}

export function NewsApiKeysCard() {
  const { t } = useTranslation()
  const { data, isLoading } = useNewsKeys()
  const updateMutation = useUpdateNewsKeys()
  const fields = useNewsKeyFields()

  const [visibleKeys, setVisibleKeys] = useState<Set<string>>(new Set())

  const toggleVisibility = (key: string) => {
    setVisibleKeys((prev) => {
      const next = new Set(prev)
      if (next.has(key)) {
        next.delete(key)
      } else {
        next.add(key)
      }
      return next
    })
  }

  const maskedKeys = data?.news_keys_masked

  const form = useForm<UpdateNewsKeysRequest>({
    values: maskedKeys
      ? {
          news_google_key: maskedKeys.news_google_key ?? '',
          news_newsapi_key: maskedKeys.news_newsapi_key ?? '',
          news_tavily_key: maskedKeys.news_tavily_key ?? '',
          news_exa_key: maskedKeys.news_exa_key ?? '',
        }
      : undefined,
  })

  const onSubmit = (values: UpdateNewsKeysRequest) => {
    updateMutation.mutate(values)
  }

  return (
    <Card data-card-hover='false' className='gap-0 overflow-hidden py-0'>
      <CardHeader className='border-b p-3 !pb-3 sm:p-5 sm:!pb-5'>
        <div className='flex items-center gap-3'>
          <IconBadge tone='warning' size='title'>
            <Key />
          </IconBadge>
          <div className='min-w-0'>
            <CardTitle className='text-lg tracking-tight sm:text-xl'>
              {t('News Search API Keys')}
            </CardTitle>
            <CardDescription className='text-xs sm:text-sm'>
              {t('Configure your own API keys for news search providers')}
            </CardDescription>
          </div>
        </div>
      </CardHeader>
      <CardContent className='p-3 sm:p-5'>
        {isLoading ? (
          <div className='flex items-center justify-center py-8'>
            <Loader2 className='text-muted-foreground h-6 w-6 animate-spin' />
          </div>
        ) : (
          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className='space-y-4'>
              {fields.map((field) => {
                const isVisible = visibleKeys.has(field.key)
                const currentValue = form.watch(field.key) ?? ''
                const isMasked = currentValue.includes('•')

                return (
                  <FormField
                    key={field.key}
                    control={form.control}
                    name={field.key}
                    render={({ field: formField }) => (
                      <FormItem>
                        <FormLabel>{field.label}</FormLabel>
                        <div className='flex gap-2'>
                          <div className='relative flex-1'>
                            <FormControl>
                              <Input
                                type={isVisible ? 'text' : 'password'}
                                placeholder={field.placeholder}
                                {...formField}
                                onFocus={() => {
                                  if (isMasked) {
                                    formField.onChange('')
                                  }
                                }}
                              />
                            </FormControl>
                          </div>
                          <Button
                            type='button'
                            variant='ghost'
                            size='icon'
                            onClick={() => toggleVisibility(field.key)}
                            className='shrink-0'
                          >
                            {isVisible ? (
                              <EyeOff className='h-4 w-4' />
                            ) : (
                              <Eye className='h-4 w-4' />
                            )}
                          </Button>
                        </div>
                        <FormDescription>{field.description}</FormDescription>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )
              })}

              <div className='flex justify-end pt-2'>
                <Button type='submit' disabled={updateMutation.isPending}>
                  {updateMutation.isPending ? (
                    <>
                      <Loader2 className='h-4 w-4 animate-spin' />
                      {t('Saving...')}
                    </>
                  ) : (
                    <>
                      <Save />
                      {t('Save Changes')}
                    </>
                  )}
                </Button>
              </div>
            </form>
          </Form>
        )}
      </CardContent>
    </Card>
  )
}
