import {test,expect} from '@playwright/test';
import {createHash} from 'node:crypto';
import {readFile} from 'node:fs/promises';
import {inventoryLogin,inventoryFocus,inventoryUploadEvidence,syntheticEvidence} from './inventory-auth.mjs';

test('MFA Owner and separate Managers perform actual 100-chair stock, loan and partial return on desktop/mobile',async({browser})=>{
 test.setTimeout(1200000);
 const owner=await inventoryLogin(browser,'browser-e2e-inventory-owner@example.test',
  'synthetic-inventory-owner-only','IFBEGRCFIZDUQSKK',{width:1366,height:768});
 const source=await inventoryLogin(browser,'browser-e2e-inventory-manager-a@example.test',
  'synthetic-inventory-manager-a-only','JBSWY3DPEHPK3PXP',{width:390,height:844});
 const dest=await inventoryLogin(browser,'browser-e2e-inventory-manager-b@example.test',
  'synthetic-inventory-manager-b-only','GEZDGNBVGY3TQOJQ',{width:390,height:844});
 const reviewer=await inventoryLogin(browser,'browser-e2e-inventory-owner-b@example.test',
  'synthetic-inventory-owner-b-only','MFRGGZDFMZTWQ2LK',{width:1280,height:800});
 const independent=await inventoryLogin(browser,'browser-e2e-inventory-owner-c@example.test',
  'synthetic-inventory-owner-c-only','KRSXG5AUMVZWK4TT',{width:1280,height:800});
 const authorizer=await inventoryLogin(browser,'browser-e2e-inventory-owner-d@example.test',
  'synthetic-inventory-owner-d-only','ORUGS4ZANFZSAYJA',{width:1280,height:800});
 try {
  const a=source.page,b=dest.page,o=owner.page,r=reviewer.page,c=independent.page,d=authorizer.page;
  // A broken UI locator must fail promptly rather than exhaust the entire
  // 10-minute genuine MFA/role-change browser journey.
  for(const page of [a,b,o,r,c,d])page.setDefaultTimeout(30000);
  await expect(a.getByRole('option',{name:'Synthetic Test Hall B'})).toHaveCount(0);
  await expect(b.getByRole('option',{name:'Synthetic Test Hall',exact:true})).toHaveCount(0);
  for(const page of [a,b]){
   await inventoryFocus(page,'Stock & counts');
   const cat=page.locator('section[aria-label="Create hall inventory catalog item"]');
   await expect(cat).toBeVisible();
   await cat.getByRole('textbox',{name:'Item name'}).fill('Ceremony Chairs');
   await cat.getByRole('combobox',{name:'Category'}).selectOption('Furniture');
   await cat.getByRole('spinbutton',{name:'Minimum quantity target (not current stock)'}).fill('20');
   await cat.getByRole('textbox',{name:'Reason for creating item'}).fill('Synthetic per-hall browser inventory acceptance');
   await cat.getByRole('button',{name:'Add hall item'}).click();
   // The stock workspace confirms the real saved catalog item; use the real
   // guided shortcut to reveal its row in Overview (rather than hidden DOM).
   await expect(cat.getByRole('status')).toContainText('Catalog item saved: Ceremony Chairs');
   await expect(cat.getByRole('status')).toContainText('No stock or purchase was posted.');
   await cat.getByRole('button',{name:'View in Overview'}).click();
   await expect(page.locator('section[aria-label="Hall inventory item catalog"]')
    .getByText('Ceremony Chairs')).toBeVisible({timeout:30000});
   await inventoryFocus(page,'Stock & counts');
  }

  const stock=a.locator('section[aria-label="Physical inventory stocktake"]');
  await stock.getByRole('combobox',{name:'Audit type'}).selectOption('opening');
  await stock.getByRole('button',{name:'+ Add item row'}).click();
  await stock.locator('select[wire\\:model="countRows.0.itemId"]').selectOption({label:'Ceremony Chairs'});
  await stock.locator('select[wire\\:model="countRows.0.ownership"]').selectOption('owned');
  await stock.locator('input[wire\\:model="countRows.0.usableCount"]').fill('100');
  await stock.locator('input[wire\\:model="countRows.0.damagedCount"]').fill('0');
  await stock.getByRole('textbox',{name:'Count reason / observations'})
   .fill('Witnessed physical count of 100 owned ceremony chairs');
  await stock.getByRole('button',{name:'Save physical count evidence'}).click();
  const posted=stock.getByRole('status').first();
  await expect(posted).toContainText('Count submitted for review',{timeout:30000});
  const countId=(await posted.textContent()).match(/[0-9a-f]{8}-[0-9a-f-]{27,}/i)?.[0];
  expect(countId).toMatch(/^[0-9a-f-]{36}$/i);
  const opening=a.locator('section[aria-label="Opening stock independent review"]');
  await opening.locator('select[wire\\:model="openingItemId"]').selectOption({label:'Ceremony Chairs'});
  await opening.locator('input[wire\\:model="openingStocktakeId"]').fill(countId);
  await opening.locator('input[wire\\:model="openingQuantity"]').fill('100');
  await opening.locator('input[wire\\:model="openingReason"]')
   .fill('A distinct Owner will review counted opening chairs');
  await opening.getByRole('button',{name:'Request separate approval (no stock posted)'}).click();
  await expect(opening.getByText('Opening evidence request saved:',{exact:false})).toBeVisible({timeout:30000});
  await inventoryFocus(o,'Stock & counts');
  const ownerOpening=o.locator('section[aria-label="Opening stock independent review"]');
  await ownerOpening.getByRole('button',{name:'Refresh opening review'}).click();
  await ownerOpening.getByRole('button',{name:'Accept verified opening stock'}).click();
  await expect(ownerOpening.getByText('ACCEPTED / BOOKED')).toBeVisible({timeout:30000});

  await inventoryFocus(a,'Borrow / lend');
  const loan=a.locator('[aria-label="Multi-item internal or external borrowing request"]');
  await loan.getByRole('button',{name:'+ Add Item Row'}).click();
  await loan.locator('select[wire\\:model="loanRows.0.itemId"]').selectOption({index:1});
  await loan.locator('input[wire\\:model="loanRows.0.quantity"]').fill('100');
  await loan.locator('select[wire\\:model="loanCounterpartHallId"]')
   .selectOption({label:'Synthetic Test Hall B'});
  await loan.locator('input[wire\\:model="loanReturnDate"]').fill('2027-12-30');
  await loan.getByRole('textbox',{name:'Purpose / linked event details'})
   .fill('Synthetic Hall A to Hall B independent 100-chair loan');
  await loan.getByRole('button',{name:'Save borrowing request (no stock moved)'}).click();
  await expect(loan.getByText('Multi-item loan request saved:',{exact:false})).toBeVisible({timeout:30000});
  // Keep the original database-issued loan identity through every physical step.
  const savedLoanText=await loan.getByText('Multi-item loan request saved:',{exact:false}).textContent();
  const originalLoanId=savedLoanText?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
  expect(originalLoanId).toMatch(/^[0-9a-f-]{36}$/i);

  await inventoryFocus(o,'Borrow / lend');
  const ownLoans=o.locator('[aria-label="Saved borrowing requests awaiting handover"]');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  await ownLoans.getByRole('button',{name:'Prepare witnessed source dispatch'}).click();
  const dispatch=o.locator('section[aria-label="Independent internal source dispatch"]');
  await expect(dispatch).toBeVisible({timeout:30000});
  await inventoryUploadEvidence(o,dispatch.locator('input[type="file"]'),syntheticEvidence);
  await dispatch.getByRole('button',{name:'Confirm actual source physical dispatch'}).click();
  await expect(o.getByText('Independently witnessed physical dispatch recorded:',{exact:false}))
   .toBeVisible({timeout:30000});

  await inventoryFocus(b,'Borrow / lend');
  const incoming=b.locator('[aria-label="Saved borrowing requests awaiting handover"]');
  await incoming.getByRole('button',{name:'Refresh requests'}).click();
  await incoming.getByRole('button',{name:'Record independent receiving count'}).click();
  const receipt=b.locator('section[aria-label="Confirm independent internal loan arrival"]');
  await expect(receipt).toBeVisible({timeout:30000});
  await receipt.locator('select[wire\\:model^="loanReceiptMapping."]')
   .selectOption({index:1});
  await inventoryUploadEvidence(b,receipt.locator('input[type="file"]'),syntheticEvidence);
  await receipt.getByRole('button',{name:'Confirm physically received stock'}).click();
  await expect(b.getByText('Receiving custody recorded:',{exact:false}))
   .toBeVisible({timeout:30000});

  await inventoryFocus(b,'Borrow / lend');
  await incoming.getByRole('button',{name:'Prepare partial return shipment'}).click();
  const returnForm=b.locator('section[aria-label="Send partial physical borrowed return"]');
  await expect(returnForm).toBeVisible({timeout:30000});
  await returnForm.locator('input[type="number"]').first().fill('85');
  await inventoryUploadEvidence(b,returnForm.locator('input[type="file"]'),syntheticEvidence);
  await returnForm.getByRole('button',{name:'Record witnessed return shipment'}).click();
  // A saved, numbered-in-this-loan return batch must be visible as the real
  // in-transit status; generic 'Return shipment' matches unrelated buttons.
  const shipment=b.locator('p[role="status"]').filter({hasText:/Return shipment [0-9a-f-]{36} is in transit/});
  await expect(shipment).toBeVisible({timeout:30000});
  const returnBatchId=(await shipment.textContent())?.match(/Return shipment ([0-9a-f-]{36}) is in transit/i)?.[1];
  expect(returnBatchId).toMatch(/^[0-9a-f-]{36}$/i);

  // Refresh source records. A queue containing multiple originals must not
  // use the first next-step shortcut for an unrelated loan.
  await inventoryFocus(o,'Borrow / lend');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  await inventoryFocus(o,'Overview');
  const overview=o.locator('section[aria-label="Inventory at a glance"]');
  const nextLoanStatus=overview.locator('p').filter({hasText:`Original loan: ${originalLoanId}`});
  await expect(nextLoanStatus).toHaveCount(1);
  await expect(nextLoanStatus).toContainText('Source Owner/Admin: review partial return by condition');
  const originalLoanRow=nextLoanStatus.locator('xpath=../..');
  await originalLoanRow.getByRole('button',{name:'Open next loan step'}).click();
  const batches=o.locator('section[aria-label="Incoming physical return batches"]');
  await expect(batches).toBeVisible({timeout:30000});
  const persistedBatch=batches.locator('article').filter({
    hasText:`Loan ${originalLoanId} / return ${returnBatchId}`
  });
  await expect(persistedBatch).toHaveCount(1);
  await expect(persistedBatch).toBeVisible();
  await expect(persistedBatch).toContainText('IN TRANSIT / SOURCE ACKNOWLEDGMENT REQUIRED');
  await persistedBatch.getByRole('button',{name:'Review physically returned items'}).click();
  const acceptance=o.locator('section[aria-label="Accept returned physical stock by condition"]');
  await expect(acceptance).toBeVisible({timeout:30000});
  await acceptance.getByRole('spinbutton',{name:'Physically good quantity'}).fill('80');
  await acceptance.getByRole('spinbutton',{name:'Physically damaged quantity'}).fill('5');
  await inventoryUploadEvidence(o,acceptance.locator('input[type="file"]'),syntheticEvidence);
  await acceptance.getByRole('button',{name:'Accept observed good/damaged return quantities'}).click();
  await inventoryFocus(o,'Overview');
  await o.getByRole('button',{name:'Refresh book balances'}).click();
  const balance=o.locator('section[aria-label="Posted inventory book balances"]');
  const row=balance.locator('tbody tr').filter({hasText:'Ceremony Chairs'});
  await expect(row).toBeVisible({timeout:30000});
  await expect(row).toContainText(/80\s*15\s*5/);
  await expect(o.locator('section[aria-label="Inventory at a glance"]'))
   .toContainText('Owned damaged');

  // Borrower testimony is scoped to this exact original loan. It must not
  // pretend the remaining 15 chairs were physically returned or paid for.
  await inventoryFocus(b,'Borrow / lend');
  await incoming.getByRole('button',{name:'Refresh requests'}).click();
  const borrowingLoan=incoming.locator('article')
    .filter({hasText:`Reference: ${originalLoanId}.`});
  await expect(borrowingLoan).toHaveCount(1);
  await borrowingLoan.getByRole('button',{
    name:'File signed borrowing-hall missing/dispute statement'
  }).click();
  const borrowerStatement=b.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await expect(borrowerStatement).toBeVisible({timeout:30000});
  await expect(borrowerStatement).toContainText(`Loan ${originalLoanId}`);
  await borrowerStatement.getByRole('combobox',{name:'Original discrepancy item'})
    .selectOption({index:1});
  await borrowerStatement.getByRole('combobox',{name:'Discrepancy kind'}).selectOption('missing');
  await borrowerStatement.getByRole('spinbutton',{name:'Claimed discrepancy quantity'}).fill('15');
  await borrowerStatement.getByRole('textbox',{name:'Signed statement reason'})
    .fill('15 chairs remain physically missing after witnessed 85-chair return shipment');
  await inventoryUploadEvidence(b,borrowerStatement.locator('input[type="file"]'),syntheticEvidence);
  await borrowerStatement.getByRole('button',{name:'Submit signed borrowing-hall statement'}).click();
  const pendingStatement=borrowerStatement.locator('p')
    .filter({hasText:/Statement [0-9a-f-]{36} item .*15 missing.*SOURCE REVIEW PENDING/});
  await expect(pendingStatement).toHaveCount(1);
  await expect(pendingStatement).toBeVisible({timeout:30000});
  const statementId=(await pendingStatement.textContent())
    ?.match(/Statement ([0-9a-f-]{36}) item/i)?.[1];
  expect(statementId).toMatch(/^[0-9a-f-]{36}$/i);

  // A different authenticated source Owner reviews only the original signed
  // statement. Corroboration is evidence, not a receipt, write-off or finance.
  await inventoryFocus(o,'Borrow / lend');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  const lendingLoan=ownLoans.locator('article')
    .filter({hasText:`Reference: ${originalLoanId}.`});
  await expect(lendingLoan).toHaveCount(1);
  await lendingLoan.getByRole('button',{
    name:'Review original borrower signed discrepancy evidence'
  }).click();
  const ownerStatement=o.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await expect(ownerStatement).toBeVisible({timeout:30000});
  await expect(ownerStatement).toContainText(`Loan ${originalLoanId}`);
  await ownerStatement.getByRole('combobox',{name:'Independent signed statement review'})
    .selectOption(statementId);
  await ownerStatement.getByRole('combobox',{name:'Independent discrepancy decision'})
    .selectOption('corroborated');
  await ownerStatement.getByRole('textbox',{name:'Independent review reason'})
    .fill('Independent source review corroborated missing 15 chairs; no custody or payment posted');
  await ownerStatement.getByRole('button',{name:'Record independent source review'}).click();
  const reviewedStatement=ownerStatement.locator('p')
    .filter({hasText:`Statement ${statementId} item`});
  await expect(reviewedStatement).toContainText('15 missing — corroborated',{timeout:30000});

  // Original-source incident is independent evidence, not an automatic charge.
  await inventoryFocus(o,'Borrow / lend');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  const originLoan=ownLoans.locator('article').filter({hasText:`Reference: ${originalLoanId}.`});
  await expect(originLoan).toHaveCount(1);
  await originLoan.getByRole('button',{name:'Review damage, missing or disputed items'}).click();
  const incident=o.locator('section[aria-label="Record inventory loan incident"]');
  await expect(incident).toBeVisible({timeout:30000});
  await expect(incident).toContainText(`Loan: ${originalLoanId}`);
  await incident.getByRole('combobox',{name:'Source item'}).selectOption({index:1});
  await incident.getByRole('combobox',{name:'Incident type'}).selectOption('missing');
  await incident.getByRole('spinbutton',{name:'Affected quantity'}).fill('15');
  await incident.getByRole('textbox',{name:/Incident explanation/})
    .fill('Original lender observed 15 unreturned chairs after independently accepted partial return');
  await incident.getByRole('button',{name:'Record immutable incident'}).click();
  const savedIncident=incident.locator('p').filter({hasText:/Recorded incident: [0-9a-f-]{36}/});
  await expect(savedIncident).toBeVisible({timeout:30000});
  const incidentId=(await savedIncident.textContent())?.match(/Recorded incident: ([0-9a-f-]{36})/i)?.[1];
  expect(incidentId).toMatch(/^[0-9a-f-]{36}$/i);

  // Owner B did not write the lender report. The original 15 chairs remain
  // physically outstanding, and a separate reviewer must substantiate it.
  await inventoryFocus(r,'Borrow / lend');
  const reviewerLoans=r.locator('[aria-label="Saved borrowing requests awaiting handover"]');
  await reviewerLoans.getByRole('button',{name:'Refresh requests'}).click();
  const reviewerLoan=reviewerLoans.locator('article').filter({hasText:`Reference: ${originalLoanId}.`});
  await expect(reviewerLoan).toHaveCount(1);
  await reviewerLoan.getByRole('button',{name:'Review damage, missing or disputed items'}).click();
  const reviewIncident=r.locator('section[aria-label="Record inventory loan incident"]');
  await expect(reviewIncident).toBeVisible({timeout:30000});
  await reviewIncident.getByRole('combobox',{name:'Unreviewed incident'}).selectOption(incidentId);
  await reviewIncident.getByRole('combobox',{name:'Evidence assessment'}).selectOption('substantiated');
  await reviewIncident.getByRole('textbox',{name:'Review explanation'})
    .fill('Independent Owner B substantiates only the original fifteen missing chairs');
  await reviewIncident.getByRole('button',{name:'Record independent decision'}).click();
  await expect(reviewIncident.locator('p').filter({hasText:`Report ${incidentId}: substantiated`}))
    .toBeVisible({timeout:30000});
  await expect(reviewIncident.getByRole('combobox',{name:'Unreviewed incident'})
    .locator(`option[value="${incidentId}"]`)).toHaveCount(0);

  // Owner B may investigate independently because Owner A filed the incident.
  // The signed borrower statement and separately reviewed incident must match
  // original loan, original source item, kind and exact missing quantity.
  await inventoryFocus(r,'Borrow / lend');
  await reviewerLoan.getByRole('button',{name:'Review original borrower signed discrepancy evidence'}).click();
  const sourceEvidence=r.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await expect(sourceEvidence).toBeVisible({timeout:30000});
  await expect(sourceEvidence).toContainText(`Loan ${originalLoanId}`);
  const physical=sourceEvidence.locator('[aria-label="Independent original-loan physical findings"]');
  await physical.getByRole('combobox',{name:'Corroborated borrower signed original-loan statement'})
    .selectOption(statementId);
  await physical.getByRole('combobox',{name:'Independently substantiated original source incident'})
    .selectOption(incidentId);
  await physical.getByRole('textbox',{name:'Source physical investigation reason'})
    .fill('Independent original source reconciliation matches signed borrower and lender reports for fifteen chairs');
  await inventoryUploadEvidence(r,physical.getByLabel('Signed source PDF/photo'),syntheticEvidence);
  await physical.getByRole('button',{
    name:'File original-loan source physical proof for independent review'
  }).click();
  const pendingFinding=physical.locator('article')
    .filter({hasText:/Finding [0-9a-f-]{36}: missing 15 original item/});
  await expect(pendingFinding).toHaveCount(1);
  await expect(pendingFinding).toContainText('INDEPENDENT REVIEW PENDING',{timeout:30000});
  const findingId=(await pendingFinding.textContent())?.match(/Finding ([0-9a-f-]{36}):/i)?.[1];
  expect(findingId).toMatch(/^[0-9a-f-]{36}$/i);

  // Owner C is distinct from source reporter A, investigator B and borrower.
  await inventoryFocus(c,'Borrow / lend');
  const independentLoans=c.locator('[aria-label="Saved borrowing requests awaiting handover"]');
  await independentLoans.getByRole('button',{name:'Refresh requests'}).click();
  const independentLoan=independentLoans.locator('article')
    .filter({hasText:`Reference: ${originalLoanId}.`});
  await expect(independentLoan).toHaveCount(1);
  await independentLoan.getByRole('button',{name:'Review original borrower signed discrepancy evidence'}).click();
  const independentEvidence=c.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await expect(independentEvidence).toBeVisible({timeout:30000});
  const independentPhysical=independentEvidence.locator(
    '[aria-label="Independent original-loan physical findings"]');
  await independentPhysical.getByRole('combobox',{name:'Independent decision on a pending physical finding'})
    .selectOption(findingId);
  await independentPhysical.getByRole('combobox',{name:'Decision',exact:true})
    .selectOption('confirmed_outstanding');
  await independentPhysical.getByRole('textbox',{name:'Independent physical reviewer reason'})
    .fill('Third owner confirms only the exact fifteen unreturned units; stock and finance remain open');
  await independentPhysical.getByRole('button',{name:'Record immutable source review'}).click();
  await expect(independentPhysical.locator('article')
    .filter({hasText:`Finding ${findingId}:`})).toContainText('confirmed_outstanding',{timeout:30000});

  // The independent physical finding does NOT itself move cash or retire stock.
  // Owner C proposes a provisional ADR-0009 inter-hall obligation, with no
  // company-level journal. Owner A independently decides that amount.
  const confirmedFinding=independentPhysical.locator('article')
    .filter({hasText:`Finding ${findingId}:`});
  await confirmedFinding.getByRole('button',{name:'Propose internal loss obligation'}).click();
  const obligationForm=c.locator('section[aria-label="Propose internal loss obligation"]');
  await expect(obligationForm).toBeVisible({timeout:30000});
  await obligationForm.getByRole('textbox',{name:'Currency (ISO 4217)'}).fill('PKR');
  await obligationForm.getByRole('spinbutton',{name:'Amount in minor units'}).fill('150000');
  await obligationForm.getByRole('textbox',{name:'Reason'})
    .fill('Provisional internal allocation for exactly fifteen independently confirmed missing chairs');
  await obligationForm.getByRole('button',{name:'Propose internal loss obligation'}).click();
  const obligationStatus=independentEvidence.locator('p[role="status"]')
    .filter({hasText:/Internal loss obligation [0-9a-f-]{36} recorded/});
  await expect(obligationStatus).toBeVisible({timeout:30000});
  const obligationId=(await obligationStatus.textContent())
    ?.match(/Internal loss obligation ([0-9a-f-]{36}) recorded/i)?.[1];
  expect(obligationId).toMatch(/^[0-9a-f-]{36}$/i);

  await inventoryFocus(o,'Borrow / lend');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  await originLoan.getByRole('button',{name:'Review original borrower signed discrepancy evidence'}).click();
  const obligationReviewSection=o.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await expect(obligationReviewSection).toBeVisible({timeout:30000});
  const obligationReviewFinding=obligationReviewSection.locator('article')
    .filter({hasText:`Finding ${findingId}:`});
  await obligationReviewFinding.getByRole('button',{
    name:'Independently review internal loss obligation'
  }).click();
  const decisionForm=o.locator('section[aria-label="Independently review internal loss obligation"]');
  await expect(decisionForm).toBeVisible({timeout:30000});
  await expect(decisionForm).toContainText(obligationId);
  await decisionForm.getByRole('combobox',{name:'Decision'}).selectOption('approved');
  await decisionForm.getByRole('textbox',{name:'Independent review reason'})
    .fill('Owner A independently approves provisional original-loan allocation without company cash posting');
  await decisionForm.getByRole('button',{name:'Record independent internal loss obligation decision'}).click();
  await expect(obligationReviewFinding).toContainText('APPROVED',{timeout:30000});

  // Owner B records separate signed settlement evidence; this is explicitly
  // not a real bank transfer or company journal in an isolated browser test.
  await inventoryFocus(r,'Borrow / lend');
  await reviewerLoans.getByRole('button',{name:'Refresh requests'}).click();
  await reviewerLoan.getByRole('button',{name:'Review original borrower signed discrepancy evidence'}).click();
  const settlementSection=r.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await settlementSection.locator('article').filter({hasText:`Finding ${findingId}:`})
    .getByRole('button',{name:'Record internal settlement evidence'}).click();
  const settlementForm=r.locator('section[aria-label="Record internal settlement evidence"]');
  await expect(settlementForm).toBeVisible({timeout:30000});
  await expect(settlementForm).toContainText(obligationId);
  await expect(settlementForm.getByRole('spinbutton',{name:'Amount in minor units'}))
    .toHaveValue('150000');
  await settlementForm.getByRole('textbox',{name:'Reference'}).fill('SYNTHETIC-ADR0009-LOAN-15');
  await inventoryUploadEvidence(r,settlementForm.getByLabel('Private proof (PDF/JPEG/PNG)'),syntheticEvidence);
  await settlementForm.getByRole('button',{name:'Record internal settlement evidence'}).click();
  const settlementStatus=settlementSection.locator('p[role="status"]')
    .filter({hasText:/Internal settlement reference [0-9a-f-]{36} recorded/});
  await expect(settlementStatus).toBeVisible({timeout:30000});
  const settlementId=(await settlementStatus.textContent())
    ?.match(/Internal settlement reference ([0-9a-f-]{36}) recorded/i)?.[1];
  expect(settlementId).toMatch(/^[0-9a-f-]{36}$/i);
  await expect(settlementStatus).toContainText('No journal, cash mutation');

  // Owner A is neither the settlement recorder (B) nor obligation proposer (C).
  await inventoryFocus(o,'Borrow / lend');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  await originLoan.getByRole('button',{name:'Review original borrower signed discrepancy evidence'}).click();
  const verificationSection=o.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await verificationSection.locator('article').filter({hasText:`Finding ${findingId}:`})
    .getByRole('button',{name:'Independently verify internal settlement'}).click();
  const verifyForm=o.locator('section[aria-label="Independently verify internal settlement"]');
  await expect(verifyForm).toBeVisible({timeout:30000});
  await expect(verifyForm).toContainText(settlementId);
  await verifyForm.getByRole('combobox',{name:'Decision'}).selectOption('verified');
  await verifyForm.getByRole('textbox',{name:'Independent review reason'})
    .fill('Independent source Owner confirms synthetic private proof matches approved inter-hall obligation');
  await verifyForm.getByRole('button',{name:'Record independent internal settlement review'}).click();
  await expect(verificationSection.locator('article')
    .filter({hasText:`Finding ${findingId}:`})).toContainText('VERIFIED',{timeout:30000});

  // Fourth Owner has not proposed findings/obligations or recorded/reviewed
  // settlement. Only that uninvolved actor may authorize irreversible writeoff.
  await inventoryFocus(d,'Borrow / lend');
  const authorizerLoans=d.locator('[aria-label="Saved borrowing requests awaiting handover"]');
  await authorizerLoans.getByRole('button',{name:'Refresh requests'}).click();
  const authorizerLoan=authorizerLoans.locator('article')
    .filter({hasText:`Reference: ${originalLoanId}.`});
  await expect(authorizerLoan).toHaveCount(1);
  await authorizerLoan.getByRole('button',{name:'Review original borrower signed discrepancy evidence'}).click();
  const closureSection=d.locator('section[aria-label="Signed internal discrepancy evidence"]');
  await expect(closureSection).toBeVisible({timeout:30000});
  await expect(closureSection).toContainText(`Loan ${originalLoanId}`);
  await closureSection.getByRole('button',{name:'Check internal abnormal closure readiness'}).click();
  // Before final write-off and IAC issuance, the read-only readiness gate
  // MUST remain BLOCKED even after settlement is independently verified.
  await expect(closureSection).toContainText('INTERNAL ABNORMAL CLOSURE READINESS: BLOCKED',{timeout:30000});
  await expect(closureSection).toContainText('approved obligations 1, verified settlements 1');
  await expect(closureSection).toContainText('No numbered internal abnormal final certificate.');
  await closureSection.getByRole('textbox',{name:'Independent write-off authorization reason'})
    .fill('Independent Owner D authorizes final write-off of only the fifteen verified outstanding chairs');
  await closureSection.getByRole('button',{name:'Authorize internal write-off'}).click();
  await expect(closureSection).toContainText('Internal write-off authorized for 15 owned-on-loan unit(s)',{timeout:30000});
  await closureSection.getByRole('button',{name:'Issue internal abnormal final certificate'}).click();
  await expect(closureSection).toContainText(/INTERNAL ABNORMAL FINAL CERTIFICATE IAC-/,{timeout:30000});
  // The visible final certificate must retain a numbered original and an
  // immutable SHA256 proof reference; the UI must never offer second issuance.
  await expect(closureSection.locator('p')
    .filter({hasText:/INTERNAL ABNORMAL FINAL CERTIFICATE IAC-/}))
    .toContainText(/SHA256 [0-9a-f]{64}/,{timeout:30000});
  await expect(closureSection).toContainText('no company-level journal');
  await closureSection.getByRole('button',{name:'Check internal abnormal closure readiness'}).click();
  await expect(closureSection).toContainText('INTERNAL ABNORMAL CLOSURE READINESS: READY',{timeout:30000});
  await expect(closureSection.getByRole('button',{name:'Issue internal abnormal final certificate'}))
    .toHaveCount(0);
  // Audited authenticated download must return the exact immutable numbered PDF
  // whose SHA256 is shown to the authorized source Owner.
  const certificateText=await closureSection.locator('p')
    .filter({hasText:/INTERNAL ABNORMAL FINAL CERTIFICATE IAC-/}).textContent();
  const certificateSerial=certificateText?.match(/INTERNAL ABNORMAL FINAL CERTIFICATE (IAC-[0-9]+)/)?.[1];
  const certificateHash=certificateText?.match(/SHA256 ([0-9a-f]{64})/)?.[1];
  expect(certificateSerial).toMatch(/^IAC-[0-9]+$/);
  expect(certificateHash).toMatch(/^[0-9a-f]{64}$/);
  const downloadPromise=d.waitForEvent('download');
  await closureSection.getByRole('button',{name:'Private numbered certificate (audited)'}).click();
  const certificateDownload=await downloadPromise;
  expect(certificateDownload.suggestedFilename()).toBe(`internal-abnormal-${certificateSerial}.pdf`);
  const certificateBytes=await readFile(await certificateDownload.path());
  expect(certificateBytes.subarray(0,5).toString()).toBe('%PDF-');
  expect(createHash('sha256').update(certificateBytes).digest('hex')).toBe(certificateHash);

  // The final certificate cannot manufacture returns or alter the physically
  // damaged five chairs; only the 15 outstanding original units are retired.
  await inventoryFocus(d,'Overview');
  await d.getByRole('button',{name:'Refresh book balances'}).click();
  const finalStock=d.locator('section[aria-label="Posted inventory book balances"]')
    .locator('tbody tr').filter({hasText:'Ceremony Chairs'});
  await expect(finalStock).toBeVisible({timeout:30000});
  await expect(finalStock).toContainText(/80\s*0\s*5/);



  await inventoryFocus(o,'Overview');
  await o.getByRole('button',{name:'Refresh book balances'}).click();
  // Both authorized Owners must read the same final conservation state after
  // the one-time 15-unit physical retirement, never the pre-closure snapshot.
  await expect(balance.locator('tbody tr').filter({hasText:'Ceremony Chairs'}))
    .toContainText(/80\s*0\s*5/);
  // Real authenticated mobile/desktop evidence; no bypassed service or mocked screen.
  await o.screenshot({path:'test-results/phase12-inventory-desktop.png',fullPage:true});
  await b.screenshot({path:'test-results/phase12-inventory-manager-mobile.png',fullPage:true});
 } finally {
  await Promise.all([owner.ctx.close(),source.ctx.close(),dest.ctx.close(),reviewer.ctx.close(),independent.ctx.close(),authorizer.ctx.close()]);
 }
});
