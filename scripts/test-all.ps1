# STEP 41 — run every automated test suite of FacultyLens (Windows PowerShell). Exits 1 if any suite fails.
#
#   backend   : php artisan test            (Laravel — sqlite in-memory; live-AI tests skip if :8001 is down)
#   frontend  : tsc + vitest + build
#   ai-service: ruff + pytest
#   e2e       : Playwright journeys 1-8     (needs `docker compose up -d`; -E2E)
#   golden    : bash Golden Path            (needs Docker stack + Git Bash; -Golden)
#   outage    : AI outage regression        (stops/starts the ai-service container; -Outage) — STEP 42
#
# Usage: powershell -ExecutionPolicy Bypass -File scripts/test-all.ps1 [-E2E] [-Golden] [-Outage] [-SkipAi] [-SkipFrontend] [-SkipBackend]
param([switch]$E2E, [switch]$Golden, [switch]$Outage, [switch]$SkipAi, [switch]$SkipFrontend, [switch]$SkipBackend)
$ErrorActionPreference = 'Continue'
Set-Location (Join-Path $PSScriptRoot '..')
$results = New-Object System.Collections.ArrayList

function Run-Suite([string]$Name, [string]$Dir, [string]$Command) {
    Write-Host ""; Write-Host ("-" * 62); Write-Host "> $Name"; Write-Host "  $Command"
    $sw = [Diagnostics.Stopwatch]::StartNew()
    Push-Location $Dir
    try {
        $global:LASTEXITCODE = 0
        cmd /c "$Command 2>&1" | Select-Object -Last 15 | ForEach-Object { Write-Host "  $_" }
        $status = if ($LASTEXITCODE -eq 0) { 'PASS' } else { 'FAIL' }
    } finally { Pop-Location }
    [void]$results.Add([pscustomobject]@{ Suite = $Name; Result = $status; Time = ('{0}s' -f [int]$sw.Elapsed.TotalSeconds) })
}

function Backend-Up { try { (Invoke-WebRequest -UseBasicParsing -TimeoutSec 3 http://127.0.0.1:8080/api/health).StatusCode -eq 200 } catch { $false } }

if (-not $SkipBackend) { Run-Suite 'backend: php artisan test' 'backend' 'php artisan test' }
if (-not $SkipFrontend) {
    Run-Suite 'frontend: tsc --noEmit' 'frontend' 'npx tsc --noEmit -p tsconfig.json'
    Run-Suite 'frontend: vitest' 'frontend' 'npx vitest run'
    Run-Suite 'frontend: build' 'frontend' 'npx vite build'
}
if (-not $SkipAi) {
    $py = if (Test-Path 'ai-service\.venv\Scripts\python.exe') { '.venv\Scripts\python.exe' } else { 'python' }
    Run-Suite 'ai-service: ruff' 'ai-service' "$py -m ruff check app tests"
    Run-Suite 'ai-service: pytest' 'ai-service' "$py -m pytest -q"
}
if ($E2E) {
    if (Backend-Up) { Run-Suite 'e2e: Playwright journeys (Chromium)' 'frontend' 'npx tsc -p tsconfig.e2e.json && npx playwright test' }
    else { Write-Host "`n> e2e: Playwright - SKIPPED (backend not reachable on :8080; run: docker compose up -d)"; [void]$results.Add([pscustomobject]@{ Suite = 'e2e: Playwright'; Result = 'SKIPPED'; Time = '-' }) }
}
if ($Golden) {
    if (Backend-Up) { Run-Suite 'golden path (Docker, live AI)' '.' 'bash backend/tests/e2e_golden_path.sh' }
    else { Write-Host "`n> golden path - SKIPPED (backend not reachable on :8080)"; [void]$results.Add([pscustomobject]@{ Suite = 'golden path'; Result = 'SKIPPED'; Time = '-' }) }
}
if ($Outage) {
    if (Backend-Up) { Run-Suite 'AI outage regression (Docker stop/start ai-service)' '.' 'bash backend/tests/e2e_ai_outage.sh' }
    else { Write-Host "`n> AI outage regression - SKIPPED (backend not reachable on :8080)"; [void]$results.Add([pscustomobject]@{ Suite = 'AI outage regression'; Result = 'SKIPPED'; Time = '-' }) }
}
if ($Outage) {
    if (Backend-Up) { Run-Suite 'AI outage regression (Docker stop/start ai-service)' '.' 'bash backend/tests/e2e_ai_outage.sh' }
    else { Write-Host "`n> AI outage regression - SKIPPED (backend not reachable on :8080)"; [void]$results.Add([pscustomobject]@{ Suite = 'AI outage regression'; Result = 'SKIPPED'; Time = '-' }) }
}

Write-Host ""; Write-Host ("=" * 62); Write-Host " FacultyLens test summary"; Write-Host ("=" * 62)
$results | Format-Table -AutoSize | Out-String | Write-Host
$failed = @($results | Where-Object { $_.Result -eq 'FAIL' }).Count
if ($failed -eq 0) { Write-Host " ALL SUITES PASSED"; exit 0 } else { Write-Host " $failed SUITE(S) FAILED"; exit 1 }
