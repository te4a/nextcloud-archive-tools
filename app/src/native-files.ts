import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import {
  DefaultType, File, FileListFilter, Folder, Permission, getFileActions, getFileListActions,
  getFilesRegistry, getNavigation, registerFileAction, registerFileListFilter, registerFileListHeader,
} from '@nextcloud/files'
import type { ActionContext, IFileAction, IFileListAction, IFolder, INode, IView, NodeData, ViewActionContext } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api, ApiError, errorMessage, isAborted } from './api.ts'
import { canPreviewMember, downloadArchiveMember, hasViewerHandler, materializeArchiveMember, previewArchiveMember } from './member-transfer.ts'
import { archiveFormats } from './archive-format-capabilities.ts'
import { supportedArchiveName } from './archive-formats.ts'
import {
  ARCHIVE_NODE_MARKER, archiveDirectories, archiveEtagIdentity, archiveRenderIds, archiveSourceId, archiveViewId, decodeArchiveDirectory,
  encodeArchivePath, lockedArchiveRenderId, readFilesReturnRoute, sanitizeFilesReturnRoute, zipRenderId,
} from './native-archive-state.ts'
import type { FilesReturnRoute } from './native-archive-state.ts'
import type { ArchiveEntry, ArchiveInfo, ZipArchiveEntry, ZipFolderInfo } from './types.ts'
import './native-files.css'

const tr = (text: string, values?: Record<string, string | number>) => t('archive_tools', text, values)
export const archiveIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M3 3h18v5h-1v13H4V8H3V3zm2 2v1h14V5H5zm1 3v11h12V8H6zm3 3h6v2H9v-2z"/></svg>'

interface FilesRouter {
  params: Record<string, string>
  query: FilesReturnRoute['query']
  goToRoute(name: string | null, params: Record<string, string>, query?: FilesReturnRoute['query'], replace?: boolean): Promise<unknown>
}
interface ArchiveSnapshot { sourceId: string; etag: string; member: string; type: 'file' | 'dir'; generation: number; previewMime?: string }
interface FullArchiveListing {
  kind: 'full'
  info: ArchiveInfo
  password: string
  ids: Map<string, number>
  directories: ReturnType<typeof archiveDirectories>
}
type ArchiveListing = FullArchiveListing | { kind: 'zip'; info: ZipFolderInfo; password: string }
type InspectResponse = ArchiveInfo | { unchanged: true; sourceEtag: string }
interface ArchiveViewState {
  sourceId: string; sourceName?: string; view: IView; password: string; listing?: ArchiveListing; folder?: IFolder
  sourceEtag?: string; validatedDirectory?: string
  returnRoute: FilesReturnRoute | null; generation: number
  invalid: boolean; locked: boolean; prompting: boolean; closing: boolean; pendingAction: 'extract' | null
  passwordError?: string
  lifetime: AbortController
  memberTransfers: Map<string, string>
}

const states = new Map<string, ArchiveViewState>()
const snapshots = new WeakMap<INode, ArchiveSnapshot>()
const ownedActions = new Set<string>()
const guardedActions = new WeakSet<object>()
let headerElement: HTMLElement | null = null
let dialogHost: HTMLElement | null = null
let headerView: string | null = null
let initialized = false
let connectionPromptOpen = false

class ArchiveLoadingFilter extends FileListFilter {
  request?: { message: string }
  hasVisibleRows = false

  override filter(nodes: INode[]): INode[] {
    this.hasVisibleRows = nodes.length > 0
    return this.request ? [] : nodes
  }

  update(request?: { message: string }): void {
    this.request = request
    this.filterUpdated()
  }
}

const archiveLoading = new ArchiveLoadingFilter('archive-tools-loading', Number.MAX_SAFE_INTEGER)

function notifyConnected(): void {
  showSuccess(tr('Archive connected. Participants who can read this folder and archive can access its contents.'))
}

