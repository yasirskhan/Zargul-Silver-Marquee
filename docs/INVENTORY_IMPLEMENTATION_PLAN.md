# Hall Inventory Delivery Plan — schema and workflow contracts

Status: APPROVED WORKFLOW / INITIAL IMPLEMENTATION IN PROGRESS. No production stock, loans or balances until every release gate is enabled.

## Reuse and ownership
All stock records are keyed by company AND physical hall. The company is an authorization/reporting boundary, never an aggregate owner-stock bucket. Halls may be same-establishment or external. Catalog item creation starts at ZERO owned inventory and has no financial posting. Purchase/stock receipt is a separate authorized movement with evidence. Preserve existing Hall, TenantAccess, Event Operations, Approval Center, Audit and Finance services.

## Bounded implementation milestones
1. Hall catalog: migration and authorized assigned-manager/owner create service, idempotent item UUID, immutable creation event+global audit, hall composite FK, normalized name duplicate guard, unit/category and minimum quantity, PG no-cross-tenant/no-edits tests. Catalog service is initially backend-only; do not expose unapproved stock release.
2. Hall positions and movements: owned usable, owned on loan, borrowed usable, borrowed transit and damaged in hall-scoped balance rows, immutable append-only inventory movement events, deterministic locking, quantity validation and opening/purchase/consumption/loss paths. Include physical stocktake snapshots comparing expected vs counted after loan-aware netting; defer write-off until authorized review.
3. Multi-line loan contract: one unique loan ID, direction INTERNAL or EXTERNAL_IN or EXTERNAL_OUT; immutable origination and each distinct item+qty line; internal references two different authorized halls in the same tenant, external references tenant-owned partner contact. Internal dest catalog can match its own item code but ownership remains with source hall. For external in, borrower hall catalog ID records borrowed-only custody and never increases owned stock.
4. Handover and return: independent source dispatch and destination receipt counts. Every return is immutable batch with per-item counted quantity/condition and counterparty acknowledgment. Partial batches permitted; accepted damaged returns excluded from good-condition matched total. Bad quantities, duplicate confirmation actor, stale revision and external forged return must fail.
5. Automatic closure: deterministic server check of ALL row quantities matching and sound condition; both independent confirmations (or externally signed accepted receipt with authorized review); no unresolved in-transit/outstanding/penalty/deposit conditions; append COMPLETED event once and create numbered unalterable PDF receipt. A client checkbox alone is never proof of external acceptance. Idempotent retries cannot mint second completion receipt.
6. UX: Filament owner/assigned-manager hall inventory, list/new item inline in Add Row, unlimited bounded multiple rows, select source/destination, external profile, date/event, return count per row, partial return history, variance banner, confirmation workflow; no company inventory pool menu. Hall reports, post-event/monthly/quarterly audits, borrow overdue and minimum alerts.
7. Acceptance: real PG same-company different-hall, cross-company false FK, internal transfer total owned unchanged, external loan counting, 50 sent / 45 returned / five unresolved, 100 plates / 80 good + 5 damaged + 15 unaccounted, overlapping concurrent loans, repeat callbacks, actor revocation during lock, no double-count with stocktake, complete only with all lines fully good and verified, immutable audit and backed-up recovery.

## Release and performance gates
Use separate feature flag; no real inventory or customer data until acceptance. Shared finance journals not updated by catalog/loan receipts; deposits, fees, loss write-offs must use approved Finance service. Indexed company/hall/item/date lookups, per-loan bounded line queries, row-locked deterministic order and batched audit/report computation. CI must run all six required hosted jobs on exact published SHA; post-green only mark verified and continue next bounded milestone. Staging data migration non-destructive.


