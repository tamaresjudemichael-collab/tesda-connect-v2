$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$target = Join-Path $projectRoot 'resources\views\livewire\enrollments\index.blade.php'

if (-not (Test-Path -LiteralPath $target -PathType Leaf)) {
    throw "Enrollment view not found: $target. Extract this ZIP into the Laravel project root (same folder as artisan)."
}

$content = [System.IO.File]::ReadAllText($target)
$pattern = "->when\(\`$this->status\s*!==\s*'all',\s*fn\s*\(\`$query\)\s*=>\s*\`$query->where\('application_status',\s*\`$this->status\)\)"

if ($content -match "status\s*===\s*'verified_records'" -and $content -match "in_array\(\`$this->status,\s*\['all',\s*'verified_records'\]") {
    Write-Host 'Verified Records filter already contains the fix. No file changed.' -ForegroundColor Yellow
    exit 0
}

$matches = [regex]::Matches($content, $pattern)
if ($matches.Count -ne 1) {
    throw "Expected exactly one standard enrollment status filter in $target, but found $($matches.Count). No file changed. Please send the current applications query block so it can be patched safely."
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $projectRoot "storage\app\patch-backups\verified-records-filter-$stamp\resources\views\livewire\enrollments"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
Copy-Item -LiteralPath $target -Destination (Join-Path $backupDir 'index.blade.php') -Force

$replacement = @'
->when($this->status === 'verified_records', fn ($query) => $query->whereIn('application_status', ['verified', 'confirmed']))
        ->when(! in_array($this->status, ['all', 'verified_records'], true), fn ($query) => $query->where('application_status', $this->status))
'@
$updated = [regex]::Replace($content, $pattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $replacement }, 1)
[System.IO.File]::WriteAllText($target, $updated, [System.Text.UTF8Encoding]::new($false))

Write-Host 'Applied Verified Records filter fix.' -ForegroundColor Green
Write-Host "Backup: $(Join-Path $backupDir 'index.blade.php')"
Write-Host 'The verified_records filter now includes both verified and confirmed applications.' -ForegroundColor Green