/** Keep URL identity encoded while the Files UI and Router use literal names. */
class ArchiveFile extends File {
  private archiveMember: string
  private archiveName: string
  constructor(data: NodeData, member: string, name: string) {
    super(data); this.archiveMember = member; this.archiveName = name
  }
  override get basename(): string { return this.archiveMember.split('/').pop() || this.archiveName }
  override get path(): string { return '/' + this.archiveMember }
  override get isDavResource(): boolean { return false }
  override clone(): this { return new ArchiveFile(structuredClone(this._data), this.archiveMember, this.archiveName) as this }
}

class ArchiveFolder extends Folder {
  private archiveMember: string
  private archiveName: string
  constructor(data: NodeData, member: string, name: string) {
    super(data); this.archiveMember = member; this.archiveName = name
  }
  override get basename(): string { return this.archiveMember.split('/').pop() || this.archiveName }
  override get path(): string { return '/' + this.archiveMember }
  override get isDavResource(): boolean { return false }
  override clone(): this { return new ArchiveFolder(structuredClone(this._data), this.archiveMember, this.archiveName) as this }
}

function router(): FilesRouter {
  const result = (window as unknown as { OCP?: { Files?: { Router?: FilesRouter } } }).OCP?.Files?.Router
  if (!result) throw new Error(tr('The operation failed. Try again.'))
  return result
}

export function isArchiveView(view: IView): boolean { return archiveSourceId(view.id) !== null }
export function isArchiveNode(node: INode): boolean { return node.attributes[ARCHIVE_NODE_MARKER] === true }
function stateFor(view: IView): ArchiveViewState | undefined { return states.get(view.id) }
function archiveContext(context: ActionContext | ViewActionContext): boolean {
  return isArchiveView(context.view) || ('nodes' in context && context.nodes.some(isArchiveNode))
}

/** Public actions are mutable registrations. Block both visibility and direct execution. */
function guardFileAction(action: IFileAction): void {
  if (ownedActions.has(action.id) || guardedActions.has(action)) return
  guardedActions.add(action)
  const enabled = action.enabled
  const exec = action.exec
  const execBatch = action.execBatch
  action.enabled = context => !archiveContext(context) && (enabled?.call(action, context) ?? true)
  action.exec = async context => archiveContext(context) ? null : exec.call(action, context)
  if (execBatch) action.execBatch = async context => archiveContext(context) ? context.nodes.map(() => null) : execBatch.call(action, context)
}

function guardListAction(action: IFileListAction): void {
  if (ownedActions.has(action.id) || guardedActions.has(action)) return
  guardedActions.add(action)
  const enabled = action.enabled
  const exec = action.exec
  action.enabled = context => !isArchiveView(context.view) && (enabled?.call(action, context) ?? true)
  action.exec = async context => isArchiveView(context.view) ? null : exec.call(action, context)
}

function rememberReturn(sourceId: string, route: FilesReturnRoute): void {
  try { sessionStorage.setItem('archive-tools:return:' + sourceId, JSON.stringify(route)) } catch { /* Navigation still works without browser storage. */ }
}

function storedReturn(sourceId: string): FilesReturnRoute | null {
  try { return readFilesReturnRoute(sessionStorage.getItem('archive-tools:return:' + sourceId), sourceId) } catch { return null }
}

