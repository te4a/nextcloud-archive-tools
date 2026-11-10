[CmdletBinding()]
param(
    [switch]$Start,
    [switch]$SkipFrontend
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$taskRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$composeFile = Join-Path $taskRoot 'compose.yaml'
$environmentFile = Join-Path $taskRoot '.env'
$resultsDirectory = Join-Path $taskRoot '.test-results'
$projectName = 'archive-tools-dev'
$dockerCommand = Get-Command docker -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
$dockerExecutable = if ($null -ne $dockerCommand) { $dockerCommand.Source } else { 'C:\Program Files\Docker\Docker\resources\bin\docker.exe' }
if (-not (Test-Path -LiteralPath $dockerExecutable -PathType Leaf)) { throw 'Docker Desktop CLI was not found.' }
$composeArguments = @('compose', '--project-name', $projectName, '--file', $composeFile)

function Invoke-ArchiveToolsDocker {
    param([Parameter(Mandatory)][string[]]$DockerArguments)
    # Never print native Docker output that could contain local installation secrets.
    $commandOutput = & $dockerExecutable @composeArguments @DockerArguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Docker operation failed with exit code $LASTEXITCODE. Check the local Docker Desktop test stack." }
    return ($commandOutput -join [Environment]::NewLine)
}

function Get-ArchiveToolsStatus {
    $statusOutput = Invoke-ArchiveToolsDocker -DockerArguments @(
        'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'status', '--output=json'
    )
    # Core prefixes JSON with an upgrade notice when installed app code changed.
    $statusJson = [regex]::Match($statusOutput, '(?m)^\{[^\r\n]*\}\s*$')
    if (-not $statusJson.Success) { throw 'Nextcloud did not return a JSON status object.' }
    return ($statusJson.Value | ConvertFrom-Json)
}

function Test-ArchiveToolsLocalEndpoint {
    param([Parameter(Mandatory)][string]$Endpoint)
    return $Endpoint -match '^(npipe|unix)://' -or
        $Endpoint -match '^(tcp|http|https)://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?/?$'
}

function Assert-ArchiveToolsContainer {
    $containerId = (Invoke-ArchiveToolsDocker -DockerArguments @('ps', '--quiet', 'nextcloud')).Trim()
    if ($containerId -notmatch '^[a-f0-9]{12,64}$') { throw 'Exactly one running local Nextcloud test container is required.' }
    # Inspect only labels, ports and mounts; Config.Env contains the admin password.
    $inspectOutput = & $dockerExecutable inspect --format '{{json .Config.Labels}}|{{json .NetworkSettings.Ports}}|{{json .Mounts}}' $containerId 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'The local test container could not be inspected.' }
    $parts = ($inspectOutput -join '') -split '\|', 3
    if ($parts.Count -ne 3) { throw 'Unexpected local test container metadata.' }
    $labels = $parts[0] | ConvertFrom-Json
    $ports = $parts[1] | ConvertFrom-Json
    $mounts = $parts[2] | ConvertFrom-Json
    if ($labels.'com.docker.compose.project' -ne $projectName -or $labels.'com.docker.compose.service' -ne 'nextcloud') {
        throw 'Container labels do not match the isolated archive-tools-dev project.'
    }
    $bindings = @($ports.'80/tcp')
    if ($bindings.Count -ne 1 -or $bindings[0].HostIp -ne '127.0.0.1' -or $bindings[0].HostPort -ne '18080') {
        throw 'The test container must listen only on 127.0.0.1:18080.'
    }
    $codeMount = @($mounts | Where-Object { $_.Destination -eq '/var/www/html/custom_apps/archive_tools' })
    $dataMount = @($mounts | Where-Object { $_.Destination -eq '/var/www/html' })
    if ($dataMount.Count -ne 1 -or $dataMount[0].Type -ne 'volume' -or $dataMount[0].Name -ne 'archive-tools-dev_nextcloud34pgsql') {
        throw 'The NC34/PostgreSQL stack must use its separate nextcloud34pgsql volume. Earlier SQLite and NC35 volumes must not be reused.'
    }
    if ($codeMount.Count -ne 1 -or $codeMount[0].RW -ne $false) { throw 'The application must be mounted read-only from this checkout.' }
    $expectedSource = (Join-Path $taskRoot 'app').Replace('\', '/').TrimEnd('/')
    # Docker Desktop uses either WSL /run/desktop/... or Hyper-V /host_mnt/ paths.
    $normalizedSource = ([string]$codeMount[0].Source).Replace('\', '/') -replace '^/(?:run/desktop/mnt/host|host_mnt)/([a-z])/', '$1:/'
    if (-not $normalizedSource.Equals($expectedSource, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The test container is using application code from a different checkout.'
    }
}

function Invoke-ArchiveToolsPythonSuite {
    param([string]$Name, [string]$File, [string]$ReportFile, [int]$MinimumChecks)
    $suitePath = [IO.Path]::GetFullPath((Join-Path $taskRoot "tests/$File"))
    $testsRoot = [IO.Path]::GetFullPath((Join-Path $taskRoot 'tests')) + [IO.Path]::DirectorySeparatorChar
    if (-not $suitePath.StartsWith($testsRoot, [StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $suitePath -PathType Leaf)) {
        throw "The $Name suite is not a file in this project's tests directory."
    }
    $reportPath = Join-Path $resultsDirectory $ReportFile
    $outputPath = Join-Path $resultsDirectory ($Name.ToLowerInvariant() + '-output.txt')
    $previousRunId = $null
    if (Test-Path -LiteralPath $reportPath -PathType Leaf) {
        try { $previousRunId = ([IO.File]::ReadAllText($reportPath) | ConvertFrom-Json).runId } catch { }
    }
    # Absolute interpreter and script paths prevent resolution from a different checkout.
    & $pythonExecutable $suitePath 2>&1 | Out-File -LiteralPath $outputPath -Encoding utf8
    $suiteExit = $LASTEXITCODE
    if (-not (Test-Path -LiteralPath $reportPath -PathType Leaf)) { throw "$Name did not create a report. See $outputPath." }
    $report = [IO.File]::ReadAllText($reportPath) | ConvertFrom-Json
    $checkCount = @($report.checks).Count
    $failedCount = @($report.checks | Where-Object { -not $_.passed }).Count
    if (-not $report.runId -or $report.runId -eq $previousRunId) { throw "$Name did not produce a new report. See $outputPath." }
    Write-Host "${Name}: $($report.status); $checkCount checks, $failedCount failed."
    if ($suiteExit -ne 0 -or $report.status -ne 'passed' -or $failedCount -ne 0 -or $checkCount -lt $MinimumChecks) {
        throw "$Name failed or did not complete all expected checks. See $outputPath and $reportPath."
    }
}

function Invoke-ArchiveToolsFrontend {
    $nodeCommand = Get-Command node -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -eq $nodeCommand) { throw 'Node.js is required; use -SkipFrontend for an explicit backend-only run.' }
    $nodeExecutable = $nodeCommand.Source
    $nodeBin = Split-Path -Parent $nodeExecutable
    $nodeRoot = Split-Path -Parent $nodeBin
    $pnpmCandidates = @(
        (Join-Path $nodeRoot 'node_modules/pnpm/bin/pnpm.cjs'),
        (Join-Path $nodeRoot 'node_modules/pnpm/bin/pnpm.mjs'),
        (Join-Path $nodeBin 'node_modules/pnpm/bin/pnpm.cjs'),
        (Join-Path $nodeBin 'node_modules/pnpm/bin/pnpm.mjs'),
        (Join-Path $nodeRoot 'lib/node_modules/pnpm/bin/pnpm.cjs')
    )
    $pnpmScript = $pnpmCandidates | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
    if (-not $pnpmScript) { throw 'A bundled pnpm next to Node.js was not found. No package manager will be installed automatically.' }
    if (-not (Test-Path -LiteralPath (Join-Path $taskRoot 'node_modules/.pnpm') -PathType Container)) {
        throw 'Frontend dependencies are missing. Install the locked dependencies with pnpm before running the suite.'
    }
    $uiTestFiles = @(Get-ChildItem -LiteralPath (Join-Path $taskRoot 'tests') -Filter '*.test.mjs' -File | Sort-Object Name | ForEach-Object { $_.FullName })
    if ($uiTestFiles.Count -eq 0) { throw 'No UI regression test files were found.' }
    $uiOutput = & $nodeExecutable --test --test-reporter=tap @uiTestFiles 2>&1
    $uiExit = $LASTEXITCODE
    $uiText = $uiOutput -join [Environment]::NewLine
    $uiText | Out-File -LiteralPath (Join-Path $resultsDirectory 'ui-output.txt') -Encoding utf8
    $uiCount = [regex]::Match($uiText, '# tests (\d+)')
    if ($uiExit -ne 0 -or -not $uiCount.Success -or [int]$uiCount.Groups[1].Value -lt 9 -or $uiText -notmatch '# fail 0') {
        throw 'UI state tests failed. See .test-results/ui-output.txt.'
    }
    Write-Host "UI state: $($uiCount.Groups[1].Value) tests passed."
    $previousPnpmVerifyDeps = [Environment]::GetEnvironmentVariable('pnpm_config_verify_deps_before_run', 'Process')
    $previousPnpmStore = [Environment]::GetEnvironmentVariable('pnpm_config_store_dir', 'Process')
    try {
        # pnpm 11 otherwise runs an implicit install after manifest changes.
        # Checks must fail on stale dependencies, never replace node_modules.
        [Environment]::SetEnvironmentVariable('pnpm_config_verify_deps_before_run', 'error', 'Process')
        [Environment]::SetEnvironmentVariable('pnpm_config_store_dir', (Join-Path $taskRoot '.pnpm-store'), 'Process')
        foreach ($command in @('typecheck', 'build')) {
            if ($command -eq 'build') {
                # Vite must preserve the PHP tree, but old generated chunks
                # should not accumulate in installation packages.
                $chunkDirectory = [IO.Path]::GetFullPath((Join-Path $taskRoot 'app/js/chunks'))
                if (Test-Path -LiteralPath $chunkDirectory -PathType Container) {
                    $resolvedChunks = (Resolve-Path -LiteralPath $chunkDirectory).Path
                    $chunkItem = Get-Item -LiteralPath $resolvedChunks -Force
                    if ($resolvedChunks -ne $chunkDirectory -or ($chunkItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
                        throw 'The generated chunk directory is outside the expected application tree.'
                    }
                    foreach ($chunkFile in Get-ChildItem -LiteralPath $resolvedChunks -Filter '*.mjs' -File) {
                        if ($chunkFile.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'A generated chunk is a symbolic link.' }
                        Remove-Item -LiteralPath $chunkFile.FullName
                    }
                }
            }
            $frontendOutput = & $nodeExecutable $pnpmScript run $command 2>&1
            $frontendExit = $LASTEXITCODE
            $frontendOutput | Out-File -LiteralPath (Join-Path $resultsDirectory "frontend-$command-output.txt") -Encoding utf8
            if ($frontendExit -ne 0) { throw "Frontend $command failed. See .test-results/frontend-$command-output.txt." }
            Write-Host "Frontend ${command}: passed."
        }
    } finally {
        [Environment]::SetEnvironmentVariable('pnpm_config_verify_deps_before_run', $previousPnpmVerifyDeps, 'Process')
        [Environment]::SetEnvironmentVariable('pnpm_config_store_dir', $previousPnpmStore, 'Process')
    }
}

$previousProcessPath = [Environment]::GetEnvironmentVariable('PATH', 'Process')
$previousPythonEncoding = [Environment]::GetEnvironmentVariable('PYTHONIOENCODING', 'Process')
$dockerBinDirectory = Split-Path -Parent $dockerExecutable
try {
    [Environment]::SetEnvironmentVariable('PATH', $dockerBinDirectory + [IO.Path]::PathSeparator + $previousProcessPath, 'Process')
    [Environment]::SetEnvironmentVariable('PYTHONIOENCODING', 'utf-8', 'Process')
    Push-Location -LiteralPath $taskRoot
    try {
        $hostOverride = [Environment]::GetEnvironmentVariable('DOCKER_HOST', 'Process')
        if ($hostOverride -and -not (Test-ArchiveToolsLocalEndpoint -Endpoint $hostOverride)) { throw 'Remote Docker hosts are not allowed for this local test runner.' }
        $contextOutput = & $dockerExecutable context inspect --format '{{json .Endpoints.docker.Host}}' 2>&1
        if ($LASTEXITCODE -ne 0) { throw 'The local Docker Desktop context could not be inspected.' }
        $contextEndpoint = ($contextOutput -join [Environment]::NewLine) | ConvertFrom-Json
        if (-not (Test-ArchiveToolsLocalEndpoint -Endpoint $contextEndpoint)) { throw 'The selected Docker context is not local.' }
        if (-not (Test-Path -LiteralPath $composeFile -PathType Leaf)) { throw 'The local compose.yaml was not found.' }
        $composeText = [IO.File]::ReadAllText($composeFile)
        if ($composeText -notmatch '(?m)^name:\s*archive-tools-dev\s*$' -or $composeText -notmatch '127\.0\.0\.1:18080:80' -or $composeText -notmatch 'image:\s*archive-tools-nextcloud-dev:34\.0\.5-7zip26\.06' -or $composeText -notmatch 'nextcloud34pgsql:/var/www/html') {
            throw 'This runner is restricted to archive-tools-dev on 127.0.0.1:18080 with Nextcloud 34.0.5, PostgreSQL and its separate data volume.'
        }
        if ($Start) {
            $alreadyRunning = (Invoke-ArchiveToolsDocker -DockerArguments @('ps', '--quiet', 'nextcloud')).Trim()
            if ($alreadyRunning) {
                Assert-ArchiveToolsContainer
                $null = Invoke-ArchiveToolsDocker -DockerArguments @('stop', '--timeout', '10', 'nextcloud')
            }
            if (-not (Test-Path -LiteralPath $environmentFile -PathType Leaf)) {
                $randomBytes = [byte[]]::new(32)
                $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
                try { $generator.GetBytes($randomBytes) } finally { $generator.Dispose() }
                $localPassword = [Convert]::ToBase64String($randomBytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
                [IO.File]::WriteAllText($environmentFile, "ARCHIVE_TOOLS_ADMIN_PASSWORD=$localPassword`n", [Text.UTF8Encoding]::new($false))
                $localPassword = $null
                [Array]::Clear($randomBytes, 0, $randomBytes.Length)
            }
            $null = Invoke-ArchiveToolsDocker -DockerArguments @('up', '-d', '--build')
            $deadline = [DateTime]::UtcNow.AddSeconds(120)
            $ready = $false
            do {
                try { $ready = (Get-ArchiveToolsStatus).installed -eq $true } catch { $ready = $false }
                if (-not $ready -and [DateTime]::UtcNow -lt $deadline) { Start-Sleep -Seconds 2 }
            } while (-not $ready -and [DateTime]::UtcNow -lt $deadline)
            if (-not $ready) { throw 'Nextcloud did not finish installation within 120 seconds. The test volume has been preserved.' }
        } elseif (-not (Test-Path -LiteralPath $environmentFile -PathType Leaf)) {
            throw 'The local .env is missing. Run dev/test.ps1 -Start to create the isolated test environment.'
        }
        Assert-ArchiveToolsContainer
        $nextcloudStatus = Get-ArchiveToolsStatus
        if ($nextcloudStatus.installed -ne $true -or $nextcloudStatus.version -notmatch '^34\.0\.5\.') { throw 'This runner requires an installed Nextcloud 34.0.5 test instance.' }
        $phpVersion = Invoke-ArchiveToolsDocker -DockerArguments @(
            'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r',
            'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) { exit(1); } echo PHP_VERSION;'
        )
        Write-Host "Test stack: Nextcloud $($nextcloudStatus.version), PHP $phpVersion."
        $null = New-Item -ItemType Directory -Path $resultsDirectory -Force
        # Stop only the daemon this runner started. A stale PID must never target another process.
        $stopCron = @'
set -eu
marker=/tmp/archive-tools-cron.pid
if [ -f "$marker" ]; then
  pid=$(cat "$marker")
  case "$pid" in ''|*[!0-9]*) exit 1;; esac
  if [ -e "/proc/$pid/cmdline" ]; then
    command=$(tr '\000' ' ' < "/proc/$pid/cmdline")
    case "$command" in 'busybox crond -f -L /dev/stdout '|'crond -f -L /dev/stdout ') kill -TERM "$pid";; *) exit 1;; esac
    count=0
    while kill -0 "$pid" 2>/dev/null && [ "$count" -lt 50 ]; do sleep 0.1; count=$((count + 1)); done
    if [ -s "/proc/$pid/cmdline" ]; then exit 1; fi
  fi
  rm -f "$marker"
fi
# A daemon can have an already running cron.php child; let it finish before fixtures change.
has_cron_worker() {
  for process in /proc/[0-9]*/cmdline; do
    command=$(tr '\000' ' ' < "$process" 2>/dev/null || true)
    case "$command" in 'php -f /var/www/html/cron.php '|'php -f cron.php ') return 0;; esac
  done
  return 1
}
count=0
while has_cron_worker; do
  if [ "$count" -ge 30 ]; then exit 1; fi
  sleep 1
  count=$((count + 1))
done
'@
        $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', 'nextcloud', 'sh', '-c', $stopCron)
        # Apply code migrations on every run, including an already running development stack.
        if ($nextcloudStatus.needsDbUpgrade) {
            $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'upgrade', '--no-interaction')
        }
        $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'app:enable', 'archive_tools')
        $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'upgrade', '--no-interaction')
        $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'config:system:set', 'appstoreenabled', '--type=boolean', '--value=false')
        $runtimeProbe = @'
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
$db = \OC::$server->get(\OCP\IDBConnection::class);
$config = \OC::$server->get(\OCP\IConfig::class);
echo json_encode(['databaseType' => $config->getSystemValue('dbtype'), 'databaseVersion' => $db->executeQuery('SELECT VERSION()')->fetchOne(), 'lockingProvider' => get_class(\OC::$server->get(\OCP\Lock\ILockingProvider::class))]);
'@
        $runtimeJson = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', $runtimeProbe)
        $runtime = $runtimeJson | ConvertFrom-Json
        if ($runtime.databaseType -ne 'pgsql' -or $runtime.databaseVersion -notmatch '^PostgreSQL 17\.11 ' -or $runtime.lockingProvider -ne 'OC\Lock\MemcacheLockingProvider') {
            throw 'The stack must use PostgreSQL 17.11 and Redis-backed MemcacheLockingProvider like the target server.'
        }
        Write-Host "Database: $($runtime.databaseVersion); locks: $($runtime.lockingProvider)."
        $lintOutput = Invoke-ArchiveToolsDocker -DockerArguments @(
            'exec', '-T', '--user', 'www-data', 'nextcloud', 'sh', '-c',
            'find /var/www/html/custom_apps/archive_tools /opt/archive-tools-tests -type f -name "*.php" -print0 | xargs -0 -r -n1 php -l'
        )
        $lintCount = [regex]::Matches($lintOutput, 'No syntax errors detected in ').Count
        if ($lintCount -eq 0) { throw 'No application PHP files were checked.' }
        Write-Host "PHP syntax: $lintCount files passed."
        foreach ($suite in @(
            @{ File = 'metadata.php'; Pattern = 'Metadata validation: (\d+) checks passed\.'; Minimum = 6; Name = 'Metadata' },
            @{ File = 'zip-reader.php'; Pattern = 'All (\d+) ZIP reader tests passed\.'; Minimum = 21; Name = 'ZIP reader' },
            @{ File = 'tar-reader.php'; Pattern = 'All (\d+) TAR reader tests passed\.'; Minimum = 19; Name = 'TAR reader' },
            @{ File = 'sevenzip-reader.php'; Pattern = 'SevenZip reader tests: (\d+) passed, 0 failed\.'; Minimum = 33; Name = '7-Zip reader' },
            @{ File = 'operation-budget.php'; Pattern = 'All (\d+) operation budget tests passed\.'; Minimum = 10; Name = 'Operation budget' },
            @{ File = 'admission.php'; Pattern = 'All (\d+) admission tests passed\.'; Minimum = 29; Name = 'Resource admission' },
            @{ File = 'admission-concurrency.php'; Pattern = 'All (\d+) admission concurrency tests passed\.'; Minimum = 15; Name = 'Resource admission concurrency' },
            @{ File = 'admission-password.php'; Pattern = 'All (\d+) admission password tests passed\.'; Minimum = 11; Name = 'Password limits' },
            @{ File = 'format-readers.php'; Pattern = 'All (\d+) additional format reader tests passed\.'; Minimum = 4; Name = 'Additional format readers' },
            @{ File = 'job-retention.php'; Pattern = 'All (\d+) job retention tests passed\.'; Minimum = 23; Name = 'Job retention' },
            @{ File = 'extraction-plan.php'; Pattern = 'All (\d+) extraction plan tests passed\.'; Minimum = 12; Name = 'Extraction plan' },
            @{ File = 'background-user-context.php'; Pattern = 'All (\d+) background user context tests passed\.'; Minimum = 27; Name = 'Background user context' }
        )) {
            $suiteOutput = Invoke-ArchiveToolsDocker -DockerArguments @(
                'exec', '-T', '--user', 'www-data', '--env', 'ARCHIVE_TOOLS_APP_PATH=/var/www/html/custom_apps/archive_tools',
                'nextcloud', 'php', ('/opt/archive-tools-tests/' + $suite.File)
            )
            $summary = [regex]::Match($suiteOutput, $suite.Pattern)
            if (-not $summary.Success -or [int]$summary.Groups[1].Value -lt $suite.Minimum) { throw "$($suite.Name) did not complete all expected checks." }
            Write-Host "$($suite.Name): $($summary.Groups[1].Value) tests passed."
        }
        # Standalone owned temporary fixtures; root adds two foreign-UID checks.
        $temporaryOutput = Invoke-ArchiveToolsDocker -DockerArguments @(
            'exec', '-T', '--user', 'root', '--env', 'ARCHIVE_TOOLS_APP_PATH=/var/www/html/custom_apps/archive_tools',
            'nextcloud', 'php', '/opt/archive-tools-tests/temporary-resource-cleanup.php'
        )
        $temporarySummary = [regex]::Match($temporaryOutput, 'All (\d+) temporary resource cleanup tests passed\.')
        if (-not $temporarySummary.Success -or [int]$temporarySummary.Groups[1].Value -lt 22) { throw 'Temporary resource cleanup checks did not complete.' }
        Write-Host "Temporary resource cleanup: $($temporarySummary.Groups[1].Value) tests passed."
        $dtoOutput = Invoke-ArchiveToolsDocker -DockerArguments @(
            'exec', '-T', '--user', 'www-data', '--env', 'ARCHIVE_TOOLS_APP_PATH=/var/www/html/custom_apps/archive_tools',
            'nextcloud', 'php', '/opt/archive-tools-tests/job-dto.php'
        )
        foreach ($assertion in @(
            '(?m)^PASS history DTO requires no extraction plan and exposes no credentials\r?$',
            '(?m)^PASS detailed DTO restores selection without raw names or credentials\r?$'
        )) {
            if ($dtoOutput -notmatch $assertion) { throw 'The job DTO suite did not complete both expected assertions.' }
        }
        Write-Host 'Job DTO: 2 assertions passed.'
        $projectionOutput = Invoke-ArchiveToolsDocker -DockerArguments @(
            'exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '/opt/archive-tools-tests/mount-projection.php'
        )
        if ([regex]::Matches($projectionOutput, '(?m)^PASS ').Count -ne 2) { throw 'Mount projection regression checks did not complete.' }
        Write-Host 'Mount projection: 2 assertions passed.'
        $pythonCommand = Get-Command python -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -eq $pythonCommand) { throw 'Python 3 is required for the local HTTP suites.' }
        $pythonExecutable = [IO.Path]::GetFullPath($pythonCommand.Source)
        $translationGenerator = [IO.Path]::GetFullPath((Join-Path $taskRoot 'dev/update-translations.py'))
        if (-not (Test-Path -LiteralPath $translationGenerator -PathType Leaf)) { throw 'The project translation generator is missing.' }
        $translationOutput = & $pythonExecutable $translationGenerator 2>&1
        $translationExit = $LASTEXITCODE
        $translationText = $translationOutput -join [Environment]::NewLine
        $translationText | Out-File -LiteralPath (Join-Path $resultsDirectory 'translations-output.txt') -Encoding utf8
        if ($translationExit -ne 0 -or $translationText -notmatch 'RU/EN catalogs built: \d+ messages including plural forms\.') {
            throw 'Translation completeness or generation failed. See .test-results/translations-output.txt.'
        }
        Write-Host $translationText.Trim()
        if ($SkipFrontend) { Write-Host 'Frontend checks and build: explicitly skipped.' } else { Invoke-ArchiveToolsFrontend }
        Invoke-ArchiveToolsPythonSuite -Name 'Integration' -File 'integration.py' -ReportFile 'latest-integration.json' -MinimumChecks 82
        Invoke-ArchiveToolsPythonSuite -Name 'Lifecycle' -File 'lifecycle.py' -ReportFile 'latest-lifecycle.json' -MinimumChecks 30
        Invoke-ArchiveToolsPythonSuite -Name 'Features' -File 'features.py' -ReportFile 'latest-features.json' -MinimumChecks 38
        Invoke-ArchiveToolsPythonSuite -Name 'WebDAV-readonly' -File 'webdav-readonly.py' -ReportFile 'latest-webdav-readonly.json' -MinimumChecks 40
        Invoke-ArchiveToolsPythonSuite -Name 'Expected-etag' -File 'expected-etag.py' -ReportFile 'latest-expected-etag.json' -MinimumChecks 70
        Invoke-ArchiveToolsPythonSuite -Name 'Queue-security' -File 'queue-security.py' -ReportFile 'latest-queue-security.json' -MinimumChecks 52
        Invoke-ArchiveToolsPythonSuite -Name 'Multi-format' -File 'multiformat.py' -ReportFile 'latest-multiformat.json' -MinimumChecks 100
        Invoke-ArchiveToolsPythonSuite -Name 'Concurrency' -File 'concurrency.py' -ReportFile 'latest-concurrency.json' -MinimumChecks 47
        Invoke-ArchiveToolsPythonSuite -Name 'Admission API' -File 'admission.py' -ReportFile 'latest-admission.json' -MinimumChecks 58
        # Enable the image's normal five-minute cron only after deterministic suites finish.
        $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'background:cron')
        $startCron = @'
