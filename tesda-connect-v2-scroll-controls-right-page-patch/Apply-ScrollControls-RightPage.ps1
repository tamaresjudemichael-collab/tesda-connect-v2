$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot

if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'artisan'))) {
    $currentDir = (Get-Location).Path
    if (Test-Path -LiteralPath (Join-Path $currentDir 'artisan')) {
        $projectRoot = $currentDir
    } else {
        throw "Could not find Laravel project root. Extract this ZIP into the folder containing artisan, or run this script from that folder."
    }
}

$viewsRoot = Join-Path $projectRoot 'resources\views'
$sidebarPath = Join-Path $viewsRoot 'layouts\app\sidebar.blade.php'
$partialSource = Join-Path $PSScriptRoot 'patch-files\resources\views\layouts\app\partials\scroll-controls.blade.php'
$partialDir = Join-Path $viewsRoot 'layouts\app\partials'
$partialTarget = Join-Path $partialDir 'scroll-controls.blade.php'

if (-not (Test-Path -LiteralPath $partialSource -PathType Leaf)) { throw "Patch partial not found: $partialSource. Extract the entire ZIP first." }
if (-not (Test-Path -LiteralPath $viewsRoot -PathType Container)) { throw "Laravel views folder not found: $viewsRoot" }

# Prefer the application's top-level layout; otherwise find a non-sidebar layout with @fluxScripts.
$layoutPath = Join-Path $viewsRoot 'layouts\app.blade.php'
if (-not (Test-Path -LiteralPath $layoutPath -PathType Leaf)) {
    $layoutCandidates = Get-ChildItem -LiteralPath $viewsRoot -Filter '*.blade.php' -File -Recurse | Where-Object {
        $_.FullName -notmatch '[\\/]sidebar\.blade\.php$' -and
        (Select-String -LiteralPath $_.FullName -Pattern '@fluxScripts' -Quiet)
    }
    $layoutCandidates = @($layoutCandidates)
    if ($layoutCandidates.Count -eq 0) { throw "Could not find a non-sidebar Blade layout containing @fluxScripts under $viewsRoot." }
    $appLayoutCandidates = @($layoutCandidates | Where-Object { $_.FullName -match '[\\/]layouts[\\/]app[\\/]' })
    if ($appLayoutCandidates.Count -gt 0) {
        $layoutPath = $appLayoutCandidates[0].FullName
    } else {
        $layoutPath = $layoutCandidates[0].FullName
    }
}

$layout = [System.IO.File]::ReadAllText($layoutPath)
if (-not [regex]::IsMatch($layout, '@fluxScripts')) { throw "The selected layout does not contain @fluxScripts: $layoutPath" }

$sidebar = $null
if (Test-Path -LiteralPath $sidebarPath -PathType Leaf) {
    $sidebar = [System.IO.File]::ReadAllText($sidebarPath)
}
$existingPartial = Test-Path -LiteralPath $partialTarget -PathType Leaf

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\scroll-controls-right-page-$timestamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
Copy-Item -LiteralPath $layoutPath -Destination (Join-Path $backupRoot 'app-layout.blade.php') -Force
if ($null -ne $sidebar) { Copy-Item -LiteralPath $sidebarPath -Destination (Join-Path $backupRoot 'sidebar.blade.php') -Force }
if ($existingPartial) { Copy-Item -LiteralPath $partialTarget -Destination (Join-Path $backupRoot 'scroll-controls.blade.php') -Force }

# Remove any previous scroll-controls include from sidebar and main layout, then add exactly one at page-layout level.
if ($null -ne $sidebar) {
    $sidebar = [regex]::Replace($sidebar, '(?m)^[ \t]*@include\([^\r\n]*scroll-controls[^\r\n]*\)[ \t]*(?:\r?\n|$)', '')
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($sidebarPath, $sidebar, $utf8NoBom)
}

$layout = [regex]::Replace($layout, '(?m)^[ \t]*@include\([^\r\n]*scroll-controls[^\r\n]*\)[ \t]*(?:\r?\n|$)', '')
$include = "@include('layouts.app.partials.scroll-controls')`r`n"
$fluxScriptsPattern = '(?m)^[ \t]*@fluxScripts[^\r\n]*'
if (-not [regex]::IsMatch($layout, $fluxScriptsPattern)) { throw "Could not find a standalone @fluxScripts line in $layoutPath. No scroll partial was installed." }
$layout = [regex]::Replace($layout, $fluxScriptsPattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $include + $match.Value }, 1)

New-Item -ItemType Directory -Path $partialDir -Force | Out-Null
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($layoutPath, $layout, $utf8NoBom)
Copy-Item -LiteralPath $partialSource -Destination $partialTarget -Force

Write-Host ''
Write-Host 'Scroll controls moved to the RIGHT EDGE of the PAGE.' -ForegroundColor Green
Write-Host 'They are fixed at the lower-right corner, outside the sidebar, and appear only when page scroll exceeds 80px.'
Write-Host "Layout updated: $layoutPath"
Write-Host "Backup: $backupRoot"
Write-Host 'Next command: php artisan view:clear'
