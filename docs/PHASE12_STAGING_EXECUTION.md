# Phase 12 — Isolated Staging Installation, Browser UAT and Actual Evidence

**Status: STAGING NOT VERIFIED OR DEPLOYED BY THIS REPOSITORY. This is an executable plan, not a human test result.**
**Scope:** ONLY chatgpt/phase0-foundation, separate synthetic staging instance, production release OFF; Phase 12 OPEN. No dependency on unfinished bookings, public website, payroll or other phases.

## Infrastructure request: precise external deployment blockers

Repository inspection finds a test-only GitHub Actions CI workflow, no deployment job, no published GitHub Pages homepage and no authenticated staging URL or staff accounts in the repository. Connected GitHub integration does **not** grant access to deployment/environment resources, host terminal, DNS/TLS, PostgreSQL host, durable private document store or secret manager. A staging instance might exist outside GitHub, but its existence, URL and SHA cannot be confirmed.

**Owner / authorized infrastructure operator must supply, without committing secrets to GitHub:**

1. **One staging server / private environment** capable of PHP **8.3+**, Composer's exact locked dependencies for Laravel 13 / Filament 5, web server, PHP extensions, process permissions and HTTPS. Grant a named deployment operator access and specify the approved staging release SHA. Never deploy to production, main or property-platform.
2. **DNS + TLS**: a real controlled staging/test/uat host, HTTPS certificate and route to the Filament panel under /admin. Supply the deployed public HTTPS URL and a screenshot of verified certificate/response. APP_URL must be its exact HTTPS origin and APP_DEBUG=false.
3. **Isolated PostgreSQL 16+** database with dedicated least-privilege app role, separated from production, database migration/backup/restore operator, durable DB-backed cache and sessions and safe retention. Supply DB host, test database identity, connection permissions and snapshots to the staging operator via secrets manager, NOT GitHub/chat.
4. **Durable private document storage**: the current implementation uses Storage::disk('local') and private/hall-* paths; the host must mount a persistent, privately readable/writable Laravel storage volume with no public listing, shared consistently across application workers. It must be included in coordinated snapshots with PostgreSQL. Object-store production-equivalence is NOT proven by local fake-disk CI.
5. **Secrets and test identities**: a strong APP_KEY, SMTP/mail onboarding route or supervised manual credentials, real MFA enrollment for owner/admin/managers, six unique staging-only passwords securely placed in secret configuration; no shared defaults or plaintext password in a repository/issue/log.
6. **Operator-controlled fault/restore window** and two separate hall managers, three independent owner-role reviewers, external partner, actual bank-document verifier, independent security approver. Name each reviewer, staging change window, review evidence location and signed acceptance records.

Without these inputs, the real staging URL, human screenshots, bank proof and real restore test are **BLOCKED EXTERNAL**.

## Deployment operator run sequence — NOT EXECUTED HERE

Use a fresh isolated checkout of the authorized branch at an exact six-green-job SHA; check the SHA before every deploy. Run commands from the repository's backend folder; commands intentionally omit any real credentials.

    composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
    cp .env.example .env
    # Inject APP_KEY and other environment secrets from an external vault.
    # APP_ENV=staging; APP_DEBUG=false; APP_URL=https://<controlled-staging-host>
    # DB_CONNECTION=pgsql; DB_DATABASE must visibly identify isolated staging/test.
    # CACHE_STORE=database; SESSION_DRIVER=database; SESSION_SECURE_COOKIE=true
    # SESSION_ENCRYPT=true; FILESYSTEM_DISK=local on a durable private volume
    php artisan config:cache
    php artisan migrate --force
    php artisan route:list --path=admin
    php artisan about

Ensure HTTPS, backups, rollback and supervised MFA are working. The production .env and release toggles must stay unchanged. For **isolated staging ONLY**, the Owner/infrastructure reviewer may authorize appropriate VENUE_INVENTORY_* flags and finance_controls posting controls for synthetic operations. This is **not** production activation and is not authorized merely by this runbook. Test finance journals are synthetic records in an isolated database, never live bank or real cash.

### Staging-only synthetic initial identities and catalog

After exact SHA, company/hall isolation and HTTPS are verified, the authorized operator can opt in explicitly to the new CLI command:

    VENUE_PHASE12_STAGING_SEED_ALLOWED=1 php artisan venuedesk:seed-phase12-staging

