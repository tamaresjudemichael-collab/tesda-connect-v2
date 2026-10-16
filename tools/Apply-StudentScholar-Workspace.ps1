$ErrorActionPreference = 'Stop'

$projectRoot = (Get-Location).Path
if (-not (Test-Path (Join-Path $projectRoot 'artisan') -PathType Leaf)) {
    throw 'Patakbuhin ang script mula sa Laravel project root (folder na may artisan), halimbawa C:\xampp\htdocs\tesda-connect-v2.'
}

$stagingRoot = Join-Path $projectRoot 'student-scholar-workspace'
if (-not (Test-Path $stagingRoot -PathType Container)) {
    throw 'Hindi makita ang student-scholar-workspace folder. I-extract muna ang ZIP sa project root.'
}

$backupRoot = Join-Path $projectRoot ("storage\app\patch-backups\student-scholar-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Backup-ProjectFile {
    param([Parameter(Mandatory = $true)][string]$RelativePath)
    $fullPath = Join-Path $projectRoot $RelativePath
    if (-not (Test-Path $fullPath -PathType Leaf)) { return }
    $backupPath = Join-Path $backupRoot $RelativePath
    $backupDirectory = Split-Path -Parent $backupPath
    New-Item -ItemType Directory -Force -Path $backupDirectory | Out-Null
    Copy-Item -LiteralPath $fullPath -Destination $backupPath -Force
}

function Copy-StagedFile {
    param([Parameter(Mandatory = $true)][string]$RelativePath)
    $sourcePath = Join-Path $stagingRoot $RelativePath
    if (-not (Test-Path $sourcePath -PathType Leaf)) {
        throw "Hindi makita ang patch file: $RelativePath"
    }
    $destinationPath = Join-Path $projectRoot $RelativePath
    Backup-ProjectFile -RelativePath $RelativePath
    $destinationDirectory = Split-Path -Parent $destinationPath
    New-Item -ItemType Directory -Force -Path $destinationDirectory | Out-Null
    Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Force
    Write-Host "Na-install: $RelativePath" -ForegroundColor Green
}

function Update-ProjectText {
    param(
        [Parameter(Mandatory = $true)][string]$RelativePath,
        [Parameter(Mandatory = $true)][scriptblock]$Transform
    )
    $fullPath = Join-Path $projectRoot $RelativePath
    if (-not (Test-Path $fullPath -PathType Leaf)) {
        throw "Hindi makita ang required project file: $RelativePath"
    }
    $original = [System.IO.File]::ReadAllText($fullPath)
    $updated = & $Transform $original
    if ($updated -ceq $original) {
        Write-Host "Walang kailangang idagdag: $RelativePath" -ForegroundColor DarkGray
        return
    }
    Backup-ProjectFile -RelativePath $RelativePath
    [System.IO.File]::WriteAllText($fullPath, [string]$updated, $utf8NoBom)
    Write-Host "Na-update: $RelativePath" -ForegroundColor Green
}

$filesToInstall = @(
    'app/Models/Announcement.php',
    'app/Models/TrainingBatch.php',
    'database/migrations/2026_10_16_060000_add_next_class_details_to_training_batches.php',
    'database/migrations/2026_10_16_060100_add_submission_deadline_to_announcements.php',
    'resources/views/livewire/announcements/index.blade.php',
    'resources/views/livewire/learner/portal.blade.php',
    'resources/views/livewire/learner/requirements.blade.php',
    'resources/views/livewire/schedules/index.blade.php',
    'routes/student.php',
    'tests/Feature/StudentScholarWorkspaceTest.php'
)
foreach ($relativePath in $filesToInstall) {
    Copy-StagedFile -RelativePath $relativePath
}

# Register the isolated student route file without replacing existing Registrar/Focal/Offline routes.
Update-ProjectText -RelativePath 'routes/web.php' -Transform {
    param($content)
    if ($content.Contains("require __DIR__.'/student.php';")) { return $content }
    $anchor = "require __DIR__.'/settings.php';"
    if (-not $content.Contains($anchor)) {
        throw "Hindi mahanap ang settings.php require sa routes/web.php; hindi naidagdag ang student routes."
    }
    return $content.Replace($anchor, "require __DIR__.'/student.php';`r`n`r`n" + $anchor)
}

