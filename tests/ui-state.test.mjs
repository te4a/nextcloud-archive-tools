import test from 'node:test'
import assert from 'node:assert/strict'
import { compactSelection, directoryEntries, jobProgress, selectedFileCount, toggleArchiveSelection } from '../app/src/selection.ts'

const entries = [
  { path: 'a', type: 'dir' },
  { path: 'a/file.txt', type: 'file' },
  { path: 'ab', type: 'dir' },
  { path: 'ab/other.txt', type: 'file' },
  { path: 'readme.txt', type: 'file' },
]

test('directory listing respects path segment boundaries', () => {
  assert.deepEqual(directoryEntries(entries, 'a').map(entry => entry.path), ['a/file.txt'])
  assert.deepEqual(directoryEntries(entries, '').map(entry => entry.path), ['a', 'ab', 'readme.txt'])
})

test('selecting a directory includes descendants without selecting similar prefixes', () => {
  assert.deepEqual(compactSelection(['a', 'a/file.txt', 'ab/other.txt', 'a']), ['a', 'ab/other.txt'])
  assert.equal(selectedFileCount(entries, ['a']), 1)
  assert.equal(selectedFileCount(entries, ['a', 'a/file.txt', 'readme.txt']), 2)
})

test('progress values remain bounded for empty and inconsistent server counts', () => {
  assert.equal(jobProgress(50, 100), 50)
  assert.equal(jobProgress(1, 0), 0)
  assert.equal(jobProgress(110, 100), 100)
  assert.equal(jobProgress(-1, 100), 0)
})

test('unchecking a child of a selected directory excludes it from the submitted plan', () => {
  let selection = toggleArchiveSelection(entries, new Set(), 'a')
  assert.deepEqual(compactSelection(selection), ['a'])
  selection = toggleArchiveSelection(entries, selection, 'a/file.txt')
  assert.deepEqual(compactSelection(selection), [])
  selection = toggleArchiveSelection(entries, selection, 'ab/other.txt')
  assert.deepEqual(compactSelection(selection), ['ab/other.txt'])
})
