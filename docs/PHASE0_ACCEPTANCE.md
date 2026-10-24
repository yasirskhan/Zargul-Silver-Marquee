# Phase 0 acceptance — foundation

Status: **CODE COMPLETE / TECHNICALLY VERIFIED. Production and operator release remain separate and OFF.**
Verified source: `7ab6ff8b573a45f99eec19a81825a1d79ad762c7`
Hosted CI: [run 40020880262](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/40020880262) — **all six SUCCESS**.

## Approved Phase 0 requirements

The roadmap requires a dedicated repository, approved specification, requirement register, AI handoff, CI, explicit separation from `property-platform`, and imported/labeled proof-of-concept interfaces.

| Requirement | Evidence |
| --- | --- |
| Dedicated repository and protected branch workflow | `README.md`; only `chatgpt/phase0-foundation`; `.github/workflows/ci.yml`; no `main` writes or force-push. |
| Approved master specification | `docs/PROJECT_MASTER_SPEC.md` (approved baseline; development acceptance is separate). |
| Approved requirement register | `docs/FEATURE_REGISTER.csv`; Phase 0/0.5 and later approved rows. |
| Concise AI handoff and session control | `AI_HANDOFF.md`; `docs/SESSION_CONTROL.md`; archived handoffs preserved verbatim. |
| Automated tests and exact-SHA hosted CI | Six required jobs at `7ab6ff8`: `prototype-checks`, `central-postgres`, `licensing-postgres`, `laravel-postgres`, `editor-browser-e2e`, `authenticated-panel-e2e`. |
| Safe multi-session / one-writer protocol | `AGENTS.md`; expected-SHA publication, no blind overwrite, concurrent-work reconciliation. |
| Separation from `property-platform` | This repository is self-contained; `README.md` and `AGENTS.md` prohibit edits to `yasirskhan/property-platform`. |
| Proof-of-concept interfaces imported and labeled | `index.html`, `admin.html`, `website-editor.html`, `assets/` are explicitly labeled design prototypes; `README.md` states they can never authenticate staff, approve bookings, collect money or certify stock. |
| Non-authoritative demo boundary | `website-editor.html` displays `PROTOTYPE ONLY` and “No accounts, media, pages or payments are saved”; `docs/PROJECT_MASTER_SPEC.md` records the Phase 0 non-goals. |

## Explicit boundaries

- Phase 0 is a technical foundation; it is not a production release.
- No live bookings, real customer data, payments, production Telegram credentials or claims of a secure/immutable production backend are part of Phase 0.
- The static prototypes remain non-authoritative design references.
- Phase 12 operational acceptance, staging, restore, security and real operator signoffs remain separate external gates.
- All production release flags remain OFF.

## Verification method

The cited CI run belongs to the exact source SHA and all six required jobs completed `success`. A green run certifies the covered source only; it is not operator acceptance or production authorization.
