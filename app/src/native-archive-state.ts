/** Identity helpers for the Files view. These IDs are render-only, never file IDs for an API. */
export const ARCHIVE_VIEW_PREFIX = 'archive-tools-'
export const ARCHIVE_NODE_MARKER = 'archiveToolsVirtual'
const RENDER_ID_START = 8_000_000_000_000_000
const MAX_ARCHIVE_ENTRIES = 10000

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

/** Metadata may be encrypted, so retain only a safe view-local focus hint. */
export function lockedArchiveRenderId(sourceId: string, requestedId?: string): number {
  const rootId = archiveRenderIds(sourceId, []).get('')!
  const candidate = Number(requestedId)
  return Number.isSafeInteger(candidate) && candidate >= rootId && candidate <= rootId + MAX_ARCHIVE_ENTRIES
    ? candidate : rootId
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
