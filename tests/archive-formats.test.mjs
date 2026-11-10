import test from 'node:test'
import assert from 'node:assert/strict'
import { ARCHIVE_FORMATS, archiveBaseName, archiveFormatLabel, normalizeArchiveFormats, supportedArchiveName } from '../app/src/archive-formats.ts'

test('only available server formats and their aliases become archive candidates', () => {
  for (const name of ['a.zip', 'a.TAR', 'a.tar.gz', 'a.TGZ', 'a.tar.bz2', 'a.tbz', 'a.TBZ2', 'a.tar.xz', 'a.txz', 'a.7z', 'a.RAR']) {
    assert.equal(supportedArchiveName(name, ARCHIVE_FORMATS), true, name)
  }
  for (const name of ['a.gz', 'a.bz2', 'a.xz', 'a.tar.gz.txt', 'a.zip.exe', 'a.7z.001']) assert.equal(supportedArchiveName(name, ARCHIVE_FORMATS), false, name)
  assert.equal(supportedArchiveName('a.tgz', ['tar.gz']), true)
  assert.equal(supportedArchiveName('a.tar.gz', ['tar']), false)
  assert.equal(supportedArchiveName('a.rar', ['zip', 'tar']), false)
  assert.equal(supportedArchiveName('a.zip', []), false)
})

test('capability input cannot advertise unknown or missing formats', () => {
  assert.deepEqual(normalizeArchiveFormats(['rar', 'zip', 'zip', 'exe', null]), ['zip', 'rar'])
  assert.deepEqual(normalizeArchiveFormats([]), [])
  assert.deepEqual(normalizeArchiveFormats(null), [])
  assert.deepEqual(normalizeArchiveFormats('zip'), [])
})

test('suggested folder names remove complete compound suffixes while preserving names', () => {
  for (const suffix of ['.ZIP', '.tar', '.TAR.GZ', '.tgz', '.tar.bz2', '.tbz', '.tbz2', '.tar.xz', '.txz', '.7z', '.rar']) {
    assert.equal(archiveBaseName('Данные.backup' + suffix), 'Данные.backup')
  }
  assert.equal(archiveBaseName('report.gz'), 'report.gz')
  assert.equal(archiveBaseName('.tar.gz'), '.tar.gz')
  assert.equal(archiveFormatLabel('tar.xz'), 'TAR.XZ')
  assert.equal(archiveFormatLabel('7z'), '7z')
})
