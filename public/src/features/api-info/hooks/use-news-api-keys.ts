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
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import i18next from 'i18next'
import { toast } from 'sonner'

import { getNewsKeys, updateNewsKeys } from '../api'
import type { UpdateNewsKeysRequest } from '../types'

/**
 * Query hook for fetching the current user's news API keys (masked).
 */
export function useNewsKeys() {
  return useQuery({
    queryKey: ['news-keys'],
    queryFn: getNewsKeys,
    staleTime: 5 * 60 * 1000,
  })
}

/**
 * Mutation hook for updating the current user's news API keys.
 */
export function useUpdateNewsKeys() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: UpdateNewsKeysRequest) => updateNewsKeys(data),
    onSuccess: (data) => {
      if (data.success) {
        queryClient.invalidateQueries({ queryKey: ['news-keys'] })
        toast.success(i18next.t('News API keys updated'))
      } else {
        toast.error(data.message || i18next.t('Failed to update news API keys'))
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || i18next.t('Failed to update news API keys'))
    },
  })
}
