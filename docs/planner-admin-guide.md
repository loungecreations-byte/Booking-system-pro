# Planner Admin Guide

## Overzicht
Deze gids ondersteunt admins bij het beheren van de planner-module in het WordPress-dashboard. Gebruik dit document naast de releasechecklist en regressietests.

## Voorwaarden
- WordPress + WooCommerce geactiveerd
- Boekbare producttype `bookable_service` beschikbaar
- Plannerpagina met shortcode `[sbdp_dayplanner]`

## Boekbare Activiteit Aanmaken
1. Ga naar `Producten > Nieuwe toevoegen`.
2. Kies producttype **Boekbare activiteit**.
3. Vul prijs, duur (`_sbdp_duration`) en resource (_boekingresource_) in.
4. Publiceer. Controleer dat het product in de planner REST (`GET /sbdp/v1/services`) verschijnt.

## Beschikbaarheidsregels Bewerken
1. Navigeer naar **Bookings > Availability**.
2. Gebruik de kalender om blokken toe te voegen (drag & drop) en klik op **Publiceer**.
3. Controleer via planner-UI of de wijzigingen actief zijn (planner geeft conflictmelding bij gesloten blok).

## Plannerpagina & Front-end
1. Plaats shortcode `[sbdp_dayplanner]` op de gewenste pagina of Elementor-widget.
2. De Planner-config (`SBDP_CFG`) levert REST endpoints + nonce.
3. Planner-UI: selecteer datum, sleep activiteiten, valideer totale prijs, test �Boek & betaal� (redirect) en �Doe aanvraag� (succesmelding).

## Betaalverzoeken & Mollie
- Vereisten: WooCommerce + Mollie Payments for WooCommerce met geldige API-sleutel.
- Elke planner-aanvraag maakt een WooCommerce-order in status `pending` met deelnemers, productregels en notities uit de planner.
- Zodra het betaalverzoek is klaargezet, verandert de boekingsstatus naar `captured` en wordt de timestamp `captured_at` opgeslagen. Deze status blijft op `requested` wanneer WooCommerce (tijdelijk) niet beschikbaar is, zodat planners zien dat ze handmatig moeten opvolgen.
- Het systeem genereert automatisch een betaalverzoek. Standaard wordt de WooCommerce `order-pay` URL gebruikt; bij een actieve Mollie-plug-in wordt geprobeerd een Mollie-betaalverzoek (betaallink of e-mail) op te halen. Gebruik de filter `sbdp/booking/payment_request_url` om de link aan te passen.
- De link en status worden opgeslagen in het bookingrecord (`payment_request`) zodat planner, dashboards en notificaties dezelfde informatie delen.
- Via de filter `sbdp/booking/send_invoice_email` kun je het automatisch versturen van de WooCommerce factuurmail (met betaallink) uitschakelen of vervangen door een eigen notificatie.

## Snelle Acties (Dashboard)
Het dashboard toont:
- Aantal boekbare activiteiten
- Aantal resources
- Boekingen vandaag (status breakdown)
- Knoppen naar nieuwe activiteit, resourcebeheer, beschikbaarheidsregels

## REST & Beveiliging
- Client requests vereisen `X-SBDP-Nonce` (verkregen via planner). Zonder nonce ? HTTP 403.
- Filters beschikbaar:
  - `sbdp_rest_rate_limit( bool|WP_Error, WP_REST_Request $request, string $bucket )`
  - `sbdp/public_rest/allow_request( null|bool|WP_Error, $request, $bucket )`
  - `sbdp/public_rest/validate_nonce( null|bool|WP_Error, $nonce, $request, $bucket )`

## Regressie Tests (Samenvatting)
- Planner drag & drop: activiteit toevoegen/verwijderen.
- Compose pay & request: check redirect + melding.
- Pricing preview bij resource met beperkte capaciteiten.
- REST smoke (`scripts/rest-smoke.ps1`) met `-QuoteProductId`.

## Automatisering (Aanbevolen)
- Playwright/Cypress tests voor planner UI (drag, compose, share).
- Integreer E2E runs in GitHub Actions (optioneel aparte workflow).

