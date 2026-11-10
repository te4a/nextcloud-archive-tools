import { existsSync, lstatSync, readdirSync, realpathSync, unlinkSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { join } from 'node:path'

// Vite preserves the PHP tree (emptyOutDir:false). Remove only its generated
// JS chunks before rebuilding, matching dev/test.ps1 without recursive deletion.
const directory = fileURLToPath(new URL('../app/js/chunks', import.meta.url))
if (existsSync(directory)) {
  const canonical = realpathSync(directory)
  const same = process.platform === 'win32'
    ? canonical.toLowerCase() === directory.toLowerCase() : canonical === directory
  if (!same || lstatSync(directory).isSymbolicLink()) throw new Error('Generated chunk directory must not be a link')
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    if (!entry.name.endsWith('.mjs')) continue
    const path = join(directory, entry.name)
    if (lstatSync(path).isSymbolicLink() || !entry.isFile()) throw new Error('Generated chunk must be a regular file')
    unlinkSync(path)
  }
}
