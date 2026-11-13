import type { ArchiveEntry } from './types.ts'

/** Identity helpers for the Files view. These IDs are render-only, never file IDs for an API. */
export const ARCHIVE_VIEW_PREFIX = 'archive-tools-'
export const ARCHIVE_NODE_MARKER = 'archiveToolsVirtual'
const RENDER_ID_START = 8_000_000_000_000_000
// This must cover the largest server-side ZIP index, including implicit folders.
// Even the largest 32-bit hash and last member stay within safe integer range.
const MAX_ARCHIVE_ENTRIES = 120_000
const ZIP_RENDER_ID_START = 7_700_000_000_000_000
// The ZIP reader derives each ordinal from its first raw record and path depth.
const MAX_ZIP_RENDER_INDEX = MAX_ARCHIVE_ENTRIES * 33

export function archiveViewId(sourceId: string): string {
  if (!/^[1-9]\d*$/.test(sourceId)) throw new Error('Invalid source archive ID')
  return ARCHIVE_VIEW_PREFIX + sourceId
}

export function archiveSourceId(viewId: string): string | null {
  const sourceId = viewId.slice(ARCHIVE_VIEW_PREFIX.length)
  return viewId.startsWith(ARCHIVE_VIEW_PREFIX) && /^[1-9]\d*$/.test(sourceId) ? sourceId : null
}

/** Build the complete view-local namespace before browsing any directory. */
export function archiveRenderIds(sourceId: string, paths: Iterable<string>): Map<string, number> {
  archiveViewId(sourceId)
  const members = [...new Set(paths)].filter(path => path !== '').sort()
  if (members.length > MAX_ARCHIVE_ENTRIES) throw new Error('Too many archive render IDs')
  const namespace = Number.parseInt(archiveEtagIdentity(sourceId), 16)
  const base = RENDER_ID_START + namespace * (MAX_ARCHIVE_ENTRIES + 1)
  return new Map([['', base], ...members.map((path, index): [string, number] => [path, base + index + 1])])
}

/** Stable across directory requests, including an implicit folder's own listing. */
export function zipRenderId(sourceId: string, index: number): number {
  archiveViewId(sourceId)
  if (!Number.isInteger(index) || index < 0 || index > MAX_ZIP_RENDER_INDEX) throw new Error('Invalid ZIP render index')
  // The 26-bit namespace keeps ZIP IDs below the range used by full listings.
  const namespace = Number.parseInt(archiveEtagIdentity(sourceId), 16) & 0x3ffffff
  return ZIP_RENDER_ID_START + namespace * (MAX_ZIP_RENDER_INDEX + 1) + index
}

/** Metadata may be encrypted, so retain only a safe view-local focus hint. */
export function lockedArchiveRenderId(sourceId: string, requestedId?: string, preferZip = false): number {
  const rootId = archiveRenderIds(sourceId, []).get('')!
  const zipRootId = zipRenderId(sourceId, 0)
  const candidate = Number(requestedId)
  if (Number.isSafeInteger(candidate) && (candidate >= rootId && candidate <= rootId + MAX_ARCHIVE_ENTRIES
    || candidate >= zipRootId && candidate <= zipRootId + MAX_ZIP_RENDER_INDEX)) return candidate
  return preferZip ? zipRootId : rootId
}

interface ArchiveDirectory {
  entry?: ArchiveEntry
  children: ArchiveEntry[]
}

/** Group immediate children once so opening a folder costs only its own listing. */
export function archiveDirectories(entries: ArchiveEntry[]): ReadonlyMap<string, ArchiveDirectory> {
  const directories = new Map<string, ArchiveDirectory>()
  function directory(path: string): ArchiveDirectory {
    let result = directories.get(path)
    if (!result) {
      result = { children: [] }
      directories.set(path, result)
    }
    return result
  }
  directory('')
  for (const entry of entries) {
    const slash = entry.path.lastIndexOf('/')
    directory(slash === -1 ? '' : entry.path.slice(0, slash)).children.push(entry)
    if (entry.type === 'dir') directory(entry.path).entry = entry
  }
  return directories
}

/** Encode each member component: a literal percent must survive Router and URL parsing. */
export function encodeArchivePath(member: string): string {
  return member.split('/').map(encodeURIComponent).join('/')
}

export function decodeArchiveDirectory(directory: string): string {
  if (directory === '/' || directory === '') return ''
  return directory.replace(/^\/+|\/+$/g, '').split('/').map(component => {
    // Vue Router already decoded the query. A literal "%2F" is a filename,
    // not another URL layer to decode into a path separator.
    if (!component || component === '.' || component === '..' || /[\/\\\0]/.test(component)) throw new Error('Invalid archive directory')
    return component
  }).join('/')
}

/** Only an identity suffix; the complete ETag remains the server-side concurrency guard. */
export function archiveEtagIdentity(etag: string): string {
  let hash = 2166136261
  for (const character of etag) hash = Math.imul(hash ^ character.charCodeAt(0), 16777619)
  return (hash >>> 0).toString(16).padStart(8, '0')
}

export interface FilesReturnRoute {
  params: Record<string, string>
  query: Record<string, string | (string | null)[] | null | undefined>
}

/** Files treats the presence of these keys as an instruction, even if undefined. */
export function sanitizeFilesReturnRoute(route: FilesReturnRoute): FilesReturnRoute {
  const query = { ...route.query }
  delete query.openfile
  delete query.opendetails
  return { params: { ...route.params }, query }
}

/** Never deserialize an arbitrary redirect URL or any archive password. */
export function readFilesReturnRoute(serialized: string | null): FilesReturnRoute | null {
  if (!serialized) return null
  try {
    const route = JSON.parse(serialized)
    if (!route || typeof route !== 'object' || !route.params || !route.query
      || typeof route.params.view !== 'string' || archiveSourceId(route.params.view)
      || !/^[a-zA-Z0-9_-]+$/.test(route.params.view)) return null
    if (Object.values(route.params).some(value => typeof value !== 'string')) return null
    if (Object.values(route.query).some(value => value !== null && typeof value !== 'string'
      && (!Array.isArray(value) || value.some(item => item !== null && typeof item !== 'string')))) return null
    return sanitizeFilesReturnRoute({ params: route.params, query: route.query })
  } catch { return null }
}
