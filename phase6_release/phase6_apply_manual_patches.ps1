# phase6_apply_manual_patches.ps1
#
# Wendet die 13 manuellen VORHER/NACHHER-Patches (A, A2, B, C, D, E1, E2,
# F1, F2, G, H, I, J) sicher auf die echten Projektdateien an.
#
# WARUM DIESES SKRIPT NOETIG IST:
#   Die Patches A-J sind Aenderungen an bereits existierenden Dateien.
#   Echte "git diff"-Patches waeren nur gueltig, wenn der exakte
#   Ausgangszustand der realen Datei bekannt ist. Da kein Lese-/Schreibzugriff
#   auf den echten Klon bestand, wurden die Patches stattdessen als
#   VORHER/NACHHER-Textpaare erstellt (manual_patches/NN_before.txt /
#   NN_after.txt) und werden hier per literalem Textvergleich angewendet.
#   Dieses Skript ersetzt NICHT "git am" - es ist ein separater,
#   zusaetzlicher Schritt (siehe phase6_release_orchestration.ps1).
#
# SICHERHEITSPRINZIP:
#   - Vor jeder Aenderung wird geprueft, wie oft der VORHER-Text in der
#     Zieldatei vorkommt (nach CRLF/LF-Normalisierung).
#   - Kommt er genau 1x vor: anwenden.
#   - Kommt er 0x vor, aber der NACHHER-Text ist bereits vollstaendig
#     vorhanden: als "bereits angewendet" ueberspringen (keine
#     Doppelanwendung).
#   - Kommt er 0x vor und NACHHER ist auch nicht vorhanden, oder kommt er
#     mehr als 1x vor: ABBRECHEN mit klarer Fehlermeldung, NICHTS
#     schreiben. Kein blindes Ueberschreiben.
#   - Dateien werden BOM-frei als UTF-8 gelesen/geschrieben (kein
#     Set-Content/Get-Content -Encoding UTF8, das in Windows PowerShell 5.1
#     ein BOM einfuegt und PHP-Dateien beschaedigen wuerde).
#   - Vor jeder Aenderung wird eine .bak-Kopie der Zieldatei angelegt
#     (einmalig pro Lauf, falls noch keine vorhanden ist).
#
# VORAUSSETZUNG:
#   - Wird aus dem Projekt-Root ausgefuehrt (Ordner, der private/, public/,
#     WEBSITE-INTEGRATION.html usw. enthaelt).
#   - Der Ordner manual_patches/ (mit 01_before.txt ... 13_after.txt und
#     manifest.csv) liegt entweder im aktuellen Verzeichnis oder der Pfad
#     wird per -PatchDir uebergeben.
#
# AUFRUF-BEISPIEL:
#   cd C:\Users\meark\OneDrive\Desktop\MZ_Tech_Reparatursystem
#   powershell -ExecutionPolicy Bypass -File .\phase6_apply_manual_patches.ps1 -PatchDir "C:\Pfad\zu\manual_patches"
#
# Exit-Code 0 = alle Patches angewendet oder bereits vorhanden, keine Fehler.
# Exit-Code 1 = mindestens ein Patch konnte NICHT sicher angewendet werden
#               (Details in der Konsolenausgabe) - in diesem Fall NICHTS
#               committen, bevor der Grund geklaert ist.

param(
    [string]$PatchDir = ".\manual_patches",
    [string]$ProjectRoot = "."
)

$ErrorActionPreference = "Stop"

function Read-Utf8NoBom {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Datei nicht gefunden: $Path"
    }
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    # BOM erkennen und entfernen, falls die Zieldatei versehentlich eines hat
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        $bytes = $bytes[3..($bytes.Length - 1)]
    }
    $enc = New-Object System.Text.UTF8Encoding($false)
    return $enc.GetString($bytes)
}

function Write-Utf8NoBom {
    param([string]$Path, [string]$Content)
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $enc)
}

function Normalize-Newlines {
    param([string]$Text)
    return $Text -replace "`r`n", "`n" -replace "`r", "`n"
}

function Count-Occurrences {
    param([string]$Haystack, [string]$Needle)
    if ([string]::IsNullOrEmpty($Needle)) { return 0 }
    $count = 0
    $idx = 0
    while ($true) {
        $pos = $Haystack.IndexOf($Needle, $idx, [System.StringComparison]::Ordinal)
        if ($pos -lt 0) { break }
        $count++
        $idx = $pos + $Needle.Length
    }
    return $count
}

if (-not (Test-Path -LiteralPath $PatchDir)) {
    Write-Host "FEHLER: Patch-Verzeichnis nicht gefunden: $PatchDir" -ForegroundColor Red
    exit 1
}

$manifestPath = Join-Path $PatchDir "manifest.csv"
if (-not (Test-Path -LiteralPath $manifestPath)) {
    Write-Host "FEHLER: manifest.csv nicht gefunden in $PatchDir" -ForegroundColor Red
    exit 1
}

