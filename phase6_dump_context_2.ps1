# phase6_dump_context_2.ps1
#
# Zweite, gezieltere Diagnoserunde fuer 2 Patches, bei denen die erste
# Anker-Suche (phase6_dump_context.ps1) nachweislich die FALSCHE Stelle
# getroffen hat (Anker kam mehrfach in der Datei vor):
#
#   - Patch E2 (public/purchase_orders.php): "Bestellungen" traf die
#     Dateikopf-Kommentarzeile, nicht die tatsaechliche Karte weiter unten.
#   - Patch H (private/import_engine.php): "simplexml_load_string" traf
#     den Aufruf in import_parse_xlsx() (fuer sharedStrings.xml), nicht
#     den in import_parse_xml() (fuer die eigentliche XML-Preisliste).
#
# Liest NUR, aendert nichts.
#
# Aufruf:
#   cd C:\Users\meark\OneDrive\Desktop\MZ_Tech_Reparatursystem
#   powershell -ExecutionPolicy Bypass -File .\phase6_dump_context_2.ps1 > phase6_context_dump_2.txt
#
# Danach bitte den KOMPLETTEN Inhalt von phase6_context_dump_2.txt zurueckschicken.

function Show-Lines {
    param([string]$Label, [string]$RelPath, [int]$From, [int]$To)
    Write-Output "===== $Label ====="
    Write-Output "Datei: $RelPath (Zeilen $From-$To)"
    if (-not (Test-Path -LiteralPath $RelPath)) {
        Write-Output "FEHLER: Datei nicht gefunden."
        Write-Output ""
        return
    }
    $lines = Get-Content -LiteralPath $RelPath
    $start = [Math]::Max(0, $From - 1)
    $end = [Math]::Min($lines.Count - 1, $To - 1)
    Write-Output "--- START ---"
    for ($i = $start; $i -le $end; $i++) {
        Write-Output ("{0,5}: {1}" -f ($i+1), $lines[$i])
    }
    Write-Output "--- ENDE ---"
    Write-Output ""
}

function Show-AllMatches {
    param([string]$Label, [string]$RelPath, [string]$Pattern, [int]$Context = 8)
    Write-Output "===== $Label - alle Fundstellen von '$Pattern' ====="
    if (-not (Test-Path -LiteralPath $RelPath)) {
        Write-Output "FEHLER: Datei nicht gefunden."
        Write-Output ""
        return
    }
    $lines = Get-Content -LiteralPath $RelPath
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $Pattern) {
            $found = $true
            $start = [Math]::Max(0, $i - $Context)
            $end = [Math]::Min($lines.Count - 1, $i + $Context)
            Write-Output "--- Fundstelle Zeile $($i+1) ---"
            for ($j = $start; $j -le $end; $j++) {
                Write-Output ("{0,5}: {1}" -f ($j+1), $lines[$j])
            }
            Write-Output ""
        }
    }
    if (-not $found) { Write-Output "KEINE Fundstelle." }
    Write-Output ""
}

Write-Output "########## PHASE 6 KONTEXT-DUMP 2 ##########"

# Patch E2: komplette Datei public/purchase_orders.php ausgeben (nur 100
# Zeilen laut vorherigem Dump - komplett, um die richtige Karten-Stelle
# sicher zu finden statt erneut zu raten).
Show-Lines -Label "Patch E2 - public/purchase_orders.php KOMPLETT" -RelPath "public\purchase_orders.php" -From 1 -To 200

# Patch H: alle Fundstellen von simplexml_load_string in import_engine.php,
# mit Funktionsnamen-Kontext (mehr Vorschau nach oben, um den
# "function import_parse_..." Header mit einzufangen).
Show-AllMatches -Label "Patch H - alle simplexml_load_string-Aufrufe" -RelPath "private\import_engine.php" -Pattern "simplexml_load_string" -Context 20

Write-Output "########## ENDE DUMP 2 ##########"