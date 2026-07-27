# Konfigurationscheckliste

- [ ] Produktivdatenbanksicherung aktuell und rückspielbar
- [ ] FTPS-Credential `MZTech.Reparatursystem.ProductionFTPS.v1` vorhanden
- [ ] Explizites TLS, Port 21, Ziel `/mztech-it.de/repair_neu/`
- [x] Foneday-Token lokal per Windows-DPAPI vorhanden
- [ ] Foneday-Token gültig und für `GET /products` freigeschaltet (aktueller Test abgelehnt)
- [ ] Genau ein vorhandener Lieferant mit Namen `Foneday`
- [ ] Kein Foneday-Token auf dem Server oder im Projekt
- [ ] `private/config.php` vom Upload ausgeschlossen
- [ ] SMTP nicht durch Testversand ausgelöst
- [ ] Upload-Limits und schreibbares, nicht ausführbares Uploadverzeichnis geprüft
- [ ] KAS-Cron erst nach erfolgreichem Erstlauf bewertet
