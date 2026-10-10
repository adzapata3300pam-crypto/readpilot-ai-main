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
    [string]$Database = 'readpilot',
    [switch]$Fresh
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

    # Report the server version first. Schema compatibility differs sharply
    # across MySQL releases - notably ALTER TABLE ... ADD COLUMN IF NOT EXISTS,
    # which only arrived in 8.0.29.
    $previousVersion = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    function Invoke-Scalar {
        param([string]$Sql)
        # --batch --skip-column-names makes mysql emit only the value, so the
        # result can be read without having to guess which line is the data.
        $out = & $mysql @arguments --batch --skip-column-names -e $Sql 2>&1
        $last = ''
        foreach ($line in $out) { $last = "$line".Trim() }
        return $last
    }

    $serverVersion = Invoke-Scalar 'SELECT VERSION();'
    if ($serverVersion -match '\d+\.\d+\.\d+') {
        Write-Host ''
        Write-Host "  server : MySQL $serverVersion" -ForegroundColor DarkGray
    }

    # A previous run may have left tables behind that CREATE TABLE IF NOT EXISTS
    # will silently skip, which is how a half-built schema survives. -Fresh
    # removes that hazard, but only if the DROP actually succeeds - so the
    # outcome is checked rather than discarded.
    if ($Fresh) {
        Write-Host ''
        Write-Host '  -Fresh : dropping the database first' -ForegroundColor Yellow
        $dropOut = & $mysql @arguments -e "DROP DATABASE IF EXISTS ``$Database``;" 2>&1
        if ($LASTEXITCODE -ne 0) {
            $dropText = ($dropOut | ForEach-Object { "$_" }) -join ' '
            if ($dropText -match 'ERROR\s+(\d{4})') {
                Write-Host "  DROP FAILED (MySQL $($Matches[1])). The stale schema will survive." -ForegroundColor Red
                Write-Host '  Re-run with -Repair, which adds missing columns in place.' -ForegroundColor Yellow
            }
        }
    }

Write-Host 'Importing...' -ForegroundColor Yellow

    # Anything mysql writes to stderr arrives as a PowerShell error record. It
    # must not abort the script, or a harmless warning hides the real result
    # and the exit code is never inspected.
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $output = & $mysql @arguments 2>&1
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousPreference

    # Reconcile columns only once the tables are known to exist.
    if ($exitCode -eq 0) {
        # CREATE TABLE IF NOT EXISTS never alters a table that already exists,
        # so a run that aborted part way through leaves columns behind that
        # later runs cannot add back. Every column the application reads or
        # writes is therefore checked here. The existence check runs first, so
        # the ADD COLUMN itself needs no IF NOT EXISTS - a keyword that MySQL
        # servers older than 8.0.29 reject outright.
        $expectedColumns = @(
            @{ table = 'users';              column = 'profile_image';        definition = "VARCHAR(255) NOT NULL DEFAULT ''" },
            @{ table = 'users';              column = 'bio';                  definition = "TEXT NOT NULL DEFAULT ('')" },
            @{ table = 'users';              column = 'google_sub';           definition = 'VARCHAR(255) NULL' },
            @{ table = 'quiz_attempts';      column = 'session_id';           definition = 'BIGINT UNSIGNED NULL' },
            @{ table = 'session_recordings'; column = 'consent_confirmed_at'; definition = 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' },
            @{ table = 'access_requests';    column = 'id_document_path';     definition = 'VARCHAR(255) NULL' },
            @{ table = 'access_requests';    column = 'google_sub';           definition = 'VARCHAR(255) NULL' },
            @{ table = 'students';           column = 'notes';                definition = "TEXT NOT NULL DEFAULT ('')" }
        )

        $repaired = @()
        foreach ($entry in $expectedColumns) {
            $has = Invoke-Scalar ("SELECT COUNT(*) FROM information_schema.columns " +
                "WHERE table_schema = '$Database' AND table_name = '$($entry.table)' " +
                "AND column_name = '$($entry.column)';")

            if ($has -eq '1') { continue }

            Write-Host "  repairing: $($entry.table).$($entry.column)" -ForegroundColor Yellow
            & $mysql @arguments -e ("ALTER TABLE ``$($entry.table)`` ADD COLUMN ``$($entry.column)`` $($entry.definition);") 2>&1 |
                Where-Object { "$_" -notmatch 'Warning.*command line interface' } | Out-Null

            if ($LASTEXITCODE -eq 0) {
                $repaired += "$($entry.table).$($entry.column)"
            } else {
                Write-Host "  FAILED to add $($entry.table).$($entry.column)" -ForegroundColor Red
            }
        }

        if ($repaired.Count -gt 0) {
            Write-Host ''
            Write-Host "  Repaired $($repaired.Count) missing column(s): $($repaired -join ', ')" -ForegroundColor Green
        }
    }
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

    # --- Verify rather than assume ------------------------------------------
    # Count from information_schema rather than SHOW TABLES: it works no matter
    # which schema the session happens to be pointed at.
    $tableCountRaw = Invoke-Scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$Database';"
    $tableCount = -1
    if ($tableCountRaw -match '^\d+$') { $tableCount = [int]$tableCountRaw }

    Write-Host ''
    if ($tableCount -ge 15) {
        Write-Host "  Verified: $tableCount tables present in '$Database'." -ForegroundColor Green
    } elseif ($tableCount -ge 0) {
        Write-Host "  WARNING: found $tableCount tables in '$Database', expected 15." -ForegroundColor Yellow
        Write-Host '  If earlier attempts failed part way through, tables already exist' -ForegroundColor Yellow
        Write-Host '  and CREATE TABLE IF NOT EXISTS skipped them. Re-run with -Fresh.' -ForegroundColor Yellow
    } else {
        Write-Host "  Could not read the table count. Check the connection manually." -ForegroundColor Yellow
    }

    Write-Host ''
    Write-Host '  Next:' -ForegroundColor Cyan
    Write-Host '   1. Set DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS / DB_SSLMODE in Render'
    Write-Host '   2. Set DB_SSL_CA=/var/www/html/certs/ca.pem so the connection is encrypted'
    Write-Host '   3. Sign in as admin@readpilot.local / ReadPilot2026! (delete the demo'
    Write-Host '      accounts afterwards)'
    Write-Host '   4. Open /setup.php to confirm all 15 tables imported'
}

exit $exitCode
