$ErrorActionPreference = 'Stop'

$patchRoot = $PSScriptRoot
$projectRoot = Split-Path -Parent $patchRoot
$payloadRoot = Join-Path $patchRoot 'payload'
$files = @(
    'database/seeders/DatabaseSeeder.php',
    'database/seeders/DemoDataSeeder.php'
)

foreach ($relativePath in $files) {
    $source = Join-Path $payloadRoot ($relativePath -replace '/', '\')
    $destination = Join-Path $projectRoot ($relativePath -replace '/', '\')

    if (-not (Test-Path -LiteralPath $source)) {
        throw "Missing patch file: $source"
    }
    if (-not (Test-Path -LiteralPath (Split-Path -Parent $destination))) {
        throw "Laravel project folder not found: $(Split-Path -Parent $destination)"
    }
}

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\seed-patch-backups\$timestamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null

foreach ($relativePath in $files) {
    $source = Join-Path $payloadRoot ($relativePath -replace '/', '\')
    $destination = Join-Path $projectRoot ($relativePath -replace '/', '\')
    $backupDestination = Join-Path $backupRoot ($relativePath -replace '/', '\')
    New-Item -ItemType Directory -Path (Split-Path -Parent $backupDestination) -Force | Out-Null
    Copy-Item -LiteralPath $destination -Destination $backupDestination -Force
    Copy-Item -LiteralPath $source -Destination $destination -Force
    Write-Host "Updated: $relativePath" -ForegroundColor Green
}

Write-Host ''
Write-Host "Backup created at: $backupRoot" -ForegroundColor Cyan
Write-Host 'Patch applied. No database records were deleted.' -ForegroundColor Yellow
