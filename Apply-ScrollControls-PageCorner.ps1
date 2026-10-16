$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = $scriptDir

if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'artisan'))) {
    $currentDir = (Get-Location).Path
    if (Test-Path -LiteralPath (Join-Path $currentDir 'artisan')) {
        $projectRoot = $currentDir
    } else {
        throw "Could not find Laravel project root. Extract this ZIP directly into the folder containing artisan, or run the script from that folder."
    }
}

$layoutPath = Join-Path $projectRoot 'resources\views\layouts\app\sidebar.blade.php'
$partialSource = Join-Path $scriptDir 'patch-files\resources\views\layouts\app\partials\scroll-controls.blade.php'
$partialDir = Join-Path $projectRoot 'resources\views\layouts\app\partials'
$partialTarget = Join-Path $partialDir 'scroll-controls.blade.php'

if (-not (Test-Path -LiteralPath $layoutPath -PathType Leaf)) { throw "Layout not found: $layoutPath" }
if (-not (Test-Path -LiteralPath $partialSource -PathType Leaf)) { throw "Patch partial not found: $partialSource. Extract the full ZIP." }

$layout = [System.IO.File]::ReadAllText($layoutPath)
if ($layout -notmatch '(?m)^\s*@fluxScripts\s*$') {
    throw 'Could not find @fluxScripts in resources/views/layouts/app/sidebar.blade.php; no files were changed.'
}

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\scroll-controls-page-corner-$timestamp"
$backupLayout = Join-Path $backupRoot 'resources\views\layouts\app\sidebar.blade.php'
$backupPartial = Join-Path $backupRoot 'resources\views\layouts\app\partials\scroll-controls.blade.php'
New-Item -ItemType Directory -Path (Split-Path -Parent $backupLayout) -Force | Out-Null
Copy-Item -LiteralPath $layoutPath -Destination $backupLayout -Force
if (Test-Path -LiteralPath $partialTarget) {
    New-Item -ItemType Directory -Path (Split-Path -Parent $backupPartial) -Force | Out-Null
    Copy-Item -LiteralPath $partialTarget -Destination $backupPartial -Force
}

# Remove any previous placement (inside the sidebar footer or elsewhere in this layout).
$layout = [regex]::Replace($layout, '(?m)^[ \t]*@include\([\x27\"][^\r\n]*scroll-controls[^\r\n]*\)[ \t]*\r?\n?', '')
# Re-add exactly one include at the page-shell end, immediately before Flux scripts.
$evaluator = [System.Text.RegularExpressions.MatchEvaluator]{
    param($match)
    $indent = $match.Groups[1].Value
    return $indent + "@include('layouts.app.partials.scroll-controls')`r`n" + $indent + '@fluxScripts'
}
$layout = [regex]::Replace($layout, '(?m)^([ \t]*)@fluxScripts[ \t]*$', $evaluator, 1)

New-Item -ItemType Directory -Path $partialDir -Force | Out-Null
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($layoutPath, $layout, $utf8NoBom)
Copy-Item -LiteralPath $partialSource -Destination $partialTarget -Force

Write-Host ''
Write-Host 'Scroll controls moved to the fixed bottom-right corner of the page.' -ForegroundColor Green
Write-Host 'They appear after scrolling more than 80px; the down button hides near the page bottom.'
Write-Host "Backup: $backupRoot"
Write-Host 'Run: php artisan view:clear'
