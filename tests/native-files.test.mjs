import test from 'node:test'
import assert from 'node:assert/strict'
import { archiveRenderIds, archiveSourceId, archiveViewId, decodeArchiveDirectory, encodeArchivePath, lockedArchiveRenderId, readFilesReturnRoute, sanitizeFilesReturnRoute } from '../app/src/native-archive-state.ts'

test('archive views accept only positive source IDs', () => {
  assert.equal(archiveSourceId(archiveViewId('123')), '123')
  for (const value of ['files', 'archive-tools-', 'archive-tools-0', 'archive-tools-../123', 'archive-tools-12x']) assert.equal(archiveSourceId(value), null)
  assert.throws(() => archiveViewId('../12'))
})

test('archive member identity survives percent, fragment and Unicode URL components', () => {
  for (const path of ['a/b.txt', 'Папка/a#b?.txt', 'space folder/a file.txt', 'literal%2F/name%.txt', 'literal%00/%5c/%ZZ']) {
    const route = new URL('https://cloud.example/apps/files/archive-tools-123')
    route.searchParams.set('dir', '/' + path)
    assert.equal(decodeArchiveDirectory(route.searchParams.get('dir')), path)
    const identity = new URL('https://cloud.example/virtual/123/etag/' + encodeArchivePath(path))
    assert.equal(identity.hash, '')
    assert.equal(identity.search, '')
    assert.equal(identity.pathname.split('/').slice(4).map(decodeURIComponent).join('/'), path)
  }
  for (const path of ['/../secret', '/a/./secret', '/a//secret', '/a/\0', '/a/\\']) assert.throws(() => decodeArchiveDirectory(path))
})

test('render IDs are unique safe integers in a separate high range', () => {
  const members = Array.from({ length: 10000 }, (_, index) => 'file-' + index)
  const ids = [...archiveRenderIds('5682', members).values()]
  assert.equal(new Set(ids).size, ids.length)
  assert.ok(ids.every(id => Number.isSafeInteger(id) && id >= 8_000_000_000_000_000))
  assert.throws(() => archiveRenderIds('5682', [...members, 'one-too-many']))
})

test('nested reload and root-first navigation use the same complete archive identity map', () => {
  const members = ['Данные', 'Данные/вложенная папка', 'Данные/вложенная папка/file%2F#.txt', 'readme.txt']
  const rootFirst = archiveRenderIds('5682', members)
  const nestedFirst = archiveRenderIds('5682', [...members].reverse())
  assert.deepEqual(nestedFirst, rootFirst)
  assert.equal(nestedFirst.get('Данные/вложенная папка'), rootFirst.get('Данные/вложенная папка'))
  assert.notEqual(archiveRenderIds('5683', members).get(''), rootFirst.get(''))
  assert.equal(rootFirst.get('Данные'), rootFirst.get('') + 2)
})

test('encrypted-header placeholders retain only focus hints within their own render namespace', () => {
  const rootId = archiveRenderIds('5682', []).get('')
  assert.equal(lockedArchiveRenderId('5682', String(rootId + 42)), rootId + 42)
  for (const hint of [undefined, '5682', String(rootId - 1), String(rootId + 10001), 'NaN']) {
    assert.equal(lockedArchiveRenderId('5682', hint), rootId)
  }
})

test('return navigation accepts route data and rejects redirect URLs or archive loops', () => {
  const route = { params: { view: 'files', fileid: '123' }, query: { dir: '/Общее' } }
  assert.deepEqual(readFilesReturnRoute(JSON.stringify(route)), route)
  for (const value of ['https://evil.example', '{', JSON.stringify({ params: { view: 'archive-tools-123' }, query: {} }), JSON.stringify({ params: { view: 'files' }, query: { dir: { password: 'secret' } } })]) assert.equal(readFilesReturnRoute(value), null)
})

test('immediate and restored return routes remove own auto-open keys instead of setting undefined', () => {
  const captured = {
    params: { view: 'files', fileid: '8793' },
    query: { dir: '/Общее', openfile: undefined, opendetails: 'true', filter: 'documents' },
  }
  assert.ok(Object.hasOwn(captured.query, 'openfile'))
  const immediate = sanitizeFilesReturnRoute(captured)
  for (const key of ['openfile', 'opendetails']) {
    assert.equal(Object.hasOwn(immediate.query, key), false)
    assert.equal(key in immediate.query, false)
  }
  assert.deepEqual(immediate, { params: captured.params, query: { dir: '/Общее', filter: 'documents' } })
  assert.ok(Object.hasOwn(captured.query, 'openfile'), 'The captured router query is not mutated')
  assert.deepEqual(readFilesReturnRoute(JSON.stringify(immediate)), immediate)
  assert.deepEqual(readFilesReturnRoute(JSON.stringify({ ...captured, query: { ...captured.query, openfile: 'true' } })), immediate)
})