function archiveNode(state: ArchiveViewState, entry?: ArchiveEntry): INode {
  const listing = state.listing!
  const info = listing.info
  const root = generateUrl('/apps/archive_tools/virtual/' + state.sourceId + '/' + archiveEtagIdentity(info.sourceEtag))
  const member = entry?.path ?? ''
  const id = listing.kind === 'zip'
    ? zipRenderId(state.sourceId, entry ? (entry as ZipArchiveEntry).renderIndex : 0)
    : listing.ids.get(member)
  if (id === undefined) throw new Error(tr('The operation failed. Try again.'))
  const data = {
    id, source: window.location.origin + root + (member ? '/' + encodeArchivePath(member) : ''),
    root, owner: null, permissions: Permission.READ, displayname: member ? member.split('/').pop()! : info.sourceName,
    size: entry?.type === 'file' ? entry.size : 0,
    ...(entry?.mtime ? { mtime: new Date(entry.mtime * 1000) } : {}),
    attributes: { [ARCHIVE_NODE_MARKER]: true },
  }
  const node = !entry || entry.type === 'dir'
    ? new ArchiveFolder(data, member, info.sourceName)
    : new ArchiveFile({ ...data, mime: entry.mime ?? entry.previewMime ?? 'application/octet-stream' }, member, info.sourceName)
  snapshots.set(node, {
    sourceId: state.sourceId, etag: info.sourceEtag, member, type: entry?.type ?? 'dir',
    generation: state.generation, previewMime: entry?.previewMime,
  })
  return node
}

function selectedMembers(state: ArchiveViewState, nodes: INode[]): string[] {
  if (state.invalid || state.locked || !current(state)) throw new Error(tr('The archive changed. Open it again before continuing.'))
  return nodes.map(node => {
    const snapshot = snapshots.get(node)
    if (!snapshot || snapshot.sourceId !== state.sourceId || snapshot.generation !== state.generation
      || snapshot.etag !== state.listing?.info.sourceEtag || !snapshot.member) throw new Error(tr('The archive changed. Open it again before continuing.'))
    return snapshot.member
  })
}

function current(state: ArchiveViewState): boolean {
  return !state.closing && getNavigation().active?.id === state.view.id && !state.lifetime.signal.aborted
}

/** A locked view has no fabricated ArchiveInfo or selectable member entries. */
function lockedContents(state: ArchiveViewState, path: string): { folder: IFolder; contents: INode[] } {
  const member = decodeArchiveDirectory(path)
  const root = generateUrl('/apps/archive_tools/virtual/' + state.sourceId + '/locked')
  const name = state.listing?.info.sourceName || state.sourceName || tr('Archive viewer')
  const folder = new ArchiveFolder({
    id: lockedArchiveRenderId(state.sourceId, router().params.fileid, state.listing?.kind === 'zip'),
    source: window.location.origin + root + (member ? '/' + encodeArchivePath(member) : ''),
    root, owner: null, permissions: Permission.READ, displayname: member ? member.split('/').pop()! : name,
    attributes: { [ARCHIVE_NODE_MARKER]: true },
  }, member, name)
  state.folder = folder
  state.locked = true
  updateHeader(state)
  queueMicrotask(() => { void promptPending(state) })
  return { folder, contents: [] }
}

async function loadArchiveListing(state: ArchiveViewState, member: string, password: string, signal: AbortSignal): Promise<ArchiveListing> {
  const previous = state.listing
  const endpoint = '/archives/' + state.sourceId
  if (previous?.kind !== 'full') {
    try {
      const info = await api<ZipFolderInfo>(endpoint + '/browse-zip', {
        method: 'POST', body: JSON.stringify({ path: member, password: password || null, expectedEtag: state.sourceEtag }), signal,
      })
      return { kind: 'zip', info, password }
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 415) throw error
    }
  }
  // Non-ZIP archives retain full inspection and the short unchanged response.
  const cached = previous?.kind === 'full' && previous.password === password ? previous : undefined
  const result = await api<InspectResponse>(endpoint + '/inspect', {
    method: 'POST', body: JSON.stringify({ password: password || null, knownEtag: cached?.info.sourceEtag }), signal,
  })
  if ('unchanged' in result) {
    if (!cached || result.sourceEtag !== cached.info.sourceEtag) throw new Error(tr('The operation failed. Try again.'))
    return cached
  }
  return {
    kind: 'full', info: result, password,
    ids: archiveRenderIds(state.sourceId, result.entries.map(entry => entry.path)),
    directories: archiveDirectories(result.entries),
  }
}

