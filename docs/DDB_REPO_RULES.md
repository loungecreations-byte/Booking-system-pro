# DagjeDenBosch Repo Rules
- changing availability truth
- changing provider capability meaning
- changing add-to-day contract structure
- changing checkout/order contracts
- changing canonical shell behavior in a risky way

If unsure:
- preserve OMDB
- preserve Woo
- preserve shell
- preserve participants truth
- preserve availability truth
- use adapters instead of breaking contracts

---

## 15. Required docs to respect

Use these repository docs as authority:
- `docs/DDB_PLATFORM_CONSTITUTION.md`
- `docs/DDB_CTA_MAP.md`
- `docs/DDB_DO_NOT_TOUCH.md`
- `docs/DDB_PAGE_FAMILIES.md`
- `docs/DDB_COMPONENT_CANON.md`
- `docs/DDB_OMDB_WOO_BOUNDARIES.md`
- `docs/DDB_SHELL_RULES.md`
- `docs/DDB_PARTICIPANTS_TRUTH.md`
- `docs/DDB_AVAILABILITY_TRUTH.md`
- `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`

If a local implementation conflicts with these docs, the docs win unless explicitly updated first.

---

## 16. Completion standard

A refinement is only done if:
- page family role is clear
- CTA hierarchy matches journey phase
- shared components come from the design system
- shell remains stable
- dark/light remain coherent
- mobile behavior improves
- OMDB semantics remain preserved
- Woo pricing/booking truth remains preserved
- participants truth remains preserved
- availability decisions remain explicit
- request/direct routing remains correct
- provider capability separation remains preserved
- no page behaves like its own design island
- cart and checkout feel like part of the same ecosystem
- account and portal remain operational but aligned
- tour pages remain distinct in purpose but system-consistent
- quote / request flows remain operationally trustworthy

---

## 17. Final law

DagjeDenBosch must behave like one product.

If any page, module, plugin, template, or flow behaves like:
- a separate design system
- a separate product
- a separate visual language
- a separate booking truth
- a separate domain truth
- a separate participants truth
- a separate availability truth
- a separate provider-integration logic

then the platform is not yet normalized.