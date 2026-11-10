[CmdletBinding()]
param(
    [switch]$Prepare,
    [string]$Stage,
    [switch]$RequireSignature
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$taskPython = Get-Command python -CommandType Application -ErrorAction Stop | Select-Object -First 1
$taskArguments = @((Join-Path $PSScriptRoot 'package.py'))
if ($Prepare) { $taskArguments += '--prepare' }
if ($Stage) { $taskArguments += @('--stage', $Stage) }
if ($RequireSignature) { $taskArguments += '--require-signature' }
& $taskPython.Source @taskArguments
if ($LASTEXITCODE -ne 0) { throw "Runtime packaging failed with exit code $LASTEXITCODE." }
