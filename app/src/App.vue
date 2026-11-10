<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { Permission } from '@nextcloud/files'
import { getRequestToken } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { n, t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api, ApiError, errorMessage, isAborted } from './api.ts'
import { archiveFormats } from './archive-format-capabilities.ts'
import { archiveBaseName, archiveFormatLabel, supportedArchiveName } from './archive-formats.ts'
import { compactSelection, directoryEntries, jobProgress, selectedFileCount, toggleArchiveSelection } from './selection.ts'
import type { ArchiveEntry, ArchiveInfo, Job, JobDetails, Mount, Settings } from './types.ts'

const tr = (text: string, values?: Record<string, string | number>) => t('archive_tools', text, values)
const settings = loadState<Settings>('archive_tools', 'settings', { openByClick: true, idleTimeoutMinutes: 60, allowPin: true, jobsRetentionDays: 30 })
const initialSource = loadState<string | null>('archive_tools', 'sourceId', null)
const initialQuery = new URL(window.location.href).searchParams
const initialAction = initialQuery.get('action')
const requestedJobId = initialQuery.get('jobId')
const linkedJobId = requestedJobId && /^[1-9]\d*$/.test(requestedJobId) && Number.isSafeInteger(Number(requestedJobId))
  ? Number(requestedJobId) : null
const tab = ref<'archive' | 'connections' | 'jobs'>(linkedJobId || !initialSource ? 'jobs' : 'archive')
const sourceId = ref<string | null>(initialSource)
const info = ref<ArchiveInfo | null>(null)
const directory = ref('')
const selection = ref(new Set<string>())
const password = ref('')
const passwordDraft = ref('')
const loading = ref(false)
const actionBusy = ref(false)
const error = ref('')
const notice = ref('')
const mounts = ref<Mount[]>([])
const jobs = ref<Job[]>([])
const listBusy = ref(false)
const dialogMode = ref<'password' | 'extract' | 'connect' | 'disconnect' | null>(null)
const pendingDisconnect = ref<Mount | null>(null)
const dialog = ref<HTMLDialogElement | null>(null)
const destination = ref<{ id: string; name: string } | null>(null)
const folderName = ref('')
const connectionName = ref('')
const pinned = ref(false)
const pendingAction = ref<string | null>(initialAction)
const retryPlan = ref<JobDetails | null>(null)
const retryBusy = ref<number | null>(null)
const search = ref('')
const page = ref(0)
const pageSize = 100
let inspectRequest: AbortController | null = null
let collectionRequest: AbortController | null = null
let revision = 0
let timer: ReturnType<typeof setInterval> | undefined
let linkedJobScrolled = false
let linkedJobUnavailable = false

const matchingEntries = computed(() => search.value.trim()
  ? (info.value?.entries ?? []).filter(entry => entry.path.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()))
  : directoryEntries(info.value?.entries ?? [], directory.value))
const visibleEntries = computed(() => matchingEntries.value.slice(page.value * pageSize, (page.value + 1) * pageSize))
const hasSelection = computed(() => selection.value.size > 0)
const selectionCount = computed(() => selectedFileCount(info.value?.entries ?? [], selection.value))
const breadcrumbs = computed(() => {
  const parts = directory.value.split('/').filter(Boolean)
  return parts.map((name, index) => ({ name, path: parts.slice(0, index + 1).join('/') }))
})
const activeJobs = computed(() => jobs.value.filter(job => job.status === 'queued' || job.status === 'running').length)
const basename = (path: string) => path.split('/').pop() || path

function bytes(value: number): string {
  if (!Number.isFinite(value) || value < 0) return '—'
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
  let size = value
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++ }
  return new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: unit ? 1 : 0 }).format(size) + ' ' + units[unit]
}

function changedSource(id: string): void {
  inspectRequest?.abort()
  revision++
  sourceId.value = id
  info.value = null
  password.value = ''
  passwordDraft.value = ''
  selection.value = new Set()
  directory.value = ''
  search.value = ''
  page.value = 0
  retryPlan.value = null
  destination.value = null
  error.value = ''
  notice.value = ''
  closeDialog()
  tab.value = 'archive'
  const url = new URL(window.location.href)
  url.searchParams.set('sourceId', id)
  url.searchParams.delete('action')
  history.replaceState(null, '', url)
}

