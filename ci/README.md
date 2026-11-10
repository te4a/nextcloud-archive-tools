# Disposable release validation

This runner exercises the existing PHP and HTTP suites on a fresh Nextcloud
installation. It never reads the development `.env`, starts `archive-tools-dev`,
or reuses its volumes. The retained Windows development stack can continue on
port 18080 while this runner uses 18081.

Requirements: local Docker with Compose v2, Python 3.12+, current built `app/js`
assets, and an amd64 Linux container engine. Install/build the frontend using the
root README first. No production credentials or existing data are needed.

```sh
python3 -m unittest discover -s ci -p 'test_*.py' -v
python3 ci/run.py --nextcloud 34 --database pgsql --suite full
python3 ci/run.py --nextcloud 35 --database pgsql --suite full
python3 ci/run.py --nextcloud 34 --database sqlite --suite critical
python3 ci/run.py --nextcloud 34 --database mariadb --suite critical
python3 ci/run.py --nextcloud 35 --database mysql --suite critical
```

Full runs execute all existing reader/unit and HTTP suites, including real
concurrent workers and owned-process crash recovery. Critical database runs
execute the same unit suites and the sharing, lifecycle, extraction, WebDAV,
ETag and queue-security HTTP suites; they omit the repeated multi-format and
concurrency suites. PHP must actually report 8.5; another version fails the gate.
Full runs also queue a new fixture extraction and execute the real `cron.php`,
checking completion and output bytes without `background-job:execute --force`.

The generated Compose project is `archive-tools-ci-<random token>`. Its loopback
port, token labels, own volume and read-only bind to this checkout are checked
before HTTP tests. Cleanup revalidates the project and removes only its own
containers/volumes. A failed cleanup fails the run. Images remain cached.
`.ci-runs/<token>` retains private local logs; its generated `.env` is removed
after cleanup. Never publish `.ci-runs` or raw browser traces.
CI reports stay in the private run directory before sanitized copies are made,
so a concurrent retained development gate cannot overwrite their result files.
Reports record the application version and source fingerprint; changing `app/`
while a gate is running makes the gate fail.

## Browser E2E

```sh
pnpm --dir ci/browser install --frozen-lockfile --ignore-scripts --store-dir ../../.pnpm-store
node ci/browser/node_modules/@playwright/test/cli.js install --with-deps chromium
python3 ci/run.py --nextcloud 34 --database pgsql --suite full --browser
```

Playwright runs its own headless Chromium with a newly created fixture user. It
checks native Files opening, Unicode/literal-percent directory navigation,
reload/sidebar/return behavior, download bytes, selected extraction and the
native destination picker, pinning, encrypted headers, wrong/correct passwords,
and password cancellation. HTTP/HTTPS requests outside the disposable loopback
origin are blocked. The fixture user is deleted in `finally`; the entire owned
stack is then removed. Tests do not control the user's browser.

Public CI artifacts are limited to sanitized assertion reports and screenshots
of disposable fixture files under `.test-results/ci-public`. No generated
credentials, cookies, browser storage, traces or raw container logs are uploaded.
An added workflow is not evidence of a green GitHub run: local/GitHub results
must be recorded separately, including any matrix lanes not yet executed.
