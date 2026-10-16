$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$sidebarPath = Join-Path $projectRoot 'resources\views\layouts\app\sidebar.blade.php'

if (-not (Test-Path -LiteralPath $sidebarPath -PathType Leaf)) {
    throw "Sidebar file not found: $sidebarPath. Extract this ZIP into the Laravel project root. No files have been changed."
}

$sidebarText = [System.IO.File]::ReadAllText($sidebarPath)
$originalSidebar = $sidebarText

# Remove the existing handbook wrapper wherever it currently appears, so this patch can be re-run safely.
$guidePattern = '(?is)\r?\n[\t ]*<div\b(?=[^>]*\bdata-user-guide-sidebar\b)[^>]*>.*?</div>'
$sidebarText = [regex]::Replace($sidebarText, $guidePattern, '')

# Use the existing Flux spacer to push the handbook toward the sidebar bottom, just above the profile/sign-out area.
$spacerMatch = [regex]::Match($sidebarText, '(?i)<flux:spacer\s*/>')
if (-not $spacerMatch.Success) {
    throw 'Could not find <flux:spacer /> in sidebar.blade.php. No files have been changed.'
}

$guideBlock = @'

            <div data-user-guide-sidebar class="px-3 pb-3">
                <flux:sidebar.item
                    icon="book-open"
                    :href="route('user-guide')"
                    :current="request()->routeIs('user-guide')"
                    wire:navigate
                >
                    User Guide &amp; Role Handbook
                </flux:sidebar.item>
            </div>
'@

$insertAt = $spacerMatch.Index + $spacerMatch.Length
$sidebarText = $sidebarText.Substring(0, $insertAt) + $guideBlock + $sidebarText.Substring($insertAt)

if ($sidebarText -eq $originalSidebar) {
    Write-Host 'User Guide is already in the requested bottom position. No file changes needed.' -ForegroundColor Yellow
    exit 0
}

$backupStamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\user-guide-sidebar-bottom-$backupStamp"
New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
Copy-Item -LiteralPath $sidebarPath -Destination (Join-Path $backupRoot 'sidebar.blade.php') -Force

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($sidebarPath, $sidebarText, $utf8NoBom)

Write-Host ''
Write-Host 'User Guide & Role Handbook moved to the lower sidebar position.' -ForegroundColor Green
Write-Host 'It now sits below the flexible spacer and immediately above the profile/sign-out section.'
Write-Host "Backup: $backupRoot\sidebar.blade.php"
Write-Host 'Next run:'
Write-Host '  php artisan view:clear'
Write-Host '  php artisan test --filter=UserGuideTest'