**Required secret vault environment variables:** PHASE12_STAGING_OWNER_A_PASSWORD, PHASE12_STAGING_OWNER_B_PASSWORD, PHASE12_STAGING_OWNER_C_PASSWORD, PHASE12_STAGING_ADMIN_PASSWORD, PHASE12_STAGING_MANAGER_A_PASSWORD, PHASE12_STAGING_MANAGER_B_PASSWORD. All six must be independently generated and at least 16 characters, injected out of band. The command refuses production, reused passwords, non-HTTPS staging hosts, non-stage/test database identities and any second execution on the same seeded company. Never echo passwords or MFA seeds to logs.

Only synthetic company "Phase 12 SYNTHETIC acceptance only" is created, with "Hall A — Synthetic Source" and "Hall B — Synthetic Borrower", independent role-scoped staff and zero-owned-stock catalog items Chairs, Tables, Serving Trays and Stage Lights for each hall. Managers are assigned to separate halls; three owner identities permit actual independence checks. **No physical stock, loans, cash, journals or fake partner proofs are posted by the seed.** Owner/Manager must use the real authenticated Inventory UI to create original counted stock and physically witnessed test transactions. Each user must separately enroll valid MFA at first login and be given credentials securely outside GitHub.

**Reset procedure:** before the first scenario, capture coordinated snapshots of the isolated synthetic **PostgreSQL staging database AND private document volume** with matching UTC identifiers. Between scenarios the approved infrastructure operator restores BOTH snapshots into the same isolated staging environment, verifies schema, user MFA, catalog counts, document paths and row/hash counts, and records reset evidence. Do not use TRUNCATE of immutable audit tables, force-reset production, silently delete loan records or reuse live finance data. Reseeding after snapshot restore only when the initial seed does not already exist.

## Browser screen and mandatory evidence recording

Visit https://<your-real-staging-host>/admin, authenticate with your own staged account and complete Filament MFA. Open sidebar **Hall inventory**, then select synthetic company and Hall A or Hall B at the top. The responsive screen is built at backend/resources/views/filament/pages/hall-inventory.blade.php; buttons below are sourced from that current view. Capture **desktop 1366x768 and mobile 390x844** shots of this actual hosted screen after any stage exists; original PDFs/downloads must be stored privately with SHA256, not published into GitHub. No screenshots in repository currently establish an actual staged UAT run.

For every scenario save: test ID, deployed SHA, actor ID/role/hall, UTC, signed private file SHA256/size, before/after per-item owned_usable/owned_on_loan/owned_damaged/borrowed_usable/borrowed_transit/borrowed_damaged, movement revision chain, audit action/count, finance_journals/lines, unique closure serial/PDF, observed error/success, screenshot/recording pointers and separate reviewer signoff. Until it is really performed, **actual result = NOT RUN**.

### A. Create inventory, purchase and stocktake

1. Log in as Owner A (Hall A). In Hall inventory use **Add a new item to this hall** > **Add hall item** for any extra test SKU. Catalog creation must leave owned stock **zero**; select Chairs, Tables, Serving Trays, Stage Lights.
2. In **Count physical inventory**, enter an **opening** count with Chairs 100 good, Tables 20 good, and separate other items. Save the count. In **Opening stock: independent review**, choose the original stocktake and quantity 100 and request independent verification. Log in as Owner B; press **Accept verified opening stock**. Expected Hall A owned usable Chairs 100, on-loan 0, damaged 0; no finance journal created.
3. In **Purchased stock: receiving requested**, propose synthetic Tables 10 with vendor/document reference, then use **Record independently counted delivery** as a different witness. Attach private original evidence using **Attach original evidence privately**. Owner B/C independently reviews in **Owner/admin independent receiving review**, then click **Accept purchased stock (no financial entry)**. Expect actual counted +10 usable Tables once, exact same UUID retry does not post twice.
4. Use **Count physical inventory** for monthly/post-event counts and open **Physical versus recorded book snapshot**. Reviewer acknowledges variance; **Damaged owned stock: independent physical disposition** is an independently authorized review, not an automatic write-off. Verify condition and historical audit remain immutable.

### B. INTERNAL Hall A -> Hall B, 100 chairs