function showArchiveLoading(state: ArchiveViewState, path: string, signal: AbortSignal): () => void {
  if (signal.aborted) return () => {}

  const request = { message: path === '/' ? tr('Opening archive…') : tr('Loading archive folder…') }
  archiveLoading.update(request)
  updateHeader(state)
  const finish = () => {
    signal.removeEventListener('abort', finish)
    // Files applies the returned folder before the next paint.
    requestAnimationFrame(() => {
      if (archiveLoading.request !== request) return
      archiveLoading.update()
      updateHeader(state)
    })
  }
  signal.addEventListener('abort', finish, { once: true })
  return finish
}

async function getContents(state: ArchiveViewState, path: string, signal: AbortSignal): Promise<{ folder: IFolder; contents: INode[] }> {
  const requestSignal = AbortSignal.any([signal, state.lifetime.signal])
  const finish = showArchiveLoading(state, path, requestSignal)
  try {
    return await readContents(state, path, requestSignal)
  } catch (error) {
    // The native error panel is only visible when the list has no rows.
    if (!requestSignal.aborted && !isAborted(error) && current(state) && archiveLoading.hasVisibleRows) {
      showError(errorMessage(error))
    }
    throw error
  } finally {
    finish()
  }
}

async function readContents(state: ArchiveViewState, path: string, signal: AbortSignal): Promise<{ folder: IFolder; contents: INode[] }> {
  if (state.invalid) throw new Error(tr('The archive changed. Open it again before continuing.'))
  const member = decodeArchiveDirectory(path)
  const lifetime = state.lifetime
  const password = state.password
  const cached = state.listing?.password === password ? state.listing : undefined
  const validated = state.validatedDirectory === member ? cached : undefined
  state.validatedDirectory = undefined
  const assertRequestCurrent = () => {
    if (signal.aborted || lifetime.signal.aborted || state.lifetime !== lifetime || state.password !== password) {
      throw new DOMException('Aborted', 'AbortError')
    }
  }
  let listing: ArchiveListing
  try {
    listing = validated ?? await loadArchiveListing(state, member, password, signal)
  } catch (error) {
    assertRequestCurrent()
    if (error instanceof ApiError && error.status === 409 && error.code === 'archive_changed') {
      state.password = ''; state.invalid = true; state.listing = undefined
      updateHeader(state)
    }
    if (error instanceof ApiError && error.status === 422 && current(state)
      && (error.code === 'password_required' || (password && state.listing?.kind !== 'zip'))) {
      state.passwordError = password ? error.message : undefined
      const locked = lockedContents(state, path)
      state.password = ''
      state.listing = undefined
      return locked
    }
    throw error
  }
  assertRequestCurrent()
  if (state.sourceEtag !== undefined && state.sourceEtag !== listing.info.sourceEtag) {
    state.password = ''; state.invalid = true
    state.listing = undefined
    updateHeader(state)
    throw new Error(tr('The archive changed. Open it again before continuing.'))
  }
  const directory = listing.kind === 'zip'
    ? { entry: listing.info.folder ?? undefined, children: listing.info.entries }
    : listing.directories.get(member)
  if (!directory || (directory.entry?.path ?? '') !== member || directory.entry?.type === 'file') {
    throw new Error(tr('The operation failed. Try again.'))
  }
  state.listing = listing
  state.sourceEtag = listing.info.sourceEtag
  const folder = archiveNode(state, directory.entry) as IFolder
  state.folder = folder
  state.locked = listing.info.encrypted && !password
  updateHeader(state)
  if (state.locked) {
    queueMicrotask(() => { void promptPending(state) })
    return { folder, contents: [] }
  }
  const contents = directory.children.map(entry => archiveNode(state, entry))
  queueMicrotask(() => { void promptPending(state) })
  return { folder, contents }
}