async function inspect(): Promise<void> {
  if (!sourceId.value) return
  inspectRequest?.abort()
  const controller = new AbortController()
  inspectRequest = controller
  const id = sourceId.value
  const requestRevision = ++revision
  loading.value = true
  error.value = ''
  try {
    const result = await api<ArchiveInfo>('/archives/' + encodeURIComponent(id) + '/inspect', {
      method: 'POST', body: JSON.stringify({ password: password.value || null }), signal: controller.signal,
    })
    if (sourceId.value !== id || requestRevision !== revision) return
    info.value = result
    const name = archiveBaseName(result.sourceName)
    folderName.value = tr('{name} (extracted)', { name })
    connectionName.value = tr('{name} (archive)', { name })
    if (retryPlan.value && String(retryPlan.value.sourceId) === id) {
      const available = new Set(result.entries.map(entry => entry.path))
      selection.value = new Set(retryPlan.value.members.filter(path => available.has(path)))
      destination.value = { id: String(retryPlan.value.destinationId), name: tr('Previous destination folder') }
      folderName.value = retryPlan.value.folderName
    }
    if (result.encrypted && !password.value) {
      await openDialog('password', true)
    } else if (pendingAction.value === 'extract' || pendingAction.value === 'connect') {
      const action = pendingAction.value
      pendingAction.value = null
      retryPlan.value = null
      await openDialog(action)
    }
  } catch (caught) {
    if (isAborted(caught) || sourceId.value !== id || requestRevision !== revision) return
    error.value = errorMessage(caught)
    if (caught instanceof ApiError && caught.status === 422 && (caught.code === 'password_required' || /password|парол/i.test(caught.message))) {
      password.value = ''
      await openDialog('password', true)
    }
  } finally {
    if (requestRevision === revision) loading.value = false
  }
}

async function pickArchive(): Promise<void> {
  try {
    const nodes = await getFilePickerBuilder(tr('Choose an archive'))
      .setMultiSelect(false)
      .setFilter(node => node.type !== 'file' || supportedArchiveName(node.basename, archiveFormats))
      .setCanPick(node => node.type === 'file' && supportedArchiveName(node.basename, archiveFormats))
      .addButton({ label: tr('Open archive'), variant: 'primary', callback: () => {} })
      .build().pickNodes()
    const id = nodes[0]?.id
    if (id) { changedSource(id); pendingAction.value = null; await inspect() }
  } catch (caught) {
    if (!(caught instanceof FilePickerClosed)) error.value = errorMessage(caught)
  }
}

async function pickDestination(): Promise<void> {
  const restoreExtractionDialog = dialogMode.value === 'extract'
  // Native <dialog> makes the rest of the document inert. Temporarily close
  // it while Nextcloud mounts its own picker dialog under <body>.
  if (restoreExtractionDialog) dialog.value?.close()
  try {
    const nodes = await getFilePickerBuilder(tr('Choose destination folder'))
      .setMultiSelect(false).allowDirectories(true)
      .setMimeTypeFilter(['httpd/unix-directory'])
      .setCanPick(node => node.type !== 'file' && (node.permissions & Permission.CREATE) !== 0)
      .addButton({ label: tr('Choose folder'), variant: 'primary', callback: () => {} })
      .build().pickNodes()
    if (nodes[0]?.id) destination.value = { id: nodes[0].id, name: nodes[0].path }
  } catch (caught) {
    if (!(caught instanceof FilePickerClosed)) error.value = errorMessage(caught)
  } finally {
    if (restoreExtractionDialog && dialogMode.value === 'extract') {
      await nextTick()
      if (dialog.value && !dialog.value.open) dialog.value.showModal()
    }
  }
}

async function openDialog(mode: 'password' | 'extract' | 'connect' | 'disconnect', preserveError = false): Promise<void> {
  if ((mode === 'extract' || mode === 'connect') && info.value?.encrypted && !password.value) {
    pendingAction.value = mode
    mode = 'password'
  }
  if (!preserveError) error.value = ''
  passwordDraft.value = ''
  pinned.value = false
  dialogMode.value = mode
  await nextTick()
  if (dialog.value && !dialog.value.open) dialog.value.showModal()
}

function closeDialog(): void {
  dialog.value?.close()
  dialogMode.value = null
  passwordDraft.value = ''
  pendingDisconnect.value = null
}

async function unlock(): Promise<void> {
  password.value = passwordDraft.value
  closeDialog()
  await inspect()
}

function toggleSelection(path: string): void {
  selection.value = toggleArchiveSelection(info.value?.entries ?? [], selection.value, path)
}

function partialSelection(entry: ArchiveEntry): boolean {
  return entry.type === 'dir' && !selection.value.has(entry.path)
    && (info.value?.entries ?? []).some(candidate => candidate.path.startsWith(entry.path + '/') && selection.value.has(candidate.path))
}

function selectShown(): void {
  for (const entry of visibleEntries.value) {
    if (!selection.value.has(entry.path)) toggleSelection(entry.path)
  }
}

function enterDirectory(path: string): void {
  directory.value = path
  search.value = ''
}

function download(entry: ArchiveEntry): void {
  if (!sourceId.value || entry.type !== 'file') return
  if (info.value?.encrypted && !password.value) { void openDialog('password'); return }
  // A native form streams large attachments without buffering the full member
  // in a browser Blob, and keeps the archive password out of the URL.
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = generateUrl('/apps/archive_tools/archives/' + encodeURIComponent(sourceId.value) + '/download')
  form.target = '_blank'
  form.hidden = true
  for (const [name, value] of Object.entries({ member: entry.path, password: password.value, expectedEtag: info.value?.sourceEtag ?? '', requesttoken: getRequestToken() || '' })) {
    const input = document.createElement('input')
    input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input)
  }
  document.body.appendChild(form)
  form.submit()
  form.remove()
}

