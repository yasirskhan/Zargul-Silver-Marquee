# Zargul Silver Marquee — Phase 12 live staging/technical handoff (2026-10-23 UTC)

**Repository** yasirskhan/Zargul-Silver-Marquee; ONLY chatgpt/phase0-foundation. One writer in SAME normal Chat. Never Work, new branch, main/property-platform, force push or production activation.

## Cold start and source of truth
- Recheck LIVE branch SHA, branch-specific exact GitHub Actions workflow and SIX jobs (prototype-checks, central-postgres, licensing-postgres, laravel-postgres, editor-browser-e2e, authenticated-panel-e2e). Pending/failed != certified; investigate actual failing job logs first.
- Read AGENTS.md, this ENTIRE concise handoff, docs/SESSION_CONTROL.md, docs/PHASE12_FINAL_ACCEPTANCE_REPORT.md, docs/PHASE12_ACCEPTANCE_STATUS.md, docs/PHASE12_STAGING_EXECUTION.md, docs/PHASE12_59_REQUIREMENT_EVIDENCE_MATRIX.csv, original 59 Phase 12 FEATURE_REGISTER.csv rows, INVENTORY_IMPLEMENTATION_PLAN, roadmap, and relevant actual source/UI/tests. Never repeat already verified code.
- Before GitHub writes inspect current HEAD and file blobs; only expected_sha and force=false on authorized branch. Reconcile any concurrent writer change. No pretend access to hosting or bank evidence.

## Latest code and CI
- Fully green baseline SHA 0a08a8ebf0642adc3a71e9ecbffc6c4454fa276e, CI 39895044762 ALL SIX SUCCESS. Includes ENCL/reversal real forked PG both winning orders, same-length original proof corruption/missing-fail-closed, exact recovery, revoked owner while blocked, NCL/IAC/ENCL serial uniqueness and storage/DB rollback synthetic tests.
- New actual multi-original INTERNAL partial return acceptance race: SHA e6965049a831fa458ca79eda9391f107f0d6d8da, CI 39896741281 ALL SIX SUCCESS. Two loans share source and dest catalog/physical positions, concurrent separate PostgreSQL processes; stale acceptance denied, retry with current revision succeeds, stock total conserved, two independent append-only acceptance audits, no fake cash.
- Staging-only implementation source e2e71969bf0f78765946000801cb6bb1ca82ad72, CI 39897523649 was IN PROGRESS at this handoff snapshot; recheck results. Adds backend/app/Console/Commands/SeedPhase12Staging.php (explicit safe opt-in isolated testing/staging, six separately credentialed accounts, two halls/eight zero-stock items), PHPUnit gate/seed tests, scripts/phase12-staging-browser-smoke.mjs and docs/PHASE12_STAGING_EXECUTION.md. Browser smoke needs real MFA-authenticated storageState files OUTSIDE repo and verified staging HTTPS URL.
- New requirement CSV docs/PHASE12_59_REQUIREMENT_EVIDENCE_MATRIX.csv has all 59 exact IDs and source tier: 40 labeled hosted-code-tested, 17 code-present/legacy label, 2 source-present unaccepted foundations. Labels are not 59 individually browser-verified operator passes. Aggregate CI != external signoff. See final report.
- Current handoff is a factual snapshot; documentation-inclusive last SHA requires its OWN six-job CI before fully VERIFIED. Prior root handoff is archived unchanged at docs/AI_HANDOFF_ARCHIVE_2026-10-23_e2e71969.md.

## Hard deployment reality and responsibilities
- No verified working staging URL, deployment workflow or user test accounts observable. GitHub deployments/environments endpoint access denied; README/homepage cannot prove third-party deployment does not exist. Any existing staging owner must supply exact HTTPS URL, deployed SHA, authorized access and private credentials via secret manager.
- Staging setup needs authorized infra operator, PHP8.3+/Laravel13/Filament5 deployment, stage DNS/TLS, isolated PostgreSQL16, persistent private DOCUMENT volume (disk local), DB/cache/session workers, secure APP_KEY, valid MFA accounts, staging-only flags explicitly supervised; NO production gate change.
- Operator runs php artisan venuedesk:seed-phase12-staging with explicit non-production guard and six unique passwords from secret vault, then physically counted opening/purchases/loans through real Owner and Manager UI; command intentionally posts ZERO stock or cash and is not a substitute for signed physical events.
- 100 Chairs Hall A -> B, return 80 good/5 damaged/15 missing, distinct signed findings, ADR-0009 Option A (NO company-level internal journal) independent obligation/settlement/write-off and IAC serial; EXTERNAL_IN ENCL, EXTERNAL_OUT NCL/EOL, security, and screenshots each have step-by-step expected/actual=NOT RUN in docs/PHASE12_STAGING_EXECUTION.md.
- Restore must coordinate actual PostgreSQL and private PDF store snapshots, inject real staging upload/storage/outage faults, record checksums, RPO/RTO, logs, independent security signoff. All these plus partner signatures, bank verification, human staging UAT and release approval are BLOCKED EXTERNAL.
- Continue remaining meaningful technical tests only where unverified; don't claim all cross-flow interleavings. Phase 12 OPEN / production inventory+finance OFF / Phase 13 not promoted.

## Immediate follow-up
1. Verify CI exact latest SHA; if red fix root cause and repeat full six-job hosted workflow without disabling tests, permissions or stock/finance guards.
2. Reconcile 59 source/test tiers against new staging UAT evidence only after actual staged testing, and close remaining code/technical matrix gaps if executable.
3. Ask Owner to identify authorized hosting/deployment/infrastructure operator and supply *existing* staging URL + exact deployed SHA or provision the blockers. Never invent URL, accounts, signed physical evidence or bank payments.
4. Publish updated report and last tested SHA/CI. Keep one writer and no premature phase acceptance.