function ensureView(sourceId: string): ArchiveViewState {
  const id = archiveViewId(sourceId)
  const existing = states.get(id)
  if (existing) return existing
  const state = {
    sourceId, password: '', returnRoute: storedReturn(sourceId), generation: 0,
    memberTransfers: new Map(),
    invalid: false, locked: false, prompting: false, closing: false, pendingAction: null, lifetime: new AbortController(),
  } as ArchiveViewState
  state.view = {
    id, name: tr('Archive viewer'), icon: archiveIcon, hidden: true,
    emptyTitle: tr('This folder is empty.'), emptyCaption: tr('Browse archive contents and extract only what you need.'),
    getContents: (path, { signal }) => getContents(state, path, signal),
    columns: [],
  }
  states.set(id, state)
  getNavigation().register(state.view)
  return state
}

export async function connectNativeArchive(context: ActionContext): Promise<void> {
  const source = context.nodes[0]
  const sourceId = source?.id
  if (!sourceId || connectionPromptOpen || archiveContext(context)) return
  connectionPromptOpen = true
  const host = document.createElement('div')
  const sourceName = source.basename
  const etag: unknown = source.attributes.etag
  const expectedEtag = typeof etag === 'string' && etag.trim() !== '' ? etag : undefined
  try {
    const { showArchiveDialog } = await import('./archive-dialogs.ts')
    const result = await showArchiveDialog(document.body.appendChild(host), { kind: 'connect', sourceId, sourceName, expectedEtag })
    if (result?.kind === 'connect') {
      notifyConnected()
      if (context.folder.id === String(result.mount.parentId)) emit('files:node:updated', context.folder)
    }
  } catch (error) { if (!isAborted(error)) showError(errorMessage(error)) }
  finally { host.remove(); connectionPromptOpen = false }
}

export async function openNativeArchive(context: ActionContext, action: 'open' | 'extract'): Promise<void> {
  const sourceId = context.nodes[0]?.id
  if (!sourceId || isArchiveView(context.view) || context.nodes.some(isArchiveNode)) return
  const currentRouter = router()
  await enterArchive(sourceId, context.nodes[0].basename, {
    params: { ...currentRouter.params, view: context.view.id, fileid: sourceId },
    query: { ...currentRouter.query, dir: context.folder.path },
  }, action)
}

async function enterArchive(sourceId: string, sourceName: string, returnRoute: FilesReturnRoute, action: 'open' | 'extract'): Promise<void> {
  const state = ensureView(sourceId)
  state.lifetime.abort()
  state.lifetime = new AbortController()
  state.closing = false
  state.password = ''; state.listing = undefined; state.invalid = false; state.locked = false
  state.sourceEtag = undefined; state.validatedDirectory = undefined
  state.sourceName = sourceName
  state.passwordError = undefined
  state.generation++
  state.returnRoute = sanitizeFilesReturnRoute(returnRoute)
  rememberReturn(sourceId, state.returnRoute)
  state.pendingAction = action === 'open' ? null : action
  await router().goToRoute('filelist', { view: state.view.id }, { dir: '/' })
}

async function returnToFiles(state: ArchiveViewState): Promise<void> {
  // Navigation may publish the same active view again before the route settles.
  // Do not let those events reopen a password prompt we are leaving.
  state.closing = true
  state.password = ''; state.lifetime.abort(); state.pendingAction = null
  if (state.returnRoute) {
    const route = sanitizeFilesReturnRoute(state.returnRoute)
    const parent = archiveSourceId(route.params.view)
    if (parent) ensureView(parent)
    await router().goToRoute('filelist', route.params, route.query)
  } else if (state.listing) {
    window.location.assign(generateUrl('/f/' + state.listing.info.parentId))
  } else {
    await router().goToRoute('filelist', { view: 'files' }, { dir: '/' })
  }
}