## Verified and open status — October 19, 2026
- Hall-owned catalog creation, counted physical evidence and multi-line draft loan UI remain separate feature-flagged foundations; no owned stock quantity was created solely by adding a catalog item.
- Read-only authorized review of loan draft rows, expected returns, origin/destination and item snapshots VERIFIED: `4fa961e063de40133df1c4534367e393c0f1f8ce`, [CI 39242040957](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39242040957), all six jobs success.
- New read-only hall physical stocktake history is published `78ddd22486f3b257d6fa74e238eaa639e419a6b8`; [CI 39242991304](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39242991304) was PENDING when this note was prepared, and MUST pass all six jobs before marked verified.
- **NOT IMPLEMENTED OR RELEASED:** independently corroborated stock opening/purchase balances; inventory movement book; two-party dispatch and receipt acceptance; loaned versus owned balance arithmetic; partial return batches and damaged/missing stock financial escalation; final numbered immutable receipt and reports; real operational pilot. Maintain no-false-posting safeguards, immutable audit and external contact encryption.


### Exact verification update
- Published history source `78ddd22486f3b257d6fa74e238eaa639e419a6b8` verified [CI 39242991304](https://github.com/yasirskhan/Zargul-Silver-Marquee/actions/runs/39242991304) SUCCESS all six required jobs. It reads saved stocktake evidence only under active assigned-hall permissions and exposes neither current owned balances nor approved variances. Next priority is a correct immutable owned-stock/borrowed-position movement book, followed by independently acknowledged dispatch, acceptance, partial return and closed-case proof.


### Next safe position arithmetic foundation (October 19, 2026)
- `HallInventoryPositionTransition` is a PURE typed candidate transition policy with six independent stock buckets: owned usable, owned on loan, owned damaged, borrowed usable, borrowed transit, borrowed damaged. Counter invariants prevent negatives, overflow, forged categories, unsupported events, and borrowed-as-owned classification. Borrowed return remains in transit until separate confirmation.
- Isolated Laravel feature tests exercise 50 dispatched / 45 good + 5 damaged, 100 borrowed / 80 returned with 20 outstanding and 5 damaged, strict underflow, overflow, zero, unrecognized events and immutable caller input.
- This is NOT a stock journal, actual count reconciliation, approval, physical handover, authorization or release. Never use this pure policy to post inventory alone; next implementation must add isolated hall-scoped immutable row-locked DB positions with independently reviewed receipts and authorization. All inventory production gates remain OFF. Verify exact six-job CI before calling the foundation VERIFIED.


### PREPARED additive hall position and append-only movement journal schema
- A separate PostgreSQL migration adds hall/item/company composite-foreign-keyed six-bucket position rows with bounded unsigned counts, revision tracking, zero defaults and unique per-item position ownership. Catalog items retain ZERO stock unless separate approved receipt action is recorded.
- Append-only movement events bind each record to exact company/hall/item/position, typed candidate event, positive quantity, consecutive revision, actor, reason, fingerprint and before/after snapshots. Database trigger refuses UPDATE/DELETE on journal entries; unique (position,revision_after) prevents double audit entries at one revision.
- This schema is NOT itself a stock issue/receipt service, authorizer, verified movement reconciler or financial posting. Do not write any positions outside a separately tested service with trusted evidence and locking. All production inventory feature gates stay OFF. Add isolated PostgreSQL and cross-hall isolation tests before publishing and require own exact six-job CI.


### DETACHED opening-stock evidence and independent approval
- An immutable proposal records item, existing same-hall physical opening stocktake and positive quantity; no stock balance is changed when requested. The existing physical count's owned/usable line must EXACTLY match the proposed quantity; no invented stock or unverified purchase value.
- A DISTINCT currently authorized owner/admin must approve before the first `opening_owned` movement can be posted. Acceptance is a separate immutable row referencing exact immutable movement and original request, not an editable approved checkbox. The unique per-item opening request prevents repeated re-opening to inflate owned goods. Production switch `VENUE_INVENTORY_OPENING_POSTING_ENABLED=false`.
- These are schema prerequisites only until a secure deterministic locking service and PostgreSQL tests are complete. No data is inserted by migration, no revenue/expense recorded or payment posted.
