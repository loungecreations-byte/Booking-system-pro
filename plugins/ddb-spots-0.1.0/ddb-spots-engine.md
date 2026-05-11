# PROMPT: DDB Spots Engine (CityOS Component) + Google Places Import

## ROLE
You are Codex CLI. Act as:
- Senior WordPress plugin architect (WP 6.x, PHP 8.1+)
- Systems architect (CityOS / Commercial OS mindset)
- Conversion engineer (AOV, CTA, monetization-first)
- Data architect (event-driven, scalable MariaDB design)
- UX engineer (compact, low cognitive load, no bloat)

Be critical:
- REMOVE anything that looks like GeoDirectory / directory software
- REMOVE generic “content-first” patterns
- ENFORCE product/conversion-first logic
- This is NOT a listing plugin → this is a **conversion + intelligence layer**

---

# OBJECTIVE

Build/upgrade plugin **"ddb-spots"** into:

👉 A **DDB Spots Engine** that:
1. Acts as **canonical structured data layer for places**
2. Drives **conversion (CTA, booking, bundles)**
3. Feeds:
   - AI Agent (agent.ddb.nl)
   - Plan-je-Dag engine
   - SEO pages
4. Is **TOGAF-aligned (governance, traceability, enforceable rules)**

---

# CORE PRINCIPLES (NON-NEGOTIABLE)

### 1. Conversion First
Every spot MUST:
- Have CTA OR explicitly be "informational only"
- Be usable in a plan or recommendation

### 2. Canonical Data Model
- ONE source of truth for spots
- No duplication across plugins

### 3. API First
- Everything available via REST
- Plugin = control/config layer
- Node backend = decision engine

### 4. Governance by Design
- Cannot publish bad data
- Quality enforced in UI

### 5. Event-Driven
- Every interaction logged
- Optimization loop built-in

---

# ARCHITECTURE OVERVIEW

## Layers

### 1. Data Layer (MariaDB-first design)
Custom tables (NOT postmeta-heavy):

- `wp_dbspots_spots`
- `wp_dbspots_events`
- `wp_dbspots_audit`

Use:
- dbDelta → initial create
- Custom Migrator → ALL updates

---

### 2. Plugin Role

DBSpots = **Control Layer**
- admin UI
- validation
- mappings
- settings
- API

NOT:
- decision engine
- planning engine

---

### 3. External Systems

- WooCommerce = product SoT
- Agent (Node) = decision logic
- Plan engine = bundling + scheduling

---

# DATABASE DESIGN (CRITICAL)

## Table: wp_dbspots_spots

Fields include:

- id, slug, name, type, status
- short_desc, long_desc
- address, lat/lng, area
- price_band, duration_hint
- suitability_json
- images_json
- tags_json

### Conversion fields:
- primary_cta_type
- primary_cta_value
- primary_cta_label

### Relations:
- near_spots_json
- bundles_json

---

## Table: wp_dbspots_events

Track:
- spot_view
- cta_click
- add_to_plan
- agent_recommended
- book_click

NO PII allowed.

---

## Table: wp_dbspots_audit

Track:
- who changed what
- diff_json
- timestamp

---

## INDEXES (MANDATORY)

- idx_status_type_area
- idx_event_type_time
- idx_spot_time

---

# MIGRATION SYSTEM (IMPORTANT)

Implement:

- dbDelta → initial tables
- Custom Migrator class:
  - versioned migrations
  - ALTER TABLE support
- Option key:
  `dbspots_schema_version`

---

# ADMIN UX (CRITICAL – KEEP COMPACT)

## Menu

Spots →
- All Spots
- Add New
- Settings (Engine)
- Import (Google Places)
- Insights

---

## Spot Editor (Tabbed UI)

Tabs:
- Basis
- Content
- Booking
- Location
- Media
- SEO
- Advanced

### Sidebar:
## Spot Health (TOP)

Shows:
- score (0–100)
- issues (fail/warn)
- FIX links (jump to field)

---

# QUALITY GATES (TOGAF ENFORCEMENT)

## Save (soft)
- warnings allowed

## Publish (HARD BLOCK)
Block if:
- no type
- no CTA (unless informational)
- no image
- no area
- short_desc too short

---

# SETTINGS (ENGINE)

Option key:
`ddb_spots_engine_config`

Tabs:

### 1. Spot Types
- enable/disable
- required fields

### 2. Monetization
- booking providers
- featured boost
- priority scoring

### 3. Ranking
- weights:
  - distance
  - popularity
  - margin
  - priority

### 4. Data Sources
- Google API key
- radius
- sync frequency

### 5. UX Rules
- min images
- CTA required
- text length

---

# GOOGLE PLACES IMPORT

## Admin Page

Spots → Import

Features:
- search query
- results list
- select + import

## Import Logic

- Upsert by place_id
- source = google_places
- default type = restaurant

Map:
- name
- address
- lat/lng
- phone
- website
- opening hours
- photo refs

---

## Auto Sync (WP-Cron)

- refresh all autosync spots
- update:
  - hours
  - phone
  - website
- store last_synced_at

---

# REST API (API-FIRST)

Namespace:
`/wp-json/dbspots/v1`

### Public

GET /spots  
GET /spots/{id}  
POST /events  
POST /suggest  

### Admin

POST /spots  
PUT /spots/{id}  
POST /publish  
POST /archive  

---

# SUGGEST ENGINE (BASIC)

Input:
- intent
- pax
- duration
- area

Output:
- 1 primary
- 3 alternatives

Scoring:
- type match
- suitability
- distance
- duration fit
- priority boost

---

# EVENT TRACKING

Track:
- views
- clicks
- conversions
- agent suggestions

---

# SECURITY

- RBAC roles:
  - admin
  - editor
  - analyst

- Nonce checks
- Sanitization everywhere
- Rate limiting API
- No PII storage

---

# PERFORMANCE

- Cache:
  - spot lists
  - suggestions

- Invalidate on update

---

# FILE STRUCTURE

dbspots/
  dbspots.php
  /includes
    /Core
    /Domain
    /Admin
    /Rest
    /Services
    /Integrations
    /Cron

---

# WORKFLOW

1. Inspect repo
2. Detect conflicts (ACF, RankMath, old plugins)
3. Plan commits

### Commits:

1. DB + Migrator
2. Settings (Engine)
3. Editor UI + cleanup
4. Spot Health + validation
5. REST API
6. Google Import
7. Cron Sync
8. Events + Insights
9. Security + RBAC
10. README

---

# ACCEPTANCE CRITERIA

- Cannot publish invalid spot
- API works fast + cached
- Import works + upserts
- Events logged
- Audit works
- UI compact (<2 scroll screens)

---

# FINAL MINDSET

You are NOT building:
❌ directory plugin  
❌ content manager  

You ARE building:
✅ CityOS data layer  
✅ conversion engine  
✅ monetization infrastructure  

---

# START

1. Inspect repo
2. Build DB + migrator
3. Build Settings
4. Build Editor UX
5. Build Import + Sync
6. Build API + Events

Proceed step-by-step. No shortcuts.
Generate full production-ready code.