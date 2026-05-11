# Directienotitie TOGAF — Governance en Livegang DagjeDenBosch
Datum: 9 maart 2026  
Status: Besluitvormend

## 1. Doel
Het platform DagjeDenBosch bestuurbaar maken op groei, kwaliteit, releasezekerheid en risico.

TOGAF wordt hierbij formeel gebruikt als governancekader om over te gaan van ad-hoc wijzigingen naar gecontroleerde, toetsbare en bestuurbare verandering.

---

## 2. Managementsamenvatting
De afgelopen periode is gewerkt aan het inrichten van architectuursturing over business, data, applicaties en technologie. Daarmee is een fundament gelegd om DagjeDenBosch als één samenhangend digitaal platform te ontwikkelen in plaats van als losse pagina’s, plugins en wijzigingen.

De kern hiervan is:
- een duidelijk doelbeeld voor de platformarchitectuur;
- vaste governance-documenten voor ontwerp, release en review;
- een set release-gates voor kwaliteit en risicobeheersing;
- een governance-dashboard waarmee directie en verantwoordelijken zicht houden op voortgang, afwijkingen en liveganggereedheid.

Deze notitie vraagt directie om deze governance-aanpak formeel te bekrachtigen.

---

## 3. Wat volgens TOGAF is ingericht

### 3.1 Preliminary + Phase A — Vision
Vastgesteld zijn:
- scope van het platform;
- architectuurprincipes;
- besluitrechten;
- doelarchitectuur;
- governancekaders voor livegang en wijzigingscontrole.

### 3.2 Phase B — Business Architecture
De kernjourneys zijn uitgewerkt als primaire waardestroom:
- ontdekken
- kiezen
- plannen
- boeken
- upsell / vervolg
- beleven
- terugkomen

Hiermee is een eenduidig model ontstaan voor homepage, spots, activiteiten, planner, checkout, account en tourlaag.

### 3.3 Phase C — Information Systems Architecture
Ingericht zijn:
- uniforme componentlogica voor cards, CTA’s, shells en page families;
- scheiding tussen Design CSOT, Domain CSOT en Execution Truth;
- randvoorwaarden voor data- en UI-consistentie.

### 3.4 Phase D — Technology Architecture
Gestabiliseerd en ingericht zijn onder andere:
- runtime-continuïteit;
- routecontinuïteit;
- validatie- en release-discipline;
- basis voor regressie- en kwaliteitscontrole.

### 3.5 Phase E/F/G — Opportunities, Migration, Implementation Governance
Er ligt nu een uitvoerbaar model voor:
- gefaseerde implementatie;
- architectuur-compliance tijdens uitvoering;
- verplichte release-gates;
- gecontroleerde livegang.

### 3.6 Phase H — Architecture Change Management
Geborgd wordt:
- periodieke governance-review;
- continue wijzigingsbesturing;
- dashboardgestuurde monitoring;
- bijsturing op KPI’s, regressies en afwijkingen.

---

## 4. Zakelijke impact
De verwachte zakelijke impact van deze governance-aanpak is:

- **hogere conversiekans** door consistente UX over de kernfunnel;
- **lagere operationele kosten** door minder spoedfixes en minder regressies;
- **betere voorspelbaarheid** van releasekwaliteit en doorlooptijd;
- **meer bestuurlijke controle** op risico, prioritering en livegangbesluiten;
- **meer schaalbaarheid** doordat groei niet langer afhankelijk is van ad-hoc oplossingen.

---

## 5. Open risico’s
De belangrijkste huidige risico’s zijn:

1. **Omgevingsdrift**  
   Verschillen tussen lokale, test- en productieomgevingen kunnen leiden tot inconsistent gedrag en lastig reproduceerbare fouten.

2. **Afhankelijkheid van content- en datakwaliteit**  
   Met name upsell, aanbevelingen en doorstroomprestaties blijven afhankelijk van correcte, consistente en volledige content/data.

3. **Terugval naar ad-hoc werken**  
   Zonder discipline op governance, gates en review bestaat het risico dat uitzonderingen weer de norm worden.

4. **Design drift en implementatiedrift**  
   Zonder strakke handhaving van CSOT en page-family regels kunnen opnieuw lokale afwijkingen ontstaan.

5. **Release-risico in kernjourneys**  
   Veranderingen in planner, add-to-day, cart of checkout kunnen disproportioneel veel impact hebben op omzet en klantvertrouwen.

---

## 6. Besluit gevraagd
De directie wordt gevraagd de volgende besluiten per direct te bekrachtigen:

1. **TOGAF-gebaseerde governance formeel vaststellen** als standaard verandermodel voor digitale wijzigingen binnen DagjeDenBosch.
2. **Verplichte release-gates invoeren** voor iedere productie-release.
3. **Maandelijkse governance-review op directieniveau** vastleggen op basis van dashboard en KPI’s.
4. **Afwijkingen van doelarchitectuur, Design CSOT, OMDB/Woo-boundaries of verplichte release-gates als blokkerend behandelen**, tenzij expliciet en tijdelijk geaccordeerd via een formele uitzonderingsprocedure.

---

## 7. Kaders

### 7.1 Scope
Deze governance geldt voor:
- website
- homepage
- spots
- activiteiten
- planner / Plan je dag
- planning cart
- checkout / afrekenen
- boekbare producten
- upsell
- account
- tourlaag / beleven
- relevante portal- en beheercomponenten

