$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$patchRoot = Join-Path $PSScriptRoot 'patch-files'

$routesPath = Join-Path $projectRoot 'routes\web.php'
$sidebarPath = Join-Path $projectRoot 'resources\views\layouts\app\sidebar.blade.php'
$dashboardPath = Join-Path $projectRoot 'resources\views\livewire\dashboard.blade.php'
$guideTarget = Join-Path $projectRoot 'resources\views\livewire\user-guide.blade.php'
$testTarget = Join-Path $projectRoot 'tests\Feature\UserGuideTest.php'
$guideSource = Join-Path $patchRoot 'resources\views\livewire\user-guide.blade.php'
$testSource = Join-Path $patchRoot 'tests\Feature\UserGuideTest.php'

foreach ($path in @($routesPath, $sidebarPath, $dashboardPath, $guideSource, $testSource)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required file was not found: $path. Extract the entire ZIP into the project root and replace matching files if asked."
    }
}

$routesText = [System.IO.File]::ReadAllText($routesPath)
$sidebarText = [System.IO.File]::ReadAllText($sidebarPath)
$dashboardText = [System.IO.File]::ReadAllText($dashboardPath)

# Prepare route update (inside the existing auth + active middleware group).
if ($routesText -notmatch "->name\('user-guide'\)") {
    $routeAnchor = [regex]::Match($routesText, 'Volt::route\([''"]dashboard[''"],\s*[''"]dashboard[''"]\)->name\([''"]dashboard[''"]\);')
    if (-not $routeAnchor.Success) { throw 'Could not find the dashboard route in routes/web.php. No files have been changed.' }
    $routeLine = "`r`n    Volt::route('user-guide', 'user-guide')->name('user-guide');"
    $routesText = $routesText.Insert($routeAnchor.Index + $routeAnchor.Length, $routeLine)
}

# Add one common sidebar link immediately after the Dashboard link.
if ($sidebarText -notmatch "route\('user-guide'\)") {
    $sidebarAnchor = [regex]::Match($sidebarText, '(?s)<flux:sidebar\.item\s+icon="home".*?>\s*Dashboard\s*</flux:sidebar\.item>')
    if (-not $sidebarAnchor.Success) { throw 'Could not find the Dashboard item in the sidebar. No files have been changed.' }
    $sidebarItem = @'

                    <flux:sidebar.item
                        icon="book-open"
                        :href="route('user-guide')"
                        :current="request()->routeIs('user-guide')"
                        wire:navigate
                    >
                        User Guide
                    </flux:sidebar.item>
'@
    $sidebarText = $sidebarText.Insert($sidebarAnchor.Index + $sidebarAnchor.Length, $sidebarItem)
}

# Add a visible guide callout to the authenticated home/dashboard.
if ($dashboardText -notmatch 'data-user-guide-card') {
    $dashboardAnchor = [regex]::Match($dashboardText, '<div class="mx-auto max-w-7xl[^>]*>')
    if (-not $dashboardAnchor.Success) { throw 'Could not find the dashboard container. No files have been changed.' }
    $dashboardCard = @'

    <a data-user-guide-card href="{{ route('user-guide') }}" wire:navigate class="group relative block overflow-hidden rounded-2xl bg-gradient-to-r from-[#102f68] via-[#174b83] to-cyan-700 p-5 text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg sm:p-6">
        <div class="pointer-events-none absolute -right-6 -top-12 h-40 w-40 rounded-full border-[20px] border-white/10"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-amber-400 text-2xl font-black text-[#102f68]">?</span>
                <span>
                    <span class="block text-xs font-bold uppercase tracking-[0.18em] text-cyan-100">New here? Start here.</span>
                    <span class="mt-1 block text-xl font-black">User Guide & Role Handbook</span>
                    <span class="mt-1 block max-w-2xl text-sm leading-6 text-blue-100">Learn what your role can do, follow the right workflow, and find answers to common questions.</span>
                </span>
            </div>
            <span class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-4 py-3 text-sm font-extrabold text-[#102f68] transition group-hover:bg-amber-300">Open User Guide <span aria-hidden="true">→</span></span>
        </div>
    </a>
'@
    $dashboardText = $dashboardText.Insert($dashboardAnchor.Index + $dashboardAnchor.Length, $dashboardCard)
}

# Validate patch sources are available before changing anything; then back up existing targets.
$backupStamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path $projectRoot "storage\app\patch-backups\user-guide-$backupStamp"
$existingTargets = @($routesPath, $sidebarPath, $dashboardPath, $guideTarget, $testTarget) | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf }
foreach ($source in $existingTargets) {
    $relative = $source.Substring($projectRoot.Length).TrimStart('\')
    $backupFile = Join-Path $backupRoot $relative
    $backupDir = Split-Path -Parent $backupFile
    New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $backupFile -Force
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($routesPath, $routesText, $utf8NoBom)
[System.IO.File]::WriteAllText($sidebarPath, $sidebarText, $utf8NoBom)
[System.IO.File]::WriteAllText($dashboardPath, $dashboardText, $utf8NoBom)

foreach ($target in @($guideTarget, $testTarget)) {
    $parent = Split-Path -Parent $target
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}
Copy-Item -LiteralPath $guideSource -Destination $guideTarget -Force
Copy-Item -LiteralPath $testSource -Destination $testTarget -Force

Write-Host ''
Write-Host 'User Guide installed successfully.' -ForegroundColor Green
Write-Host "Backup folder: $backupRoot"
Write-Host 'Next run:'
Write-Host '  php artisan route:clear'
Write-Host '  php artisan view:clear'
Write-Host '  php artisan route:list --name=user-guide'
Write-Host '  php artisan test --filter=UserGuideTest'
