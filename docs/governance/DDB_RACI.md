# DagjeDenBosch Governance RACI

## Purpose
This document defines ownership, accountability, review, and escalation responsibilities for governance and release decisions.

RACI meanings:
- **R** = Responsible (does the work)
- **A** = Accountable (final owner)
- **C** = Consulted (must be consulted)
- **I** = Informed (must be kept informed)

---

## Roles

### Directie
Strategic authority and exception acceptance at highest level.

### Product Owner
Owns business value, prioritization, and customer outcome.

### Tech Lead
Owns architecture, system integrity, and technical compliance.

### Operations / Release Owner
Owns release coordination, timing, rollback readiness, and operational release quality.

### Security / Privacy
Owns privacy/security-related compliance and relevant governance review.

### UX / Design System Owner
Owns page-family consistency, CTA hierarchy, and design-system truth.

### QA / Review Owner
Owns regression validation and pass/fail review evidence.

---

## 1. Governance policy ownership

| Activity | Directie | Product Owner | Tech Lead | Operations | Security/Privacy | UX/DS Owner | QA/Review |
|---|---|---|---|---|---|---|---|
| Approve governance policy | A | C | C | I | C | C | I |
| Maintain governance policy | I | C | A | C | C | C | I |
| Review policy effectiveness | A | C | C | C | C | C | I |

---

## 2. Architecture / constitution ownership

| Activity | Directie | Product Owner | Tech Lead | Operations | Security/Privacy | UX/DS Owner | QA/Review |
|---|---|---|---|---|---|---|---|
| Maintain platform constitution | I | C | A | I | I | C | I |
| Maintain CTA map | I | A | C | I | I | R | I |
| Maintain page-family rules | I | C | C | I | I | A | I |
| Maintain component canon | I | I | C | I | I | A | I |
| Maintain OMDB/Woo boundaries | I | I | A | I | C | I | I |

---

## 3. Change intake and classification

| Activity | Directie | Product Owner | Tech Lead | Operations | Security/Privacy | UX/DS Owner | QA/Review |
|---|---|---|---|---|---|---|---|
| Classify change scope | I | A | R | C | C | C | I |
| Mark change as high-risk | I | C | A | C | C | C | I |
| Decide if exception process is needed | C | C | A | C | C | I | I |

---

## 4. Gate review ownership

| Gate | Directie | Product Owner | Tech Lead | Operations | Security/Privacy | UX/DS Owner | QA/Review |
|---|---|---|---|---|---|---|---|
| Constitution Gate | I | C | A | I | I | C | I |
| Design System Truth Gate | I | I | C | I | I | A | C |
| Shell Integrity Gate | I | I | A | C | I | C | C |
| Page Family Gate | I | C | C | I | I | A | C |
| OMDB Boundary Gate | I | I | A | I | C | I | I |
| Woo / Commerce Truth Gate | I | I | A | C | C | I | I |
| Planner Continuity Gate | I | I | A | I | I | C | C |
| Cart / Checkout Execution Gate | I | I | A | C | C | I | C |
| Mobile / Responsive Gate | I | I | C | I | I | C | A |
| Dark / Light Integrity Gate | I | I | C | I | I | A | C |
| Launch Readiness Gate | I | C | C | A | C | C | C |

---

## 5. Release approval ownership

| Activity | Directie | Product Owner | Tech Lead | Operations | Security/Privacy | UX/DS Owner | QA/Review |
|---|---|---|---|---|---|---|---|
| Approve normal release | I | C | C | A | C | C | C |
| Approve high-risk release | I | C | A | C | C | C | C |
| Approve exception release | A | C | C | C | C | I | I |
| Coordinate rollback | I | I | C | A | I | I | C |

---

## 6. Exception handling ownership

| Activity | Directie | Product Owner | Tech Lead | Operations | Security/Privacy | UX/DS Owner | QA/Review |
|---|---|---|---|---|---|---|---|
| Raise exception request | I | C | A | C | C | C | I |
| Assess exception risk | I | C | A | C | C | C | I |
| Approve exception | A | C | C | C | C | I | I |
| Track exception expiry and fix | I | C | A | C | I | I | I |

---

## 7. KPI and dashboard ownership

| Activity | Directie | Product Owner | Tech Lead | Operations | Security/Privacy | UX/DS Owner | QA/Review |
|---|---|---|---|---|---|---|---|
| Define KPI set | C | A | C | C | C | C | I |
| Maintain dashboard logic | I | C | A | C | C | C | I |
| Update launch board | I | C | C | A | I | C | C |
| Monthly governance review | A | C | C | C | C | C | I |
| 90-day effectiveness review | A | C | C | C | C | C | I |

---

## 8. Blocking rights

The following roles may block a release within their scope:

### Tech Lead may block
- architecture violations
- shell violations
- OMDB/Woo boundary violations
- planner continuity risk
- execution risk

### UX / Design System Owner may block
- severe design-system truth violations
- severe page-family violations
- severe CTA hierarchy violations on critical public pages

### Operations may block
- missing release readiness
- rollback unprepared
- unclear release ownership
- operational instability

### Security / Privacy may block
- compliance or privacy/security risk

### Directie may block or overrule
- strategic misalignment
- unacceptable business risk
- exception approval/denial

---

## 9. Final law

If ownership is unclear, the release is not ready.

If accountability is unclear, the release is blocked until clarified.