import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

export class ApiError extends Error {
  constructor(message: string, public readonly status: number, public readonly code?: string) { super(message) }
}

export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  let response: Response
  try {
    response = await fetch(generateUrl('/apps/archive_tools' + path), {
      credentials: 'same-origin',
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        requesttoken: getRequestToken() ?? '',
        ...options.headers,
      },
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new ApiError(t('archive_tools', 'The server could not be reached. Check your connection and try again.'), 0)
  }
  const result = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new ApiError(result.message || t('archive_tools', 'The operation failed. Try again.'), response.status,
      typeof result.code === 'string' ? result.code : undefined)
  }
  return result as T
}

export function isAborted(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : t('archive_tools', 'The operation failed. Try again.')
}
