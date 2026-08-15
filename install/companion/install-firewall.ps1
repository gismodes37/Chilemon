<#
.SYNOPSIS
    Installs the Windows Firewall rule for ChileMon Companion IAX2 traffic.

.DESCRIPTION
    Creates an inbound firewall rule that allows the ChileMon Companion App
    to receive IAX2 UDP frames (NEWACK, ACCEPT, audio, control) from Asterisk.

    Windows Firewall Public profile blocks all inbound UDP by default,
    which prevents the companion from receiving responses from Asterisk
    even though its outbound UDP frames reach the server.

    This script MUST be run as Administrator.

.PARAMETER ProgramPath
    Full path to the companion executable.

.PARAMETER AsteriskIP
    Optional -- restrict the rule to a specific Asterisk server IP.

.PARAMETER Remove
    Switch -- remove the rule instead of creating it.
#>

param(
    [string]$ProgramPath = "$env:LOCALAPPDATA\ChileMon\chilemon-companion.exe",
    [string]$AsteriskIP = "",
    [switch]$Remove
)

$RuleName = "ChileMon Companion IAX2"

# ---- Admin check ----
$IsAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator
)

if (-not $IsAdmin) {
    Write-Host ""
    Write-Host "ERROR: Must run as Administrator" -ForegroundColor Red
    Write-Host ""
    Write-Host "Right-click PowerShell and select Run as Administrator" -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

# ---- Remove mode ----
if ($Remove) {
    $existing = Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue
    if ($existing) {
        Remove-NetFirewallRule -DisplayName $RuleName
        Write-Host "[OK] Rule removed: $RuleName" -ForegroundColor Green
    } else {
        Write-Host "[--] Rule does not exist: $RuleName" -ForegroundColor Yellow
    }
    exit 0
}

# ---- Validate program path ----
$resolvedPath = $null
if (Test-Path -LiteralPath $ProgramPath -PathType Leaf) {
    $resolvedPath = (Resolve-Path -LiteralPath $ProgramPath).Path
} else {
    Write-Host ""
    Write-Host "WARNING: Program path not found: $ProgramPath" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "The rule will be created, but it won't apply until the file exists."
    Write-Host ""
    $resolvedPath = $ProgramPath
}

# ---- Build rule parameters ----
$ruleParams = @{
    DisplayName = $RuleName
    Description = "Allow ChileMon Companion to receive IAX2 UDP frames from Asterisk"
    Direction   = [string]"Inbound"
    Protocol    = [string]"UDP"
    Program     = $resolvedPath
    Action      = [string]"Allow"
    Profile     = [string]"Any"
}

if ($AsteriskIP) {
    if ($AsteriskIP -notmatch '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$') {
        Write-Host "ERROR: Invalid IP format: $AsteriskIP" -ForegroundColor Red
        Write-Host "Use format: 192.168.0.116"
        exit 1
    }
    $ruleParams.RemoteAddress = $AsteriskIP
}

# ---- Create rule ----
Write-Host ""
Write-Host "ChileMon Companion -- Firewall Installer" -ForegroundColor Cyan
Write-Host "----------------------------------------" -ForegroundColor Cyan
Write-Host ""

$existing = Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "[..] Rule already exists -- updating..." -ForegroundColor Yellow
    Remove-NetFirewallRule -DisplayName $RuleName
}

try {
    New-NetFirewallRule @ruleParams -ErrorAction Stop | Out-Null
    Write-Host "[OK] Firewall rule created:" -ForegroundColor Green
    Write-Host "     Name:     $RuleName" -ForegroundColor Green
    Write-Host "     Program:  $resolvedPath" -ForegroundColor Green
    Write-Host "     Protocol: UDP (inbound)" -ForegroundColor Green
    if ($AsteriskIP) {
        Write-Host "     From:     $AsteriskIP" -ForegroundColor Green
    } else {
        Write-Host "     From:     Any source" -ForegroundColor Green
    }
    Write-Host ""
} catch {
    Write-Host "[FAIL] Could not create firewall rule:" -ForegroundColor Red
    Write-Host "      $_" -ForegroundColor Red
    Write-Host ""
    Write-Host "Try running: Get-NetFirewallRule | Where-Object Name -like ChileMon*" -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

# ---- Verify ----
$verify = Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue
if ($verify) {
    Write-Host "[OK] Rule verified" -ForegroundColor Green
} else {
    Write-Host "[WARN] Rule created but verification failed" -ForegroundColor Yellow
    Write-Host "Try: Get-NetFirewallRule -DisplayName $RuleName"
}

Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "  1. Restart the Companion App"
Write-Host "  2. On Asterisk: iax2 show peers"
Write-Host ""

# ---- Suggest changing network profile ----
$profile = Get-NetConnectionProfile -ErrorAction SilentlyContinue | Select-Object -First 1
if ($profile -and $profile.NetworkCategory -eq "Public") {
    Write-Host "TIP: Network $($profile.Name) is Public." -ForegroundColor Yellow
    Write-Host "     For best results, change to Private:" -ForegroundColor Yellow
    Write-Host "       Set-NetConnectionProfile -NetworkCategory Private" -ForegroundColor Yellow
    Write-Host ""
}
