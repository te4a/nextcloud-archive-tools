import { test, expect } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const fixture = JSON.parse(readFileSync(process.env.ARCHIVE_TOOLS_BROWSER_FIXTURE, 'utf8'))
const nativeView = '/archive-tools-' + fixture.sourceId
const row = (page, name) => page.locator('[data-cy-files-list-row][data-cy-files-list-row-name=' + JSON.stringify(name) + ']')
const openRow = (page, name) => row(page, name).locator('[data-cy-files-list-row-name-link]').click()
const currentDir = page => new URL(page.url()).searchParams.get('dir') || '/'
async function downloadRow(page, name) {
  await row(page, name).click({ button: 'right' })
  await page.getByRole('menuitem', { name: 'Download', exact: true }).click()
}

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
  let finishDownload
  const waitForDownload = new Promise(resolve => { finishDownload = resolve })
  await page.route('**' + archiveEndpoint + '/download', async route => {
    await waitForDownload
    await route.continue()
  })
  const downloadPromise = page.waitForEvent('download', { timeout: 20_000 })
  await downloadRow(page, 'README.txt')
  const transferStatus = page.locator('[data-archive-transfer]')
  try {
    await expect(transferStatus).toHaveText('Preparing README.txt for download…')
  } finally {
    finishDownload()
  }
  const download = await downloadPromise
  expect(download.suggestedFilename()).toBe('README.txt')
  const chunks = []
  for await (const part of await download.createReadStream()) chunks.push(part)
  expect(Buffer.concat(chunks).toString('utf8')).toBe('Archive Tools browser fixture\n')
  await expect(transferStatus).toBeHidden()
  expect(new URL(page.url()).pathname).toContain(nativeView)
  await page.screenshot({ path: join(fixture.outputDirectory, 'native-files.png') })
  await page.getByRole('button', { name: 'Back to Files', exact: true }).click()
  await expect(row(page, 'Browser.zip')).toBeVisible()
  expect(currentDir(page)).toBe('/CI Archives')
  expect(new URL(page.url()).searchParams.has('openfile')).toBe(false)
  expect(fullInspections).toBe(0)
})

test('a file without a handler offers Download and downloads on click', async ({ page }) => {
  await openRow(page, 'Browser.zip')
  const name = 'Unsupported.archive-test-data'
  const file = row(page, name)
  await expect(file.locator('[data-cy-files-list-row-name-link]')).toHaveAccessibleName('Download: ' + name)
  await file.click({ button: 'right' })
  await expect(page.getByRole('menuitem', { name: 'Download', exact: true })).toBeVisible()
  await expect(page.getByRole('menuitem', { name: 'Open', exact: true })).toHaveCount(0)
  await page.keyboard.press('Escape')

  const openRequests = []
  page.on('request', request => {
    if (request.url().endsWith('/open-member')) openRequests.push(request)
  })
  const download = page.waitForEvent('download')
  await openRow(page, name)
  const result = await download
  expect(result.suggestedFilename()).toBe(name)
  const chunks = []
  for await (const part of await result.createReadStream()) chunks.push(part)
  expect(Buffer.concat(chunks).toString()).toBe('No registered viewer\n')
  expect(openRequests).toHaveLength(0)
})

test('a nested ZIP opens in Files and returns to its parent archive', async ({ page }) => {
  await openRow(page, 'Browser.zip')
  await openRow(page, 'Nested Юникод')
  const materialized = page.waitForResponse(response => response.url().endsWith('/archives/' + fixture.sourceId + '/open-member'))
  await openRow(page, 'Inner.zip')
  const response = await materialized
  expect(response.status()).toBe(200)
  const { fileId } = await response.json()
  await expect(page).toHaveURL(url => url.pathname.endsWith('/archive-tools-' + fileId))
  await openRow(page, 'deep')
  await expect(row(page, 'inside.txt')).toBeVisible()
  await page.reload()
  await expect(row(page, 'inside.txt')).toBeVisible()
  const download = page.waitForEvent('download')
  await downloadRow(page, 'inside.txt')
  const chunks = []
  for await (const part of await (await download).createReadStream()) chunks.push(part)
  expect(Buffer.concat(chunks).toString()).toBe('Nested archive fixture\n')
  await page.getByRole('button', { name: 'Back to Files', exact: true }).click()
  await expect(page).toHaveURL(url => url.pathname.split('/').includes(nativeView.slice(1)))
  expect(currentDir(page)).toBe('/Nested Юникод')
  await expect(row(page, 'Inner.zip')).toBeVisible()
})

