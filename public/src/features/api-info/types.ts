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

// ============================================================================
// API Info Page Type Definitions
// ============================================================================

/**
 * News API key fields returned masked by the backend
 */
export interface NewsKeysMasked {
  news_google_key: string
  news_newsapi_key: string
  news_tavily_key: string
  news_exa_key: string
  news_brave_key: string
}

/**
 * Request body for updating news API keys
 */
export interface UpdateNewsKeysRequest {
  news_google_key?: string
  news_newsapi_key?: string
  news_tavily_key?: string
  news_exa_key?: string
  news_brave_key?: string
}

/**
 * Response from PUT /api/user/news-keys
 */
export interface UpdateNewsKeysResponse {
  success: boolean
  message: string
  news_keys_masked: NewsKeysMasked
}

/**
 * Metadata for a news API key field
 */
export interface NewsKeyFieldDef {
  key: keyof NewsKeysMasked
  label: string
  description: string
  placeholder: string
}
