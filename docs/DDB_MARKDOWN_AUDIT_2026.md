# DDB Markdown Audit
Date: 2026  
Scope: All project-owned `.md` files (vendor/third-party excluded)  
Status: Complete

---

## Executive Summary

The project's markdown estate is well-intentioned but carries **two active structural problems** plus one resolved historical conflict:

1. **Typography canon conflict resolved** — the former mismatch between authoritative files has been corrected
2. **Three confirmed duplicates** — governance/RACI and release-gate files are doubled
3. **AGENTS.md mirrors the Constitution** — creates a long-term maintenance split-brain risk

Vendor changelogs and CI output logs are also being stored as part of the project but are not governance documents.

---

## Classification Legend

| Label | Meaning |
|---|---|
| CONSTITUTION | Platform or system truth — must be enforced |
| AGENT RULES | Agent/AI instruction files — consumed at runtime |
| GOVERNANCE | Process, RACI, release, and compliance docs |
| RUNBOOK | Step-by-step operational procedures |
| SPEC | Detailed technical specification |
| STATUS | Tracker or board — must be kept current |
| REFERENCE | Module/plugin documentation |
| CODEX PROMPT | Codex CLI / AI build prompt |
| ARCHIVE | Historical record — keep but not active |
| DUPLICATE | Superseded — should be archived |
| CI OUTPUT | Machine-generated operational output — not a doc |

---

## Root Level

| File | Size | Class | Verdict |
|---|---|---|---|
| `AGENTS.md` | 10.4 KB | AGENT RULES | **Keep** — but see Critical Finding #1 |
| `DDB_DESIGN_SYSTEM_SPEC.md` | 4.3 KB | CONSTITUTION | **Keep** — but see Critical Finding #2 |

### Root issues
- `AGENTS.md` is a near-complete restatement of `DDB_PLATFORM_CONSTITUTION.md` compressed for agent consumption. Every rule update must now be applied in two places. This is a maintenance split-brain risk. Consider making `AGENTS.md` a thin wrapper that references the canonical docs (or explicitly state it is a derived summary that may lag the constitution).
- `DDB_DESIGN_SYSTEM_SPEC.md` lives at root instead of `docs/`. Should be moved or clearly cross-referenced from the constitution.

---

## docs/ — Constitution & Platform Truth

