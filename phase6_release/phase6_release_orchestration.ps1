# phase6_release_orchestration.ps1
#
# Phase 6 - Release- und Deployment-Vorbereitung.
# Fuehrt die vollstaendige, abgesicherte Uebernahme aller Aenderungen aus
# Phase 1-5 in den echten lokalen Git-Klon durch und erzeugt einen
# freigabefaehigen Release-Branch. KEIN Live-Deployment, KEINE
# Produktiv-Datenbank-Aenderung, KEIN Force-Push, KEIN Merge nach main.
#
# Manuell auszufuehren in:
#   C:\Users\meark\OneDrive\Desktop\MZ_Tech_Reparatursystem
#
# Voraussetzungen im selben Ordner (bzw. Pfade unten anpassen):
#   - integration_git_patch_v2.diff
#   - phase4_git_patch.diff
#   - phase5_git_patch.diff
#   - manual_patches\ (13 before/after-Paare + manifest.csv)
#   - phase6_apply_manual_patches.ps1
#   - sql\2a_append_to_update.sql  (Inhalt, der an sql\update.sql angehaengt wird)
#
# Das Skript stoppt bei jedem Fehler (kein "weiter auf gut Glueck").
# Bitte NACH jedem Abschnitt die Ausgabe lesen, bevor fortgefahren wird.

$ErrorActionPreference = "Stop"

# --- Pfade anpassen, falls abweichend ---
$ProjectRoot   = "C:\Users\meark\OneDrive\Desktop\MZ_Tech_Reparatursystem"
$PatchesSource = "C:\Users\meark\Downloads\phase6_release"   # Ordner mit den .diff-Dateien, manual_patches\, diesem Skript
$BackupBranch  = "backup/vor-phase6-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
$ReleaseBranch = "release/phase6-production-ready"

Set-Location $ProjectRoot

# =========================================================================
# SCHRITT 0: Aktuellen Stand pruefen
# =========================================================================
Write-Host "=== SCHRITT 0: Aktueller Branch/Status ===" -ForegroundColor Cyan
git status
git branch --show-current
git log -1 --oneline

$currentBranch = (git branch --show-current).Trim()
if ($currentBranch -eq "main" -or $currentBranch -eq "master") {
    Write-Host "WARNUNG: Du befindest dich auf '$currentBranch'. Es wird dringend empfohlen," -ForegroundColor Red
    Write-Host "NICHT direkt auf main/master zu arbeiten. Bitte zuerst manuell in einen" -ForegroundColor Red
    Write-Host "Feature-/Integrationsbranch wechseln (git checkout -b integration/phase1-6)" -ForegroundColor Red
    Write-Host "und dieses Skript danach erneut starten." -ForegroundColor Red
    exit 1
}

$statusOutput = git status --porcelain
if ($statusOutput) {
    Write-Host "WARNUNG: Es gibt bereits uncommittete Aenderungen im Arbeitsverzeichnis:" -ForegroundColor Red
    Write-Host $statusOutput
    Write-Host "Bitte zuerst sichern/committen/stashen, bevor dieses Skript fortfaehrt." -ForegroundColor Red
    exit 1
}

# =========================================================================
# SCHRITT 1: Backup-Branch anlegen (Sicherheitsnetz)
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 1: Backup-Branch anlegen ===" -ForegroundColor Cyan
git branch $BackupBranch
Write-Host "Backup-Branch erstellt: $BackupBranch (aktueller Stand vor jeder Aenderung)" -ForegroundColor Green

# =========================================================================
# SCHRITT 2: Git-Patches pruefen (--check) und anwenden, in der Reihenfolge
#            integration_git_patch_v2 -> phase4 -> phase5
#            (integration_git_patch.diff OHNE "_v2" NICHT verwenden - veraltet,
#             nur Teilstand, wird durch v2 vollstaendig abgedeckt)
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 2: Git-Patches pruefen und anwenden ===" -ForegroundColor Cyan

$patches = @(
    "integration_git_patch_v2.diff",
    "phase4_git_patch.diff",
    "phase5_git_patch.diff"
)

foreach ($p in $patches) {
    $patchPath = Join-Path $PatchesSource $p
    if (-not (Test-Path -LiteralPath $patchPath)) {
        Write-Host "FEHLER: Patch-Datei nicht gefunden: $patchPath" -ForegroundColor Red
        exit 1
    }
    Write-Host "--- Pruefe $p (git apply --check) ---"
    git apply --check "$patchPath"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FEHLER: $p kann nicht sauber angewendet werden (Konflikt). Abbruch. Keine Aenderung vorgenommen." -ForegroundColor Red
        Write-Host "Moegliche Ursache: Patch wurde bereits angewendet, oder die Zieldateien wurden zwischenzeitlich veraendert." -ForegroundColor Yellow
        exit 1
    }
    Write-Host "--- Wende $p an (git am, erhaelt Commit-Historie/Autor) ---"
    git am "$patchPath"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FEHLER beim Anwenden von $p. Abbruch mit 'git am --abort' pruefen." -ForegroundColor Red
        exit 1
    }
    Write-Host "OK: $p angewendet." -ForegroundColor Green
}

