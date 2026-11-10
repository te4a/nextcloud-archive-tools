import { defineConfig } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const manifest = process.env.ARCHIVE_TOOLS_BROWSER_FIXTURE
if (!manifest) throw new Error('Browser E2E requires the disposable CI fixture manifest.')
const fixture = JSON.parse(readFileSync(manifest, 'utf8'))
const origin = new URL(fixture.baseUrl)
if (origin.protocol !== 'http:' || origin.hostname !== '127.0.0.1'
  || Number(origin.port) < 18081 || Number(origin.port) > 18999
  || !/^archive-tools-ci-[a-f0-9]{12}$/.test(fixture.project)
  || fixture.project !== 'archive-tools-ci-' + fixture.token.slice(0, 12)) {
  throw new Error('Browser tests refuse a non-local or non-CI target.')
}

export default defineConfig({
  testDir: '.', testMatch: 'native-files.spec.mjs', fullyParallel: false, workers: 1,
  timeout: 90_000, expect: { timeout: 15_000 }, retries: 0,
  reporter: [['list'], ['json', { outputFile: resolve(manifest, '..', 'browser-results.json') }]],
  outputDir: resolve(manifest, '..', 'browser-private'),
  use: { baseURL: fixture.baseUrl, browserName: 'chromium', locale: 'en-US', viewport: { width: 1440, height: 1000 },
    headless: true, trace: 'off', screenshot: 'off', video: 'off', actionTimeout: 15_000, navigationTimeout: 45_000 },
})
