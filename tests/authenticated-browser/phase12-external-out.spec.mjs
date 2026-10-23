import {test,expect} from '@playwright/test';
import {inventoryLogin,inventoryFocus,inventoryUploadEvidence,syntheticEvidence} from './inventory-auth.mjs';

test('EXTERNAL_OUT MFA: independently witnessed owned dispatch, partial damaged partner return, stock conservation',async({browser})=>{
 test.setTimeout(720000);
 const keeper=await inventoryLogin(browser,'browser-e2e-inventory-manager-a@example.test',
  'synthetic-inventory-manager-a-only','JBSWY3DPEHPK3PXP',{width:390,height:844});
 const openingOwner=await inventoryLogin(browser,'browser-e2e-inventory-owner-b@example.test',
  'synthetic-inventory-owner-b-only','MFRGGZDFMZTWQ2LK',{width:1280,height:800});
 const approver=await inventoryLogin(browser,'browser-e2e-inventory-owner-c@example.test',
  'synthetic-inventory-owner-c-only','KRSXG5AUMVZWK4TT',{width:1280,height:800});
 const reviewer=await inventoryLogin(browser,'browser-e2e-inventory-owner-d@example.test',
  'synthetic-inventory-owner-d-only','ORUGS4ZANFZSAYJA',{width:1280,height:800});
 let drafter=null;
 try {
  const a=keeper.page,w=openingOwner.page,o=approver.page,d=reviewer.page;
  for(const p of [a,w,o,d])p.setDefaultTimeout(30000);
  await inventoryFocus(a,'Stock & counts');
  const cat=a.locator('section[aria-label="Create hall inventory catalog item"]');
  await expect(cat).toBeVisible();
  await cat.getByRole('textbox',{name:'Item name'}).fill('Outside Loan Silver Platters');
  await cat.getByRole('combobox',{name:'Category'}).selectOption('Serving');
  await cat.getByRole('spinbutton',{name:'Minimum quantity target (not current stock)'}).fill('5');
  await cat.getByRole('textbox',{name:'Reason for creating item'}).fill('Synthetic outside owned custody acceptance');
  await cat.getByRole('button',{name:'Add hall item'}).click();
  await expect(cat.getByRole('status')).toContainText('Catalog item saved: Outside Loan Silver Platters',{timeout:30000});
  const stock=a.locator('section[aria-label="Physical inventory stocktake"]');
  await stock.getByRole('combobox',{name:'Audit type'}).selectOption('opening');
  await stock.getByRole('button',{name:'+ Add item row'}).click();
  await stock.locator('select[wire\\:model="countRows.0.itemId"]').selectOption({label:'Outside Loan Silver Platters'});
  await stock.locator('select[wire\\:model="countRows.0.ownership"]').selectOption('owned');
  await stock.locator('input[wire\\:model="countRows.0.usableCount"]').fill('30');
  await stock.locator('input[wire\\:model="countRows.0.damagedCount"]').fill('0');
  await stock.getByRole('textbox',{name:'Count reason / observations'})
    .fill('Independent synthetic opening count of thirty owned platters');
  await stock.getByRole('button',{name:'Save physical count evidence'}).click();
  const posted=stock.getByRole('status').first();
  await expect(posted).toContainText('Count submitted for review',{timeout:30000});
  const countId=(await posted.textContent())?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
  expect(countId).toMatch(/^[0-9a-f-]{36}$/i);
  const opening=a.locator('section[aria-label="Opening stock independent review"]');
  await opening.locator('select[wire\\:model="openingItemId"]').selectOption({label:'Outside Loan Silver Platters'});
  await opening.locator('input[wire\\:model="openingStocktakeId"]').fill(countId);
  await opening.locator('input[wire\\:model="openingQuantity"]').fill('30');
  await opening.locator('input[wire\\:model="openingReason"]')
    .fill('Source owner must accept real count before partner dispatch');
  await opening.getByRole('button',{name:'Request separate approval (no stock posted)'}).click();
  await expect(opening.getByText('Opening evidence request saved:',{exact:false})).toBeVisible({timeout:30000});
  await inventoryFocus(w,'Stock & counts');
  const ownerOpening=w.locator('section[aria-label="Opening stock independent review"]');
  await ownerOpening.getByRole('button',{name:'Refresh opening review'}).click();
  await ownerOpening.getByRole('button',{name:'Accept verified opening stock'}).click();
  await expect(ownerOpening.getByText('ACCEPTED / BOOKED')).toBeVisible({timeout:30000});

  // Fresh current source Manager C is distinct from manager A's witnessed counts
  // and future independently recorded partial partner returns.
  drafter=await inventoryLogin(browser,'browser-e2e-inventory-manager-c@example.test',
    'synthetic-inventory-manager-c-only','IFBEGRCFIZDUQSKL',{width:390,height:844});
  const m=drafter.page;m.setDefaultTimeout(30000);
  await inventoryFocus(m,'Borrow / lend');
  const form=m.locator('[aria-label="Multi-item internal or external borrowing request"]');
  await form.getByRole('combobox',{name:'Borrowing transaction type'}).selectOption('external_out');
  await form.getByRole('textbox',{name:'Outside hall / establishment'}).fill('Synthetic Outside Loan Partner');
  await form.getByRole('textbox',{name:'Contact person'}).fill('Synthetic Partner Representative');
  await form.getByRole('textbox',{name:'Contact phone'}).fill('000-000-0000');
  await form.getByRole('button',{name:'+ Add Item Row'}).click();
  await form.locator('select[wire\\:model="loanRows.0.itemId"]')
    .selectOption({label:'Outside Loan Silver Platters — piece'});
  await form.locator('input[wire\\:model="loanRows.0.quantity"]').fill('30');
  await form.locator('input[wire\\:model="loanReturnDate"]').fill('2027-12-30');
  await form.getByRole('textbox',{name:'Purpose / linked event details'})
    .fill('Synthetic outside partner signed physical dispatch and damaged return');
  await form.getByRole('button',{name:'Save borrowing request (no stock moved)'}).click();
  const saved=form.getByText('Multi-item loan request saved:',{exact:false});
  await expect(saved).toBeVisible({timeout:30000});
  const loanId=(await saved.textContent())?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
  expect(loanId).toMatch(/^[0-9a-f-]{36}$/i);
  const queue=p=>p.locator('[aria-label="Saved borrowing requests awaiting handover"]');
  const loan=p=>queue(p).locator('article').filter({hasText:'Reference: '+loanId+'.'});
  async function refresh(p){
    await inventoryFocus(p,'Borrow / lend');
    await queue(p).getByRole('button',{name:'Refresh requests'}).click();
    await expect(loan(p)).toHaveCount(1);
  }
  await refresh(m);
  await expect(loan(m).getByRole('button',{name:'Witness outside partner physical handover'}))
    .toHaveCount(0); // original drafter cannot be the physical witness
  await refresh(w);
  await loan(w).getByRole('button',{name:'Witness outside partner physical handover'}).click();
  const handover=w.locator('section[aria-label="Witness outside lending physical handover"]');
  await expect(handover).toBeVisible({timeout:30000});
  await expect(handover).toContainText(loanId);
  await handover.locator('input[wire\\:model^="externalOutCounts."]').fill('30');
  await inventoryUploadEvidence(w,handover.locator('input[type="file"]'),syntheticEvidence);
  await handover.getByRole('button',{name:'Submit witnessed outside lending handover'}).click();
  await expect(w.getByRole('status').filter({hasText:'Outside lending custody reference'}))
    .toBeVisible({timeout:30000});
  await refresh(o);
  await expect(loan(o)).toContainText('counted 30 / requested 30');
  await loan(o).getByRole('button',{name:'Independently approve outside lending stock dispatch'}).click();
  await expect(loan(o)).toContainText('Hall-owned goods are ON LOAN to the partner',{timeout:30000});

  await refresh(a);
  await loan(a).getByRole('button',{name:'Record partial owned-stock return from outside partner'}).click();
  const back=a.locator('section[aria-label="Record outside owned stock return"]');
  await expect(back).toBeVisible({timeout:30000});
  await expect(back).toContainText(loanId);
  await back.locator('input[wire\\:model^="externalOutReturnQuantities."]').fill('12');
  await inventoryUploadEvidence(a,back.locator('input[type="file"]'),syntheticEvidence);
  await back.getByRole('button',{name:'Record witnessed partner return only'}).click();
  const status=a.getByRole('status').filter({hasText:'Outside partner return evidence/review'});
  await expect(status).toBeVisible({timeout:30000});
  const returnId=(await status.textContent())?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
  expect(returnId).toMatch(/^[0-9a-f-]{36}$/i);

  await refresh(d);
  await inventoryFocus(d,'Handover & returns');
  const returns=d.locator('section[aria-label="External owned stock physical return history"]');
  await expect(returns).toBeVisible({timeout:30000});
  const exact=returns.locator('article').filter({hasText:'Loan '+loanId+'; physical return '+returnId});
  await expect(exact).toHaveCount(1);
  await expect(exact).toContainText('INDEPENDENT REVIEW PENDING');
  await exact.getByRole('button',{name:'Independently assess outside partner physical return'}).click();
  const review=d.locator('section[aria-label="Independent outside partner return condition review"]');
  await expect(review).toBeVisible({timeout:30000});
  await expect(review).toContainText(returnId);
  await review.locator('input[wire\\:model^="externalOutReviewGood."]').fill('9');
  await review.locator('input[wire\\:model^="externalOutReviewDamaged."]').fill('2');
  await review.locator('input[wire\\:model^="externalOutReviewUnresolved."]').fill('1');
  await review.getByRole('button',{name:'Independently record partner-return condition'}).click();
  await expect(exact).toContainText('INDEPENDENT PHYSICAL CONDITION REVIEWED',{timeout:30000});
  await expect(exact).toContainText('good 9,');
  await expect(exact).toContainText('damaged 2,');
  await expect(exact).toContainText('unresolved 1');
  await expect(exact.getByRole('button',{name:'Independently assess outside partner physical return'}))
    .toHaveCount(0); // immutably reviewed receipt cannot be reviewed a second time
  await inventoryFocus(o,'Overview');
  await o.getByRole('button',{name:'Refresh book balances'}).click();
  const book=o.locator('section[aria-label="Posted inventory book balances"]')
    .locator('tbody tr').filter({hasText:'Outside Loan Silver Platters'});
  await expect(book).toContainText(/9\s*19\s*2/,{timeout:30000});
  await o.screenshot({path:'test-results/phase12-external-out-damaged.png',fullPage:true});
 } finally {
  await Promise.all([keeper.ctx.close(),openingOwner.ctx.close(),approver.ctx.close(),reviewer.ctx.close(),
    ...(drafter?[drafter.ctx.close()]:[])]);
 }
});
