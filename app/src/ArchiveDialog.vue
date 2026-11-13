<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { Permission } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { api, errorMessage, isAborted } from './api.ts'
import { compactSelection } from './selection.ts'
import { archiveBaseName } from './archive-formats.ts'
import type { ArchiveDialogOptions, ArchiveDialogResult } from './archive-dialogs.ts'
import type { Job, Mount, Settings } from './types.ts'

const props = defineProps<{ options: ArchiveDialogOptions }>()
const emit = defineEmits<{ complete: [result: ArchiveDialogResult] }>()
const tr = (message: string, values?: Record<string, string | number>) => t('archive_tools', message, values)
const settings = loadState<Settings>('archive_tools', 'settings', { openByClick: true, idleTimeoutMinutes: 60, allowPin: true, jobsRetentionDays: 30 })
const modal = ref<HTMLDialogElement | null>(null)
const passwordInput = ref<HTMLInputElement | null>(null)
const cancelButton = ref<HTMLButtonElement | null>(null)
const password = ref(props.options.password ?? '')
const error = ref(props.options.error ?? '')
const busy = ref(false)
const choosingDestination = ref(false)
const destination = ref<{ id: string; name: string } | null>(null)
const baseName = archiveBaseName(props.options.info?.sourceName ?? '')
const folderName = ref(tr(props.options.kind === 'connect' ? '{name} (archive)' : '{name} (extracted)', { name: baseName }))
const pinned = ref(false)
const selected = compactSelection(props.options.members ?? [])
const request = new AbortController()
let disposed = false

function cancel(event?: Event): void {
  event?.preventDefault()
  if (busy.value || choosingDestination.value) return
  emit('complete', null)
}

async function pickDestination(): Promise<void> {
  choosingDestination.value = true
  modal.value?.close()
  try {
    const nodes = await getFilePickerBuilder(tr('Choose destination folder'))
      .setMultiSelect(false).allowDirectories(true)
      .setMimeTypeFilter(['httpd/unix-directory'])
      .setCanPick(node => node.type !== 'file' && (node.permissions & Permission.CREATE) !== 0)
      .addButton({ label: tr('Choose folder'), variant: 'primary', callback: () => {} })
      .build().pickNodes()
    if (!disposed && nodes[0]?.id) destination.value = { id: nodes[0].id, name: nodes[0].path }
  } catch (caught) {
    if (!disposed && !(caught instanceof FilePickerClosed)) error.value = errorMessage(caught)
  } finally {
    choosingDestination.value = false
    await nextTick()
    if (!disposed && modal.value && !modal.value.open) modal.value.showModal()
  }
}

async function submit(): Promise<void> {
  if (busy.value || choosingDestination.value) return
  if (props.options.kind === 'password') {
    busy.value = true
    error.value = ''
    try {
      await props.options.validatePassword(password.value, request.signal)
      if (!disposed) emit('complete', { kind: 'password', password: password.value })
    } catch (caught) {
      if (!disposed && !isAborted(caught)) error.value = errorMessage(caught)
    } finally { busy.value = false }
    return
  }
  const info = props.options.info
  if (!info) return
  busy.value = true
  error.value = ''
  try {
    if (props.options.kind === 'extract') {
      const job = await api<Job>('/archives/' + encodeURIComponent(props.options.sourceId) + '/extract', {
        method: 'POST', signal: request.signal,
        body: JSON.stringify({
          destinationId: destination.value?.id ?? null,
          folderName: folderName.value,
          members: selected,
          password: password.value || null,
          expectedEtag: info.sourceEtag,
        }),
      })
      if (!disposed) emit('complete', { kind: 'extract', job })
    } else {
      const mount = await api<Mount>('/mounts', {
        method: 'POST', signal: request.signal,
        body: JSON.stringify({ sourceId: props.options.sourceId, name: folderName.value,
          password: password.value || null, pinned: pinned.value, expectedEtag: info.sourceEtag }),
      })
      if (!disposed) emit('complete', { kind: 'connect', mount })
    }
  } catch (caught) {
    if (!disposed && !isAborted(caught)) error.value = errorMessage(caught)
  } finally { busy.value = false }
}

onMounted(async () => {
  modal.value?.showModal()
  await nextTick()
  if (props.options.kind === 'password') passwordInput.value?.focus()
  else cancelButton.value?.focus()
})

onBeforeUnmount(() => {
  disposed = true
  request.abort()
  password.value = ''
  modal.value?.close()
})
</script>

