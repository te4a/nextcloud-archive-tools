import { getRequestToken } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import {
  DefaultType, File, Folder, Permission, getFileActions, getFileListActions,
  getFilesRegistry, getNavigation, registerFileAction, registerFileListHeader,
} from '@nextcloud/files'
import type { ActionContext, IFileAction, IFileListAction, IFolder, INode, IView, NodeData, ViewActionContext } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api, ApiError, errorMessage, isAborted } from './api.ts'
import {
  ARCHIVE_NODE_MARKER, archiveEtagIdentity, archiveRenderIds, archiveSourceId, archiveViewId, decodeArchiveDirectory,
  encodeArchivePath, lockedArchiveRenderId, readFilesReturnRoute, sanitizeFilesReturnRoute,
} from './native-archive-state.ts'
import type { FilesReturnRoute } from './native-archive-state.ts'
import type { ArchiveEntry, ArchiveInfo } from './types.ts'
import './native-files.css'

const tr = (text: string, values?: Record<string, string | number>) => t('archive_tools', text, values)
export const archiveIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M3 3h18v5h-1v13H4V8H3V3zm2 2v1h14V5H5zm1 3v11h12V8H6zm3 3h6v2H9v-2z"/></svg>'

interface FilesRouter {
  params: Record<string, string>
  query: FilesReturnRoute['query']
  goToRoute(name: string | null, params: Record<string, string>, query?: FilesReturnRoute['query'], replace?: boolean): Promise<unknown>
}
interface ArchiveSnapshot { sourceId: string; etag: string; member: string; type: 'file' | 'dir'; generation: number }
interface ArchiveViewState {
  sourceId: string; sourceName?: string; view: IView; password: string; info?: ArchiveInfo; folder?: IFolder
  returnRoute: FilesReturnRoute | null; ids: Map<string, number>; generation: number
  invalid: boolean; locked: boolean; prompting: boolean; closing: boolean; pendingAction: 'extract' | 'connect' | null
  passwordError?: string
  lifetime: AbortController
}

const states = new Map<string, ArchiveViewState>()
const snapshots = new WeakMap<INode, ArchiveSnapshot>()
const ownedActions = new Set<string>()
const guardedActions = new WeakSet<object>()
let headerElement: HTMLElement | null = null
let dialogHost: HTMLElement | null = null
let headerView: string | null = null
let initialized = false

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
  try { return readFilesReturnRoute(sessionStorage.getItem('archive-tools:return:' + sourceId)) } catch { return null }
}

function renderId(state: ArchiveViewState, member: string): number {
  const id = state.ids.get(member)
  if (id === undefined) throw new Error(tr('The operation failed. Try again.'))
  return id
}

function archiveNode(state: ArchiveViewState, entry?: ArchiveEntry): INode {
  const info = state.info!
  const root = generateUrl('/apps/archive_tools/virtual/' + state.sourceId + '/' + archiveEtagIdentity(info.sourceEtag))
  const member = entry?.path ?? ''
  const data = {
    id: renderId(state, member), source: window.location.origin + root + (member ? '/' + encodeArchivePath(member) : ''),
    root, owner: null, permissions: Permission.READ, displayname: member ? member.split('/').pop()! : info.sourceName,
    size: entry?.type === 'file' ? entry.size : 0,
    ...(entry?.mtime ? { mtime: new Date(entry.mtime * 1000) } : {}),
    attributes: { [ARCHIVE_NODE_MARKER]: true },
  }
  const node = !entry || entry.type === 'dir'
    ? new ArchiveFolder(data, member, info.sourceName)
    : new ArchiveFile({ ...data, mime: 'application/octet-stream' }, member, info.sourceName)
  snapshots.set(node, { sourceId: state.sourceId, etag: info.sourceEtag, member, type: entry?.type ?? 'dir', generation: state.generation })
  return node
}

