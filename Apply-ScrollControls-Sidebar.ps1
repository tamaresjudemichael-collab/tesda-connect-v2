$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot

if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'artisan'))) {
    $currentDir = (Get-Location).Path
    if (Test-Path -LiteralPath (Join-Path $currentDir 'artisan')) {
        $projectRoot = $currentDir
    } else {
        throw "Could not find Laravel project root. Extract this ZIP directly into the folder containing artisan, or run the script from that folder."
    }
}

$layoutPath = Join-Path $projectRoot 'resources\views\layouts\app\sidebar.blade.php'
$partialSource = Join-Path $PSScriptRoot 'patch-files\resources\views\layouts\app\partials\scroll-controls.blade.php'
$partialDir = Join-Path $projectRoot 'resources\views\layouts\app\partials'
$partialTarget = Join-Path $partialDir 'scroll-controls.blade.php'

if (-not (Test-Path -LiteralPath $layoutPath -PathType Leaf)) { throw "Layout not found: $layoutPath" }
if (-not (Test-Path -LiteralPath $partialSource -PathType Leaf)) { throw "Patch partial not found: $partialSource. Extract the full ZIP." }

$layout = [System.IO.File]::ReadAllText($layoutPath)
$originalLayout = $layout
$slotPattern = '(?s)(<flux:spacer\s*/>\s*)<div class="border-t border-white/10 p-4">'
if (-not [regex]::IsMatch($layout, $slotPattern)) {
    throw 'Could not locate the sidebar spacer/profile boundary. No files were changed.'
}

# Remove existing include(s), including the earlier version placed near @fluxScripts.
$layout = [regex]::Replace($layout, '(?m)^\s*@include\([^\r\n]*scroll-controls[^\r\n]*\)\s*\r?\n', '')
# Place the controls after the flexible spacer and directly above the profile/sign-out footer.
$include = "`$1@include('layouts.app.partials.scroll-controls')`r`n`r`n            <div class=`"border-t border-white/10 p-4`">"
$layout = [regex]::Replace($layout, $slotPattern, $include, 1)

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\scroll-controls-sidebar-$timestamp"
$backupLayout = Join-Path $backupRoot 'resources\views\layouts\app\sidebar.blade.php'
$backupPartial = Join-Path $backupRoot 'resources\views\layouts\app\partials\scroll-controls.blade.php'
New-Item -ItemType Directory -Path (Split-Path -Parent $backupLayout) -Force | Out-Null
Copy-Item -LiteralPath $layoutPath -Destination $backupLayout -Force
if (Test-Path -LiteralPath $partialTarget) {
    New-Item -ItemType Directory -Path (Split-Path -Parent $backupPartial) -Force | Out-Null
    Copy-Item -LiteralPath $partialTarget -Destination $backupPartial -Force
}

New-Item -ItemType Directory -Path $partialDir -Force | Out-Null
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($layoutPath, $layout, $utf8NoBom)
Copy-Item -LiteralPath $partialSource -Destination $partialTarget -Force

Write-Host ''
Write-Host 'Scroll controls moved to the requested sidebar position.' -ForegroundColor Green
Write-Host 'Location: centered above the profile card and Sign out button.'
Write-Host "Backup: $backupRoot"
Write-Host 'Run: php artisan view:clear'
