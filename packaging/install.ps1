#Requires -Version 5.1
<#
.SYNOPSIS
  Thin installer for the Social Spot server (Windows).

  powershell -ExecutionPolicy Bypass -File packaging\install.ps1 [-Force]

  Mirrors install.sh: checks for Docker, asks a few questions, writes
  packaging\.env, and runs `docker compose up`. Re-running against an existing
  .env just rebuilds and restarts (the upgrade path), unless -Force rewrites
  the answers. Every answer is also overridable from the environment.
#>
param([switch]$Force)

$ErrorActionPreference = 'Stop'
$Dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$EnvFile = Join-Path $Dir '.env'

function Say([string]$Message) { Write-Host "[install] $Message" -ForegroundColor Cyan }
function Fail([string]$Message) { Write-Host "[install] $Message" -ForegroundColor Red; exit 1 }

try { docker info --format '{{.ServerVersion}}' | Out-Null }
catch { Fail "Docker is not installed or not running. Install Docker Desktop (https://www.docker.com/products/docker-desktop/), start it, then run this again." }
try { docker compose version | Out-Null }
catch { Fail "The Docker Compose plugin is missing (Docker Desktop ships it)." }

# Alphanumerics only, so values need no quoting in .env or in SQL.
function New-Secret {
  (-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ }))
}

function Ask-Plain([string]$Name, [string]$Prompt, [string]$Default) {
  $current = [Environment]::GetEnvironmentVariable($Name, 'Process')
  if ($current) { return $current }
  $answer = Read-Host "$Prompt [$Default]"
  if ([string]::IsNullOrWhiteSpace($answer)) { return $Default }
  return $answer
}

if ((Test-Path $EnvFile) -and (-not $Force)) {
  Say 'using the existing answers in packaging\.env (pass -Force to redo them)'
  Get-Content $EnvFile | ForEach-Object {
    if ($_ -match '^\s*#' -or $_ -notmatch '=') { return }
    $key, $value = $_.Split('=', 2)
    [Environment]::SetEnvironmentVariable($key.Trim(), $value.Trim(), 'Process')
  }
}
else {
  Say 'a few questions (Enter accepts the default shown in brackets)'
  $httpPort = Ask-Plain 'HTTP_PORT' 'Port on this machine that serves the site' '8080'
  $baseUrl = Ask-Plain 'BASE_URL' 'Public address members type into their browsers' "http://localhost:$httpPort"
  $orgName = Ask-Plain 'ORG_NAME' 'Organization name' 'Social Spot'

  Write-Host 'Kind of organization (only the starting feature set; everything can'
  Write-Host 'still be toggled in the officer console afterwards):'
  Write-Host '  1) hoa        Homeowners association'
  Write-Host '  2) club       Club or society'
  Write-Host '  3) nonprofit  Non-profit organization'
  Write-Host '  4) community  Neighborhood or general community'
  $choice = Read-Host 'Choice [1]'
  $profile = switch ($choice) {
    '2' { 'club' } '3' { 'nonprofit' } '4' { 'community' } default { 'hoa' }
  }

  $adminNick = Ask-Plain 'ADMIN_NICK' 'Administrator account name' 'admin'
  $adminMail = Ask-Plain 'ADMIN_MAIL' 'Administrator email' 'admin@example.com'

  $adminPass = [Environment]::GetEnvironmentVariable('ADMIN_PASS', 'Process')
  if (-not $adminPass) {
    $secure = Read-Host 'Administrator password (empty generates a safe one)' -AsSecureString
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
      [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
    if ([string]::IsNullOrEmpty($plain)) { $plain = New-Secret }
    $adminPass = $plain
  }

  $lines = @(
    '# Written by packaging\install.ps1 - passwords live here, never commit this file.',
    "BASE_URL=$baseUrl",
    "HTTP_PORT=$httpPort",
    "ORG_NAME=$orgName",
    "ORG_PROFILE=$profile",
    "ADMIN_NICK=$adminNick",
    "ADMIN_PASS=$adminPass",
    "ADMIN_MAIL=$adminMail",
    'DB_NAME=socialspot',
    'DB_USER=socialspot',
    "DB_PASSWORD=$(New-Secret)",
    "DB_ROOT_PASSWORD=$(New-Secret)"
  )
  # No BOM: some env-file readers choke on one, and there is nothing non-ASCII here.
  [IO.File]::WriteAllLines($EnvFile, $lines, (New-Object Text.UTF8Encoding $false))
  try {
    icacls $EnvFile /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
  } catch {
    Write-Host '[install] note: could not lock down .env permissions; the passwords are still only in that file.' -ForegroundColor Yellow
  }
  Say 'answers written to packaging\.env'
  [Environment]::SetEnvironmentVariable('BASE_URL', $baseUrl, 'Process')
  [Environment]::SetEnvironmentVariable('HTTP_PORT', $httpPort, 'Process')
}

$port = [Environment]::GetEnvironmentVariable('HTTP_PORT', 'Process')
if (-not $port) { $port = '8080' }
$site = [Environment]::GetEnvironmentVariable('BASE_URL', 'Process')
$admin = [Environment]::GetEnvironmentVariable('ADMIN_NICK', 'Process')
if (-not $admin) { $admin = 'admin' }

Say 'building and starting (first build fetches Friendica core and its dependencies, so expect several minutes with network activity)'
Push-Location $Dir
try {
  docker compose up -d --build
  if ($LASTEXITCODE -ne 0) { throw 'compose failed' }
}
catch {
  Pop-Location
  Fail "'docker compose up' failed; the output above names the cause (often Docker Desktop not running)."
}
Pop-Location

# Readiness of the APPLICATION is what matters: /login only renders once the
# database behind it is actually connected.
Say 'waiting for the site to answer (up to 3 minutes on first boot)'
$ready = $false
for ($i = 0; $i -lt 90; $i++) {
  try {
    Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 "http://127.0.0.1:$port/login" -OutFile $null
    $ready = $true
    break
  } catch { Start-Sleep -Seconds 2 }
}
if (-not $ready) {
  Fail "the site is not answering yet. It may still be on first-boot provisioning - follow it with:  docker compose -f packaging\compose.yaml logs -f app"
}

Write-Host ""
Write-Host "Your server is up at $site" -ForegroundColor Green
Write-Host "Sign in as $admin with the administrator password, then open the Officer console."
Write-Host 'The generated database passwords are in packaging\.env - keep a copy somewhere safe.'
Write-Host ''
Write-Host 'Useful from here:  docker compose -f packaging\compose.yaml logs -f app'
Write-Host 'Backup is one command - see docs\packaging.md.'
