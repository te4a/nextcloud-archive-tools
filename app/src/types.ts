export interface Settings {
  openByClick: boolean
  idleTimeoutMinutes: number
  allowPin: boolean
  jobsRetentionDays: number
}

export interface ArchiveEntry {
  path: string
  type: 'file' | 'dir'
  size: number
  mtime: number
  encrypted: boolean
}

export interface ArchiveInfo {
  sourceId: number
  sourceName: string
  parentId: number
  sourceEtag: string
  format: string
  fileCount: number
  directoryCount: number
  totalSize: number
  compressedSize: number
  encrypted: boolean
  entries: ArchiveEntry[]
}

export interface Mount {
  id: number
  sourceId: number
  parentId: number
  name: string
  path?: string
  sourceName?: string
  available?: boolean
  state?: 'ready' | 'source_changed'
  createdBy: string
  pinned: boolean
  canManage?: boolean
  lastAccess: number
  fileCount: number
  totalSize: number
}

export interface Job {
  id: number
  status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled'
  sourceId: number
  sourceName: string
  destinationId: number
  folderName: string
  filesTotal: number
  filesDone: number
  bytesTotal: number
  bytesDone: number
  createdAt: number
  updatedAt: number
  targetId: number | null
  message: string | null
  cancelRequested: boolean
}

/** The selection is loaded on demand when preparing another extraction. */
export interface JobDetails extends Job {
  members: string[]
}
