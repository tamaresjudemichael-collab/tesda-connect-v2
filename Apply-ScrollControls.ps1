$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = $scriptDir

if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'artisan'))) {
    $currentDir = (Get-Location).Path
    if (Test-Path -LiteralPath (Join-Path $currentDir 'artisan')) {
        $projectRoot = $currentDir
    } else {
        throw "Could not find Laravel project root. Extract this ZIP directly into the folder containing artisan, then run this script there."
    }
}

$layoutPath = Join-Path $projectRoot 'resources\views\layouts\app\sidebar.blade.php'
$partialSource = Join-Path $scriptDir 'patch-files\resources\views\layouts\app\partials\scroll-controls.blade.php'
$partialDir = Join-Path $projectRoot 'resources\views\layouts\app\partials'
$partialTarget = Join-Path $partialDir 'scroll-controls.blade.php'

if (-not (Test-Path -LiteralPath $layoutPath)) {
    throw "Layout not found: $layoutPath"
}
if (-not (Test-Path -LiteralPath $partialSource)) {
    throw "Patch partial not found: $partialSource. Extract the full ZIP; do not copy only the script."
}

$layout = [System.IO.File]::ReadAllText($layoutPath)
if ($layout -notmatch '@fluxScripts') {
    throw 'Could not find @fluxScripts in the app layout; no files were changed.'
}

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\scroll-controls-$timestamp"
$backupLayout = Join-Path $backupRoot 'resources\views\layouts\app\sidebar.blade.php'
$backupPartial = Join-Path $backupRoot 'resources\views\layouts\app\partials\scroll-controls.blade.php'

New-Item -ItemType Directory -Path (Split-Path -Parent $backupLayout) -Force | Out-Null
Copy-Item -LiteralPath $layoutPath -Destination $backupLayout -Force
if (Test-Path -LiteralPath $partialTarget) {
    New-Item -ItemType Directory -Path (Split-Path -Parent $backupPartial) -Force | Out-Null
    Copy-Item -LiteralPath $partialTarget -Destination $backupPartial -Force
}

New-Item -ItemType Directory -Path $partialDir -Force | Out-Null
Copy-Item -LiteralPath $partialSource -Destination $partialTarget -Force

$includeLine = "@include('layouts.app.partials.scroll-controls')"
if ($layout -notmatch [regex]::Escape($includeLine)) {
    $layout = [regex]::Replace($layout, '(?m)^(\s*)@fluxScripts\s*$', "$includeLine`r`n`$1@fluxScripts", 1)
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($layoutPath, $layout, $utf8NoBom)
}

Write-Host ''
Write-Host 'Scroll controls installed.' -ForegroundColor Green
Write-Host "Layout: $layoutPath"
Write-Host "Partial: $partialTarget"
Write-Host "Backup: $backupRoot"
Write-Host 'Next run: php artisan view:clear'