async function showDialog(state: ArchiveViewState, kind: 'password' | 'extract' | 'connect', members: string[] = []): Promise<void> {
  if (!dialogHost?.isConnected || headerView !== state.view.id || !current(state) || state.prompting) return
  const info = state.listing?.info
  if (kind !== 'password' && (!info || info.mountedSource)) return
  const lifetime = state.lifetime
  const wasLocked = state.locked
  const host = dialogHost
  const member = decodeArchiveDirectory(state.folder?.path ?? '/')
  let validatedListing: ArchiveListing | undefined
  state.prompting = true
  try {
    const { showArchiveDialog } = await import('./archive-dialogs.ts')
    const staleBeforeMount = state.lifetime !== lifetime || !current(state) || dialogHost !== host || !host.isConnected
    if (staleBeforeMount) return
    const base = { sourceId: state.sourceId, password: state.password, signal: lifetime.signal }
    const result = kind === 'password' ? await showArchiveDialog(host, {
      ...base, kind, info, error: state.passwordError,
      validatePassword: async (password, signal) => {
        const listing = await loadArchiveListing(state, member, password, signal)
        if (state.sourceEtag !== undefined && listing.info.sourceEtag !== state.sourceEtag) {
          throw new Error(tr('The archive changed. Open it again before continuing.'))
        }
        validatedListing = listing
      },
    }) : info ? await showArchiveDialog(host, kind === 'extract'
      ? { ...base, kind, info, members } : { ...base, kind, info }) : null
    if (state.lifetime !== lifetime || !current(state)) return
    if (result?.kind === 'password' && validatedListing) {
      state.password = result.password
      state.listing = validatedListing
      state.sourceEtag = validatedListing.info.sourceEtag
      state.validatedDirectory = member
      state.passwordError = undefined
      state.locked = false
      if (state.folder) {
        // Files keeps a view root separately from its node map. Announce our
        // virtual root there first so its update never falls back to DAV stat.
        if (state.folder.path === '/') emit('files:node:created', state.folder)
        emit('files:node:updated', state.folder)
      }
    } else if (!result && kind === 'password' && (wasLocked || state.locked || !state.password)) {
      await returnToFiles(state)
    } else if (result?.kind === 'extract') {
      showSuccess(tr('Extraction queued. You can leave this page; the operation will continue in the background.'))
    } else if (result?.kind === 'connect') {
      notifyConnected()
    }
  } catch (error) { if (!isAborted(error)) showError(errorMessage(error)) }
  finally {
    if (state.lifetime === lifetime) {
      state.prompting = false; updateHeader(state)
      if (!state.locked) queueMicrotask(() => { void promptPending(state) })
    }
  }
}

async function promptPending(state: ArchiveViewState): Promise<void> {
  if (!current(state) || !dialogHost?.isConnected || headerView !== state.view.id || state.prompting) return
  if (state.locked) { await showDialog(state, 'password'); return }
  if (state.pendingAction && state.listing) {
    const action = state.pendingAction
    state.pendingAction = null
    await showDialog(state, action)
  }
}

function updateHeader(state: ArchiveViewState): void {
  if (!headerElement || headerView !== state.view.id) return
  const loading = archiveLoading.request
  const status = headerElement.querySelector<HTMLElement>('.archive-tools-files-loading')
  if (status) {
    const message = loading?.message ?? ''
    if (status.textContent !== message) status.textContent = message
  }
  const transfer = headerElement.querySelector<HTMLElement>('[data-archive-transfer]')
  if (transfer) {
    const message = [...state.memberTransfers.values()].at(-1) ?? ''
    if (transfer.textContent !== message) transfer.textContent = message
  }
  const title = headerElement.querySelector<HTMLElement>('[data-archive-name]')
  if (title) title.textContent = state.listing?.info.sourceName || state.sourceName || tr('Archive viewer')
  for (const button of headerElement.querySelectorAll<HTMLButtonElement>('button[data-archive-action]')) {
    button.disabled = !!loading || state.prompting || state.invalid || !state.listing
    button.hidden = !!state.listing?.info.mountedSource
    if (button.dataset.archiveAction === 'password') {
      button.hidden = !state.listing?.info.encrypted && !state.locked
      button.disabled = !!loading || state.prompting || state.invalid || (!state.listing?.info.encrypted && !state.locked)
      button.textContent = state.password ? tr('Change password') : tr('Enter password')
    }
  }
}

