import type { ArchiveEntry } from './types.ts'

export function directoryEntries(entries: ArchiveEntry[], directory: string): ArchiveEntry[] {
  const prefix = directory ? directory + '/' : ''
  return entries.filter(entry => {
    if (!entry.path.startsWith(prefix)) return false
    const rest = entry.path.slice(prefix.length)
    return rest !== '' && !rest.includes('/')
  }).sort((left, right) => {
    if (left.type !== right.type) return left.type === 'dir' ? -1 : 1
    return left.path.localeCompare(right.path, undefined, { numeric: true })
  })
}

/** Send only parent-most checked members; a checked directory includes descendants. */
export function compactSelection(paths: Iterable<string>): string[] {
  const sorted = [...new Set(paths)].sort()
  return sorted.filter((path, index) => !sorted.slice(0, index).some(parent => path.startsWith(parent + '/')))
}

export function selectedFileCount(entries: ArchiveEntry[], paths: Iterable<string>): number {
  const selected = compactSelection(paths)
  return entries.filter(entry => entry.type === 'file'
    && selected.some(path => entry.path === path || entry.path.startsWith(path + '/'))).length
}

/** Explicit descendants let a user uncheck one file inside a checked folder. */
export function toggleArchiveSelection(entries: ArchiveEntry[], selected: ReadonlySet<string>, path: string): Set<string> {
  const entry = entries.find(candidate => candidate.path === path)
  const next = new Set(selected)
  if (!entry) return next
  const targets = entries.filter(candidate => candidate.path === path || (entry.type === 'dir' && candidate.path.startsWith(path + '/')))
  if (selected.has(path)) {
    for (const target of targets) next.delete(target.path)
    for (const candidate of entries) {
      if (candidate.type === 'dir' && path.startsWith(candidate.path + '/')) next.delete(candidate.path)
    }
  } else {
    for (const target of targets) next.add(target.path)
  }
  return next
}

export function jobProgress(done: number, total: number): number {
  if (!Number.isFinite(total) || total <= 0 || !Number.isFinite(done)) return 0
  return Math.max(0, Math.min(100, Math.round(done / total * 100)))
}
