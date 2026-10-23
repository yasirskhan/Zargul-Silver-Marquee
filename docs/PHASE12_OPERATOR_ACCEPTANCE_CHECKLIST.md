# Phase 12 Operational Acceptance and Evidence Checklist

**Gate: OPEN / BLOCKED EXTERNAL.** Owner acceptance and production release are NOT granted. All inventory/finance flags remain OFF.
**Scope:** Zargul Silver Marquee, authorized branch chatgpt/phase0-foundation. See [59-ID feature register](FEATURE_REGISTER.csv) and [technical acceptance report](PHASE12_FINAL_ACCEPTANCE_REPORT.md).

This checklist is a plan, not evidence that any staging, bank, partner or human test has been performed. Do not replace real source documents with CI fake uploads, change release gates or erase failed results.

## Owner/Admin and two independent hall managers

- [ ] Record exact staging build SHA, all six named CI jobs, roles, company/hall setup, UTC case IDs, expected positions and before/after audit counts.
- [ ] Two independently approved original INTERNAL loans share an item; competing dispatch of last unit, duplicate requests, revoked manager and other-company access do not double-consume stock.
- [ ] Signed borrower receipt, partially good/damaged physical return, source acceptance, independently attested missing/disputed finding and later return are entered with independent witnesses.
- [ ] Third authorized owner decides physical write-off and settlement under ADR-0009 Option A (no internal company cash journal); exact IAC PDF serial, SHA256, storage length, custody and audit chain are verified.
- [ ] Fully accepted clean loan generates exactly one clean numbered PDF. Missing/disputed/late-return and reverse-order actions are denied after final closure.
- [ ] Include monthly/quarterly stocktake, source opening/purchase receipt, minimum level and post-event variance workflow with visible accessible Owner/Manager UI, role-bound downloads, actual mobile and desktop screenshots.

## External-in partner + financial reviewer

- [ ] Two original EXTERNAL_IN loans share a catalog item; partner signs original intake, short/damaged partial return and later verified count.
- [ ] Independent reviewer validates discrepancy/clearance, approved obligation and original bank/partner payment. Record bank reference from original document and verify recorder is not independent reviewer.
- [ ] Reversal BEFORE closure invalidates settlement/readiness; new eligible evidence is required. Reversal racing final ENCL closure may not corrupt journal, mutate owned stock, issue two certificates or allow post-final reversal.
- [ ] Validate ENCL-1/ENCL-2 sequence, unique exact original-loan PDFs, SHA256/length, transit movement, immutable audit and absent owner-cash mutation. Partner signs the actual witnessed evidence.

## External-out partner + owner

- [ ] Actual witnessed lending dispatch, multiple separately signed partial returns, independent condition review, damaged/missing disputed findings, claim and approved contractual obligation.
- [ ] Original bank recovery or independent signed waiver and separately authorized irreversible stock write-off; exact clean EOL or abnormal NCL PDF with unique numbering, immutable bytes and provenance.
- [ ] Duplicate request, forged source/partner proof, wrong hall/company, revoked role and post-final refund/write-off/return attempts must be rejected, with no extra stock, cash or audit posts.

## Real staging failure and restore drill (infrastructure operator + independent reviewer)

- [ ] On authorized disposable staging, break a document upload midstream; simulate storage unavailable, truncated/wrong/late PDF and database failure after document write. Retain timestamps, failure injections, error logs, SHA256 before/after and request IDs.
- [ ] Verify transaction rollback of positions, audit, journals and serial allocation, removal of orphan generated PDF, occupied immutable key denial and exact request-ID retry once restored.
- [ ] Create coordinated PostgreSQL and document-store backups, with backup IDs, snapshot UTC, recovery-point objective. Restore **both** into an isolated recovery environment with independent approver.
- [ ] Compare original/restored counts, position revisions, source and final certificate bytes/hashes, serials, journals and audit chain; measure actual RPO and RTO. Attach logs, commands, timestamps, approvals and rollback report.
- [ ] Without real staging access, backup credentials, network fault authorization or separately approved recovery window, mark these tests BLOCKED EXTERNAL, not passed.

## Security and operations independent acceptance

- [ ] Security reviewer signs TLS, MFA, least-privilege database role, scoped download URLs, forged requests, role revocation during transaction, cross-company/hall isolation, immutable audit and document checksum results.
- [ ] Owner and managers independently execute the full protected browser UI on desktop/mobile. Validate usability, original signed evidence integrity, authenticated downloads and negative role-revocation cases.
- [ ] Infrastructure reviewer supplies dashboard screenshots, retained alerts, backups, alert recipient/on-call owner, key custody and tested incident recovery runbook.
- [ ] Every failed case has a reproducible issue, repair commit and exact six-job CI success before acceptance.

## Final owner gate

- [ ] Review the 59-ID matrix against real code, UI, access, tests and accepted actual operator evidence; distinguish code-passed from operator-accepted.
- [ ] Obtain original signed physical/partner documents and independently verified bank receipt evidence; require separate security and restoration sign-offs.
- [ ] Make a distinct written go/no-go decision naming exact commit SHA, responsible approvers, authorized release environment/flags, monitoring and rollback. **This document alone never activates production.**
- [ ] Until all approval gates genuinely pass: Phase 12 OPEN, Phase 13 not promoted, inventory and finance release OFF.

## Evidence record (copy for every executed case)

| Requirement IDs / case ID | Environment / SHA / CI | Actors, hall, UTC time | Original proof and checksum | Before/after position, journal, audit | Observed outcome and links | Independent signature |
| --- | --- | --- | --- | --- | --- | --- |
| PENDING | PENDING | PENDING | PENDING | PENDING | NOT EXECUTED | PENDING |

Never manufacture any human signature, original payment evidence, staging backup/restore, successful test or production authorization.
