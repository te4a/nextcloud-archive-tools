import { createApp } from 'vue'
import ArchiveDialog from './ArchiveDialog.vue'
import type { ArchiveInfo, Job, Mount } from './types.ts'

interface ArchiveDialogBase {
  sourceId: string
  password?: string
  error?: string
  signal?: AbortSignal
}

export type ArchiveDialogOptions = ArchiveDialogBase & (
  | { kind: 'password'; validatePassword: (password: string, signal: AbortSignal) => Promise<void>; info?: ArchiveInfo; members?: never }
  | { kind: 'extract'; info: ArchiveInfo; members?: string[] }
  | { kind: 'connect'; info: ArchiveInfo; members?: never }
  | { kind: 'connect'; info?: never; sourceName: string; expectedEtag?: string; members?: never }
)

export type ArchiveDialogResult =
  | { kind: 'password'; password: string }
  | { kind: 'extract'; job: Job }
  | { kind: 'connect'; mount: Mount }
  | null

/** Mount in the caller's dialog host and remove the target after dismissal. */
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