function selectedMembers(state: ArchiveViewState, nodes: INode[]): string[] {
  if (state.invalid || state.locked || !current(state)) throw new Error(tr('The archive changed. Open it again before continuing.'))
  return nodes.map(node => {
    const snapshot = snapshots.get(node)
    if (!snapshot || snapshot.sourceId !== state.sourceId || snapshot.generation !== state.generation
      || snapshot.etag !== state.info?.sourceEtag || !snapshot.member) throw new Error(tr('The archive changed. Open it again before continuing.'))
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
  const name = state.info?.sourceName || state.sourceName || tr('Archive viewer')
  const folder = new ArchiveFolder({
    id: lockedArchiveRenderId(state.sourceId, router().params.fileid),
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

async function getContents(state: ArchiveViewState, path: string, signal: AbortSignal): Promise<{ folder: IFolder; contents: INode[] }> {
  if (state.invalid) throw new Error(tr('The archive changed. Open it again before continuing.'))
  let info: ArchiveInfo
  try {
    info = await api<ArchiveInfo>('/archives/' + state.sourceId + '/inspect', {
      method: 'POST', body: JSON.stringify({ password: state.password || null }), signal,
    })
  } catch (error) {
    if (error instanceof ApiError && error.status === 422 && current(state)
      && (error.code === 'password_required' || state.password)) {
      state.passwordError = state.password ? error.message : undefined
      state.password = ''
      return lockedContents(state, path)
    }
    throw error
  }
  if (signal.aborted) throw new DOMException('Aborted', 'AbortError')
  if (state.info && state.info.sourceEtag !== info.sourceEtag) {
    state.password = ''; state.invalid = true
    throw new Error(tr('The archive changed. Open it again before continuing.'))
  }
  state.info = info
  state.ids = archiveRenderIds(state.sourceId, info.entries.map(entry => entry.path))
  const member = decodeArchiveDirectory(path)
  const entry = member ? info.entries.find(candidate => candidate.path === member && candidate.type === 'dir') : undefined
  if (member && !entry) throw new Error(tr('The operation failed. Try again.'))
  const folder = archiveNode(state, entry) as IFolder
  state.folder = folder
  state.locked = info.encrypted && !state.password
  updateHeader(state)
  if (state.locked) {
    queueMicrotask(() => { void promptPending(state) })
    return { folder, contents: [] }
  }
  const prefix = member ? member + '/' : ''
  const contents = info.entries.filter(candidate => candidate.path.startsWith(prefix)
    && !candidate.path.slice(prefix.length).includes('/') && candidate.path !== member).map(candidate => archiveNode(state, candidate))
  queueMicrotask(() => { void promptPending(state) })
  return { folder, contents }
}

function ensureView(sourceId: string): ArchiveViewState {
  const id = archiveViewId(sourceId)
  const existing = states.get(id)
  if (existing) return existing
  const state = {
    sourceId, password: '', returnRoute: storedReturn(sourceId), ids: new Map(), generation: 0,
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

export async function openNativeArchive(context: ActionContext, action: 'open' | 'extract' | 'connect'): Promise<void> {
  const sourceId = context.nodes[0]?.id
  if (!sourceId || isArchiveView(context.view) || context.nodes.some(isArchiveNode)) return
  const state = ensureView(sourceId)
  state.lifetime.abort()
  state.lifetime = new AbortController()
  state.closing = false
  state.password = ''; state.info = undefined; state.invalid = false; state.locked = false
  state.sourceName = context.nodes[0].basename
  state.passwordError = undefined
  state.generation++; state.ids.clear()
  const currentRouter = router()
  state.returnRoute = sanitizeFilesReturnRoute({
    params: { ...currentRouter.params, view: context.view.id, fileid: sourceId },
    query: { ...currentRouter.query, dir: context.folder.path },
  })
  rememberReturn(sourceId, state.returnRoute)
  state.pendingAction = action === 'open' ? null : action
  await currentRouter.goToRoute('filelist', { view: state.view.id }, { dir: '/' })
}

async function returnToFiles(state: ArchiveViewState): Promise<void> {
  // Navigation may publish the same active view again before the route settles.
  // Do not let those events reopen a password prompt we are leaving.
  state.closing = true
  state.password = ''; state.lifetime.abort(); state.pendingAction = null
  if (state.returnRoute) {
    const route = sanitizeFilesReturnRoute(state.returnRoute)
    await router().goToRoute('filelist', route.params, route.query)
  } else if (state.info) {
    window.location.assign(generateUrl('/f/' + state.info.parentId))
  } else {
    await router().goToRoute('filelist', { view: 'files' }, { dir: '/' })
  }
}

async function showDialog(state: ArchiveViewState, kind: 'password' | 'extract' | 'connect', members: string[] = []): Promise<void> {
  if (!dialogHost?.isConnected || headerView !== state.view.id || !current(state) || state.prompting) return
  const lifetime = state.lifetime
  const wasLocked = state.locked
  const host = dialogHost
  state.prompting = true
  try {
    const { showArchiveDialog } = await import('./archive-dialogs.ts')
    const staleBeforeMount = state.lifetime !== lifetime || !current(state) || dialogHost !== host || !host.isConnected
    if (staleBeforeMount) return
    const result = await showArchiveDialog(host, {
      kind, sourceId: state.sourceId, info: state.info, password: state.password, members,
      error: kind === 'password' ? state.passwordError : undefined, signal: lifetime.signal,
    })
    if (state.lifetime !== lifetime || !current(state)) return
    if (result?.kind === 'password') {
      state.password = result.password
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
      showSuccess(tr('Archive connected. Participants who can read this folder and archive can access its contents.'))
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
  if (state.pendingAction && state.info) {
    const action = state.pendingAction
    state.pendingAction = null
    await showDialog(state, action)
  }
}

function updateHeader(state: ArchiveViewState): void {
  if (!headerElement || headerView !== state.view.id) return
  const title = headerElement.querySelector<HTMLElement>('[data-archive-name]')
  if (title) title.textContent = state.info?.sourceName || state.sourceName || tr('Archive viewer')
  for (const button of headerElement.querySelectorAll<HTMLButtonElement>('button[data-archive-action]')) {
    button.disabled = state.prompting || state.invalid || !state.info
    if (button.dataset.archiveAction === 'password') {
      button.hidden = !state.info?.encrypted && !state.locked
      button.disabled = state.prompting || state.invalid || (!state.info?.encrypted && !state.locked)
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
  dialogHost = document.createElement('div')
  element.append(toolbar, dialogHost)
  const state = states.get(view.id)
  if (state) { updateHeader(state); void promptPending(state) }
}

function downloadMember(state: ArchiveViewState, node: INode): void {
  const [member] = selectedMembers(state, [node])
  // A hidden same-origin download sink keeps JSON errors out of the Files
  // document and lets the browser stream attachments without a full-size Blob.
  const sink = document.createElement('iframe')
  sink.name = 'archive-tools-download-' + crypto.randomUUID()
  sink.hidden = true; sink.tabIndex = -1; sink.setAttribute('aria-hidden', 'true')
  let cleanupTimer: ReturnType<typeof setTimeout>
  const cleanup = (): void => {
    clearTimeout(cleanupTimer)
    state.lifetime.signal.removeEventListener('abort', cleanup)
    sink.remove()
  }
  sink.addEventListener('load', () => {
    const text = sink.contentDocument?.body?.textContent?.trim()
    if (!text) return // Initial about:blank; attachments are handed to the browser.
    try {
      const response = JSON.parse(text)
      showError(typeof response.message === 'string' ? response.message : tr('The operation failed. Try again.'))
    } catch { showError(tr('The operation failed. Try again.')) }
    cleanup()
  })
  state.lifetime.signal.addEventListener('abort', cleanup, { once: true })
  // Attachment navigation has no load/completion event. Retire its empty sink
  // after hand-off while the browser owns the download transfer.
  cleanupTimer = setTimeout(cleanup, 300000)
  document.body.append(sink)
  const form = document.createElement('form')
  form.method = 'POST'; form.action = generateUrl('/apps/archive_tools/archives/' + state.sourceId + '/download')
  form.target = sink.name; form.hidden = true
  for (const [name, value] of Object.entries({ member, expectedEtag: state.info!.sourceEtag, password: state.password, requesttoken: getRequestToken() || '' })) {
    const field = document.createElement('input')
    field.type = 'hidden'; field.name = name; field.value = value; form.append(field)
  }
  document.body.append(form); form.submit(); form.remove()
}

function registerOwnAction(action: IFileAction): void { ownedActions.add(action.id); registerFileAction(action) }

export function initializeNativeArchives(): void {
  if (initialized) return
  initialized = true
  for (const action of getFileActions()) guardFileAction(action)
  for (const action of getFileListActions()) guardListAction(action)
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
    id: 'archive-tools-native-download', displayName: () => tr('Download'), iconSvgInline: () => archiveIcon,
    order: -90, default: DefaultType.DEFAULT,
    enabled: context => isArchiveView(context.view) && context.nodes.length === 1 && context.nodes[0].type === 'file' && snapshots.has(context.nodes[0]),
    exec: async context => { downloadMember(stateFor(context.view)!, context.nodes[0]); return null },
  })
  registerOwnAction({
    id: 'archive-tools-native-extract', displayName: () => tr('Extract selected'), iconSvgInline: () => archiveIcon, order: 10,
    enabled: context => isArchiveView(context.view) && context.nodes.length > 0 && context.nodes.every(node => snapshots.has(node)),
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
        state.password = ''; state.passwordError = undefined; state.lifetime.abort(); state.pendingAction = null; state.prompting = false
      } else if (entering) {
        state.closing = false
        if (state.lifetime.signal.aborted) state.lifetime = new AbortController()
      }
    }
  })
}