### 7.2 Eigenaarschap
- **Product Owner** — businesswaarde, prioritering en klantimpact
- **Tech Lead** — architectuur, technische compliance en regressierisico
- **Operations / Release Owner** — releaseplanning, livegang en rollback
- **Security / Privacy** — compliance, privacy en relevante risicobeoordeling
- **UX / Design System Owner** — design system truth, page-family consistentie en CTA-hiërarchie
- **QA / Review Owner** — regressiecontrole en reviewbewijslast

### 7.3 Escalatie
Iedere niet-gehaalde gate of afwijking van doelarchitectuur leidt tot:
- blokkade van release,
of
- formele uitzonderingsaanvraag met expliciete risicoacceptatie.

---

## 8. Governance-artifacts onder dit besluit
Dit besluit wordt operationeel gemaakt via de volgende documenten en instrumenten:

### Bestuurlijke en operationele governance
- `docs/governance/DDB_GOVERNANCE_POLICY.md`
- `docs/governance/DDB_RELEASE_GATES.md`
- `docs/governance/DDB_RACI.md`
- `docs/governance/DDB_GOVERNANCE_DASHBOARD_SPEC.md`

### Platformwaarheid en architectuurkaders
- `docs/DDB_PLATFORM_CONSTITUTION.md`
- `docs/DDB_CTA_MAP.md`
- `docs/DDB_DO_NOT_TOUCH.md`
- `docs/DDB_PAGE_FAMILIES.md`
- `docs/DDB_COMPONENT_CANON.md`
- `docs/DDB_OMDB_WOO_BOUNDARIES.md`
- `docs/DDB_PARTICIPANTS_TRUTH.md`
- `docs/DDB_AVAILABILITY_TRUTH.md`
- `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`
- `docs/DDB_LAUNCH_BOARD.md`
- `docs/DDB_REGRESSION_CHECKLIST.md`
- `docs/DDB_SHELL_RULES.md`
- `docs/DDB_IMPLEMENTATION_SEQUENCE.md`
- `docs/DDB_REVIEW_LOOP.md`

### Governance cockpit
De governance cockpit / het platform health dashboard fungeert als centrale stuur- en signaleringslaag voor:
- launch readiness
- gate status
- page-family status
- design-system drift
- shell-integriteit
- OMDB/Woo-boundary health
- regressies
- KPI’s

---

## 9. Bestuurlijke consequentie
Vanaf ingangsdatum geldt:

- geen productie-release zonder aantoonbare gate-review;
- geen afwijking van doelarchitectuur zonder expliciete tijdelijke uitzondering;
- geen wijziging aan kernjourneys zonder beoordeling van impact op conversie, regressierisico en releasekwaliteit;
- governance-afwijkingen worden zichtbaar gemaakt in de governance cockpit en periodiek op directieniveau besproken.

---

## 10. KPI’s voor directiesturing

### 10.1 Conversie
Te volgen KPI’s:
- activiteitenoverzicht → detail
- detail → add-to-day
- add-to-day → planner
- planner → booking/request
- activiteitenweergave → boeking

### 10.2 Incidenten
Te volgen KPI’s:
- productie-issues per maand
- kritieke incidenten per maand
- rollback-events per periode

### 10.3 Regressies
Te volgen KPI’s:
- regressies na release
- aantal releases met kritieke blockers na livegang
- planner/add-to-day regressies
- shell-/template-regressies

### 10.4 Lead time
Te volgen KPI’s:
- doorlooptijd van akkoord tot live
- doorlooptijd van fix tot productie
- voorspelbaarheid van releaseplanning

### 10.5 Betrouwbaarheid
Te volgen KPI’s:
- percentage foutvrije kernroute-smoketests
- percentage releases met volledig ingevulde gates
- percentage releases zonder kritieke regressie

---

## 11. Meetpunt na 90 dagen
Na 90 dagen wordt deze governance-aanpak geëvalueerd op basis van:

1. **Minimaal 30% reductie** in release-gerelateerde incidenten ten opzichte van de vastgestelde baseline.
2. **100% van de releases** voorzien van volledig ingevulde en beoordeelde gates.
3. **Meetbare verbetering** in kernconversie op:
   - planner start → add-to-day
   - add-to-day → booking/request
   - activiteitenflow → detail / add-to-day / booking
4. **Aantoonbare reductie in regressies** op shell, planner en kernjourneys.
5. **Inzichtelijke en bruikbare dashboardsturing** voor directie en verantwoordelijken.

---

## 12. Vervolgactie na 90 dagen
Na het 90-dagen meetpunt volgt:

- evaluatie van effectiviteit van governance;
- vergelijking van baseline vs actuele KPI-stand;
- beoordeling van uitzonderingen en structurele blokkades;
- aanscherping van release-gates waar nodig;
- herbevestiging of bijstelling van dit besluit door directie.

---

## 13. Ingangsdatum
Dit besluit treedt in werking per direct na directiebesluit.

---

## 14. Slot
Deze notitie vormt het bestuurlijke anker voor gecontroleerde digitale verandering binnen DagjeDenBosch.

Het doel is niet meer documentatie, maar:
- voorspelbare kwaliteit,
- betere bestuurbaarheid,
- snellere en veiligere livegang,
- en een platform dat schaalbaar kan doorgroeien zonder terug te vallen in ad-hoc wijzigingen.