function renderHeader(element: HTMLElement, _folder: IFolder, view: IView): void {
  headerElement = element
  headerView = view.id
  element.replaceChildren()
  element.classList.add('archive-tools-files-header')
  const toolbar = document.createElement('div')
  toolbar.className = 'archive-tools-files-toolbar'
  const back = document.createElement('button')
  back.textContent = tr('Back to Files')
  back.addEventListener('click', () => { const state = states.get(headerView!); if (state) void returnToFiles(state).catch(error => showError(errorMessage(error))) })
  toolbar.append(back)
  const title = document.createElement('strong')
  title.dataset.archiveName = ''
  toolbar.append(title)
  for (const [kind, label] of [['extract', tr('Extract all')], ['connect', tr('Connect as folder')], ['password', tr('Enter password')]] as const) {
    const button = document.createElement('button')
    button.dataset.archiveAction = kind
    button.textContent = label
    button.addEventListener('click', () => { const state = states.get(headerView!); if (state) void showDialog(state, kind) })
    toolbar.append(button)
  }
  const operations = document.createElement('a')
  operations.href = generateUrl('/apps/archive_tools/')
  operations.textContent = tr('Operations')
  toolbar.append(operations)
  const status = document.createElement('div')
  status.className = 'archive-tools-files-loading'
  status.setAttribute('role', 'status')
  const transfer = document.createElement('div')
  transfer.className = 'archive-tools-files-transfer'
  transfer.dataset.archiveTransfer = ''
  transfer.setAttribute('role', 'status')
  dialogHost = document.createElement('div')
  element.append(toolbar, status, transfer, dialogHost)
  const state = states.get(view.id)
  if (state) { updateHeader(state); void promptPending(state) }
}

async function transferMember(state: ArchiveViewState, node: INode, open: boolean): Promise<void> {
  let key: string | undefined
  try {
    const [member] = selectedMembers(state, [node])
    const request = {
      sourceId: state.sourceId, member, expectedEtag: state.listing!.info.sourceEtag,
      password: state.password, signal: state.lifetime.signal,
    }
    const operationKey = state.generation + ':' + (open ? 'open:' : 'download:') + member
    if (state.memberTransfers.has(operationKey)) return
    key = operationKey
    state.memberTransfers.set(key, open
      ? tr('Opening {name}…', { name: node.basename })
      : tr('Preparing {name} for download…', { name: node.basename }))
    updateHeader(state)
    if (!open) {
      await downloadArchiveMember(request)
      return
    }
    const previewMime = snapshots.get(node)?.previewMime
    if (previewMime && canPreviewMember(previewMime)) {
      await previewArchiveMember(request, previewMime, node.id!)
      return
    }
    const opened = await materializeArchiveMember(request)
    request.signal.throwIfAborted()
    if (supportedArchiveName(node.basename, archiveFormats)) {
      const currentRouter = router()
      await enterArchive(opened.fileId, node.basename, {
        params: { ...currentRouter.params, view: state.view.id, fileid: node.id! },
        query: currentRouter.query,
      }, 'open')
    } else {
      window.location.assign(opened.url)
    }
  } catch (error) {
    if (!isAborted(error)) showError(errorMessage(error))
  } finally {
    if (key) {
      state.memberTransfers.delete(key)
      updateHeader(state)
    }
  }
}

function registerOwnAction(action: IFileAction): void { ownedActions.add(action.id); registerFileAction(action) }

