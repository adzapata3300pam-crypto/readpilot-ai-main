<#
.SYNOPSIS
    Imports ReadPilot's schema into a hosted MySQL server.

.DESCRIPTION
    PowerShell has no '<' input redirection, and PowerShell 5.1 pipes native
    commands as us-ascii, which would silently replace the en-dashes in
    database.sql with '?'. This script therefore never pipes the file through
    PowerShell. It hands the path to the mysql client instead, via its own
    "source" command, so the file is read as raw bytes and stdin stays on the
    console for the password prompt.

.EXAMPLE
    .\import-schema.ps1
    .\import-schema.ps1 -Host gateway-sgp1.aivencloud.com -Port 23456 -User avnadmin
#>

[CmdletBinding()]
param(
    [string]$Host_,
    [int]$Port = 0,
    [string]$User,
    [string]$Database = 'readpilot'
)

$ErrorActionPreference = 'Stop'

$schema = Join-Path $PSScriptRoot 'database.sql'

if (-not (Test-Path $schema)) {
    throw "Cannot find database.sql next to this script ($PSScriptRoot)."
}

# --- Locate the mysql client -------------------------------------------------
$mysql = Get-Command mysql -ErrorAction SilentlyContinue
if (-not $mysql) {
    $candidates = @(
        'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe',
        'C:\xampp\mysql\bin\mysql.exe'
    ) | Where-Object { Test-Path $_ }

    if (-not $candidates) {
        throw 'mysql.exe was not found. Install the MySQL client, or add it to PATH.'
    }
    $mysql = $candidates[0]
}

Write-Host ''
Write-Host '  ReadPilot schema import' -ForegroundColor Cyan
Write-Host '  ------------------------'
Write-Host "  file  : $schema"
Write-Host "  client: $mysql"
Write-Host ''

# --- Prompt for anything not supplied ---------------------------------------
if (-not $Host_) { $Host_     = Read-Host 'Host (e.g. gateway-sgp1.aivencloud.com)' }
if (-not $Port)   { $Port      = [int](Read-Host 'Port (e.g. 23456)') }
if (-not $User)   { $User      = Read-Host 'User (use an admin account, e.g. avnadmin)' }

$secure = Read-Host 'Password' -AsSecureString
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$plain = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

# --- Build the argument list -------------------------------------------------
# "source" makes the mysql client read the file itself, so bytes stay intact and
# stdin is untouched, which is what lets -p prompt for the password.
$forwardSlashed = $schema.Replace('\', '/')

$arguments = @(
    '-h', $Host_,
    '-P', "$Port",
    '-u', $User,
    '-p$plain',
    '--ssl-mode=REQUIRED',
    '-e', "source $forwardSlashed"
)

Write-Host ''
Write-Host 'Importing...' -ForegroundColor Yellow

& $mysql @arguments
$exitCode = $LASTEXITCODE

$plain = $null

Write-Host ''
if ($exitCode -eq 0) {
    Write-Host 'Import finished.' -ForegroundColor Green
    Write-Host ''
    Write-Host '  Next:' -ForegroundColor Cyan
    Write-Host '   1. Set DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS / DB_SSLMODE in Render'
    Write-Host "   2. Sign in as admin@readpilot.local / ReadPilot2026!`
    (delete these demo accounts afterwards)"
    Write-Host '   3. Open /setup.php to confirm all 15 tables imported'
} else {
    Write-Host "mysql exited with code $exitCode." -ForegroundColor Red
    Write-Host ''
    Write-Host '  Access denied? Use an admin account such as avnadmin.' -ForegroundColor Yellow
    Write-Host '  Aiven scopes ordinary service users to "defaultdb" only, which'
    Write-Host '  cannot run CREATE DATABASE.'
}

exit $exitCode