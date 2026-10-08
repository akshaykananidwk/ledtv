<#
  Krishna Cloud TV Management bulk setup tool (Windows PowerShell 5.1+).

  For every TV listed in tvs.csv it:
    1. connects with adb (pairs first on Android 11+ "Wireless debugging" TVs - asks for the 6-digit code)
    2. installs / updates the Krishna Cloud TV APK found in this folder
    3. removes extra Android users (Kids / guest profiles) - required for device owner
    4. checks Google accounts (device owner is impossible while an account exists)
    5. makes the TV app the device owner (kiosk, real standby/wake, silent updates, reboot)
    6. allows auto-start (SYSTEM_ALERT_WINDOW)
    7. sends server address, screen name / ID and registration key to the app and waits until the TV
       has registered with the server
  and writes a colour summary plus a log file.

  Usage: double-click KrishnaCloud-Setup.bat   (or: powershell -ExecutionPolicy Bypass -File KrishnaCloud-Setup.ps1 [-Csv tvs.csv])
#>
param(
    [string]$Csv = "",
    [switch]$SkipDeviceOwner
)

$ErrorActionPreference = "Continue"
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Here
$Stamp = Get-Date -Format "yyyyMMdd_HHmm"
$LogFile = Join-Path $Here "setup_log_$Stamp.txt"
$ResultFile = Join-Path $Here "setup_result_$Stamp.csv"
$Package = "com.hotelcast.tv"
$Admin = "com.hotelcast.tv/.AdminReceiver"

function Log([string]$msg, [string]$color = "Gray") {
    $line = "[{0}] {1}" -f (Get-Date -Format "HH:mm:ss"), $msg
    Write-Host $line -ForegroundColor $color
    Add-Content -Path $LogFile -Value $line -Encoding UTF8
}
function Title([string]$en, [string]$gu) {
    Write-Host ""
    Write-Host ("=" * 70) -ForegroundColor DarkCyan
    Write-Host " $en" -ForegroundColor Cyan
    if ($gu) { Write-Host " $gu" -ForegroundColor Cyan }
    Write-Host ("=" * 70) -ForegroundColor DarkCyan
    Add-Content -Path $LogFile -Value "== $en" -Encoding UTF8
}

# ------------------------------------------------------------------ adb
function Find-Adb {
    $local = Join-Path $Here "platform-tools\adb.exe"
    if (Test-Path $local) { return $local }
    $cmd = Get-Command adb.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    Title "Downloading Android platform-tools (adb)..." "adb download થાય છે..."
    $zip = Join-Path $Here "platform-tools.zip"
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -UseBasicParsing -Uri "https://dl.google.com/android/repository/platform-tools-latest-windows.zip" -OutFile $zip
        Expand-Archive -Path $zip -DestinationPath $Here -Force
        Remove-Item $zip -Force
    } catch {
        Log "Could not download adb: $($_.Exception.Message)" "Red"
        Log "Download platform-tools manually from https://developer.android.com/tools/releases/platform-tools and extract it next to this script." "Yellow"
        exit 1
    }
    if (Test-Path $local) { return $local }
    Log "adb.exe not found after download." "Red"
    exit 1
}
$Adb = Find-Adb
Log "Using adb: $Adb"

function AdbRun([string[]]$argList, [int]$timeoutSec = 60) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $Adb
    $psi.Arguments = ($argList | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join " "
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $p = [System.Diagnostics.Process]::Start($psi)
    $outTask = $p.StandardOutput.ReadToEndAsync()
    $errTask = $p.StandardError.ReadToEndAsync()
    if (-not $p.WaitForExit($timeoutSec * 1000)) {
        try { $p.Kill() } catch { }
        return @{ code = -1; out = "TIMEOUT after $timeoutSec s" }
    }
    $text = ($outTask.Result + $errTask.Result).Trim()
    Add-Content -Path $LogFile -Value ("   adb " + $psi.Arguments + "`r`n   " + ($text -replace "`n", "`n   ")) -Encoding UTF8
    return @{ code = $p.ExitCode; out = $text }
}
function Shell([string]$serial, [string]$cmd, [int]$timeoutSec = 60) {
    return (AdbRun @("-s", $serial, "shell", $cmd) $timeoutSec).out
}

# ------------------------------------------------------------------ input files
if (-not $Csv) { $Csv = Join-Path $Here "tvs.csv" }
if (-not (Test-Path $Csv)) {
    Log "tvs.csv not found. Download it from Admin -> Screens & TVs -> 'Download setup file' (or copy tvs.sample.csv)." "Red"
    Log "tvs.csv મળી નથી. Admin -> Screens & TVs -> 'Download setup file' માંથી download કરો." "Red"
    exit 1
}
$Server = ""; $Key = ""; $Tvs = @()
foreach ($raw in Get-Content -Path $Csv -Encoding UTF8) {
    $line = $raw.Trim()
    if ($line -eq "" -or $line.StartsWith("#")) { continue }
    $parts = $line.Split(",") | ForEach-Object { $_.Trim() }
    if ($parts[0] -eq "server") { $Server = $parts[1]; continue }
    if ($parts[0] -eq "key") { $Key = $parts[1]; continue }
    if ($parts[0] -eq "tv_address") { continue }
    if ($parts.Count -ge 2 -and $parts[0] -and $parts[1]) { $Tvs += [pscustomobject]@{ Address = $parts[0]; Room = $parts[1] } }
}
if (-not $Server -or -not $Key) { Log "tvs.csv must contain 'server,<url>' and 'key,<registration key>' lines." "Red"; exit 1 }
if ($Tvs.Count -eq 0) { Log "No TVs in tvs.csv." "Red"; exit 1 }