export function initializeNativeArchives(): void {
  if (initialized) return
  initialized = true
  for (const action of getFileActions()) guardFileAction(action)
  for (const action of getFileListActions()) guardListAction(action)
  registerFileListFilter(archiveLoading)
  getFilesRegistry().addEventListener('register:action', event => guardFileAction(event.detail))
  getFilesRegistry().addEventListener('register:listAction', event => guardListAction(event.detail))
  registerFileListHeader({
    id: 'archive-tools-native-header', order: -100, enabled: (_folder, view) => isArchiveView(view), render: renderHeader,
    updated: (folder, view) => {
      if (headerView !== view.id && headerElement) renderHeader(headerElement, folder, view)
      const state = states.get(view.id)
      if (state) { updateHeader(state); void promptPending(state) }
    },
  })
  registerOwnAction({
    id: 'archive-tools-native-folder', displayName: () => t('files', 'Open folder'), iconSvgInline: () => archiveIcon,
    order: -110, default: DefaultType.HIDDEN,
    enabled: context => isArchiveView(context.view) && context.nodes.length === 1 && context.nodes[0].type === 'folder' && snapshots.has(context.nodes[0]),
    exec: async context => {
      const state = stateFor(context.view)!
      selectedMembers(state, context.nodes)
      await router().goToRoute('filelist', { view: state.view.id, fileid: context.nodes[0].id! }, { dir: context.nodes[0].path })
      return null
    },
  })
  registerOwnAction({
    id: 'archive-tools-native-preview', displayName: () => t('files', 'Open'), iconSvgInline: () => archiveIcon,
    order: -100, default: DefaultType.DEFAULT,
    enabled: context => isArchiveView(context.view) && context.nodes.length === 1 && context.nodes[0].type === 'file'
      && snapshots.has(context.nodes[0])
      && (supportedArchiveName(context.nodes[0].basename, archiveFormats) || hasViewerHandler(context.nodes[0].mime)),
    exec: async context => { await transferMember(stateFor(context.view)!, context.nodes[0], true); return null },
  })
  registerOwnAction({
    id: 'archive-tools-native-download', displayName: () => tr('Download'), iconSvgInline: () => archiveIcon,
    order: -90, default: DefaultType.DEFAULT,
    enabled: context => isArchiveView(context.view) && context.nodes.length === 1 && context.nodes[0].type === 'file' && snapshots.has(context.nodes[0]),
    exec: async context => { await transferMember(stateFor(context.view)!, context.nodes[0], false); return null },
  })
  registerOwnAction({
    id: 'archive-tools-native-extract', displayName: () => tr('Extract selected'), iconSvgInline: () => archiveIcon, order: 10,
    enabled: context => isArchiveView(context.view) && !stateFor(context.view)?.listing?.info.mountedSource
      && context.nodes.length > 0 && context.nodes.every(node => snapshots.has(node)),
    exec: async context => { const state = stateFor(context.view)!; await showDialog(state, 'extract', selectedMembers(state, context.nodes)); return null },
    execBatch: async context => { const state = stateFor(context.view)!; await showDialog(state, 'extract', selectedMembers(state, context.nodes)); return context.nodes.map(() => null) },
  })
  const id = new URL(window.location.href).pathname.match(/\/apps\/files\/(archive-tools-[1-9]\d*)(?:\/|$)/)?.[1]
  if (id) ensureView(archiveSourceId(id)!)
  let lastActiveViewId = getNavigation().active?.id ?? null
  getNavigation().addEventListener('updateActive', event => {
    const nextViewId = event.detail?.id ?? null
    const entering = nextViewId !== lastActiveViewId
    lastActiveViewId = nextViewId
    for (const state of states.values()) {
      if (nextViewId !== state.view.id) {
        state.closing = true
        state.listing = undefined; state.folder = undefined; state.sourceEtag = undefined; state.validatedDirectory = undefined
        state.password = ''; state.passwordError = undefined; state.lifetime.abort(); state.pendingAction = null; state.prompting = false
      } else if (entering) {
        state.closing = false
        if (state.lifetime.signal.aborted) state.lifetime = new AbortController()
      }
    }
  })
}
