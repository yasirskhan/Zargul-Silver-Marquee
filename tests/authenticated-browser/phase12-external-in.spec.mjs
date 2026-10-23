import {test,expect} from '@playwright/test';
import {inventoryLogin,inventoryFocus,inventoryUploadEvidence,syntheticEvidence} from './inventory-auth.mjs';

test('EXTERNAL_IN MFA: signed intake, independent acceptance, two partial returns and numbered clean receipt',async({browser})=>{
 test.setTimeout(720000);
 const drafter=await inventoryLogin(browser,'browser-e2e-inventory-manager-a@example.test','synthetic-inventory-manager-a-only','JBSWY3DPEHPK3PXP',{width:390,height:844});
 const witness=await inventoryLogin(browser,'browser-e2e-inventory-owner-b@example.test','synthetic-inventory-owner-b-only','MFRGGZDFMZTWQ2LK',{width:1280,height:800});
 const approver=await inventoryLogin(browser,'browser-e2e-inventory-owner-c@example.test','synthetic-inventory-owner-c-only','KRSXG5AUMVZWK4TT',{width:1280,height:800});
 const verifier=await inventoryLogin(browser,'browser-e2e-inventory-owner-d@example.test','synthetic-inventory-owner-d-only','ORUGS4ZANFZSAYJA',{width:1280,height:800});
 try {
  const a=drafter.page,w=witness.page,o=approver.page,c=verifier.page;
  for(const page of [a,w,o,c])page.setDefaultTimeout(30000);
  await expect(a.getByRole('option',{name:'Synthetic Test Hall B'})).toHaveCount(0);
  await inventoryFocus(a,'Borrow / lend');
  const form=a.locator('[aria-label="Multi-item internal or external borrowing request"]');
  await form.getByRole('combobox',{name:'Borrowing transaction type'}).selectOption('external_in');
  await form.getByRole('textbox',{name:'Outside hall / establishment'}).fill('Synthetic Partner Garden Hall');
  await form.getByRole('textbox',{name:'Contact person'}).fill('Synthetic Partner Witness');
  await form.getByRole('textbox',{name:'Contact phone'}).fill('000-000-0000');
  await form.getByRole('button',{name:'+ Add Item Row'}).click();
  await form.getByRole('button',{name:'+ New item'}).click();
  const item=form.locator('[aria-label="Create a new inventory item directly in this borrowing row"]');
  await expect(item).toBeVisible();
  await item.getByRole('textbox',{name:'New item name'}).fill('Partner Borrowed Banquet Jugs');
  await item.getByRole('combobox',{name:'Category'}).selectOption('Serving');
  await item.getByRole('button',{name:'Save and select this item'}).click();
  await expect(item).toHaveCount(0);
  await form.locator('input[wire\\:model="loanRows.0.quantity"]').fill('12');
  await form.locator('input[wire\\:model="loanReturnDate"]').fill('2027-12-30');
  await form.getByRole('textbox',{name:'Purpose / linked event details'}).fill('Outside partner custody test with two independently confirmed partial returns');
  await form.getByRole('button',{name:'Save borrowing request (no stock moved)'}).click();
  const saved=form.getByText('Multi-item loan request saved:',{exact:false});
  await expect(saved).toBeVisible({timeout:30000});
  const loanId=(await saved.textContent())?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
  expect(loanId).toMatch(/^[0-9a-f-]{36}$/i);
  const loans=page=>page.locator('[aria-label="Saved borrowing requests awaiting handover"]');
  const row=page=>loans(page).locator('article').filter({hasText:'Reference: '+loanId+'.'});
  async function refresh(page){
   await inventoryFocus(page,'Borrow / lend');
   await loans(page).getByRole('button',{name:'Refresh requests'}).click();
   await expect(row(page)).toHaveCount(1);
  }

  await refresh(w);
  await row(w).getByRole('button',{name:'Record witnessed external borrowed delivery'}).click();
  const intake=w.locator('section[aria-label="Witnessed external borrowing arrival"]');
  await expect(intake).toBeVisible({timeout:30000});
  await expect(intake).toContainText(loanId);
  await intake.locator('input[wire\\:model^="externalIntakeCounts."]').fill('12');
  await inventoryUploadEvidence(w,intake.locator('input[type="file"]'),syntheticEvidence);
  await intake.getByRole('button',{name:'Submit witnessed outside delivery for independent review'}).click();
  await expect(w.getByRole('status').filter({hasText:'External physical evidence/approval reference:'}))
    .toBeVisible({timeout:30000});
  await refresh(w);
  await expect(row(w).getByRole('button',{name:'Independently accept externally borrowed stock'}))
    .toHaveCount(0); // the witness cannot self-approve their physical intake

  await refresh(o);
  await expect(row(o)).toContainText('physically counted 12');
  await row(o).getByRole('button',{name:'Independently accept externally borrowed stock'}).click();
  await expect(row(o)).toContainText('External inventory accepted as BORROWED',{timeout:30000});
  await inventoryFocus(o,'Overview');
  await o.getByRole('button',{name:'Refresh book balances'}).click();
  const book=o.locator('section[aria-label="Posted inventory book balances"]')
    .locator('tbody tr').filter({hasText:'Partner Borrowed Banquet Jugs'});
  await expect(book).toBeVisible({timeout:30000});

  for(const qty of [7,5]){
   await refresh(w);
   await row(w).getByRole('button',{name:'Prepare partial outside partner return'}).click();
   const outgoing=w.locator('section[aria-label="Dispatch outside partner return"]');
   await expect(outgoing).toBeVisible({timeout:30000});
   await expect(outgoing).toContainText(loanId);
   await outgoing.locator('input[wire\\:model^="externalReturnQuantities."]').fill(String(qty));
   await inventoryUploadEvidence(w,outgoing.locator('input[type="file"]'),syntheticEvidence);
   await outgoing.getByRole('button',{name:'Record witnessed outside return shipment'}).click();
   const status=w.getByRole('status').filter({hasText:'External return custody reference:'});
   await expect(status).toBeVisible({timeout:30000});
   const batchId=(await status.textContent())?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
   expect(batchId).toMatch(/^[0-9a-f-]{36}$/i);

   await refresh(c);
   await inventoryFocus(c,'Handover & returns');
   const batches=c.locator('section[aria-label="Outside borrowed return batches"]');
   await expect(batches).toBeVisible({timeout:30000});
   const matching=batches.locator('article').filter({hasText:'Loan '+loanId+' / batch '+batchId});
   await expect(matching).toHaveCount(1);
   await expect(matching).toContainText('IN TRANSIT / PARTNER ACKNOWLEDGMENT PENDING');
   await matching.getByRole('button',{name:'Independently verify partner receipt'}).click();
   const confirmation=c.locator('section[aria-label="Independent outside partner receipt verification"]');
   await expect(confirmation).toBeVisible({timeout:30000});
   await expect(confirmation).toContainText(batchId);
   await confirmation.locator('input[wire\\:model^="externalConfirmCounts."]').fill(String(qty));
   await inventoryUploadEvidence(c,confirmation.locator('input[type="file"]'),syntheticEvidence);
   await confirmation.getByRole('button',{name:'Confirm independently counted partner receipt'}).click();
   await expect(matching).toContainText('PARTNER PHYSICAL RECEIPT CONFIRMED',{timeout:30000});
  }
  await refresh(o);
  await expect(row(o)).toContainText('Physical return verified; formal closure pending.');
  await row(o).getByRole('button',{name:'Finalize externally borrowed clean loan'}).click();
  await expect(row(o)).toContainText(/External borrowed clean return receipt EX-[0-9]+/,{timeout:30000});
  await expect(row(o).getByRole('button',{name:'Finalize externally borrowed clean loan'})).toHaveCount(0);
  const download=o.waitForEvent('download');
  await row(o).getByRole('button',{name:'Download numbered external clean-return PDF'}).click();
  const pdf=await download;
  expect(pdf.suggestedFilename()).toMatch(/\.pdf$/);
  await inventoryFocus(o,'Overview');
  await o.getByRole('button',{name:'Refresh book balances'}).click();
  await expect(book).toBeVisible({timeout:30000});
  await o.screenshot({path:'test-results/phase12-external-in-clean.png',fullPage:true});
 } finally {
  await Promise.all([drafter.ctx.close(),witness.ctx.close(),approver.ctx.close(),verifier.ctx.close()]);
 }
});