Write-Host ""
Write-Host "Hinweis: Diese drei Patches enthalten NUR neue Dateien (CLI-Worker," -ForegroundColor Yellow
Write-Host "Dokumentation, Tests, SQL-Ergaenzungsdatei). Sie aendern KEINE bestehenden" -ForegroundColor Yellow
Write-Host "Dateien (supplier_adapters.php, purchase_orders.php, products.php," -ForegroundColor Yellow
Write-Host "import_engine.php, supplier_cron.php, WEBSITE-INTEGRATION.html)." -ForegroundColor Yellow
Write-Host "Diese Aenderungen erfolgen erst in SCHRITT 4 (manuelle Patches)." -ForegroundColor Yellow

# =========================================================================
# SCHRITT 3: SQL-Ergaenzung in sql\update.sql einfuegen
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 3: SQL-Ergaenzung pruefen/einfuegen ===" -ForegroundColor Cyan

$sqlAppendFile = Join-Path $ProjectRoot "sql\2a_append_to_update.sql"
$sqlUpdateFile = Join-Path $ProjectRoot "sql\update.sql"

if (-not (Test-Path -LiteralPath $sqlAppendFile)) {
    Write-Host "FEHLER: sql\2a_append_to_update.sql nicht gefunden (sollte durch Schritt 2 vorhanden sein)." -ForegroundColor Red
    exit 1
}
if (-not (Test-Path -LiteralPath $sqlUpdateFile)) {
    Write-Host "FEHLER: sql\update.sql nicht gefunden im Projekt." -ForegroundColor Red
    exit 1
}

$appendContent = [System.IO.File]::ReadAllText($sqlAppendFile, (New-Object System.Text.UTF8Encoding($false)))
$updateContent = [System.IO.File]::ReadAllText($sqlUpdateFile, (New-Object System.Text.UTF8Encoding($false)))