$manifest = Import-Csv -LiteralPath $manifestPath
$anyFailed = $false
$backedUp = @{}
$appliedCount = 0
$skippedCount = 0
$failedList = @()

Write-Host "=== Phase 6: Manuelle Patches (A-J) werden geprueft/angewendet ===" -ForegroundColor Cyan
Write-Host "Projekt-Root: $((Resolve-Path $ProjectRoot).Path)"
Write-Host "Patch-Verzeichnis: $((Resolve-Path $PatchDir).Path)"
Write-Host ""

foreach ($row in $manifest) {
    $id = $row.id
    $targetRel = $row.target_file
    $desc = $row.description
    $beforeFile = Join-Path $PatchDir "$($id)_before.txt"
    $afterFile = Join-Path $PatchDir "$($id)_after.txt"
    $targetPath = Join-Path $ProjectRoot $targetRel

    Write-Host "[$id] $desc" -ForegroundColor Yellow
    Write-Host "     Ziel: $targetRel"

    if (-not (Test-Path -LiteralPath $beforeFile) -or -not (Test-Path -LiteralPath $afterFile)) {
        Write-Host "     FEHLER: before/after-Datei fehlt fuer Patch $id" -ForegroundColor Red
        $anyFailed = $true
        $failedList += "$id ($targetRel): before/after-Datei fehlt"
        continue
    }
    if (-not (Test-Path -LiteralPath $targetPath)) {
        Write-Host "     FEHLER: Zieldatei existiert nicht im Projekt: $targetRel" -ForegroundColor Red
        $anyFailed = $true
        $failedList += "$id ($targetRel): Zieldatei nicht gefunden"
        continue
    }

    $before = Normalize-Newlines (Read-Utf8NoBom $beforeFile)
    $after  = Normalize-Newlines (Read-Utf8NoBom $afterFile)
    $target = Read-Utf8NoBom $targetPath
    $targetNorm = Normalize-Newlines $target

    $beforeCount = Count-Occurrences -Haystack $targetNorm -Needle $before
    $afterCount  = Count-Occurrences -Haystack $targetNorm -Needle $after

    if ($beforeCount -eq 1) {
        # Backup einmal pro Zieldatei anlegen
        if (-not $backedUp.ContainsKey($targetPath)) {
            $bakPath = "$targetPath.phase6.bak"
            if (-not (Test-Path -LiteralPath $bakPath)) {
                Copy-Item -LiteralPath $targetPath -Destination $bakPath
            }
            $backedUp[$targetPath] = $true
        }

        # Ersetzen im NICHT normalisierten Original (um vorhandene
        # Zeilenenden der Datei ansonsten nicht anzutasten): wir ersetzen
        # anhand des normalisierten Textes und schreiben das Ergebnis mit
        # LF-Zeilenenden zurueck (PHP ist zeilenendenunabhaengig lauffaehig;
        # falls die Datei durchgehend CRLF nutzt, unten optional
        # zurueckkonvertieren).
        $newTargetNorm = $targetNorm.Replace($before, $after)

        # Zeilenenden-Stil der Originaldatei erkennen und beibehalten
        if ($target -match "`r`n") {
            $newTarget = $newTargetNorm -replace "`n", "`r`n"
        } else {
            $newTarget = $newTargetNorm
        }

        Write-Utf8NoBom -Path $targetPath -Content $newTarget
        Write-Host "     OK: Patch angewendet." -ForegroundColor Green
        $appliedCount++
    }
    elseif ($beforeCount -eq 0 -and $afterCount -ge 1) {
        Write-Host "     UEBERSPRUNGEN: NACHHER-Text bereits vorhanden (Patch scheint bereits angewendet)." -ForegroundColor DarkYellow
        $skippedCount++
    }
    else {
        Write-Host "     FEHLER: VORHER-Text $beforeCount mal gefunden (erwartet genau 1), NACHHER $afterCount mal gefunden." -ForegroundColor Red
        Write-Host "     -> Datei wurde NICHT veraendert. Bitte manuell pruefen: $targetRel" -ForegroundColor Red
        $anyFailed = $true
        $failedList += "$id ($targetRel): VORHER $beforeCount x gefunden, NACHHER $afterCount x gefunden - nicht eindeutig, nicht angewendet"
    }
    Write-Host ""
}

Write-Host "=== Zusammenfassung ===" -ForegroundColor Cyan
Write-Host "Angewendet:    $appliedCount"
Write-Host "Uebersprungen (bereits vorhanden): $skippedCount"
Write-Host "Fehlgeschlagen: $($failedList.Count)"

if ($anyFailed) {
    Write-Host ""
    Write-Host "Folgende Patches konnten NICHT sicher angewendet werden:" -ForegroundColor Red
    foreach ($f in $failedList) { Write-Host "  - $f" -ForegroundColor Red }
    Write-Host ""
    Write-Host "ABBRUCH: Bitte diese Punkte klaeren, bevor committet wird." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Alle Patches erfolgreich angewendet oder bereits vorhanden. Backups mit Endung .phase6.bak neben den Originaldateien." -ForegroundColor Green
exit 0