## Belangrijkste wijzigingen 4.0
- Nieuwe preset-wizard via **Bookings → Booking Suite Setup** om in één klik Dagje Den Bosch-configuraties (prijs, personen, beschikbaarheid, extra kosten, lastminute-korting en optionele resources) toe te passen.
- Planner-menu’s gebruiken nu dezelfde fallback-capability zodat admins zonder WooCommerce-managerrechten de boards zien.
- Orderregels, e-mails en facturen tonen datum/tijd en aantal personen via de vernieuwde meta-weergave.
- Planboard ondersteunt dag/week/maand, tijdslijnsegmenten en lijsten met vrije sloten; het REST-endpoint levert de uitgebreide payload.

## Planner & Dashboard build-pipeline
```yaml
# ==========================================================
#  BOOKING PRO 4.0  Planner & Dashboard Module Build
#  Compatibel met booking-pro-module-4.0 backend-refresh
# ==========================================================

task: build-dashboard-planner

output-policy:
  - verbose
  - color
  - fail-fast

steps:
  # 1 Koppel nieuwe modules aan core bootstrap
  - name: "Register planner & dashboard modules"
    run: >
      agent.run("booking_core.register", {
        modules: ["planner", "dashboard", "reports"],
        autoload: true,
        version_check: true
      })

  # 2 Bouw Planner UI + backend logica
  - name: "Initialize visual planner"
    run: >
      agent.run("booking_planner.build", {
        storage: "wp_posts",
        ui: {
          component: "calendar-grid",
          drag_drop: true,
          resize: true,
          quick_add: true,
          toggle_views: ["day","week","month"]
        },
        rest: {
          endpoint: "/booking/v1/planner",
          methods: ["GET","POST","PATCH"],
          autosave: true
        },
        data: {
          include_resources: true,
          include_availability: true,
          include_people: true
        }
      })

  # 3 Kalender-synchronisatie voor klanten
  - name: "Setup calendar export & sync"
    run: >
      agent.run("booking_calendar.connect", {
        export_ics: true,
        export_ical: true,
        sync_google: true,
        sync_apple: true,
        attach_on_confirmation: true,
        webhook: "sync/ical/export"
      })

  # 4 Beschikbaarheidsoverzicht voor aanbieders
  - name: "Enable provider availability overview"
    run: >
      agent.run("booking_availability.overview", {
        threshold_warning: 0.85,
        threshold_full: 1.0,
        alert: {
          email_admin: true,
          email_provider: true,
          method: ["mail","toast"]
        },
        ui: {
          heatmap: true,
          legend: ["Vrij","Bijna vol","Vol"],
          realtime: true
        }
      })

  # 5 Dashboard-functionaliteit
  - name: "Build dashboard widgets"
    run: >
      agent.run("booking_dashboard.build", {
        widgets: [
          {id:"bookings_total", label:"Totaal boekingen", type:"count"},
          {id:"revenue_total", label:"Omzet", type:"currency"},
          {id:"occupancy_rate", label:"Bezettingsgraad", type:"percent"},
          {id:"popular_activities", label:"Populaire activiteiten", type:"list"}
        ],
        filters: ["periode","activiteit","aanbieder","status"],
        layout: "grid",
        theme: "light",
        auto_refresh: 60
      })

  # 6 Inline bewerking & bulkacties in dashboard
  - name: "Activate inline editing"
    run: >
      agent.run("booking_dashboard.edit", {
        inline_edit: true,
        editable_fields: ["personen","tijd","status"],
        bulk_actions: ["status_bevestigd","status_geannuleerd","export_csv"],
        confirm_before_apply: true
      })

  # 7 Rapportagesysteem koppelen
  - name: "Enable reporting engine"
    run: >
      agent.run("booking_reports.enable", {
        schedule: "weekly",
        recipients: ["admin"],
        formats: ["csv","xlsx","pdf"],
        metrics: ["omzet","bezetting","top_activiteiten"],
        email_subject: "Wekelijks boekingsrapport",
        include_dashboard_snapshot: true
      })

  # 8 Controle & registratie
  - name: "Validate new modules"
    run: >
      agent.run("booking_core.validate", {
        required_modules: ["planner","dashboard","reports"],
        log_results: true,
        save_state: "modules/active.json"
      })

  # Klaar
  - name: "Finish build"
    run: >
      log.success(" Planner & Dashboard succesvol opgebouwd binnen Booking Pro 4.0 backend.")
```

## Planner Legend
- Added legend to scheduler views.
- Extra preset: Binnendieze Avondtour.
