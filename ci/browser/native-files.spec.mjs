import { test, expect } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const fixture = JSON.parse(readFileSync(process.env.ARCHIVE_TOOLS_BROWSER_FIXTURE, 'utf8'))
const nativeView = '/archive-tools-' + fixture.sourceId
const row = (page, name) => page.locator('[data-cy-files-list-row][data-cy-files-list-row-name=' + JSON.stringify(name) + ']')
const openRow = (page, name) => row(page, name).locator('[data-cy-files-list-row-name-link]').click()
const currentDir = page => new URL(page.url()).searchParams.get('dir') || '/'

test.beforeEach(async ({ page, context }) => {
  // This browser is unrelated to the user's browser and cannot reach a remote origin.
  await context.route('**/*', route => {
    const url = new URL(route.request().url())
    return url.origin === fixture.baseUrl || ['data:', 'blob:'].includes(url.protocol)
      ? route.continue() : route.abort('blockedbyclient')
  })
  await page.goto('/index.php/login')
  await page.locator('input[name="user"]').fill(fixture.user)
  await page.locator('input[name="password"]').fill(fixture.password)
  await page.getByRole('button', { name: /^Log in$/i }).click()
  await page.waitForURL(url => !url.pathname.endsWith('/login'))
  await page.goto('/index.php/apps/files/files/' + fixture.folderId + '?dir=' + encodeURIComponent('/CI Archives'))
  await expect(row(page, 'Browser.zip')).toBeVisible()
})

test('native archive navigation, reload, literal names, download and return', async ({ page }) => {
  const archiveEndpoint = '/archives/' + fixture.sourceId
  const zipListing = '**' + archiveEndpoint + '/browse-zip'
  let finishRoot, finishFolder
  const waitForRoot = new Promise(resolve => { finishRoot = resolve })
  const waitForFolder = new Promise(resolve => { finishFolder = resolve })
  await page.route(zipListing, async route => {
    const { path } = route.request().postDataJSON()
    if (path === '') await waitForRoot
    if (path === 'Nested Юникод') await waitForFolder
    await route.continue()
  })
  const loading = page.locator('.archive-tools-files-loading[role=status]')
  let fullInspections = 0
  page.on('request', request => {
    if (request.url().endsWith(archiveEndpoint + '/inspect')) fullInspections++
  })
  const rootListing = page.waitForResponse(response => response.url().endsWith(archiveEndpoint + '/browse-zip')
    && response.request().postDataJSON().path === '')
  await openRow(page, 'Browser.zip')
  await expect(page).toHaveURL(url => url.pathname.includes(nativeView))
  await expect(loading).toHaveText('Opening archive…')
  await expect(row(page, 'Browser.zip')).toBeHidden()
  await expect(page.locator('[data-cy-files-navigation-item="files"]')).toBeVisible()
  finishRoot()
  await expect(page.getByRole('button', { name: 'Back to Files', exact: true })).toBeVisible()
  const root = await (await rootListing).json()
  expect(root.entries.every(entry => !entry.path.includes('/'))).toBe(true)
  await expect(loading).toBeHidden()
  const folderListing = page.waitForResponse(response => response.url().endsWith(archiveEndpoint + '/browse-zip')
    && response.request().postDataJSON().path === 'Nested Юникод')
  await openRow(page, 'Nested Юникод')
  await expect.poll(() => currentDir(page)).toBe('/Nested Юникод')
  await expect(loading).toHaveText('Loading archive folder…')
  await expect(row(page, 'README.txt')).toBeHidden()
  finishFolder()
  await expect(row(page, 'notes.txt')).toBeVisible()
  await expect(loading).toBeHidden()
  const folder = await folderListing
  expect(folder.request().postDataJSON().expectedEtag).toBe(root.sourceEtag)
  expect((await folder.json()).entries.map(entry => entry.path)).toContain('Nested Юникод/notes.txt')
  await page.reload()
  await expect(row(page, 'notes.txt')).toBeVisible()
  await expect(page.locator('[data-cy-files-navigation-item="files"]')).toBeVisible()
  await expect(page.getByText('The file could not be found', { exact: true })).toHaveCount(0)
  await page.goBack()
  await expect(row(page, 'README.txt')).toBeVisible()
  await openRow(page, 'Знаки %2F #?')
  await expect.poll(() => currentDir(page)).toBe('/Знаки %2F #?')
  await expect(row(page, 'literal.txt')).toBeVisible()
  await page.reload()
  await expect(row(page, 'literal.txt')).toBeVisible()
  await page.goBack()
  await expect(row(page, 'README.txt')).toBeVisible()
  const downloadPromise = page.waitForEvent('download', { timeout: 20_000 })
  await openRow(page, 'README.txt')
  const download = await downloadPromise
  expect(download.suggestedFilename()).toBe('README.txt')
  const chunks = []
  for await (const part of await download.createReadStream()) chunks.push(part)
  expect(Buffer.concat(chunks).toString('utf8')).toBe('Archive Tools browser fixture\n')
  expect(new URL(page.url()).pathname).toContain(nativeView)
  await page.screenshot({ path: join(fixture.outputDirectory, 'native-files.png') })
  await page.getByRole('button', { name: 'Back to Files', exact: true }).click()
  await expect(row(page, 'Browser.zip')).toBeVisible()
  expect(currentDir(page)).toBe('/CI Archives')
  expect(new URL(page.url()).searchParams.has('openfile')).toBe(false)
  expect(fullInspections).toBe(0)
})