| File | Size | Class | Verdict |
|---|---|---|---|
| `DDB_PLATFORM_CONSTITUTION.md` | 17.6 KB | CONSTITUTION | **Keep** — master authority |
| `DDB_CTA_MAP.md` | 2.4 KB | CONSTITUTION | **Keep** — clean, unambiguous |
| `DDB_DO_NOT_TOUCH.md` | 2.1 KB | CONSTITUTION | **Keep — but update** (see Critical Finding #3) |
| `DDB_PAGE_FAMILIES.md` | 8.1 KB | CONSTITUTION | **Keep** — authoritative |
| `DDB_COMPONENT_CANON.md` | 7.3 KB | CONSTITUTION | **Keep** — clean |
| `DDB_OMDB_WOO_BOUNDARIES.md` | 6.4 KB | CONSTITUTION | **Keep** — clear scope |
| `DDB_SHELL_RULES.md` | 1.5 KB | CONSTITUTION | **Keep** — tight and correct |

**Observation:** Seven constitution files is the right number. No merges needed here. The risk is that they are already partially repeated in `AGENTS.md` — that repetition is the problem, not the originals.

---

## docs/ — Execution & Runbook

| File | Size | Class | Verdict |
|---|---|---|---|
| `DDB_REVIEW_LOOP.md` | 4 KB | RUNBOOK | **Keep** — cross-references checklist correctly |
| `DDB_REGRESSION_CHECKLIST.md` | 4.1 KB | RUNBOOK | **Keep** — useful companion to review loop |
| `DDB_IMPLEMENTATION_SEQUENCE.md` | 4.1 KB | RUNBOOK | **Keep** — phased build order; update phase statuses |
| `DDB_LAUNCH_BOARD.md` | 6.4 KB | STATUS | **Keep — but stale** (see Critical Finding #4) |

---

## docs/ — Audit History

| File | Size | Class | Verdict |
|---|---|---|---|
| `DDB_CLEANUP_AUDIT_2026-04-08.md` | 13.7 KB | ARCHIVE | **Keep** — valuable historical record |

**Note:** This audit recorded the migration from the legacy `ddb-ui.css` blob, the mu-plugins consolidation, and the discovery of two competing design-system runtimes. Keep as permanent reference.

---

## docs/ — Agent Definitions (01–10)

| File | Size | Class | Verdict |
|---|---|---|---|
| `01-platform-governor.md` | 1.4 KB | AGENT RULES | **Keep** |
| `02-shell-template-agent.md` | 1.4 KB | AGENT RULES | **Keep** |
| `03-design-system-primitives-agent.md` | 8.7 KB | AGENT RULES | **Keep — but fix font conflict** (see Critical Finding #2) |
| `04-overview-family-agent.md` | 1.7 KB | AGENT RULES | **Keep** |
| `05-detail-family-agent.md` | 1.7 KB | AGENT RULES | **Keep** |
| `06-planner-safety-agent.md` | 1.2 KB | AGENT RULES | **Keep** |
| `07-omdb-woo-boundary-agent.md` | 1.4 KB | AGENT RULES | **Keep** |
| `08-mobile-regression-qa-agent.md` | 1.1 KB | AGENT RULES | **Keep** |
| `09-design-system-truth-agent.md` | 2.1 KB | AGENT RULES | **Keep** |
| `10-csot-omdb-review-agent.md` | 1.0 KB | AGENT RULES | **Keep** |

**Observation:** The 10-agent structure maps cleanly to the review loop in `DDB_REVIEW_LOOP.md`. The numbering is deliberate. Do not collapse these.

**Concern:** Agents 07 (`07-omdb-woo-boundary-agent.md`) and 10 (`10-csot-omdb-review-agent.md`) have significantly overlapping scope (both check OMDB/Woo boundaries). Agent 10 is the final gate; agent 07 is the dedicated mid-loop check. The overlap is structurally intentional but both files should be checked for contradictions if either is updated.

---

## docs/governance/

### Keep — Authoritative

| File | Size | Class | Verdict |
|---|---|---|---|
| `DDB_GOVERNANCE_POLICY.md` | 8.6 KB | GOVERNANCE | **Keep** — master governance policy |
| `DDB_RACI.md` | 5.2 KB | GOVERNANCE | **Keep** — authoritative RACI |
| `DDB_RELEASE_GATES.md` | 6.6 KB | GOVERNANCE | **Keep** — authoritative gate conditions |
| `DDB_GOVERNANCE_DASHBOARD_SPEC.md` | 7.8 KB | SPEC | **Keep** — dashboard blueprint |
| `DDB_DIRECTIENOTITIE_TOGAF_GOVERNANCE.md` | 9.3 KB | GOVERNANCE | **Keep** — official board document |
| `2026-03-09-besluitmemo-directie.md` | 1.5 KB | GOVERNANCE | **Keep** — board decision record |
| `kpi-en-roadmap-90-dagen.md` | 1.2 KB | GOVERNANCE | **Keep** — KPI definitions |

### Archive — Superseded

| File | Size | Class | Verdict |
|---|---|---|---|
| `raci-governance-dagjedenbosch.md` | 0.8 KB | DUPLICATE | **Archive** — compact RACI matrix that is fully covered by `DDB_RACI.md` |
| `release-gates-productie.md` | 0.9 KB | DUPLICATE | **Archive** — brief Dutch gate checklist fully covered by `DDB_RELEASE_GATES.md` |
| `2026-03-09-directienotitie-togaf-dagjedenbosch.md` | 2.1 KB | ARCHIVE | **Archive** — earlier draft superseded by `DDB_DIRECTIENOTITIE_TOGAF_GOVERNANCE.md` |

---

## plugins/ddb-core-ui/

| File | Size | Class | Verdict |
|---|---|---|---|
| `GOVERNANCE.md` | 2.5 KB | REFERENCE | **Keep** — plugin-scoped developer governance guide |
| `README.md` | 0.5 KB | REFERENCE | **Keep** — minimal stub, acceptable |

**Observation:** `GOVERNANCE.md` partially overlaps with the platform-level governance policy but serves a different audience (plugin developers, CI/ops). Overlap is acceptable as long as it does not contradict the platform governance. No conflicts found.

---

## plugins/ddb-spots-0.1.0/

| File | Size | Class | Verdict |
|---|---|---|---|
| `ddb-spots-engine.md` | 6.3 KB | CODEX PROMPT | **Keep** — Codex CLI build prompt for the spots engine |
| `README.md` | 6.0 KB | REFERENCE | **Keep** |
| `CHANGELOG-0.2.0.md` | 2.3 KB | ARCHIVE | **Keep** — version history |
| `ROLLBACK-0.2.0.md` | 1.4 KB | RUNBOOK | **Keep** — operational rollback procedure |

**Concern:** `ddb-spots-engine.md` is a Codex CLI prompt embedded in the plugin source tree. This is unusual practice — build prompts as source files. It is useful but should be clearly labeled as a build prompt, not documentation. If it becomes outdated relative to actual plugin behavior, it will be actively misleading.

---

## plugins/ddb-mega-menu/

| File | Size | Class | Verdict |
|---|---|---|---|
| `PAGESPEED-CHECKLIST.md` | 0.9 KB | RUNBOOK | **Keep** — useful pre-deploy checklist |
| `REFACTOR-NOTES.md` | 1.0 KB | REFERENCE | **Keep** — architecture decision record |
| `README.md` | 1.7 KB | REFERENCE | **Keep** |
| `ELEMENTOR-INSTALL-STEPS.md` | 1.5 KB | RUNBOOK | **Keep** — operational install steps |

No issues in this directory.

---

## plugins/booking-pro-module/ (project-owned only)

| File | Size | Class | Verdict |
|---|---|---|---|
| `AGENTS.md` | 12.2 KB | AGENT RULES | **Keep** — BPM-specific PR review guide for AI |
| `README.md` | 4.4 KB | REFERENCE | **Keep** |
| `CHANGELOG.md` | 1.3 KB | ARCHIVE | **Keep** |
| `modules/planboard/README.md` | 0.6 KB | REFERENCE | **Keep** — feature flag + admin page reference |
| `modules/planner/README-planboard.md` | 1.1 KB | REFERENCE | **Keep** — REST API endpoint reference |
| `modules/sales/README.md` | 1.5 KB | REFERENCE | **Keep** — module API + CLI reference |
| `ops/codex-prompts/bookingboard.md` | 12.6 KB | CODEX PROMPT | **Keep** — operational build prompt |
| `ops/codex-output/weekend_summary.md` | 1.1 KB | CI OUTPUT | **Archive** — machine-generated CI run summary |
| `ops/codex-output/weekend_pr.md` | 1.3 KB | CI OUTPUT | **Archive** — machine-generated PR log |
| `scripts/README.md` | 0.7 KB | REFERENCE | **Keep** |

**Observation:** `ops/codex-output/` contains machine-generated CI logs stored as markdown. These are not documentation — they are operational artifacts. If you want to keep CI output history, store it in a dedicated archive path (e.g. `ops/archive/`) or exclude from the main tree.

---

## Critical Findings

### Finding #1 — AGENTS.md maintenance split-brain
`AGENTS.md` (root, 10.4 KB) duplicates nearly everything in `DDB_PLATFORM_CONSTITUTION.md`. It is a compressed agent-optimized rewrite of the constitution — which is useful — but it means every governing rule change must be applied in two places. If they drift, the agent will contradict the constitution.

**Recommendation:** Either:
- Add a header to `AGENTS.md` explicitly stating it is derived from the constitution and may lag
- Or restructure `AGENTS.md` so it only defines the enforcement mandate and references the constitution docs rather than re-stating all the rules

---

### Finding #2 — Typography canon conflict (RESOLVED)
**`DDB_DESIGN_SYSTEM_SPEC.md`** (root) specifies:
- UI/body = **Quattrocento Sans**
- Headlines = **Quattrocento Serif**

**`docs/03-design-system-primitives-agent.md`** now specifies:
- UI/body = **Quattrocento Sans**
- Headlines = **Quattrocento Serif**

The primitives agent doc has been corrected to match `DDB_DESIGN_SYSTEM_SPEC.md`, so the typography authority is now aligned and no longer a live contradiction.

---

### Finding #3 — DDB_DO_NOT_TOUCH.md incorrectly identifies the design CSOT
`DDB_DO_NOT_TOUCH.md` states:

> `ddb-core-design-system.php` remains the design CSOT

But `DDB_CLEANUP_AUDIT_2026-04-08.md` explicitly states that after the cleanup execution:

> active visual runtime truth is now `ddb-core-ui`  
> the legacy mu design-system file is disabled unless `DDB_ENABLE_LEGACY_MU_DESIGN_SYSTEM` is explicitly set

`DDB_DO_NOT_TOUCH.md` was not updated after the runtime migration. It is now pointing to the wrong file as the design CSOT.

**Required fix:** Update `DDB_DO_NOT_TOUCH.md` to reflect `ddb-core-ui/core-ui.php` as the active design CSOT.

---

### Finding #4 — DDB_LAUNCH_BOARD.md is stale
The launch board is frozen at 2026-04-02. Phase 3 (Overview Family) is marked as "Current". The board does not reflect any work done since then, including the design system cleanup, the combi deals restoration, the shortcode fix, or the browser-bar stripping done in the current session.

**Recommendation:** Either adopt a discipline to update this board after each significant change, or clearly mark it as a point-in-time snapshot with the date.

---

### Finding #5 — Three governance duplicates
The following files duplicate content from authoritative files:

| Duplicate | Superseded by |
|---|---|
| `docs/governance/raci-governance-dagjedenbosch.md` | `docs/governance/DDB_RACI.md` |
| `docs/governance/release-gates-productie.md` | `docs/governance/DDB_RELEASE_GATES.md` |
| `docs/governance/2026-03-09-directienotitie-togaf-dagjedenbosch.md` | `docs/governance/DDB_DIRECTIENOTITIE_TOGAF_GOVERNANCE.md` |

All three should be moved to an `archive/` subfolder inside `docs/governance/`.

---

## Action Summary

### Immediate (blockers)

1. **Typography conflict resolved**: `docs/03-design-system-primitives-agent.md` now matches `DDB_DESIGN_SYSTEM_SPEC.md` with Quattrocento for headlines and Quattrocento Sans for UI/body.
2. **Fix DDB_DO_NOT_TOUCH.md**: Change design CSOT reference from `ddb-core-design-system.php` to `ddb-core-ui/core-ui.php`.

### Short-term (before next release)

3. **Archive or consolidate the 3 duplicate governance files** (RACI, release-gates, directienotitie draft)
4. **Update DDB_LAUNCH_BOARD.md** to reflect current phase status
5. **Add a "derived from constitution" disclaimer to AGENTS.md** so agents and developers know it may not be the final word

### Low priority

6. Move `DDB_DESIGN_SYSTEM_SPEC.md` from root into `docs/` for consistency
7. Move `ops/codex-output/` CI logs to `ops/archive/` or exclude from documentation tree
8. Add dates/version stamps to constitution files (only `DDB_IMPLEMENTATION_SEQUENCE.md` has date markers)

---

## Summary Table — All Files

| File | Class | Action |
|---|---|---|
| `AGENTS.md` | AGENT RULES | Keep + add disclaimer |
| `DDB_DESIGN_SYSTEM_SPEC.md` | CONSTITUTION | Keep + resolve font conflict |
| `docs/DDB_PLATFORM_CONSTITUTION.md` | CONSTITUTION | Keep |
| `docs/DDB_CTA_MAP.md` | CONSTITUTION | Keep |
| `docs/DDB_DO_NOT_TOUCH.md` | CONSTITUTION | Keep + update CSOT reference |
| `docs/DDB_PAGE_FAMILIES.md` | CONSTITUTION | Keep |
| `docs/DDB_COMPONENT_CANON.md` | CONSTITUTION | Keep |
| `docs/DDB_OMDB_WOO_BOUNDARIES.md` | CONSTITUTION | Keep |
| `docs/DDB_SHELL_RULES.md` | CONSTITUTION | Keep |
| `docs/DDB_REVIEW_LOOP.md` | RUNBOOK | Keep |
| `docs/DDB_REGRESSION_CHECKLIST.md` | RUNBOOK | Keep |
| `docs/DDB_IMPLEMENTATION_SEQUENCE.md` | RUNBOOK | Keep + update phase status |
| `docs/DDB_LAUNCH_BOARD.md` | STATUS | Keep + update |
| `docs/DDB_CLEANUP_AUDIT_2026-04-08.md` | ARCHIVE | Keep |
| `docs/01-platform-governor.md` | AGENT RULES | Keep |
| `docs/02-shell-template-agent.md` | AGENT RULES | Keep |
| `docs/03-design-system-primitives-agent.md` | AGENT RULES | Keep + fix font |
| `docs/04-overview-family-agent.md` | AGENT RULES | Keep |
| `docs/05-detail-family-agent.md` | AGENT RULES | Keep |
| `docs/06-planner-safety-agent.md` | AGENT RULES | Keep |
| `docs/07-omdb-woo-boundary-agent.md` | AGENT RULES | Keep |
| `docs/08-mobile-regression-qa-agent.md` | AGENT RULES | Keep |
| `docs/09-design-system-truth-agent.md` | AGENT RULES | Keep |
| `docs/10-csot-omdb-review-agent.md` | AGENT RULES | Keep |
| `docs/governance/DDB_GOVERNANCE_POLICY.md` | GOVERNANCE | Keep |
| `docs/governance/DDB_RACI.md` | GOVERNANCE | Keep |
| `docs/governance/DDB_RELEASE_GATES.md` | GOVERNANCE | Keep |
| `docs/governance/DDB_GOVERNANCE_DASHBOARD_SPEC.md` | SPEC | Keep |
| `docs/governance/DDB_DIRECTIENOTITIE_TOGAF_GOVERNANCE.md` | GOVERNANCE | Keep |
| `docs/governance/2026-03-09-besluitmemo-directie.md` | GOVERNANCE | Keep |
| `docs/governance/kpi-en-roadmap-90-dagen.md` | GOVERNANCE | Keep |
| `docs/governance/raci-governance-dagjedenbosch.md` | DUPLICATE | Archive |
| `docs/governance/release-gates-productie.md` | DUPLICATE | Archive |
| `docs/governance/2026-03-09-directienotitie-togaf-dagjedenbosch.md` | ARCHIVE | Archive |
| `plugins/ddb-core-ui/GOVERNANCE.md` | REFERENCE | Keep |
| `plugins/ddb-core-ui/README.md` | REFERENCE | Keep |
| `plugins/ddb-spots-0.1.0/ddb-spots-engine.md` | CODEX PROMPT | Keep |
| `plugins/ddb-spots-0.1.0/README.md` | REFERENCE | Keep |
| `plugins/ddb-spots-0.1.0/CHANGELOG-0.2.0.md` | ARCHIVE | Keep |
| `plugins/ddb-spots-0.1.0/ROLLBACK-0.2.0.md` | RUNBOOK | Keep |
| `plugins/ddb-mega-menu/PAGESPEED-CHECKLIST.md` | RUNBOOK | Keep |
| `plugins/ddb-mega-menu/REFACTOR-NOTES.md` | REFERENCE | Keep |
| `plugins/ddb-mega-menu/README.md` | REFERENCE | Keep |
| `plugins/ddb-mega-menu/ELEMENTOR-INSTALL-STEPS.md` | RUNBOOK | Keep |
| `plugins/booking-pro-module/AGENTS.md` | AGENT RULES | Keep |
| `plugins/booking-pro-module/README.md` | REFERENCE | Keep |
| `plugins/booking-pro-module/CHANGELOG.md` | ARCHIVE | Keep |
| `plugins/booking-pro-module/modules/planboard/README.md` | REFERENCE | Keep |
| `plugins/booking-pro-module/modules/planner/README-planboard.md` | REFERENCE | Keep |
| `plugins/booking-pro-module/modules/sales/README.md` | REFERENCE | Keep |
| `plugins/booking-pro-module/ops/codex-prompts/bookingboard.md` | CODEX PROMPT | Keep |
| `plugins/booking-pro-module/ops/codex-output/weekend_summary.md` | CI OUTPUT | Archive |
| `plugins/booking-pro-module/ops/codex-output/weekend_pr.md` | CI OUTPUT | Archive |
| `plugins/booking-pro-module/scripts/README.md` | REFERENCE | Keep |
