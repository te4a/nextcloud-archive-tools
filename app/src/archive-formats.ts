export const ARCHIVE_FORMATS = ['zip', 'tar', 'tar.gz', 'tar.bz2', 'tar.xz', '7z', 'rar'] as const
export type ArchiveFormat = typeof ARCHIVE_FORMATS[number]

const extensions: ReadonlyArray<readonly [string, ArchiveFormat]> = [
  ['.tar.bz2', 'tar.bz2'], ['.tar.gz', 'tar.gz'], ['.tar.xz', 'tar.xz'],
  ['.tbz2', 'tar.bz2'], ['.tgz', 'tar.gz'], ['.tbz', 'tar.bz2'], ['.txz', 'tar.xz'],
  ['.zip', 'zip'], ['.tar', 'tar'], ['.7z', '7z'], ['.rar', 'rar'],
]

/** Unknown or unavailable formats never become Files actions. */
export function normalizeArchiveFormats(value: unknown): ArchiveFormat[] {
  if (!Array.isArray(value)) return []
  return ARCHIVE_FORMATS.filter(format => value.includes(format))
}

export function supportedArchiveName(name: string, available: readonly string[]): boolean {
  const lowerName = name.toLowerCase()
  const match = extensions.find(([suffix]) => lowerName.endsWith(suffix))
  return match !== undefined && available.includes(match[1])
}

/** Remove a complete compound extension, retaining the spelling of the name. */
export function archiveBaseName(name: string): string {
  const lowerName = name.toLowerCase()
  const match = extensions.find(([suffix]) => lowerName.endsWith(suffix))
  return match ? name.slice(0, -match[0].length) || name : name
}

export function archiveFormatLabel(format: string): string {
  return format === '7z' ? '7z' : format.toUpperCase()
}
