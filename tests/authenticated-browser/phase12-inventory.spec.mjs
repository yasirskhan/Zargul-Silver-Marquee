import {test,expect} from '@playwright/test';
import {inventoryLogin,inventoryFocus,syntheticEvidence} from './inventory-auth.mjs';

test('MFA Owner and separate Managers perform actual 100-chair stock, loan and partial return on desktop/mobile',async({browser})=>{
 test.setTimeout(600000);
 const owner=await inventoryLogin(browser,'browser-e2e-inventory-owner@example.test',
  'synthetic-inventory-owner-only','IFBEGRCFIZDUQSKK',{width:1366,height:768});
 const source=await inventoryLogin(browser,'browser-e2e-inventory-manager-a@example.test',
  'synthetic-inventory-manager-a-only','JBSWY3DPEHPK3PXP',{width:390,height:844});
 const dest=await inventoryLogin(browser,'browser-e2e-inventory-manager-b@example.test',
  'synthetic-inventory-manager-b-only','GEZDGNBVGY3TQOJQ',{width:390,height:844});
 try {
  const a=source.page,b=dest.page,o=owner.page;
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
  const loan=a.locator('section[aria-label="Multi-item internal or external borrowing request"]');
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

  await inventoryFocus(o,'Borrow / lend');
  const ownLoans=o.locator('section[aria-label="Saved borrowing requests awaiting handover"]');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  await ownLoans.getByRole('button',{name:'Prepare witnessed source dispatch'}).click();
  const dispatch=o.locator('section[aria-label="Independent internal source dispatch"]');
  await expect(dispatch).toBeVisible({timeout:30000});
  await dispatch.locator('input[type="file"]').setInputFiles(syntheticEvidence);
  await dispatch.getByRole('button',{name:'Confirm actual source physical dispatch'}).click();
  await expect(o.getByText('Independently witnessed physical dispatch recorded:',{exact:false}))
   .toBeVisible({timeout:30000});

  await inventoryFocus(b,'Borrow / lend');
  const incoming=b.locator('section[aria-label="Saved borrowing requests awaiting handover"]');
  await incoming.getByRole('button',{name:'Refresh requests'}).click();
  await incoming.getByRole('button',{name:'Record independent receiving count'}).click();
  const receipt=b.locator('section[aria-label="Confirm independent internal loan arrival"]');
  await expect(receipt).toBeVisible({timeout:30000});
  await receipt.locator('select[wire\\:model^="loanReceiptMapping."]')
   .selectOption({index:1});
  await receipt.locator('input[type="file"]').setInputFiles(syntheticEvidence);
  await receipt.getByRole('button',{name:'Confirm physically received stock'}).click();
  await expect(b.getByText('Receiving custody recorded:',{exact:false}))
   .toBeVisible({timeout:30000});

  await inventoryFocus(b,'Borrow / lend');
  await incoming.getByRole('button',{name:'Prepare partial return shipment'}).click();
  const returnForm=b.locator('section[aria-label="Send partial physical borrowed return"]');
  await expect(returnForm).toBeVisible({timeout:30000});
  await returnForm.locator('input[type="number"]').first().fill('85');
  await returnForm.locator('input[type="file"]').setInputFiles(syntheticEvidence);
  await returnForm.getByRole('button',{name:'Record witnessed return shipment'}).click();
  await expect(b.getByText('Return shipment',{exact:false}))
   .toBeVisible({timeout:30000});

  // The read-only loan queue must lead an Owner directly to the next
  // physical custody review, not back to the unrelated draft-entry form.
  await inventoryFocus(o,'Borrow / lend');
  await ownLoans.getByRole('button',{name:'Refresh requests'}).click();
  await inventoryFocus(o,'Overview');
  await o.locator('section[aria-label="Inventory at a glance"]')
    .getByRole('button',{name:'Open next loan step'}).first().click();
  const batches=o.locator('section[aria-label="Incoming physical return batches"]');
  await expect(batches).toBeVisible({timeout:30000});
  await batches.getByRole('button',{name:'Review physically returned items'}).click();
  const acceptance=o.locator('section[aria-label="Accept returned physical stock by condition"]');
  await expect(acceptance).toBeVisible({timeout:30000});
  await acceptance.getByRole('spinbutton',{name:'Physically good quantity'}).fill('80');
  await acceptance.getByRole('spinbutton',{name:'Physically damaged quantity'}).fill('5');
  await acceptance.locator('input[type="file"]').setInputFiles(syntheticEvidence);
  await acceptance.getByRole('button',{name:'Accept observed good/damaged return quantities'}).click();
  await inventoryFocus(o,'Overview');
  await o.getByRole('button',{name:'Refresh book balances'}).click();
  const balance=o.locator('section[aria-label="Posted inventory book balances"]');
  const row=balance.locator('tbody tr').filter({hasText:'Ceremony Chairs'});
  await expect(row).toBeVisible({timeout:30000});
  await expect(row).toContainText(/80\s*15\s*5/);
  await expect(o.locator('section[aria-label="Inventory at a glance"]'))
   .toContainText('Owned damaged');
  // Real authenticated mobile/desktop evidence; no bypassed service or mocked screen.
  await o.screenshot({path:'test-results/phase12-inventory-desktop.png',fullPage:true});
  await b.screenshot({path:'test-results/phase12-inventory-manager-mobile.png',fullPage:true});
 } finally {
  await Promise.all([owner.ctx.close(),source.ctx.close(),dest.ctx.close()]);
 }
});
