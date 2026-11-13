import { createApp } from 'vue'
import ArchiveDialog from './ArchiveDialog.vue'
import type { ArchiveInfo, Job, Mount } from './types.ts'

export interface ArchiveDialogOptions {
  kind: 'password' | 'extract' | 'connect'
  sourceId: string
  info?: ArchiveInfo
  password?: string
  members?: string[]
  error?: string
  signal?: AbortSignal
  validatePassword: (password: string, signal: AbortSignal) => Promise<void>
}

export type ArchiveDialogResult =
  | { kind: 'password'; password: string }
  | { kind: 'extract'; job: Job }
  | { kind: 'connect'; mount: Mount }
  | null

/** Mount only in the element supplied by the public Files header API. */
export function showArchiveDialog(container: HTMLElement, options: ArchiveDialogOptions): Promise<ArchiveDialogResult> {
  if (options.signal?.aborted) return Promise.resolve(null)
  return new Promise(resolve => {
    const target = document.createElement('div')
    container.appendChild(target)
    let settled = false
    const finish = (result: ArchiveDialogResult): void => {
      if (settled) return
      settled = true
      options.signal?.removeEventListener('abort', abort)
      // Unmount after the component event handler finishes using its refs.
      queueMicrotask(() => { app.unmount(); target.remove() })
      resolve(result)
    }
    const abort = (): void => finish(null)
    const app = createApp(ArchiveDialog, { options, onComplete: finish })
    options.signal?.addEventListener('abort', abort, { once: true })
    app.mount(target)
    if (options.signal?.aborted) finish(null)
  })
}