$Apk = Get-ChildItem -Path $Here -Filter "*-TV-*.apk" | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $Apk) { Log "Put KrishnaCloud-TV-x.y.z.apk in this folder first. / APK ફાઇલ આ folder માં મૂકો." "Red"; exit 1 }

Title "Krishna Cloud TV bulk setup: $($Tvs.Count) TV(s)" "Krishna Cloud TV setup: $($Tvs.Count) TV"
Log "Server: $Server"
Log "APK:    $($Apk.Name)"
Write-Host ""
Write-Host "Before starting, on EVERY TV: Settings > Developer options > turn ON 'USB debugging' and 'Wireless debugging' (Android 11+)" -ForegroundColor Yellow
Write-Host "દરેક TV માં: Settings > Developer options > 'USB debugging' અને 'Wireless debugging' ચાલુ કરો." -ForegroundColor Yellow
Write-Host "Remove the Google account first if you want full control (device owner)." -ForegroundColor Yellow
Write-Host "પૂરું control (device owner) જોઈએ તો પહેલાં TV માંથી Google account કાઢો." -ForegroundColor Yellow
Read-Host "Press ENTER to start / શરૂ કરવા ENTER દબાવો" | Out-Null

& $Adb start-server | Out-Null
$Results = @()

# ------------------------------------------------------------------ per TV
function Connect-Tv([string]$address) {
    $target = $address
    if ($target -notmatch ":\d+$") { $target = "${target}:5555" }
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        $r = AdbRun @("connect", $target) 20
        if ($r.out -match "connected to|already connected") {
            Start-Sleep -Seconds 1
            $state = (AdbRun @("-s", $target, "get-state") 10).out
            if ($state -match "device") { return $target }
            if ($state -match "unauthorized") {
                Write-Host "  Accept 'Allow USB debugging' on the TV (tick 'Always allow'), then press ENTER." -ForegroundColor Yellow
                Write-Host "  TV પર 'Allow USB debugging' માં 'Always allow' ટિક કરી OK દબાવો, પછી ENTER." -ForegroundColor Yellow
                Read-Host | Out-Null
                continue
            }
        }
        # Android 11+: needs pairing first (or the port changed)
        Write-Host ""
        Write-Host "  Could not connect to $target." -ForegroundColor Yellow
        Write-Host "  On the TV open: Developer options > Wireless debugging > 'Pair device with pairing code'." -ForegroundColor Yellow
        Write-Host "  TV પર Wireless debugging > 'Pair device with pairing code' ખોલો." -ForegroundColor Yellow
        $pairAddr = Read-Host "  Pairing IP:PORT shown in that box (e.g. 192.168.10.7:39439) [ENTER = skip TV]"
        if (-not $pairAddr) { return $null }
        $code = Read-Host "  6-digit pairing code / 6 આંકડાનો code"
        $pr = AdbRun @("pair", $pairAddr, $code) 30
        if ($pr.out -notmatch "Successfully paired") { Log "  Pairing failed: $($pr.out)" "Red"; continue }
        Log "  Paired." "Green"
        $newPort = Read-Host "  Now close the box. 'IP address and port' on the Wireless debugging screen (ENTER = $target)"
        if ($newPort) { $target = $newPort }
    }
    return $null
}