# Route the shared Dashboard item to the learner dashboard for applicants/scholars, keeping staff routing unchanged.
Update-ProjectText -RelativePath 'resources/views/layouts/app/sidebar.blade.php' -Transform {
    param($content)
    $updated = $content
    if (-not $updated.Contains('STUDENT SCHOLAR NAVIGATION V2')) {
        $studentPattern = '(?s)@if\s*\(\$user->hasRole\(''applicant'',\s*''scholar''\)\).*?@endif'
        $studentReplacement = @'
@if ($user->hasRole('applicant', 'scholar'))
                            <!-- STUDENT SCHOLAR NAVIGATION V2 -->
                            <div class="px-3 pt-4 pb-1 text-[10px] font-bold tracking-[0.2em] text-blue-200">ENROLLMENT SUBMISSION</div>
                            <flux:sidebar.item
                                icon="clipboard-document-list"
                                :href="route('learner.requirements')"
                                :current="request()->routeIs('learner.requirements')"
                                wire:navigate
                            >
                                View Requirements
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="clipboard-document-list"
                                :href="route('learner.registration')"
                                :current="request()->routeIs('learner.registration')"
                                wire:navigate
                            >
                                Submit Enrollment
                            </flux:sidebar.item>
                        @endif
'@
        $regex = [System.Text.RegularExpressions.Regex]::new($studentPattern, [System.Text.RegularExpressions.RegexOptions]::Singleline)
        $matches = $regex.Matches($updated)
        if ($matches.Count -lt 1) {
            throw "Hindi mahanap ang applicant/scholar navigation block sa sidebar.blade.php. Walang binagong sidebar."
        }
        $updated = $regex.Replace($updated, $studentReplacement, 1)
    }

    $dashboardPattern = '(?s)<flux:sidebar\.item\s+icon="home"\s+:href="route\(''dashboard''\)"\s+:current="request\(\)->routeIs\(''dashboard''\)"\s+wire:navigate\s*>\s*Dashboard\s*</flux:sidebar\.item>'
    if ($updated -notmatch 'hasRole\(''applicant'', ''scholar''\) \? route\(''learner\.portal''\)') {
        $dashboardReplacement = @'
<flux:sidebar.item
                            icon="home"
                            :href="$user->hasRole('applicant', 'scholar') ? route('learner.portal') : route('dashboard')"
                            :current="request()->routeIs('dashboard', 'learner.portal')"
                            wire:navigate
                        >
                            Dashboard
                        </flux:sidebar.item>
'@
        $regex = [System.Text.RegularExpressions.Regex]::new($dashboardPattern, [System.Text.RegularExpressions.RegexOptions]::Singleline)
        if (-not $regex.IsMatch($updated)) {
            throw "Hindi mahanap ang generic Dashboard link sa sidebar. Walang binagong dashboard link."
        }
        $updated = $regex.Replace($updated, $dashboardReplacement, 1)
    }

    # This fallback group contains applicant/scholar navigation; staff role branches above remain untouched.
    $updated = $updated.Replace('<flux:sidebar.group heading="MAIN MENU" class="grid text-blue-200">', '<flux:sidebar.group heading="STUDENT / SCHOLAR" class="grid text-blue-200">')
    # Compatibility guard for the earlier Focal sidebar icon name that caused HTTP 500.
    $updated = $updated.Replace('icon="clipboard-check"', 'icon="clipboard-document-list"')
    return $updated
}

# Update the existing announcement lifecycle test in place to reflect explicit mark-as-read behavior.
Update-ProjectText -RelativePath 'tests/Feature/AnnouncementManagementTest.php' -Transform {
    param($content)
    $oldName = "test('learner portal records a unique view for a published learner announcement', function () {"
    if (-not $content.Contains($oldName)) { return $content }
    $pattern = "(?ms)^test\('learner portal records a unique view for a published learner announcement', function \(\) \{.*?^\}\);"
    $replacement = @'
test('learner portal keeps published announcements unread until the learner marks them as read', function () {
    $learner = User::factory()->create([
        'role' => 'scholar',
        'account_status' => 'active',
    ]);

    $announcement = Announcement::query()->create([
        'institution_id' => null,
        'author_user_id' => null,
        'title' => 'Learner safety briefing',
        'body' => 'Please read the safety guidance.',
        'audience' => 'scholar',
        'published_at' => now()->subMinute(),
    ]);

    $this->actingAs($learner);

    Livewire::test('learner.portal')
        ->assertSee('Learner safety briefing')
        ->assertSee('1 unread')
        ->assertSee('Mark as read')
        ->call('markAnnouncementRead', $announcement->id)
        ->assertSee('Read')
        ->assertDontSee('Mark as read');

    expect($announcement->views()->count())->toBe(1)
        ->and($announcement->views()->first()?->user_id)->toBe($learner->id);
});
'@
    $regex = [System.Text.RegularExpressions.Regex]::new($pattern)
    if (-not $regex.IsMatch($content)) {
        throw 'Hindi na-update ang dating learner announcement view test; hindi natagpuan ang buong test block.'
    }
    return $regex.Replace($content, $replacement, 1)
}

Write-Host ''
Write-Host 'Na-install ang Student / Scholar workspace patch.' -ForegroundColor Cyan
Write-Host "Backup ng mga napalitan/binagong file: $backupRoot" -ForegroundColor Cyan
Write-Host 'Patakbuhin ngayon ang php artisan migrate, route:clear, at view:clear.' -ForegroundColor Yellow
