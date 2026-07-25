# phase6_dump_context.ps1
#
# Liest NUR (keine Aenderung an Dateien) die aktuellen Codeabschnitte rund
# um die 8 fehlgeschlagenen Patches aus und gibt sie mit klaren
# Trennmarkierungen aus. Bitte die KOMPLETTE Konsolenausgabe kopieren und
# zurueckschicken - keine Zusammenfassung, keine Kuerzung.
#
# Aufruf:
#   cd C:\Users\meark\OneDrive\Desktop\MZ_Tech_Reparatursystem
#   powershell -ExecutionPolicy Bypass -File .\phase6_dump_context.ps1 > phase6_context_dump.txt
#
# Danach bitte den Inhalt von phase6_context_dump.txt hier einfuegen.

function Show-Context {
    param(
        [string]$Label,
        [string]$RelPath,
        [string]$AnchorPattern,
        [int]$Before = 5,
        [int]$After = 40
    )
    Write-Output "===== $Label ====="
    Write-Output "Datei: $RelPath"
    if (-not (Test-Path -LiteralPath $RelPath)) {
        Write-Output "FEHLER: Datei nicht gefunden."
        Write-Output ""
        return
    }
    $lines = Get-Content -LiteralPath $RelPath
    $idx = -1
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $AnchorPattern) { $idx = $i; break }
    }
    if ($idx -eq -1) {
        Write-Output "ANKER NICHT GEFUNDEN (Muster: $AnchorPattern)"
        Write-Output "-> Bitte die Datei manuell nach dem sinngemaessen Codeabschnitt durchsuchen."
        Write-Output ""
        return
    }
    $start = [Math]::Max(0, $idx - $Before)
    $end = [Math]::Min($lines.Count - 1, $idx + $After)
    Write-Output "(Zeilen $($start+1) bis $($end+1) von $($lines.Count), Anker gefunden in Zeile $($idx+1))"
    Write-Output "--- START ---"
    for ($i = $start; $i -le $end; $i++) {
        Write-Output ("{0,5}: {1}" -f ($i+1), $lines[$i])
    }
    Write-Output "--- ENDE ---"
    Write-Output ""
}

Write-Output "########## PHASE 6 KONTEXT-DUMP ##########"
Write-Output "Branch:"
git branch --show-current
Write-Output ""

# Patch A2 - SoapApiAdapter (private/supplier_adapters.php)
Show-Context -Label "Patch A2 - SoapApiAdapter" -RelPath "private\supplier_adapters.php" -AnchorPattern "class SoapApiAdapter" -Before 2 -After 60

# Patch B - Versandklasse/Land case-Bloecke (private/purchase_orders.php)
Show-Context -Label "Patch B - Versandklasse/Land" -RelPath "private\purchase_orders.php" -AnchorPattern "pro_versandklasse" -Before 10 -After 30

# Patch G - purchase_order_item_receive() (private/purchase_orders.php)
Show-Context -Label "Patch G - purchase_order_item_receive" -RelPath "private\purchase_orders.php" -AnchorPattern "function purchase_order_item_receive" -Before 2 -After 40

# Patch E2 - UI-Karte Bestellvorschlaege, Ankerblock (public/purchase_orders.php)
Show-Context -Label "Patch E2 - Anker vor Bestellungen-Karte" -RelPath "public\purchase_orders.php" -AnchorPattern "Bestellungen" -Before 15 -After 15

# Patch F1 - hero-btns (WEBSITE-INTEGRATION.html)
Show-Context -Label "Patch F1 - hero-btns" -RelPath "WEBSITE-INTEGRATION.html" -AnchorPattern "hero-btns" -Before 2 -After 15

# Patch F2 - Navigationsmenue (WEBSITE-INTEGRATION.html)
Show-Context -Label "Patch F2 - Nav-Menue Termin/Anfrage" -RelPath "WEBSITE-INTEGRATION.html" -AnchorPattern "nav-cta" -Before 10 -After 10

# Patch H - import_parse_xml (private/import_engine.php)
Show-Context -Label "Patch H - simplexml_load_string" -RelPath "private\import_engine.php" -AnchorPattern "simplexml_load_string" -Before 10 -After 15

# Patch J - supplier_cron.php Secret-Pruefung
Show-Context -Label "Patch J - hash_equals Secret-Check" -RelPath "public\api\supplier_cron.php" -AnchorPattern "hash_equals" -Before 10 -After 15

Write-Output "########## ENDE DUMP ##########"