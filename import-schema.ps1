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

# Pass credentials through a throwaway option file rather than "-p<password>".
# That keeps the password out of the process list, where Task Manager and ps
# would otherwise expose it, and avoids mysql's "password on the command line"
# warning - which PowerShell surfaces as a NativeCommandError and, under
# $ErrorActionPreference = 'Stop', treats as fatal.
$optionFile = Join-Path ([System.IO.Path]::GetTempPath()) ('readpilot-import-' + [guid]::NewGuid().ToString('N') + '.cnf')

# Escape the password so a value containing # ; quotes or newlines cannot break
# out of the option-file format.
$escapedPassword = '"' + ($plain -replace '\\', '\\\\' -replace '"', '\"' -replace "`r`n", '\n' -replace "`n", '\n' -replace "`r", '\r') + '"'

$arguments = @(
    "--defaults-extra-file=$optionFile",
    '-e', "source $forwardSlashed"
)

$exitCode = 1
try {
    $optionFileContent = @(
        '[client]'
        "host=$Host_"
        "port=$Port"
        "user=$User"
        "password=$escapedPassword"
        'ssl-mode=REQUIRED'
    ) -join "`n"

    [System.IO.File]::WriteAllText($optionFile, $optionFileContent, (New-Object System.Text.UTF8Encoding $false))

    Write-Host ''
    Write-Host 'Importing...' -ForegroundColor Yellow

    # Anything mysql writes to stderr arrives as a PowerShell error record. It
    # must not abort the script, or a harmless warning hides the real result
    # and the exit code is never inspected.
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $output = & $mysql @arguments 2>&1
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousPreference
} finally {
    $plain = $null
    $escapedPassword = $null
    if (Test-Path $optionFile) { Remove-Item $optionFile -Force -ErrorAction SilentlyContinue }
}

# Interpret the driver's error code rather than guessing, because "access
# denied" and "host does not exist" need completely different fixes.
$errorText = ($output | ForEach-Object { "$_" }) -join "`n"
$code = if ($errorText -match 'ERROR\s+(\d{4})') { $Matches[1] } else { '' }

if ($exitCode -ne 0) {
    $raw = ($output | ForEach-Object { "$_" } | Where-Object { $_ -notmatch 'Warning.*command line interface' }) -join "`n"
    if ($raw.Trim()) { Write-Host $raw -ForegroundColor Red }
    Write-Host ''
}

Write-Host ''
switch ($code) {
    '1045' {
        Write-Host 'Rejected: wrong username or password.' -ForegroundColor Red
        Write-Host '  Re-copy the user and password from the Aiven Connection tab.'
    }
    '1044' {
        Write-Host 'Access denied on the database.' -ForegroundColor Red
        Write-Host '  Use the admin account (avnadmin). Ordinary service users are'
        Write-Host '  scoped to "defaultdb" and cannot run CREATE DATABASE.'
    }
    '1049' {
        Write-Host 'The database name does not exist.' -ForegroundColor Red
        Write-Host '  database.sql creates "readpilot" itself, so this usually means the'
        Write-Host '  connection landed somewhere unexpected. Check the host and port.'
    }
    '2005' {
        Write-Host 'Unknown MySQL server host (2005).' -ForegroundColor Red
        Write-Host '  The hostname did not resolve. Two usual causes:' -ForegroundColor Yellow
        Write-Host '   1. The hostname was mistyped. Copy-paste it from Aiven''s'
        Write-Host '      Connection tab rather than retyping it.'
        Write-Host '   2. Aiven powered the free service down while it sat idle.'
        Write-Host '      Open the Aiven console and press Power on if it shows as off.'
    }
    '2002' {
        Write-Host 'Connection refused (2002).' -ForegroundColor Red
        Write-Host '  Wrong port, or the service is stopped. Aiven usually assigns a'
        Write-Host '  random high port such as 23456 rather than 3306.'
    }
    '2003' {
        Write-Host 'Host unreachable (2003).' -ForegroundColor Red
        Write-Host '  The name resolved but nothing answered. Confirm the service is running.'
    }
    '1153' {
        Write-Host 'Connection dropped (1153).' -ForegroundColor Red
        Write-Host '  This is often a regional mismatch or an IP allowlist on the provider.'
    }
    default {
        Write-Host "mysql exited with code $exitCode." -ForegroundColor Red
    }
}

if ($exitCode -eq 0) {
    Write-Host 'Import finished.' -ForegroundColor Green
    Write-Host ''
    Write-Host '  Next:' -ForegroundColor Cyan
    Write-Host '   1. Set DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS / DB_SSLMODE in Render'
    Write-Host '   2. Sign in as admin@readpilot.local / ReadPilot2026! (delete the demo'
    Write-Host '      accounts afterwards)'
    Write-Host '   3. Open /setup.php to confirm all 15 tables imported'
}

exit $exitCode