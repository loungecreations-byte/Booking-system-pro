# Governance & Architectuur: dagjedenbosch.nl

Dit document beschrijft de architectuur en governanceprincipes (gebaseerd op het TOGAF framework) die ten grondslag liggen aan het platform *dagjedenbosch.nl*. Het dient als leidraad voor ontwikkelaars, beheerders en auditors om te waarborgen dat de codebase veilig, traceerbaar en schaalbaar blijft.

## 1. Architectuur Principes (TOGAF)

### Business Architecture
*   **Doel**: Aansluiting bij citymarketing en toerismebevordering.
*   **Governance**: Content wordt beheerd via strikte Rollen en Rechten (RBAC). Alleen geautoriseerde redacteuren publiceren content.

### Data Architecture
*   **Doel**: 'Single Source of Truth' met focus op privacy (AVG/GDPR).
*   **Governance**: Dataminimalisatie staat centraal. Custom Post Types en taxonomieën worden gebruikt voor gestructureerde data-entiteiten.

### Application Architecture
*   **Doel**: Modulair applicatielandschap om vendor lock-in te minimaliseren.
*   **Governance**: Centralisatie van herbruikbare componenten zoals `ddb-core-ui`. Security by Design en minimale afhankelijkheid van externe plugins.

### Technology Architecture
*   **Doel**: Hoge beschikbaarheid en schaalbare infrastructuur.
*   **Governance**: Oplevering via CI/CD, verplichte code-reviews en geautomatiseerde back-ups (Disaster Recovery).

## 2. Backend Governance & Code Standaarden

Om deze architectuur in stand te houden, hanteren we de volgende standaarden voor de WordPress backend en codebase (zoals deze plugin):

*   **OTAP**: Wijzigingen vinden uitsluitend plaats via de Ontwikkel-, Test-, en Acceptatieomgevingen, nooit direct op Productie (geen 'cowboy coding').
*   **Code Review**: Code in de `ddb-core-ui` plugin en themes vereist validatie (pull requests) en minimaal naleving van de [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/).
*   **Zero Trust & IAM**: Beheerdersaccounts hebben uitsluitend de rechten die nodig zijn voor de eigen rol (Principle of Least Privilege) en worden beschermd met MFA.
*   **Assets Inladen**: Alle styling (CSS) en scripts (JS) moeten strikt ingeladen worden via WordPress enqueuing (`wp_enqueue_script` / `wp_enqueue_style`) om security en correcte cache-busting (cache-invalidatie via versienummers) te garanderen.
*   **Data Isolatie**: Gebruik de juiste `nonce`-controles bij frontend naar backend operaties (AJAX, POST requests).

---
*Status: Opgesteld ter directe referentie door developers en governance experts.*