1. Ensure genuine synthetic Hall A 100 owned usable chairs, Hall B has matching catalog Chairs but 0 owned. On Hall inventory select Hall A, **Borrow / lend multiple inventory items**, internal direction, lending A/borrowing B, Chairs quantity **100**, then **Save borrowing request (no stock moved)**. Observe owned=100/on-loan=0.
2. Change to separate authorized Owner/Admin who did NOT draft, open original request and **Independently witnessed source dispatch** > **Confirm actual source physical dispatch**, with signed original handover file. Hall A goes owned usable **0**, owned on loan **100**. Hall B owns **0** chairs throughout.
3. Hall B Manager independently chooses original loan > **Confirm receiving-hall physical custody** > **Confirm physically received stock** with a separate signed receiving file. Hall B borrowed usable **100**, no owned increase.
4. Hall B Manager opens **Send a partial borrowed-stock return**, sends **85** with original loan file. Hall B borrowed usable **15**, borrowed transit **85**. Hall A Owner B uses **Returned stock awaiting source-hall confirmation** > **Independent lender return condition count** > **Accept observed good/damaged return quantities**: **80 good / 5 damaged**. Hall A becomes owned usable **80**, owned on loan **15**, owned damaged **5**; Hall B transit returns to **0**, borrowed usable remains **15**.
5. Hall B manager presses **File signed borrowing-hall missing/dispute statement** for original remaining **15**, attaches source proof. Different Hall A owner chooses **Review original borrower signed discrepancy evidence** and independently decides. Complete separately signed original physical findings and independent original-loan dispute review; **do not** treat testimony as returned stock.
6. Owner A/B/C use **Propose internal abnormal loss obligation**, **Independently review internal loss obligation**, **Record private internal settlement evidence**, **Independently verify internal settlement**, then **Authorize internal write-off** in **Original loan physical discrepancy findings**. ADR-0009 Option A does **not** create company cash/bank journals. Release only independently confirmed 15 missing units.
7. Click readiness, then **Issue internal abnormal final certificate** once, download IAC-* PDF. Expected after independently authorized write-off: Hall A owned usable 80, owned on loan 0, owned damaged 5; **15** retired, never silently moved to usable, and Hall B original borrowed missing custody is reconciled. One immutable IAC serial, one write-off movement; a retry cannot change it. An ordinary CLEAN receipt must not issue.
8. Also test a separate original loan with one unresolved unit later actually found using **Later independently witnessed physical arrival** > **Post separately witnessed physical return** BEFORE abnormal closure. Verify no double acceptance, no retirement of found stock and correct third-actor approval.

### C. EXTERNAL_IN from outside partner

1. Create a fresh external-in loan for synthetic Hall B, e.g. 20 chairs. Use **Witnessed external borrowing, signed partner handover** > **Submit witnessed outside delivery for independent review**. A separate owner independently accepts. Expect B **borrowed** usable 20, B **owned** unchanged.
2. In **External borrowed physical returns** > **Physically send partial borrowed return**, send 20. Partner signs evidence of receiving 18 good, 2 missing. Use **Record partner short or damaged return count** > **Record signed partner discrepancy**, then **Independently review partner short/damaged evidence**. Clear only confirmed 18 received units; 2 stay in borrowed transit.
3. Through **Propose provisional partner loss obligation**, **Independently review partner loss obligation**, **Record partner payment evidence** and **Independently verify partner payment evidence**, have separate original-bank-document verifier review actual document. In staging these are *synthetic financial fixtures*, not proof a real bank moved money.
4. Choose original loan > readiness > **Issue non-clean final closure** only if all approval and exact ledger/stock checks are green. ENCL-* PDF unique, 2 outstanding transit units properly retired, no owned stock changes, no new cash journal at certificate creation. Reversal before closure blocks; after closure must be denied. Verify original signed files and PDF SHA256 on read.

### D. EXTERNAL_OUT to outside partner