async function extract(): Promise<void> {
  if (!sourceId.value || actionBusy.value) return
  actionBusy.value = true
  error.value = ''
  try {
    await api<Job>('/archives/' + encodeURIComponent(sourceId.value) + '/extract', {
      method: 'POST', body: JSON.stringify({
        destinationId: destination.value?.id ?? null,
        folderName: folderName.value,
        members: compactSelection(selection.value),
        password: password.value || null,
        expectedEtag: info.value?.sourceEtag,
      }),
    })
    closeDialog()
    notice.value = tr('Extraction queued. You can leave this page; the operation will continue in the background.')
    tab.value = 'jobs'
    await refreshCollections()
  } catch (caught) { error.value = errorMessage(caught) }
  finally { actionBusy.value = false }
}

async function connect(): Promise<void> {
  if (!sourceId.value || actionBusy.value) return
  actionBusy.value = true
  error.value = ''
  try {
    await api<Mount>('/mounts', {
      method: 'POST', body: JSON.stringify({ sourceId: sourceId.value, name: connectionName.value, password: password.value || null, pinned: pinned.value, expectedEtag: info.value?.sourceEtag }),
    })
    closeDialog()
    notice.value = tr('Archive connected. Participants who can read this folder and archive can access its contents.')
    tab.value = 'connections'
    await refreshCollections()
  } catch (caught) { error.value = errorMessage(caught) }
  finally { actionBusy.value = false }
}

async function refreshCollections(): Promise<void> {
  collectionRequest?.abort()
  const controller = new AbortController()
  collectionRequest = controller
  listBusy.value = true
  try {
    const [connections, operations] = await Promise.all([
      api<Mount[]>('/mounts', { signal: controller.signal }),
      api<Job[]>('/jobs', { signal: controller.signal }),
    ])
    if (collectionRequest !== controller) return
    mounts.value = connections
    jobs.value = operations
    if (linkedJobId && !linkedJobUnavailable && !operations.some(job => job.id === linkedJobId)) {
      try {
        const details = await api<JobDetails>('/jobs/' + linkedJobId, { signal: controller.signal })
        if (collectionRequest !== controller) return
        // Keep large retry selections out of the polled collection state.
        const { members: _members, ...summary } = details
        jobs.value = [summary, ...operations]
      } catch (caught) {
        if (caught instanceof ApiError && (caught.status === 404 || caught.status === 403)) linkedJobUnavailable = true
        throw caught
      }
    }
    await revealLinkedJob()
  } catch (caught) {
    if (!isAborted(caught)) error.value = errorMessage(caught)
  } finally {
    if (collectionRequest === controller) listBusy.value = false
  }
}

async function revealLinkedJob(): Promise<void> {
  if (!linkedJobId || linkedJobScrolled || tab.value !== 'jobs' || dialogMode.value) return
  await nextTick()
  const row = document.getElementById('at-job-' + linkedJobId)
  if (!row) return
  row.scrollIntoView({ block: 'center' })
  row.focus({ preventScroll: true })
  linkedJobScrolled = true
}

async function disconnect(mount: Mount): Promise<void> {
  pendingDisconnect.value = mount
  await openDialog('disconnect')
}

async function confirmDisconnect(): Promise<void> {
  const mount = pendingDisconnect.value
  if (!mount || actionBusy.value) return
  actionBusy.value = true
  error.value = ''
  try {
    await api('/mounts/' + mount.id, { method: 'DELETE' })
    closeDialog()
    notice.value = tr('Archive disconnected. The original file was kept.')
    await refreshCollections()
  } catch (caught) { error.value = errorMessage(caught) }
  finally { actionBusy.value = false }
}

async function togglePinned(mount: Mount): Promise<void> {
  try {
    await api('/mounts/' + mount.id, { method: 'PATCH', body: JSON.stringify({ pinned: !mount.pinned }) })
    await refreshCollections()
  } catch (caught) { error.value = errorMessage(caught) }
}

async function cancelJob(job: Job): Promise<void> {
  try {
    await api('/jobs/' + job.id, { method: 'DELETE' })
    await refreshCollections()
  } catch (caught) { error.value = errorMessage(caught) }
}

async function openSource(id: number): Promise<void> {
  changedSource(String(id))
  pendingAction.value = null
  await inspect()
}

async function retryJob(job: Job): Promise<void> {
  if (retryBusy.value !== null) return
  retryBusy.value = job.id
  const requestRevision = revision
  try {
    const details = await api<JobDetails>('/jobs/' + job.id)
    if (revision !== requestRevision) return
    changedSource(String(details.sourceId))
    retryPlan.value = details
    pendingAction.value = 'extract'
    notice.value = tr('Review the previous selection and destination before starting another extraction.')
    await inspect()
  } catch (caught) {
    if (revision === requestRevision) error.value = errorMessage(caught)
  } finally { retryBusy.value = null }
}

function statusLabel(job: Job): string {
  if (job.cancelRequested && (job.status === 'queued' || job.status === 'running')) return tr('Cancelling')
  return ({ queued: tr('Waiting for background processing'), running: tr('Extracting'), succeeded: tr('Completed'), failed: tr('Failed'), cancelled: tr('Cancelled') })[job.status]
}