test('archive images, PDFs and video open in Nextcloud Viewer', async ({ page, context }) => {
  await openRow(page, 'Browser.zip')
  const isPreview = response => /\/apps\/archive_tools\/preview\/[a-f0-9]{48}$/.test(response.url())
  const imageName = 'Preview #%.png'
  const imageResponse = page.waitForResponse(isPreview)
  await openRow(page, imageName)
  const image = await imageResponse
  expect(image.status()).toBe(200)
  expect(image.headers()['content-type']).toBe('image/png')
  const imageElement = page.locator('#viewer img').filter({ visible: true }).first()
  await expect(imageElement).toBeVisible()
  await expect.poll(() => imageElement.evaluate(element => element.naturalWidth)).toBe(1)

  const bytes = await image.body()
  const range = await context.request.get(image.url(), { headers: { Range: 'bytes=3-7' } })
  expect(range.status()).toBe(206)
  expect(range.headers()['content-range']).toBe('bytes 3-7/' + bytes.length)
  expect(await range.body()).toEqual(bytes.subarray(3, 8))
  const invalidRange = await context.request.get(image.url(), { headers: { Range: 'bytes=' + bytes.length + '-' } })
  expect(invalidRange.status()).toBe(416)
  expect(invalidRange.headers()['content-range']).toBe('bytes */' + bytes.length)
  const head = await context.request.head(image.url())
  expect(head.status()).toBe(200)
  expect(head.headers()['content-length']).toBe(String(bytes.length))
  expect((await head.body()).length).toBe(0)
  await page.keyboard.press('Escape')
  await expect(imageElement).toBeHidden()

  const downloadPromise = page.waitForEvent('download')
  await row(page, imageName).click({ button: 'right' })
  await page.getByRole('menuitem', { name: 'Download', exact: true }).click()
  expect((await downloadPromise).suggestedFilename()).toBe(imageName)

  const pdfResponse = page.waitForResponse(response => isPreview(response)
    && response.headers()['content-type'] === 'application/pdf')
  await openRow(page, 'Preview #%.pdf')
  expect((await pdfResponse).ok()).toBe(true)
  await expect(page.frameLocator('#viewer iframe').locator('.page[data-page-number="1"]')).toBeVisible()
  await page.locator('#viewer').getByRole('button', { name: 'Close', exact: true }).click()

  const videoResponse = page.waitForResponse(response => isPreview(response)
    && response.headers()['content-type'] === 'video/webm')
  await openRow(page, 'Preview.webm')
  const videoSource = await videoResponse
  expect(videoSource.ok()).toBe(true)
  const video = page.locator('#viewer video')
  await expect(video).toBeVisible()
  await expect.poll(() => video.evaluate(element => element.videoWidth)).toBe(8)
  await video.evaluate(async element => {
    element.muted = true
    await element.play()
  })
  await expect.poll(() => video.evaluate(element => element.currentTime)).toBeGreaterThan(0.1)
  await video.evaluate(element => {
    element.pause()
    element.currentTime = 3.5
  })
  await expect.poll(() => video.evaluate(element => !element.seeking && element.currentTime === 3.5)).toBe(true)
  const wholeVideo = await context.request.get(videoSource.url())
  const videoTail = await context.request.get(videoSource.url(), { headers: { Range: 'bytes=-32' } })
  expect(videoTail.status()).toBe(206)
  expect(await videoTail.body()).toEqual((await wholeVideo.body()).subarray(-32))
})

