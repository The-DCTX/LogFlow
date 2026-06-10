# ─────────────────────────────────────────────────────────────
#  LogFlow Agent — Windows PowerShell
#  Surveille l'Event Log Windows et envoie les événements de
#  sécurité à LogFlow via HTTP.
#
#  Installation (PowerShell Admin) :
#    Set-ExecutionPolicy RemoteSigned -Scope LocalMachine
#    .\windows-agent.ps1 -Install
#
#  Lancement manuel :
#    .\windows-agent.ps1
# ─────────────────────────────────────────────────────────────
param(
    [switch]$Install,
    [switch]$Uninstall
)

$LOGFLOW_URL = "http://YOUR_SERVER/api/receive.php"
$API_KEY     = "YOUR_API_KEY"
$POLL_SECS   = 30        # intervalle de poll (secondes)
$MAX_EVENTS  = 100       # events max par poll

# Event IDs de sécurité à surveiller
$SECURITY_IDS = @(
    4624,   # Logon réussi
    4625,   # Logon échoué
    4634,   # Logoff
    4648,   # Logon avec credentials explicites (RunAs)
    4656,   # Accès objet demandé
    4672,   # Privilèges spéciaux assignés
    4688,   # Processus créé
    4698,   # Tâche planifiée créée
    4720,   # Compte utilisateur créé
    4722,   # Compte activé
    4723,   # Tentative changement MDP
    4724,   # MDP réinitialisé
    4725,   # Compte désactivé
    4726,   # Compte supprimé
    4728,   # Membre ajouté au groupe sécurisé
    4732,   # Membre ajouté groupe local
    4740,   # Compte verrouillé
    4756,   # Membre ajouté groupe universel
    4771,   # Kerberos pré-auth échouée
    4776,   # NTLM auth attempt
    7045,   # Nouveau service installé
    1102,   # Journal d'audit effacé
    104     # Journal système effacé
)

$SEV_MAP = @{
    4624 = 6; 4634 = 7; 4672 = 5; 4688 = 7
    4625 = 3; 4740 = 2; 4771 = 3; 4776 = 4
    4648 = 4; 4720 = 4; 4726 = 4; 4728 = 4
    4723 = 5; 4724 = 4; 4725 = 4; 4732 = 4
    4698 = 4; 4756 = 4; 7045 = 3; 1102 = 1; 104 = 1
}

# ── Install en tant que tâche planifiée ───────────────────────
if ($Install) {
    $scriptPath = $MyInvocation.MyCommand.Path
    $action  = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NonInteractive -File `"$scriptPath`""
    $trigger = New-ScheduledTaskTrigger -AtStartup
    $settings = New-ScheduledTaskSettingsSet -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1)
    $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName "LogFlowAgent" -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force
    Start-ScheduledTask -TaskName "LogFlowAgent"
    Write-Host "[LogFlow] Agent installé et démarré comme tâche planifiée SYSTEM."
    exit
}

if ($Uninstall) {
    Stop-ScheduledTask -TaskName "LogFlowAgent" -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName "LogFlowAgent" -Confirm:$false
    Write-Host "[LogFlow] Agent désinstallé."
    exit
}

# ── Fonction d'envoi ──────────────────────────────────────────
function Send-Logs($entries) {
    if ($entries.Count -eq 0) { return }
    $body = $entries | ConvertTo-Json -Compress
    if ($entries.Count -eq 1) { $body = "[$body]" }
    try {
        Invoke-RestMethod -Uri $LOGFLOW_URL -Method POST -Body $body `
            -ContentType "application/json" `
            -Headers @{"X-Api-Key" = $API_KEY} | Out-Null
    } catch {
        Write-Warning "[LogFlow] Envoi échoué : $_"
    }
}

# ── Boucle principale ─────────────────────────────────────────
$hostname   = $env:COMPUTERNAME
$lastRun    = (Get-Date).AddSeconds(-$POLL_SECS)

Write-Host "[LogFlow Agent Windows] Démarré sur $hostname"
Write-Host "[LogFlow Agent] Envoi vers : $LOGFLOW_URL"

while ($true) {
    $since = $lastRun
    $lastRun = Get-Date
    $entries = @()

    # Sécurité
    try {
        $secEvents = Get-WinEvent -FilterHashtable @{
            LogName   = 'Security'
            Id        = $SECURITY_IDS
            StartTime = $since
        } -MaxEvents $MAX_EVENTS -ErrorAction SilentlyContinue

        foreach ($ev in $secEvents) {
            $sev = if ($SEV_MAP.ContainsKey($ev.Id)) { $SEV_MAP[$ev.Id] } else { 5 }
            $msg = "EventID: $($ev.Id) | $($ev.Message -replace '\r?\n',' ' -replace '\s+',' ')"
            $entries += @{
                host     = $hostname
                program  = "WinEvent"
                severity = $sev
                message  = $msg.Substring(0, [Math]::Min($msg.Length, 1000))
                os       = "windows"
                time     = $ev.TimeCreated.ToString("yyyy-MM-dd HH:mm:ss")
            }
        }
    } catch {}

    # Application (erreurs critiques)
    try {
        $appEvents = Get-WinEvent -FilterHashtable @{
            LogName   = 'Application'
            Level     = @(1, 2)           # Critical + Error
            StartTime = $since
        } -MaxEvents 20 -ErrorAction SilentlyContinue

        foreach ($ev in $appEvents) {
            $sev = if ($ev.Level -eq 1) { 2 } else { 3 }
            $msg = "$($ev.ProviderName): $($ev.Message -replace '\r?\n',' ' -replace '\s+',' ')"
            $entries += @{
                host     = $hostname
                program  = $ev.ProviderName
                severity = $sev
                message  = $msg.Substring(0, [Math]::Min($msg.Length, 1000))
                os       = "windows"
                time     = $ev.TimeCreated.ToString("yyyy-MM-dd HH:mm:ss")
            }
        }
    } catch {}

    # Système (erreurs + warnings)
    try {
        $sysEvents = Get-WinEvent -FilterHashtable @{
            LogName   = 'System'
            Level     = @(1, 2, 3)
            StartTime = $since
        } -MaxEvents 20 -ErrorAction SilentlyContinue

        foreach ($ev in $sysEvents) {
            $sev = switch ($ev.Level) { 1 { 2 } 2 { 3 } 3 { 4 } default { 5 } }
            $msg = "$($ev.ProviderName): $($ev.Message -replace '\r?\n',' ' -replace '\s+',' ')"
            $entries += @{
                host     = $hostname
                program  = $ev.ProviderName
                severity = $sev
                message  = $msg.Substring(0, [Math]::Min($msg.Length, 1000))
                os       = "windows"
                time     = $ev.TimeCreated.ToString("yyyy-MM-dd HH:mm:ss")
            }
        }
    } catch {}

    Send-Logs $entries
    Write-Host "[LogFlow] $(Get-Date -Format 'HH:mm:ss') — $($entries.Count) événements envoyés"
    Start-Sleep -Seconds $POLL_SECS
}
