# Codex Nightly Run

Automatiseer de lokale Codex-build door de meegeleverde scripts te gebruiken.

## Bestanden
- `codex-nightly.ps1` – voert een Codex-run uit en draait aansluitend `scripts/run-quality-checks.ps1`. Logt alles naar `logs/codex_overnight.log`.
- `nightly-run.cmd` – eenvoudige wrapper voor gebruik met de Windows Taakplanner.

## Configuratie
1. Zorg dat de Codex CLI in je PATH staat (`codex --version`).
2. Maak de map `logs/` aan als die nog niet bestaat.
3. Open **Taakplanner** en maak een taak die `nightly-run.cmd` draait volgens jouw schema (bijv. dagelijks om 02:00). Kies “Run with highest privileges” en laat de taak ook draaien wanneer je niet bent aangemeld.

Het logbestand staat na elke run in `logs/codex_overnight.log`. Bij een niet-nul exitcode kun je daarin de foutdetails terugvinden.