test('archive PDFs use the bundled viewer when another app handles PDF by default', async ({ page }) => {
  await page.route(/\/apps\/files_pdfviewer\/js\/files_pdfviewer-main\.js(?:\?|$)/, async route => {
    const response = await route.fetch()
    expect(response.ok()).toBe(true)
    const competingHandler = `
      OCA.Viewer.registerHandler({
        id: 'ci-competing-pdf',
        mimes: ['application/pdf'],
        component: {
          name: 'CiCompetingPdfViewer',
          render(h) { return h('div', 'Competing PDF handler') },
        },
      }),
    `
    const script = await response.text()
    const pdfRegistration = /OCA\.Viewer\.registerHandler\(\{id:(['"])pdf\1/
    expect(pdfRegistration.test(script), 'The PDF Viewer script registers the bundled PDF handler').toBe(true)
    await route.fulfill({
      contentType: 'application/javascript',
      body: script.replace(pdfRegistration, match => competingHandler + match),
    })
  })
  await page.goto('/index.php/apps/files/files/' + fixture.folderId + '?dir=' + encodeURIComponent('/CI Archives'))
  await expect.poll(() => page.evaluate(() => window.OCA?.Viewer?.availableHandlers?.map(handler => handler.id) ?? []))
    .toEqual(expect.arrayContaining(['ci-competing-pdf', 'pdf']))

  await page.evaluate(() => {
    const fileInfo = {
      filename: '/ci-default-handler.pdf', basename: 'ci-default-handler.pdf', fileid: 1,
      mime: 'application/pdf', hasPreview: false, permissions: 'R',
    }
    window.OCA.Viewer.open({ fileInfo, list: [fileInfo], enableSidebar: false })
  })
  const competingViewer = page.getByText('Competing PDF handler', { exact: true })
  await expect(competingViewer).toBeVisible()
  await page.locator('#viewer').getByRole('button', { name: 'Close', exact: true }).click()

  await openRow(page, 'Browser.zip')
  await openRow(page, 'Preview #%.pdf')
  await expect(page.frameLocator('#viewer iframe').locator('.page[data-page-number="1"]')).toBeVisible()
  await expect(competingViewer).toBeHidden()
})

test('archive download failure clears its preparation status', async ({ page }) => {
  await openRow(page, 'Browser.zip')
  const response = page.waitForResponse(value => value.url().endsWith('/archives/' + fixture.sourceId + '/download'))
  await page.route('**/archives/' + fixture.sourceId + '/download', route => route.fulfill({
    status: 409,
    json: { message: 'The source archive has changed.' },
  }))
  await downloadRow(page, 'README.txt')
  expect((await response).status()).toBe(409)
  await expect(page.getByText('The source archive has changed.', { exact: true })).toBeVisible()
  await expect(page.locator('[data-archive-transfer]')).toBeHidden()
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

test('the Files menu can connect an archive without loading its preview', async ({ page }) => {
  const previewRequests = []
  await page.route('**/archives/' + fixture.sourceId + '/{browse-zip,inspect}', async route => {
    previewRequests.push(route.request().url())
    await route.abort()
  })

  const startingDirectory = currentDir(page)
  const openConnect = async () => {
    await row(page, 'Browser.zip').click({ button: 'right' })
    await page.locator('[data-cy-files-list-row-action="archive-tools-connect"]').click()
  }
  const dialog = page.getByRole('dialog', { name: 'Connect as folder', exact: true })
  await openConnect()
  await expect(dialog.getByLabel('New folder name', { exact: true })).toHaveValue('Browser (archive)')
  await expect(dialog.getByLabel('Archive password', { exact: true })).toBeVisible()
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(dialog).toHaveCount(0)

  await openConnect()
  await dialog.getByLabel('New folder name', { exact: true }).fill('Connected without preview')
  const connected = page.waitForResponse(response => response.url().endsWith('/mounts') && response.request().method() === 'POST')
  await dialog.getByRole('button', { name: 'Connect archive', exact: true }).click()
  const response = await connected
  expect(response.status()).toBe(201)
  expect(response.request().postDataJSON().expectedEtag).toMatch(/^.+$/)
  expect(previewRequests).toEqual([])
  await expect(dialog).toHaveCount(0)
  await expect(row(page, 'Connected without preview')).toBeVisible()
  expect(currentDir(page)).toBe(startingDirectory)
  expect(new URL(page.url()).pathname).not.toContain(nativeView)
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

test('an unavailable connection offers only disconnect without source metadata', async ({ page }) => {
  const connection = {
    id: 321,
    parentId: Number(fixture.folderId),
    name: 'Unavailable connection',
    path: 'CI Archives/Unavailable connection',
    pinned: true,
    available: false,
    canManage: true,
    state: 'source_unavailable',
  }
  let disconnected = false
  await page.route('**/apps/archive_tools/mounts', route => route.fulfill({ json: disconnected ? [] : [connection] }))
  await page.route('**/apps/archive_tools/mounts/' + connection.id, async route => {
    expect(route.request().method()).toBe('DELETE')
    disconnected = true
    await route.fulfill({ json: { disconnected: true } })
  })

  await page.goto('/index.php/apps/archive_tools/')
  await page.getByRole('button', { name: /^Connected archives/ }).click()
  const card = page.locator('.at-card').filter({ has: page.getByRole('heading', { name: connection.name, exact: true }) })
  await expect(card.getByText('Source unavailable', { exact: true })).toBeVisible()
  await expect(card.getByRole('button')).toHaveText(['Disconnect for everyone'])
  await expect(card.getByText('Connected by', { exact: false })).toHaveCount(0)
  await card.getByRole('button', { name: 'Disconnect for everyone', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Disconnect for everyone', exact: true })
  await expect(dialog.getByText('Disconnect “Unavailable connection” for all participants?', { exact: true })).toBeVisible()
  await dialog.getByRole('button', { name: 'Disconnect for everyone', exact: true }).click()
  await expect(card).toHaveCount(0)
  await expect(page.getByText('Archive disconnected.', { exact: true })).toBeVisible()
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