# Idempotenz-Marker: die angehaengten Statements enthalten "IF NOT EXISTS"/
# INFORMATION_SCHEMA-Pruefungen und sind daher mehrfach ausfuehrbar - aber
# wir vermeiden trotzdem eine doppelte TEXT-Einfuegung in die Datei selbst.
$marker = "-- PHASE2A_SUPPLIER_FOUNDATION_APPENDED --"
if ($updateContent -match [regex]::Escape($marker)) {
    Write-Host "UEBERSPRUNGEN: sql\update.sql enthaelt bereits den Phase-2a-Anhang (Marker gefunden)." -ForegroundColor DarkYellow
} else {
    $newUpdateContent = $updateContent.TrimEnd() + "`n`n" + $marker + "`n" + $appendContent.TrimEnd() + "`n"
    $bakSql = "$sqlUpdateFile.phase6.bak"
    if (-not (Test-Path -LiteralPath $bakSql)) { Copy-Item -LiteralPath $sqlUpdateFile -Destination $bakSql }
    [System.IO.File]::WriteAllText($sqlUpdateFile, $newUpdateContent, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "OK: sql\update.sql um Phase-2a-Ergaenzung erweitert (Backup: $bakSql)." -ForegroundColor Green
}

Write-Host "WICHTIG: sql\update.sql wird durch dieses Skript NICHT gegen die echte" -ForegroundColor Yellow
Write-Host "Datenbank ausgefuehrt. Die Ausfuehrung erfolgt separat und manuell erst" -ForegroundColor Yellow
Write-Host "nach deiner Freigabe (siehe Abschlussbericht, Punkt 'Datenbank-Migration')." -ForegroundColor Yellow

# =========================================================================
# SCHRITT 4: Manuelle Patches A-J anwenden
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 4: Manuelle Patches (A-J) anwenden ===" -ForegroundColor Cyan

$manualScript = Join-Path $PatchesSource "phase6_apply_manual_patches.ps1"
$manualPatchesDir = Join-Path $PatchesSource "manual_patches"

if (-not (Test-Path -LiteralPath $manualScript)) {
    Write-Host "FEHLER: phase6_apply_manual_patches.ps1 nicht gefunden unter $PatchesSource" -ForegroundColor Red
    exit 1
}

& $manualScript -PatchDir $manualPatchesDir -ProjectRoot $ProjectRoot
if ($LASTEXITCODE -ne 0) {
    Write-Host "FEHLER: Mindestens ein manueller Patch konnte nicht sicher angewendet werden. Abbruch." -ForegroundColor Red
    Write-Host "Bitte die Ausgabe oben pruefen, KEIN Commit erstellen, bis geklaert." -ForegroundColor Red
    exit 1
}

# =========================================================================
# SCHRITT 5: Quellcodepruefung
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 5: Quellcodepruefung ===" -ForegroundColor Cyan

Write-Host "--- git status ---"
git status

Write-Host "--- git diff --stat ---"
git diff --stat

Write-Host "--- PHP-Syntaxpruefung aller .php-Dateien (php -l) ---"
$phpFiles = Get-ChildItem -Path $ProjectRoot -Recurse -Filter *.php -File |
    Where-Object { $_.FullName -notmatch '\\vendor\\' -and $_.FullName -notmatch '\\node_modules\\' }

$syntaxErrors = @()
foreach ($f in $phpFiles) {
    $out = & php -l "$($f.FullName)" 2>&1
    if ($LASTEXITCODE -ne 0) {
        $syntaxErrors += $f.FullName
        Write-Host $out -ForegroundColor Red
    }
}

if ($syntaxErrors.Count -gt 0) {
    Write-Host "FEHLER: Syntaxfehler in $($syntaxErrors.Count) Datei(en). Abbruch, nichts committen." -ForegroundColor Red
    exit 1
}
Write-Host "OK: Keine PHP-Syntaxfehler gefunden ($($phpFiles.Count) Dateien geprueft)." -ForegroundColor Green

Write-Host "--- Vorhandene Tests ausfuehren (tests\*.php) ---"
$testFiles = Get-ChildItem -Path (Join-Path $ProjectRoot "tests") -Filter *.php -File -ErrorAction SilentlyContinue
$testFailures = @()
foreach ($t in $testFiles) {
    Write-Host "-> $($t.Name)"
    & php "$($t.FullName)"
    if ($LASTEXITCODE -ne 0) {
        $testFailures += $t.Name
    }
}

if ($testFailures.Count -gt 0) {
    Write-Host "FEHLER: Folgende Tests sind fehlgeschlagen: $($testFailures -join ', ')" -ForegroundColor Red
    Write-Host "Abbruch, nichts committen, bis geklaert." -ForegroundColor Red
    exit 1
}
Write-Host "OK: Alle vorhandenen Tests erfolgreich ($($testFiles.Count) Dateien)." -ForegroundColor Green

Write-Host ""
Write-Host "Manuelle Zusatzpruefungen (nicht automatisierbar, bitte visuell im 'git diff'" -ForegroundColor Yellow
Write-Host "oben nachvollziehen): require_once-Vollstaendigkeit, Kundenportal-Links," -ForegroundColor Yellow
Write-Host "Cron-Endpunkt-Verhalten, Upload-/XML-/ZIP-Schutz, Locking - siehe Abschlussbericht." -ForegroundColor Yellow

# =========================================================================
# SCHRITT 6: Finaler Commit
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 6: Finalen Commit erstellen ===" -ForegroundColor Cyan
git add -A
git status
Write-Host ""
Write-Host "Bitte die oben aufgelisteten Dateien pruefen (git status/git diff)." -ForegroundColor Yellow
Write-Host "Falls korrekt, wird jetzt committet." -ForegroundColor Yellow

git commit -m "Phase 6: Release-Vorbereitung - manuelle Patches A-J angewendet, SQL-Ergaenzung eingefuegt, Syntax-/Testpruefung bestanden"
if ($LASTEXITCODE -ne 0) {
    Write-Host "FEHLER beim Commit (evtl. keine Aenderungen vorhanden?). Bitte pruefen." -ForegroundColor Red
    exit 1
}
Write-Host "OK: Commit erstellt." -ForegroundColor Green

# =========================================================================
# SCHRITT 7: Release-Branch erstellen
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 7: Release-Branch erstellen ===" -ForegroundColor Cyan
git checkout -b $ReleaseBranch
Write-Host "OK: Release-Branch erstellt und ausgecheckt: $ReleaseBranch" -ForegroundColor Green

# =========================================================================
# SCHRITT 8: Push (KEIN Force-Push, KEIN main)
# =========================================================================
Write-Host ""
Write-Host "=== SCHRITT 8: Push zu GitHub ===" -ForegroundColor Cyan
Write-Host "Es wird NUR der Release-Branch gepusht, NICHT main/master. Kein --force." -ForegroundColor Yellow
git push -u origin $ReleaseBranch
if ($LASTEXITCODE -ne 0) {
    Write-Host "FEHLER beim Push. Branch ist lokal trotzdem vollstaendig und sicher (Backup-Branch: $BackupBranch)." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "=== FERTIG ===" -ForegroundColor Cyan
Write-Host "Backup-Branch (Ausgangszustand): $BackupBranch"
Write-Host "Release-Branch (gepusht):        $ReleaseBranch"
Write-Host ""
Write-Host "KEIN Merge nach main wurde durchgefuehrt. KEINE Datenbank-Migration wurde" -ForegroundColor Yellow
Write-Host "ausgefuehrt. KEIN Live-Deployment erfolgte. Warte auf Freigabe fuer die" -ForegroundColor Yellow
Write-Host "naechsten Schritte." -ForegroundColor Yellow
