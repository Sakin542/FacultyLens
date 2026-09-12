# STEP 44 — FacultyLens AI accuracy & evaluation validation (PowerShell).
#
#   .\scripts\run-ai-evaluation.ps1                       # full Python harness on the TEST split
#   .\scripts\run-ai-evaluation.ps1 -Split ALL
#   .\scripts\run-ai-evaluation.ps1 -Laravel              # also export + import into the STEP 35 tables (Docker stack required)
#   .\scripts\run-ai-evaluation.ps1 -SaveBaseline -Strict
param(
    [string]$Split = "TEST",
    [string]$Components = "all",
    [switch]$Laravel,
    [switch]$SaveBaseline,
    [switch]$Strict
)
$ErrorActionPreference = "Stop"
$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
$Ai = Join-Path $Root "ai-service"
$Py = Join-Path $Ai ".venv\Scripts\python.exe"
if (-not (Test-Path $Py)) { $Py = "python" }

Push-Location $Ai
try {
    $args = @("-m", "evaluation.run", "--split", $Split, "--components", $Components)
    if ($SaveBaseline) { $args += "--save-baseline" }
    if ($Strict) { $args += "--strict" }
    & $Py @args
    $status = $LASTEXITCODE

    if ($Laravel) {
        & $Py -m evaluation.export_laravel
        $dest = Join-Path $Root "backend\storage\app\ai-evaluation-benchmark"
        New-Item -ItemType Directory -Force -Path $dest | Out-Null
        Copy-Item (Join-Path $Ai "evaluation\results\laravel_import\*.json") $dest -Force
        Push-Location $Root
        docker compose exec -T app php artisan ai-evaluation:import storage/app/ai-evaluation-benchmark --run --sync --replace
        Pop-Location
    }
    exit $status
} finally {
    Pop-Location
}