set -eu
# /dev/stdout in the image's crond must point to a live file after docker exec detaches.
exec flock -n /tmp/archive-tools-cron.lock sh -c 'umask 077; : > /tmp/archive-tools-cron.log; echo "$$" > /tmp/archive-tools-cron.pid; exec /cron.sh </dev/null >> /tmp/archive-tools-cron.log 2>&1'
'@
        $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-d', '-T', 'nextcloud', 'sh', '-c', $startCron)
        $verifyCron = @'
set -eu
count=0
while [ "$count" -lt 20 ]; do
  if [ -s /tmp/archive-tools-cron.pid ]; then
    pid=$(cat /tmp/archive-tools-cron.pid)
    case "$pid" in ''|*[!0-9]*) exit 1;; esac
    if [ -e "/proc/$pid/cmdline" ]; then
      command=$(tr '\000' ' ' < "/proc/$pid/cmdline")
      case "$command" in
        'busybox crond -f -L /dev/stdout '|'crond -f -L /dev/stdout ')
          if grep -F 'crond (busybox' /tmp/archive-tools-cron.log >/dev/null; then exit 0; fi;;
      esac
    fi
  fi
  count=$((count + 1))
  sleep 0.1
done
exit 1
'@
        $null = Invoke-ArchiveToolsDocker -DockerArguments @('exec', '-T', 'nextcloud', 'sh', '-c', $verifyCron)
        Write-Host 'All checks passed. Local Nextcloud is available at http://127.0.0.1:18080; native cron is enabled.'
    } finally { Pop-Location }
} finally {
    [Environment]::SetEnvironmentVariable('PATH', $previousProcessPath, 'Process')
    [Environment]::SetEnvironmentVariable('PYTHONIOENCODING', $previousPythonEncoding, 'Process')
}