foreach ($tv in $Tvs) {
    Title "Screen $($tv.Room)  ($($tv.Address))" "સ્ક્રીન $($tv.Room)"
    $res = [ordered]@{ Room = $tv.Room; Address = $tv.Address; Connected = "no"; Installed = "no"; DeviceOwner = "no"; Registered = "no"; Note = "" }
    try {
        $serial = Connect-Tv $tv.Address
        if (-not $serial) { $res.Note = "could not connect"; Log "  FAILED: could not connect" "Red"; $Results += [pscustomobject]$res; continue }
        $res.Connected = "yes"; $res.Address = $serial
        $model = Shell $serial "getprop ro.product.model"
        $android = Shell $serial "getprop ro.build.version.release"
        Log "  Connected: $model, Android $android" "Green"

        # 1. install
        Log "  Installing $($Apk.Name) ..."
        $ir = AdbRun @("-s", $serial, "install", "-r", "-g", $Apk.FullName) 300
        if ($ir.out -match "Success") { $res.Installed = "yes"; Log "  Installed." "Green" }
        elseif ($ir.out -match "INSTALL_FAILED_UPDATE_INCOMPATIBLE") {
            $res.Note = "signature differs: uninstall old app first"; Log "  FAILED: a different TV app build is installed. Uninstall it on the TV first." "Red"
        } else { $res.Note = "install failed"; Log "  Install failed: $($ir.out)" "Red" }

        # 2. device owner
        if (-not $SkipDeviceOwner) {
            $owners = Shell $serial "dpm list-owners"
            if ($owners -match [regex]::Escape($Package)) {
                $res.DeviceOwner = "yes"; Log "  Already device owner." "Green"
            } else {
                $users = Shell $serial "pm list users"
                foreach ($m in [regex]::Matches($users, "UserInfo\{(\d+):([^:]*):")) {
                    $uid = $m.Groups[1].Value
                    if ($uid -ne "0") {
                        Log "  Removing extra user $uid ($($m.Groups[2].Value)) ..." "Yellow"
                        Shell $serial "pm remove-user $uid" | Out-Null
                    }
                }
                $acc = Shell $serial "dumpsys account"
                $accCount = 0
                if ($acc -match "Accounts:\s*(\d+)") { $accCount = [int]$Matches[1] }
                if ($accCount -gt 0) {
                    $res.Note = "Google account on TV - remove it for device owner"
                    Log "  Device owner NOT possible: $accCount account(s) on the TV. Remove them (Settings > Accounts) or factory reset, then run again." "Red"
                    Log "  TV માં Google account છે - Settings > Accounts માંથી કાઢો અથવા factory reset કરો, પછી ફરી ચલાવો." "Red"
                } else {
                    $dr = Shell $serial "dpm set-device-owner $Admin"
                    if ($dr -match "Success") { $res.DeviceOwner = "yes"; Log "  Device owner set." "Green" }
                    else { $res.Note = "device owner failed"; Log "  Device owner failed: $dr" "Red" }
                }
            }
        }

        # 3. auto start permission
        Shell $serial "appops set $Package SYSTEM_ALERT_WINDOW allow" | Out-Null

        # 4. provision + register
        AdbRun @("-s", $serial, "logcat", "-c") 10 | Out-Null
        Shell $serial "am force-stop $Package" | Out-Null
        $cmd = "am start -n $Package/.SettingsActivity --es hc_server '$Server' --es hc_room '$($tv.Room)' --es hc_key '$Key' --ez hc_autoregister true --ez hc_force true"
        Shell $serial $cmd | Out-Null
        Log "  Registering screen $($tv.Room) ..."
        $deadline = (Get-Date).AddSeconds(45)
        $reg = ""
        while ((Get-Date) -lt $deadline) {
            Start-Sleep -Seconds 3
            $reg = (AdbRun @("-s", $serial, "logcat", "-d", "-s", "HotelCastSetup") 10).out
            if ($reg -match "REGISTERED|FAILED") { break }
        }
        if ($reg -match "REGISTERED") { $res.Registered = "yes"; Log "  Registered with the server." "Green" }
        elseif ($reg -match "FAILED\s*(.*)") { $res.Note = ($res.Note + " register: " + $Matches[1]).Trim(); Log "  Registration FAILED: $($Matches[1])" "Red" }
        else { $res.Note = ($res.Note + " register: no answer").Trim(); Log "  No registration result (check the TV screen)." "Yellow" }

        Shell $serial "am start -n $Package/.MainActivity" | Out-Null
    } catch {
        $res.Note = $_.Exception.Message
        Log "  ERROR: $($_.Exception.Message)" "Red"
    }
    $Results += [pscustomobject]$res
}

# ------------------------------------------------------------------ summary
Title "Summary / પરિણામ" ""
Write-Host ("{0,-8} {1,-22} {2,-9} {3,-9} {4,-12} {5,-10} {6}" -f "Screen", "TV", "Connect", "Install", "DeviceOwner", "Registered", "Note")
foreach ($r in $Results) {
    $c = if ($r.Registered -eq "yes" -and $r.DeviceOwner -eq "yes") { "Green" } elseif ($r.Registered -eq "yes") { "Yellow" } else { "Red" }
    $row = "{0,-8} {1,-22} {2,-9} {3,-9} {4,-12} {5,-10} {6}" -f $r.Room, $r.Address, $r.Connected, $r.Installed, $r.DeviceOwner, $r.Registered, $r.Note
    Write-Host $row -ForegroundColor $c
    Add-Content -Path $LogFile -Value $row -Encoding UTF8
}
$Results | Export-Csv -Path $ResultFile -NoTypeInformation -Encoding UTF8
$ok = @($Results | Where-Object { $_.Registered -eq "yes" }).Count
Log "$ok / $($Results.Count) TVs ready. Log: $LogFile  Result: $ResultFile" ($(if ($ok -eq $Results.Count) { "Green" } else { "Yellow" }))
Write-Host ""
Write-Host "Security: turn OFF 'Wireless debugging' on every TV when you are done." -ForegroundColor Yellow
Write-Host "સલામતી માટે: કામ પૂરું થયા પછી દરેક TV માં 'Wireless debugging' બંધ કરો." -ForegroundColor Yellow
