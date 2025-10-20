# Booking Board Pro – Architectuur & Aanpak

## Bestaande bouwstenen
- **Bookings module**  
  REST (`bsp/v1/booking/*`) en `BookingManager`/`BookingRepository` houden basisboekingen bij (transient/in-memory).  
  Geen geavanceerde filters, kalender of WooCommerce-ordermapping aanwezig.

- **Planner module**  
  Levert resource-allocatie/agendahelpers (`generateSchedule`, `assignResource`) die we kunnen hergebruiken voor drag & drop en kalenderweergave.

- **Notifications & AI**  
  Notificatiemodule (SetupService) beheert configuratie maar kent nog geen integratielaag.  
  Intelligence module exposeert analysetools en cron-infrastructuur voor rapportages.

- **Admin UI infrastructuur**  
  Core enqueueer logica voor admin scripts via `core/src/Assets/EnqueueService`. Nieuwe React-apps kunnen via soortgelijke pattern geladen worden.

## Nieuwe componenten
1. **Module `BSP\BookingBoard`**
   - Admin-pagina (`sbdp_booking_board`) met React mount point.
   - Registreer via plugin bootstrap én PSR-4 autoload.

2. **REST-layer**
   - Endpoints onder `/bsp/v1/booking-board/*`:
     - `GET /bookings` met filters/paginatie.
     - `POST /reschedule` voor drag & drop updates.
     - `POST /update` voor detailwijzigingen.
     - `POST /create` voor handmatige boekingen.
   - Helpers om BookingManager uit te breiden (reschedule, mutate meta, WooCommerce sync).

3. **Services**
   - `BoardService` aggregatie: combineer BookingRepository-gegevens met WooCommerce order meta (via `wc_get_orders`).
   - `AccessControl` voor vendor/role beperkingen (re-use WP capabilities + resource IDs).
   - `NotificationBridge` (koppeling SetupService + action hooks bij create/reschedule).
   - `AiInsightsService` die Intelligence/ReportsService gebruikt voor piekdag/best slot berekening.

4. **Frontend**
   - React-app in `assets/js/admin/booking-board/` met:
     - BookingList + filters + quick actions.
     - Calendar/drag-drop scheduler (FullCalendar of custom DnD).
     - Details panel & Add Booking modal.
     - Stats bar & AI aanbevelingen.
     - Toggle list/calendar, exportknoppen.
   - REST-client wrapper (`fetch` + nonce + polling).

5. **WooCommerce & export**
   - Tweewegsync: map Booking records naar WC order meta updates en andersom.
   - Export endpoints of client-side generator (CSV/XLSX/PDF via backend job).

6. **Validatie & tooling**
   - PHPUnit/Integration tests voor nieuwe REST-acties en access control.
   - PHPCS & QA scripts updaten (inclusief autoload).
   - Documentatie in `docs/` + admin helptekst.

## Volgende stappen
1. Composer autoload + plugin bootstrap uitbreiden (module registreren).
2. Backend services & REST-controllers implementeren (create/list/reschedule/update/export).
3. React-app scaffolding, asset build scripts en styling toevoegen.
4. Integraties (notifications, AI, WooCommerce sync) en tests afronden.
