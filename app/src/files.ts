import { registerFileAction, DefaultType, Permission } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { archiveIcon, initializeNativeArchives, isArchiveNode, isArchiveView, openNativeArchive } from './native-files.ts'
import { archiveFormats } from './archive-format-capabilities.ts'
import { supportedArchiveName } from './archive-formats.ts'
import type { Settings } from './types.ts'

const settings = loadState<Settings>('archive_tools', 'settings', { openByClick: true, idleTimeoutMinutes: 60, allowPin: true, jobsRetentionDays: 30 })
initializeNativeArchives()

for (const action of ['open', 'extract', 'connect'] as const) {
  registerFileAction({
    id: 'archive-tools-' + action,
    displayName: () => action === 'open'
      ? t('archive_tools', 'Open archive')
      : action === 'extract' ? t('archive_tools', 'Extract archive') : t('archive_tools', 'Connect archive'),
    iconSvgInline: () => archiveIcon,
    order: action === 'open' ? -80 : 15,
    ...(action === 'open' && settings.openByClick ? { default: DefaultType.DEFAULT } : {}),
    enabled: ({ nodes, view }) => !isArchiveView(view) && !nodes.some(isArchiveNode) && nodes.length === 1
      && nodes[0].type === 'file'
      && nodes[0].isDavResource
      && nodes[0].id !== undefined
      && supportedArchiveName(nodes[0].basename, archiveFormats)
      && (nodes[0].permissions & Permission.READ) !== 0,
    exec: async context => {
      const { nodes } = context
      const id = nodes[0].id
      if (id === undefined) return false
      await openNativeArchive(context, action)
      return null
    },
  })
}