<template>
  <dialog ref="modal" class="at-files-dialog" :aria-label="options.kind === 'password' ? tr('Archive password') : options.kind === 'connect' ? tr('Connect as folder') : tr('Extract archive')" :aria-busy="busy" @cancel="cancel">
    <form @submit.prevent="submit">
      <h2>{{ options.kind === 'password' ? tr('Archive password') : options.kind === 'connect' ? tr('Connect as folder') : selected.length ? tr('Extract selected files') : tr('Extract archive') }}</h2>
      <template v-if="options.kind === 'password'">
        <p>{{ tr('The password is used for this archive and is never placed in its URL.') }}</p>
        <label for="at-files-password">{{ tr('Password') }}</label>
        <input id="at-files-password" ref="passwordInput" v-model="password" type="password" autocomplete="off" required :disabled="busy">
      </template>
      <template v-else>
        <p v-if="options.kind === 'connect'">{{ tr('The connection is created beside the archive. Everyone who can read this folder and archive can browse the connected contents.') }}</p>
        <p v-else>{{ selected.length ? tr('Selected files and folders will be extracted.') : tr('All archive contents will be extracted into a new folder.') }}</p>
        <template v-if="options.kind === 'extract'">
          <label>{{ tr('Destination') }}</label>
          <div class="at-files-destination"><span>{{ destination?.name || tr('Same folder as the archive') }}</span><button type="button" :disabled="busy" @click="pickDestination">{{ tr('Choose folder') }}</button></div>
        </template>
        <label for="at-files-folder-name">{{ tr('New folder name') }}</label>
        <input id="at-files-folder-name" v-model="folderName" type="text" required maxlength="200" :disabled="busy">
        <p v-if="options.kind === 'extract'" class="at-files-hint">{{ tr('Existing files are not overwritten. A conflicting destination is reported as an error.') }}</p>
        <label v-if="options.kind === 'connect' && settings.allowPin" class="at-files-checkbox"><input v-model="pinned" type="checkbox" :disabled="busy">{{ tr('Keep connected until manually disconnected') }}</label>
        <template v-if="options.info?.encrypted">
          <label for="at-files-password">{{ tr('Archive password') }}</label>
          <input id="at-files-password" ref="passwordInput" v-model="password" type="password" autocomplete="off" required :disabled="busy">
          <p v-if="options.kind === 'connect'" class="at-files-hint">{{ tr('Connecting this protected archive unlocks its contents for eligible folder participants. The password itself is not shared.') }}</p>
        </template>
      </template>
      <p v-if="error" class="at-files-error" role="alert">{{ error }}</p>
      <div class="at-files-dialog-actions">
        <button ref="cancelButton" type="button" :disabled="busy || choosingDestination" @click="cancel">{{ tr('Cancel') }}</button>
        <button type="submit" class="primary" :disabled="busy || choosingDestination">
          {{ options.kind === 'password' ? tr('Unlock archive') : options.kind === 'connect' ? (busy ? tr('Connecting…') : tr('Connect archive')) : (busy ? tr('Scheduling…') : tr('Start extraction')) }}
        </button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
.at-files-dialog { color: var(--color-main-text); background: var(--color-main-background); border: 1px solid var(--color-border, #ddd); border-radius: var(--border-radius-large, 12px); box-shadow: 0 12px 50px #0004; padding: 28px; width: min(520px, calc(100vw - 32px)); max-height: calc(100vh - 48px); overflow-y: auto; }
.at-files-dialog::backdrop { background: #0007; }
.at-files-dialog h2 { margin: 0 0 16px; font-size: 22px; }
.at-files-dialog p { line-height: 1.5; margin: 12px 0; }
.at-files-dialog label:not(.at-files-checkbox) { display: block; margin-top: 16px; margin-bottom: 6px; font-weight: 600; }
.at-files-dialog input[type="text"], .at-files-dialog input[type="password"] { width: 100%; box-sizing: border-box; }
.at-files-checkbox { display: flex; align-items: start; gap: 10px; margin-top: 18px; line-height: 1.4; }
.at-files-checkbox input { flex-shrink: 0; }
.at-files-hint { color: var(--color-text-maxcontrast); font-size: 13px; }
.at-files-error { color: var(--color-main-text); border-inline-start: 3px solid var(--color-error); padding-inline-start: 12px; }
.at-files-destination { display: flex; align-items: center; gap: 16px; justify-content: space-between; }
.at-files-destination span { overflow-wrap: anywhere; }
.at-files-destination button { flex-shrink: 0; }
.at-files-dialog-actions { display: flex; gap: 10px; justify-content: end; margin-top: 24px; }
.at-files-dialog-actions button { margin: 0; }
</style>