const filesUrl = (id: number) => generateUrl('/f/' + encodeURIComponent(String(id)))

watch([directory, search], () => { page.value = 0 })
watch(tab, () => { void revealLinkedJob() })

onMounted(() => {
  void refreshCollections()
  if (sourceId.value && !linkedJobId) void inspect()
  timer = setInterval(() => {
    if (document.visibilityState === 'visible' && !listBusy.value) void refreshCollections()
  }, 4000)
})

onBeforeUnmount(() => {
  inspectRequest?.abort()
  collectionRequest?.abort()
  clearInterval(timer)
  password.value = ''
  passwordDraft.value = ''
})
</script>

<template>
  <div class="at-shell">
    <aside class="at-navigation" :aria-label="tr('Archive Tools')">
      <div class="at-brand"><span aria-hidden="true">▣</span><strong>{{ tr('Archive Tools') }}</strong></div>
      <button class="at-nav-item" :class="{ active: tab === 'archive' }" @click="tab = 'archive'">
        <span aria-hidden="true">▤</span>{{ tr('Archive viewer') }}
      </button>
      <button class="at-nav-item" :class="{ active: tab === 'connections' }" @click="tab = 'connections'; refreshCollections()">
        <span aria-hidden="true">▣</span>{{ tr('Connected archives') }}<span class="at-count">{{ mounts.length }}</span>
      </button>
      <button class="at-nav-item" :class="{ active: tab === 'jobs' }" @click="tab = 'jobs'; refreshCollections()">
        <span aria-hidden="true">◷</span>{{ tr('Operations') }}<span v-if="activeJobs" class="at-count">{{ activeJobs }}</span>
      </button>
      <div class="at-nav-note">{{ tr('Archives open without creating a permanent connection. Connect an archive when the folder should remain available to participants.') }}</div>
      <a class="at-files-link" :href="generateUrl('/apps/files/')">{{ tr('Back to Files') }}</a>
    </aside>

    <main class="at-content" :aria-busy="loading">
      <div v-if="error" class="at-message at-error" role="alert"><span>{{ error }}</span><button :aria-label="tr('Dismiss message')" @click="error = ''">×</button></div>
      <div v-if="notice" class="at-message at-notice" role="status"><span>{{ notice }}</span><button :aria-label="tr('Dismiss message')" @click="notice = ''">×</button></div>

      <template v-if="tab === 'archive'">
        <header class="at-page-header">
          <div><h1>{{ info?.sourceName || tr('Archive viewer') }}</h1><p>{{ tr('Browse archive contents and extract only what you need.') }}</p></div>
          <button @click="pickArchive">{{ tr('Choose archive') }}</button>
        </header>
        <div v-if="loading" class="at-empty" role="status"><span class="at-spinner" aria-hidden="true"></span><h2>{{ tr('Reading archive…') }}</h2></div>
        <div v-else-if="!info" class="at-empty">
          <span class="at-empty-icon" aria-hidden="true">▣</span>
          <h2>{{ tr('Choose an archive to begin') }}</h2>
          <p>{{ tr('Open a supported archive from Files or choose one here. The original archive is kept unchanged.') }}</p>
          <button class="primary" @click="pickArchive">{{ tr('Choose archive') }}</button>
          <button v-if="sourceId" @click="inspect">{{ tr('Try again') }}</button>
        </div>
        <template v-else>
          <div class="at-summary">
            <span>{{ n('archive_tools', '%n file', '%n files', info.fileCount) }}</span><span>{{ bytes(info.totalSize) }}</span><span>{{ archiveFormatLabel(info.format) }}</span>
            <span v-if="info.encrypted" class="at-lock">{{ tr('Password protected') }}</span>
          </div>
          <div class="at-toolbar">
            <button class="primary" @click="openDialog('extract')">{{ hasSelection ? tr('Extract selected') : tr('Extract all') }}</button>
            <button @click="openDialog('connect')">{{ tr('Connect as folder') }}</button>
            <button v-if="info.encrypted" @click="openDialog('password')">{{ password ? tr('Change password') : tr('Enter password') }}</button>
            <span v-if="selection.size" class="at-selection-count">{{ n('archive_tools', '%n file selected', '%n files selected', selectionCount) }}</span>
            <button v-if="selection.size" class="at-text-button" @click="selection = new Set()">{{ tr('Clear selection') }}</button>
          </div>
          <nav class="at-breadcrumbs" :aria-label="tr('Archive path')">
            <button @click="enterDirectory('')">{{ tr('Archive root') }}</button>
            <template v-for="crumb in breadcrumbs" :key="crumb.path"><span aria-hidden="true">/</span><button @click="enterDirectory(crumb.path)">{{ crumb.name }}</button></template>
          </nav>
          <div class="at-list-tools"><input v-model="search" type="search" :placeholder="tr('Search in archive')" :aria-label="tr('Search in archive')"><button :disabled="visibleEntries.length === 0" @click="selectShown">{{ tr('Select shown entries') }}</button></div>
          <div class="at-table-wrap">
            <table class="at-table">
              <thead><tr><th class="at-check-column"><span class="at-visually-hidden">{{ tr('Select') }}</span></th><th>{{ tr('Name') }}</th><th class="at-size-column">{{ tr('Size') }}</th><th class="at-action-column"><span class="at-visually-hidden">{{ tr('Actions') }}</span></th></tr></thead>
              <tbody>
                <tr v-for="entry in visibleEntries" :key="entry.path">
                  <td><input type="checkbox" :aria-label="tr('Select {name}', { name: basename(entry.path) })" :checked="selection.has(entry.path)" :indeterminate="partialSelection(entry)" @change="toggleSelection(entry.path)"></td>
                  <td><button v-if="entry.type === 'dir'" class="at-entry" @click="enterDirectory(entry.path)"><span class="at-folder" aria-hidden="true">▰</span>{{ search ? entry.path : basename(entry.path) }}</button><span v-else class="at-entry"><span class="at-file" aria-hidden="true">▤</span>{{ search ? entry.path : basename(entry.path) }}</span></td>
                  <td class="at-size-column">{{ entry.type === 'file' ? bytes(entry.size) : '—' }}</td>
                  <td class="at-action-column"><button v-if="entry.type === 'file'" :aria-label="tr('Download {name}', { name: basename(entry.path) })" @click="download(entry)">{{ tr('Download') }}</button></td>
                </tr>
                <tr v-if="visibleEntries.length === 0"><td colspan="4" class="at-table-empty">{{ tr('This folder is empty.') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div v-if="matchingEntries.length > pageSize" class="at-pagination"><span>{{ tr('{from}–{to} of {total}', { from: page * pageSize + 1, to: Math.min((page + 1) * pageSize, matchingEntries.length), total: matchingEntries.length }) }}</span><button :disabled="page === 0" @click="page--">{{ tr('Previous') }}</button><button :disabled="(page + 1) * pageSize >= matchingEntries.length" @click="page++">{{ tr('Next') }}</button></div>
        </template>
      </template>

      <template v-else-if="tab === 'connections'">
        <header class="at-page-header"><div><h1>{{ tr('Connected archives') }}</h1><p>{{ tr('Connections available through your folders and shares.') }}</p></div><button :disabled="listBusy" @click="refreshCollections">{{ tr('Refresh') }}</button></header>
        <p class="at-policy-note">{{ settings.idleTimeoutMinutes === 0 ? tr('Automatic disconnection is disabled.') : tr('Unpinned connections are disconnected after {minutes} minutes of inactivity across all participants.', { minutes: settings.idleTimeoutMinutes }) }}</p>
        <div v-if="mounts.length === 0" class="at-empty"><span class="at-empty-icon" aria-hidden="true">▣</span><h2>{{ tr('No connected archives') }}</h2><p>{{ tr('Open an archive and choose “Connect as folder” to make its contents available alongside the original file.') }}</p><button @click="pickArchive">{{ tr('Choose archive') }}</button></div>
        <ul v-else class="at-card-list">
          <li v-for="mount in mounts" :key="mount.id" class="at-card">
            <div class="at-card-title"><h2>{{ mount.name }}</h2><span v-if="mount.state === 'source_changed'" class="at-badge at-status-failed">{{ tr('Source changed') }}</span><span v-else-if="mount.pinned" class="at-badge">{{ tr('Pinned') }}</span></div>
            <p v-if="mount.path" class="at-path">{{ mount.path }}</p>
            <p v-if="mount.state === 'source_changed'">{{ tr('The original archive changed. Disconnect and connect it again to refresh this folder.') }}</p>
            <p>{{ n('archive_tools', '%n file', '%n files', mount.fileCount) }} · {{ bytes(mount.totalSize) }} · {{ tr('Connected by {user}', { user: mount.createdBy }) }}</p>
            <div class="at-card-actions"><button @click="openSource(mount.sourceId)">{{ tr('Open archive') }}</button><button v-if="mount.canManage && (settings.allowPin || mount.pinned)" @click="togglePinned(mount)">{{ mount.pinned ? tr('Unpin') : tr('Pin') }}</button><button v-if="mount.canManage" class="at-danger" @click="disconnect(mount)">{{ tr('Disconnect for everyone') }}</button></div>
          </li>
        </ul>
      </template>

      <template v-else>
        <header class="at-page-header"><div><h1>{{ tr('Operations') }}</h1><p>{{ tr('Your extraction jobs continue in the background, including after you leave this page.') }}</p></div><button :disabled="listBusy" @click="refreshCollections">{{ tr('Refresh') }}</button></header>
        <div v-if="jobs.length === 0" class="at-empty"><span class="at-empty-icon" aria-hidden="true">◷</span><h2>{{ tr('No operations yet') }}</h2><p>{{ tr('Start an extraction to see its progress here.') }}</p></div>
        <ul v-else class="at-card-list">
          <li v-for="job in jobs" :id="'at-job-' + job.id" :key="job.id" class="at-card" :class="{ 'at-card-linked': job.id === linkedJobId }" :tabindex="job.id === linkedJobId ? -1 : undefined" :aria-current="job.id === linkedJobId ? 'true' : undefined">
            <div class="at-card-title"><h2>{{ job.sourceName }}</h2><span class="at-badge" :class="'at-status-' + job.status">{{ statusLabel(job) }}</span></div>
            <p class="at-path">{{ job.folderName }}</p>
            <template v-if="job.status === 'running' || job.status === 'queued'">
              <progress :value="jobProgress(job.bytesDone, job.bytesTotal)" max="100" :aria-label="tr('Extraction progress')"></progress>
              <p>{{ tr('{done} of {total} files', { done: job.filesDone, total: job.filesTotal }) }} · {{ bytes(job.bytesDone) }} / {{ bytes(job.bytesTotal) }}</p>
            </template>
            <p v-if="job.message" class="at-job-message">{{ job.message }}</p>
            <div class="at-card-actions">
              <button v-if="job.status === 'queued' || job.status === 'running'" :disabled="job.cancelRequested" @click="cancelJob(job)">{{ tr('Cancel operation') }}</button>
              <a v-if="job.status === 'succeeded' && job.targetId" class="button primary" :href="filesUrl(job.targetId)">{{ tr('Open extracted folder') }}</a>
              <button v-if="job.status === 'failed' || job.status === 'cancelled'" :disabled="retryBusy !== null" @click="retryJob(job)">{{ tr('Prepare another extraction') }}</button>
            </div>
          </li>
        </ul>
      </template>

      <dialog v-if="dialogMode" ref="dialog" class="at-dialog" :aria-labelledby="'at-dialog-title'" @cancel="actionBusy ? $event.preventDefault() : closeDialog()">
        <form v-if="dialogMode === 'password'" @submit.prevent="unlock">
          <h2 id="at-dialog-title">{{ tr('Archive password') }}</h2><p>{{ tr('The password is used for this archive and is never placed in its URL.') }}</p>
          <label for="at-password">{{ tr('Password') }}</label><input id="at-password" v-model="passwordDraft" type="password" autocomplete="off" required autofocus>
          <p v-if="error" class="at-dialog-error" role="alert">{{ error }}</p>
          <div class="at-dialog-actions"><button type="button" @click="closeDialog">{{ tr('Cancel') }}</button><button type="submit" class="primary">{{ tr('Unlock archive') }}</button></div>
        </form>
        <form v-else-if="dialogMode === 'extract'" @submit.prevent="extract">
          <h2 id="at-dialog-title">{{ hasSelection ? tr('Extract selected files') : tr('Extract archive') }}</h2><p>{{ hasSelection ? tr('Selected files and folders will be extracted.') : tr('All archive contents will be extracted into a new folder.') }}</p>
          <label>{{ tr('Destination') }}</label><div class="at-destination"><span>{{ destination?.name || tr('Same folder as the archive') }}</span><button type="button" @click="pickDestination">{{ tr('Choose folder') }}</button></div>
          <label for="at-extract-name">{{ tr('New folder name') }}</label><input id="at-extract-name" v-model="folderName" type="text" required maxlength="200">
          <p class="at-hint">{{ tr('Existing files are not overwritten. A conflicting destination is reported as an error.') }}</p>
          <p v-if="error" class="at-dialog-error" role="alert">{{ error }}</p>
          <div class="at-dialog-actions"><button type="button" :disabled="actionBusy" @click="closeDialog">{{ tr('Cancel') }}</button><button type="submit" class="primary" :disabled="actionBusy">{{ actionBusy ? tr('Scheduling…') : tr('Start extraction') }}</button></div>
        </form>
        <form v-else-if="dialogMode === 'connect'" @submit.prevent="connect">
          <h2 id="at-dialog-title">{{ tr('Connect as folder') }}</h2><p>{{ tr('The connection is created beside the archive. Everyone who can read this folder and archive can browse the connected contents.') }}</p>
          <label for="at-connect-name">{{ tr('Folder name') }}</label><input id="at-connect-name" v-model="connectionName" type="text" required maxlength="200">
          <label v-if="settings.allowPin" class="at-checkbox-label"><input v-model="pinned" type="checkbox">{{ tr('Keep connected until manually disconnected') }}</label>
          <p v-if="info?.encrypted" class="at-hint">{{ tr('Connecting this protected archive unlocks its contents for eligible folder participants. The password itself is not shared.') }}</p>
          <p v-if="error" class="at-dialog-error" role="alert">{{ error }}</p>
          <div class="at-dialog-actions"><button type="button" :disabled="actionBusy" @click="closeDialog">{{ tr('Cancel') }}</button><button type="submit" class="primary" :disabled="actionBusy">{{ actionBusy ? tr('Connecting…') : tr('Connect archive') }}</button></div>
        </form>
        <form v-else @submit.prevent="confirmDisconnect" :aria-busy="actionBusy">
          <h2 id="at-dialog-title">{{ tr('Disconnect for everyone') }}</h2>
          <p>{{ tr('Disconnect “{name}” for all participants? The original archive will be kept.', { name: pendingDisconnect?.name || '' }) }}</p>
          <p v-if="error" class="at-dialog-error" role="alert">{{ error }}</p>
          <div class="at-dialog-actions"><button type="button" :disabled="actionBusy" @click="closeDialog" autofocus>{{ tr('Cancel') }}</button><button type="submit" class="at-danger" :disabled="actionBusy">{{ tr('Disconnect for everyone') }}</button></div>
        </form>
      </dialog>
    </main>
  </div>
</template>

<style>
#archive-tools-app { width: 100%; height: 100%; min-height: calc(100vh - var(--header-height, 50px)); color: var(--color-main-text); }
.at-shell { display: flex; width: 100%; height: calc(100vh - var(--header-height, 50px)); min-height: 320px; background: var(--color-main-background); }
.at-navigation { flex: 0 0 258px; padding: 24px 14px; border-right: 1px solid var(--color-border, #ddd); background: var(--color-main-background); display: flex; flex-direction: column; gap: 6px; }
.at-brand { display: flex; align-items: center; gap: 12px; padding: 6px 14px 24px; font-size: 19px; }
.at-brand > span { color: var(--color-primary-element); font-size: 30px; }
.at-nav-item { width: 100%; display: flex; align-items: center; gap: 12px; padding: 12px 14px; margin: 0; text-align: left; border: 0; background: transparent; border-radius: var(--border-radius-large, 10px); font-weight: 500; }
.at-nav-item > span:first-child { font-size: 23px; width: 26px; }
.at-nav-item.active { background: var(--color-primary-element-light); color: var(--color-primary-element-light-text, var(--color-main-text)); }
.at-count { margin-left: auto; background: var(--color-background-dark); border-radius: 14px; min-width: 24px; text-align: center; font-size: 12px; padding: 0 5px; }
.at-nav-note { font-size: 13px; line-height: 1.6; color: var(--color-text-maxcontrast); padding: 22px 14px; }
.at-files-link { margin: auto 14px 4px; padding-top: 22px; color: var(--color-primary-element); }
.at-content { flex: 1; min-width: 0; max-width: 1280px; padding: 32px 40px 60px; overflow-y: auto; }
.at-page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
.at-page-header h1 { font-size: 26px; font-weight: 650; line-height: 1.35; overflow-wrap: anywhere; margin: 0 0 7px; }
.at-page-header p, .at-card > p, .at-policy-note { color: var(--color-text-maxcontrast); line-height: 1.55; }
.at-page-header > button { flex-shrink: 0; margin-top: 0; }
.at-summary { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin: -8px 0 24px; color: var(--color-text-maxcontrast); font-size: 13px; }
.at-summary > span + span::before { content: '·'; margin-right: 14px; }
.at-summary .at-lock { color: var(--color-main-text); }
.at-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
.at-toolbar button { margin: 0; }
.at-selection-count { margin-left: auto; color: var(--color-text-maxcontrast); font-size: 13px; }
.at-text-button { border: 0; background: transparent; color: var(--color-primary-element); }
.at-breadcrumbs { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; padding: 8px 0 14px; }
.at-breadcrumbs button { margin: 0; padding: 5px 8px; background: transparent; border: 0; }
.at-breadcrumbs > span { color: var(--color-text-maxcontrast); }
.at-list-tools { display: flex; justify-content: space-between; gap: 14px; align-items: center; margin-bottom: 12px; }
.at-list-tools input { margin: 0; min-width: 180px; max-width: 420px; width: 100%; }
.at-list-tools button { margin: 0; flex-shrink: 0; }
.at-pagination { display: flex; gap: 8px; align-items: center; justify-content: flex-end; margin-top: 14px; font-size: 13px; }
.at-pagination span { margin-right: auto; color: var(--color-text-maxcontrast); }
.at-table-wrap { border: 1px solid var(--color-border, #ddd); border-radius: var(--border-radius-large, 10px); overflow: auto; }
.at-table { border-collapse: collapse; width: 100%; margin: 0; }
.at-table th { background: var(--color-background-hover); color: var(--color-text-maxcontrast); font-size: 12px; font-weight: 600; text-align: left; padding: 12px; }
.at-table td { padding: 9px 12px; border-top: 1px solid var(--color-border, #ddd); height: 56px; }
.at-table tbody tr:hover { background: var(--color-background-hover); }
.at-check-column { width: 36px; }
.at-size-column { width: 110px; white-space: nowrap; color: var(--color-text-maxcontrast); }
.at-action-column { width: 100px; text-align: right; }
.at-table input[type=checkbox] { appearance: auto; width: 17px; height: 17px; margin: 0; accent-color: var(--color-primary-element); }
.at-entry { display: inline-flex; align-items: center; gap: 12px; font-size: 14px; margin: 0; padding: 4px 0; background: none; border: 0; text-align: left; word-break: break-word; }
.at-entry > span { display: inline-block; width: 24px; flex-shrink: 0; font-size: 25px; }
.at-folder { color: var(--color-primary-element); }
.at-file { color: var(--color-text-maxcontrast); }
.at-table .at-table-empty { padding: 36px; text-align: center; color: var(--color-text-maxcontrast); }
.at-empty { display: flex; min-height: 350px; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 36px 20px; gap: 12px; }
.at-empty-icon { color: var(--color-primary-element); font-size: 72px; line-height: 1.1; opacity: .7; }
.at-empty h2 { font-size: 21px; font-weight: 600; margin: 4px 0; }
.at-empty p { color: var(--color-text-maxcontrast); line-height: 1.6; max-width: 470px; margin-bottom: 8px; }
.at-spinner { height: 35px; width: 35px; border: 3px solid var(--color-background-dark); border-top-color: var(--color-primary-element); border-radius: 50%; animation: at-spin .9s linear infinite; }
@keyframes at-spin { to { transform: rotate(360deg); } }
.at-message { display: flex; align-items: center; justify-content: space-between; gap: 12px; border-radius: var(--border-radius-large, 10px); padding: 10px 14px; margin-bottom: 22px; border: 1px solid var(--color-border, #ddd); line-height: 1.5; }
.at-message > button { flex-shrink: 0; border: 0; background: transparent; margin: 0; font-size: 22px; }
.at-error { border-color: var(--color-error); background: var(--color-error-hover, var(--color-background-hover)); }
.at-notice { border-color: var(--color-primary-element); background: var(--color-primary-element-light); }
.at-card-list { list-style: none; display: flex; flex-direction: column; gap: 14px; margin: 20px 0; padding: 0; }
.at-card { border: 1px solid var(--color-border, #ddd); border-radius: var(--border-radius-large, 10px); padding: 20px; }
.at-card-linked { border-color: var(--color-primary-element); box-shadow: 0 0 0 2px var(--color-primary-element); scroll-margin-block: 24px; }
.at-card-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 6px; }
.at-card h2 { font-size: 17px; font-weight: 600; margin: 0; overflow-wrap: anywhere; }
.at-card > p { margin: 6px 0; font-size: 13px; }
.at-card .at-path { overflow-wrap: anywhere; color: var(--color-main-text); }
.at-card-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.at-card-actions > * { margin: 0; }
.at-danger { color: var(--color-error); }
.at-badge { font-size: 12px; padding: 3px 9px; background: var(--color-background-hover); border-radius: 20px; white-space: nowrap; }
.at-status-failed { color: var(--color-main-text); border-inline-start: 3px solid var(--color-error); }
.at-status-succeeded { color: var(--color-main-text); border-inline-start: 3px solid var(--color-success); }
.at-card progress { width: 100%; height: 8px; margin: 14px 0 4px; accent-color: var(--color-primary-element); }
.at-card .at-job-message { color: var(--color-main-text); }
.at-policy-note { font-size: 13px; padding: 12px 15px; border-left: 3px solid var(--color-primary-element); background: var(--color-background-hover); border-radius: 3px; }
.at-dialog { color: var(--color-main-text); background: var(--color-main-background); border: 1px solid var(--color-border, #ddd); border-radius: var(--border-radius-large, 12px); box-shadow: 0 12px 50px #0004; padding: 28px; width: min(520px, calc(100vw - 32px)); max-height: calc(100vh - 48px); overflow-y: auto; }
.at-dialog::backdrop { background: #0007; }
.at-dialog h2 { font-size: 21px; line-height: 1.4; font-weight: 600; margin: 0 0 14px; }
.at-dialog p { line-height: 1.6; color: var(--color-text-maxcontrast); margin-bottom: 20px; }
.at-dialog label { display: block; font-weight: 600; margin: 16px 0 7px; }
.at-dialog input[type=text], .at-dialog input[type=password] { width: 100%; margin: 0; }
.at-dialog .at-hint { font-size: 12px; margin: 12px 0; }
.at-dialog-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 24px; }
.at-dialog-actions button { margin: 0; }
.at-destination { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; padding: 8px 0; }
.at-destination > span { overflow-wrap: anywhere; }
.at-destination button { flex-shrink: 0; margin: 0; }
.at-dialog .at-checkbox-label { display: flex; gap: 10px; align-items: center; font-weight: 400; }
.at-checkbox-label input { appearance: auto; width: 18px; height: 18px; margin: 0; accent-color: var(--color-primary-element); }
.at-dialog .at-dialog-error { color: var(--color-error); margin: 14px 0; }
.at-visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
@media (max-width: 900px) { .at-navigation { flex-basis: 215px; } .at-content { padding: 26px 22px; } }
@media (max-width: 680px) { .at-shell { display: block; overflow-y: auto; } .at-navigation { border: 0; border-bottom: 1px solid var(--color-border, #ddd); padding: 12px; flex-direction: row; flex-wrap: wrap; } .at-brand { width: 100%; padding: 0 8px 10px; } .at-nav-item { flex: 1; width: auto; padding: 9px 8px; font-size: 12px; gap: 5px; justify-content: center; } .at-nav-note, .at-files-link { display: none; } .at-count { margin-left: 3px; } .at-content { padding: 22px 14px 40px; overflow-y: visible; } .at-page-header { flex-direction: column; gap: 12px; } .at-page-header h1 { font-size: 23px; } .at-size-column { width: 70px; } .at-action-column { width: 80px; } .at-card-title { align-items: flex-start; } .at-selection-count { width: 100%; margin: 8px 0 0; } }
</style>
