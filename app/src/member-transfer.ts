import { getRequestToken } from '@nextcloud/auth'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from './api.ts'

interface ArchiveMemberRequest {
  sourceId: string
  member: string
  expectedEtag: string
  password: string
  signal: AbortSignal
}

interface ViewerFile {
  source: string
  filename: string
  basename: string
  fileid: number | string
  mime: string
  etag: string
  hasPreview: boolean
  permissions: string
}

interface Viewer {
  mimetypes: string[]
  open: (options: { fileInfo: ViewerFile; list: ViewerFile[]; enableSidebar: boolean }) => void
}

function viewer(): Viewer | undefined {
  return (window as Window & { OCA?: { Viewer?: Viewer } }).OCA?.Viewer
}

export function canPreviewMember(mime?: string): boolean {
  const nextcloudViewer = viewer()
  return !!mime && typeof nextcloudViewer?.open === 'function'
    && (nextcloudViewer.mimetypes?.includes(mime) || nextcloudViewer.mimetypes?.includes(mime.split('/')[0]))
}

export async function previewArchiveMember(request: ArchiveMemberRequest, mime: string, fileId: number | string): Promise<void> {
  const nextcloudViewer = viewer()
  if (!nextcloudViewer || !canPreviewMember(mime)) {
    throw new Error(t('archive_tools', 'This file type cannot be previewed.'))
  }
  const { sourceId, member, expectedEtag, password, signal } = request
  const { ticket } = await api<{ ticket: string }>('/archives/' + encodeURIComponent(sourceId) + '/preview', {
    method: 'POST', body: JSON.stringify({ member, expectedEtag, password }), signal,
  })
  signal.throwIfAborted()
  const source = new URL(generateUrl('/apps/archive_tools/preview/' + encodeURIComponent(ticket)), window.location.href).href
  const fileInfo: ViewerFile = {
    source, filename: source, basename: member.split('/').pop()!, fileid: fileId,
    mime, etag: expectedEtag, hasPreview: false, permissions: 'R',
  }
  nextcloudViewer.open({ fileInfo, list: [fileInfo], enableSidebar: false })
}

/** The browser owns the attachment. A short-lived cookie acknowledges streaming headers. */
export function downloadArchiveMember({ sourceId, member, expectedEtag, password, signal }: ArchiveMemberRequest): Promise<void> {
  return new Promise((resolve, reject) => {
    signal.throwIfAborted()
    const downloadId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('')
    const cookieName = 'archive_tools_download_' + downloadId
    const sink = document.createElement('iframe')
    sink.name = cookieName
    sink.hidden = true
    sink.tabIndex = -1
    sink.setAttribute('aria-hidden', 'true')
    let accepted = false
    let poll: ReturnType<typeof setInterval>
    let expiry: ReturnType<typeof setTimeout>
    const cleanup = () => {
      clearInterval(poll)
      clearTimeout(expiry)
      signal.removeEventListener('abort', abort)
      sink.remove()
    }
    const abort = () => {
      cleanup()
      if (!accepted) reject(signal.reason)
    }
    const fail = (message: string) => {
      cleanup()
      reject(new Error(message))
    }
    const genericError = t('archive_tools', 'The operation failed. Try again.')
    sink.addEventListener('load', () => {
      if (accepted) return
      try {
        if (sink.contentWindow?.location.href === 'about:blank') return
        const body = sink.contentDocument?.body?.textContent ?? ''
        const response = JSON.parse(body)
        fail(typeof response.message === 'string' ? response.message : genericError)
      } catch {
        fail(genericError)
      }
    })
    signal.addEventListener('abort', abort, { once: true })
    poll = setInterval(() => {
      if (!document.cookie.split(';').some(cookie => cookie.trim() === cookieName + '=1')) return
      document.cookie = cookieName + '=; Path=/; Max-Age=0; SameSite=Lax'
      accepted = true
      clearInterval(poll)
      resolve()
    }, 200)
    expiry = setTimeout(() => {
      if (accepted) cleanup()
      else fail(t('archive_tools', 'Preparing the download took too long. Try again.'))
    }, 300000)
    document.body.append(sink)
    const form = document.createElement('form')
    form.method = 'POST'
    form.action = generateUrl('/apps/archive_tools/archives/' + encodeURIComponent(sourceId) + '/download')
    form.target = sink.name
    form.hidden = true
    for (const [name, value] of Object.entries({ member, expectedEtag, password, downloadId, requesttoken: getRequestToken() || '' })) {
      const input = document.createElement('input')
      input.type = 'hidden'
      input.name = name
      input.value = value
      form.append(input)
    }
    document.body.append(form)
    try {
      form.submit()
    } catch {
      fail(genericError)
    } finally {
      form.remove()
    }
  })
}