1. Hall A lends e.g. 10 Tables. **Borrow / lend multiple inventory items** > external-out direction > original loan. Independent witness uses **Outside lending physical handover — independent witness** > **Submit witnessed outside lending handover**, separate owner approves source stock dispatch. Hall A owned usable decreases 10, owned-on-loan increases 10.
2. Use **Outside partner returns of hall-owned stock** > **Witness actual partial outside partner return**, receive 6 good, 2 damaged and 2 missing across separately signed batches. Separate owner **Independently record partner-return condition**.
3. Under **Outside lending provisional loss claims** and **Independent incident evidence review**, propose and independently decide damaged/missing claims, counterparty correspondence, authorized obligation, evidenced recovery/waiver and **Independent abnormal physical write-off approval**. No self-approval or unverified financial mutation.
4. If all ten returned good, use **Finalize outside-owned clean physical loan** and verify single EOL-* PDF. Otherwise perform separately authorized **Independently finalize non-clean loan** only after reconciliation blockers clear and verify NCL-* PDF, physical ownership conservation, balanced recovery/waiver and durable audit.

### E. Security and duplicate/stale input

- Repeat every completed request with exact UUID/content: must return identical outcome, no duplicate movement, journal, audit seal, serial or PDF. Change UUID's original content: conflict.
- Assigned Hall B Manager tries Hall A Owner-only stock, financial or certificate action, or read of another company/hall: denied. A revoked manager who keeps browser session tries an edit: denied.
- Requester attempts self-approval of purchase, dispatch, discrepancy, obligation, waiver and physical retirement: denied. Repeat review with different actor after it is final: immutable conflict.
- Upload same-length tampered signed source file; ENCL/IAC/NCL must fail closed with original SHA mismatch, no stock/journal/certificate commit.
- After final IAC/ENCL/NCL/EOL, try late return, dispute mutation, settlement reversal or duplicate closure: no new physical/financial effects.
- Repeat mobile steps at narrow width (390px) plus desktop (1366px), capture actual accessible buttons/errors and role-specific navigation. A green general Playwright suite alone does NOT establish these human checks.

### Expected versus actual examples (actual is not fabricated)

| Scenario | Expected before -> after | Actual result | Real evidence |
| --- | --- | --- | --- |
| Synthetic catalog seed | No initial stock -> 8 hall-local catalog items, 0 positions, 0 journals | **NOT RUN on staging** | No staging server |
| Opening 100 chairs | A owned usable 0 -> 100 | **NOT RUN** | Owner + independent counter needed |
| Internal 100 dispatched | A usable 100 -> 0; A on-loan 0 -> 100; B borrowed usable 0 -> 100 after receipt | **NOT RUN** | Signed transfer + screenshot |
| 80 good / 5 damaged / 15 missing | A usable 0 -> 80; A damaged 0 -> 5; A on-loan 100 -> 15 before write-off | **NOT RUN** | Independent condition acceptance |
| After authorized IAC | A on-loan 15 -> 0; IAC one numbered PDF, no company cash journal | **NOT RUN** | Three reviewers + audit + PDF |
| ENCL / NCL / EOL | One original-loan numbered PDF, no duplicate stock or receipts | **NOT RUN** | Partner and owner signatures |
| Interrupted private evidence upload and backup restore | No partial closure; original/restored DB and private PDF SHA256 equal | **BLOCKED EXTERNAL** | Authorized staging fault/restore logs |

## Backup/restore and staging security acceptance

1. Infrastructure Owner authorizes a real isolated fault window and captures immutable pre-test PostgreSQL and private-document volume snapshot IDs.
2. Simulate connection loss in the middle of a private upload, unwritable mounted storage, truncated file, a database failure after private PDF write, and network outage while two original loans are active. Preserve actual request/SQL logs and monitor events.
3. Verify rollback excludes orphan PDF, false inventory movements, fake journals, duplicate serials and incorrectly accepted original proofs. Recover storage and retry the **same** idempotency key.
4. Restore **both** PostgreSQL and matching private document snapshot to a separate isolated host. Verify all scoped position rows/revisions, journals and lines, audit events, loan snapshots, unique IAC/NCL/ENCL/EOL serials and stored PDF SHA256/length. Measure RPO, RTO and record operator, UTC, restore commands and independent security sign-off.
5. Confirm TLS, MFA, no public signed-file URLs, secret management, least privilege, monitoring and tested rollback. Only actual external approvers may sign acceptance.

**Terminal rule:** Phase 12 remains OPEN and all production inventory/finance flags remain OFF. No Phase 13 promotion. A staged test URL is only reported after verified deployment and credentials provisioned outside GitHub.
