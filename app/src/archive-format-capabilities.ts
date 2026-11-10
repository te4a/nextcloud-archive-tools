import { loadState } from '@nextcloud/initial-state'
import { normalizeArchiveFormats } from './archive-formats.ts'

// Legacy servers supported ZIP only. An explicit empty list remains empty.
export const archiveFormats = normalizeArchiveFormats(loadState<unknown>('archive_tools', 'archiveFormats', ['zip']))