test('leaving a loading archive restores the regular file list', async ({ page }) => {
  let releaseResponse
  const delayedResponse = new Promise(resolve => { releaseResponse = resolve })
  const endpoint = '**/archives/' + fixture.sourceId + '/browse-zip'
  await page.route(endpoint, async route => {
    await delayedResponse
    // Leaving the view can abort the intercepted request before the gate opens.
    await route.abort().catch(error => {
      if (!error.message.includes('Route is already handled')) throw error
    })
  })

  try {
    await openRow(page, 'Browser.zip')
    const loading = page.locator('.archive-tools-files-loading[role=status]')
    await expect(loading).toHaveText('Opening archive…')
    await page.goBack({ waitUntil: 'commit' })
    await expect(loading).toBeHidden()
    await expect(row(page, 'Browser.zip')).toBeVisible()
    expect(currentDir(page)).toBe('/CI Archives')
  } finally {
    releaseResponse()
    await page.unrouteAll({ behavior: 'wait' })
  }
})

test('selection, native destination picker, queued extraction and pinned connection', async ({ page }) => {
  await openRow(page, 'Browser.zip')
  // NcCheckboxRadioSwitch paints its label over the native input. Keyboard
  // selection exercises the accessible control without force-clicking through it.
  const selected = row(page, 'Nested Юникод').getByRole('checkbox')
  await selected.press('Space')
  await expect(selected).toBeChecked()
  await expect(page.locator('[data-cy-files-list-selection-action="delete"]')).toHaveCount(0)
  await expect(page.locator('[data-cy-files-list-selection-action="moveOrCopy"]')).toHaveCount(0)
  await page.getByRole('button', { name: /^Extract selected/ }).click()
  const extract = page.getByRole('dialog', { name: 'Extract archive', exact: true })
  await expect(extract).toBeVisible()
  await extract.getByRole('button', { name: 'Choose folder', exact: true }).click()
  const picker = page.getByRole('dialog', { name: 'Choose destination folder', exact: true })
  await expect(picker).toBeVisible()
  await picker.locator('[data-testid="file-list-row"][data-filename="CI Destination"]').click()
  await picker.getByRole('button', { name: 'Choose folder', exact: true }).click()
  await expect(extract).toBeVisible()
  await expect(extract.getByText('/CI Destination', { exact: true })).toBeVisible()
  await extract.getByLabel('New folder name', { exact: true }).fill('Selected output')
  const queued = page.waitForResponse(response => response.url().endsWith('/archives/' + fixture.sourceId + '/extract') && response.request().method() === 'POST')
  await extract.getByRole('button', { name: 'Start extraction', exact: true }).click()
  const response = await queued
  expect(response.status()).toBe(202)
  const job = await response.json()
  expect(String(job.destinationId)).toBe(String(fixture.destinationId))
  expect(job.status).toBe('queued')
  expect(job.folderName).toBe('Selected output')
  await expect(extract).not.toBeVisible()
  await page.getByRole('button', { name: 'Connect as folder', exact: true }).click()
  const connect = page.getByRole('dialog', { name: 'Connect as folder', exact: true })
  await connect.getByLabel('New folder name', { exact: true }).fill('Pinned connection')
  await connect.getByRole('checkbox', { name: 'Keep connected until manually disconnected' }).check()
  const connected = page.waitForResponse(response => response.url().endsWith('/mounts') && response.request().method() === 'POST')
  await connect.getByRole('button', { name: 'Connect archive', exact: true }).click()
  const mount = await connected
  expect(mount.status()).toBe(201)
  expect((await mount.json()).pinned).toBe(true)
  await expect(connect).not.toBeVisible()
  await page.getByRole('link', { name: 'Operations', exact: true }).click()
  await expect(page.locator('#at-job-' + job.id)).toContainText('Waiting for background processing')
  await page.getByRole('button', { name: /^Connected archives/ }).click()
  const card = page.locator('.at-card').filter({ has: page.getByRole('heading', { name: 'Pinned connection', exact: true }) })
  await expect(card.getByText('Pinned', { exact: true })).toBeVisible()
  await page.screenshot({ path: join(fixture.outputDirectory, 'pinned-connection.png') })
})

test('encrypted headers, wrong password, unlock, reload and cancellation return', async ({ page }) => {
  await openRow(page, 'Protected.7z')
  const password = page.getByRole('dialog', { name: 'Archive password', exact: true })
  await expect(password).toBeVisible()
  await expect(row(page, 'one.txt')).toHaveCount(0)
  await password.getByLabel('Password', { exact: true }).fill('incorrect-public-fixture-password')
  await password.getByRole('button', { name: 'Unlock archive', exact: true }).click()
  await expect(password.getByRole('alert')).toContainText(/incorrect|damaged/i)
  await password.getByLabel('Password', { exact: true }).fill(fixture.archivePassword)
  await password.getByRole('button', { name: 'Unlock archive', exact: true }).click()
  await expect(password).not.toBeVisible()
  await expect(row(page, 'one.txt')).toBeVisible()
  expect(page.url()).not.toContain(fixture.archivePassword)
  const saved = await page.evaluate(() => JSON.stringify(sessionStorage))
  expect(saved).not.toContain(fixture.archivePassword)
  await page.reload()
  await expect(password).toBeVisible()
  await password.getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(row(page, 'Protected.7z')).toBeVisible()
  expect(currentDir(page)).toBe('/CI Archives')
  await expect(password).not.toBeVisible()
  await expect(page).not.toHaveURL(url => url.pathname.includes('/archive-tools-'))
  expect(new URL(page.url()).searchParams.has('openfile')).toBe(false)
})
