import {test,expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import {inventoryLogin,inventoryFocus} from './inventory-auth.mjs';

test('Phase12 purchase: independent real receiving and review; monthly stocktake and hall CSV remain evidence only',async({browser})=>{
 test.setTimeout(600000);
 const original=await inventoryLogin(browser,'browser-e2e-inventory-manager-a@example.test',
  'synthetic-inventory-manager-a-only','JBSWY3DPEHPK3PXP',{width:390,height:844});
 const receiver=await inventoryLogin(browser,'browser-e2e-inventory-manager-c@example.test',
  'synthetic-inventory-manager-c-only','IFBEGRCFIZDUQSKL',{width:390,height:844});
 const reviewer=await inventoryLogin(browser,'browser-e2e-inventory-owner-d@example.test',
  'synthetic-inventory-owner-d-only','ORUGS4ZANFZSAYJA',{width:1280,height:800});
 try {
  const a=original.page,b=receiver.page,o=reviewer.page;
  for(const page of [a,b,o])page.setDefaultTimeout(30000);
  await expect(a.getByRole('option',{name:'Synthetic Test Hall B'})).toHaveCount(0);
  await inventoryFocus(a,'Stock & counts');
  const cat=a.locator('section[aria-label="Create hall inventory catalog item"]');
  await cat.getByRole('textbox',{name:'Item name'}).fill('Purchase and Count Soup Bowls');
  await cat.getByRole('combobox',{name:'Category'}).selectOption('Crockery');
  await cat.getByRole('spinbutton',{name:'Minimum quantity target (not current stock)'}).fill('10');
  await cat.getByRole('textbox',{name:'Reason for creating item'}).fill('Separate purchasing and month-end stock evidence');
  await cat.getByRole('button',{name:'Add hall item'}).click();
  await expect(cat.getByRole('status')).toContainText('Catalog item saved: Purchase and Count Soup Bowls',{timeout:30000});

  const purchasing=a.locator('section[aria-label="Purchased stock request and receipt history"]');
  await expect(purchasing).toBeVisible({timeout:30000});
  await purchasing.getByRole('combobox',{name:'Hall inventory item'})
    .selectOption({label:'Purchase and Count Soup Bowls (piece)'});
  await purchasing.getByRole('textbox',{name:'Quantity delivered or expected'}).fill('20');
  await purchasing.getByRole('textbox',{name:'Supplier / vendor name'}).fill('Synthetic Crockery Supplier');
  await purchasing.getByRole('textbox',{name:'Supplier document reference'}).fill('PURCHASE-TEST-2026-10');
  await purchasing.getByRole('textbox',{name:/Evidence reference \/ document fingerprint/})
    .fill('Unverified synthetic vendor reference, no bank settlement');
  await purchasing.getByRole('textbox',{name:'Receiving request reason'})
    .fill('Request independently witnessed twenty-piece bowl receipt');
  await purchasing.getByRole('button',{name:'Submit unverified purchase claim'}).click();
  const claim=purchasing.getByRole('status').filter({hasText:'Purchase claim saved:'});
  await expect(claim).toBeVisible({timeout:30000});
  const purchaseId=(await claim.textContent())?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
  expect(purchaseId).toMatch(/^[0-9a-f-]{36}$/i);
  await expect(purchasing.getByRole('combobox',{name:'Purchase claim'}).first()
    .locator('option[value="'+purchaseId+'"]')).toHaveCount(0);

  await inventoryFocus(b,'Stock & counts');
  const physical=b.locator('form[aria-label="Record physical purchase receiving"]');
  await expect(physical).toBeVisible({timeout:30000});
  await b.locator('section[aria-label="Purchased stock request and receipt history"]')
    .getByRole('button',{name:'Refresh purchase claims'}).click();
  await physical.getByRole('combobox',{name:'Purchase claim'}).selectOption(purchaseId);
  await physical.getByRole('textbox',{name:'Quantity physically counted'}).fill('20');
  await physical.getByRole('textbox',{name:'Physical evidence reference (not verified)'})
    .fill('Synthetic independent receiving count, not vendor proof');
  await physical.getByRole('textbox',{name:'Condition notes'})
    .fill('All twenty bowls visually counted, not yet book posted');
  await physical.getByRole('button',{name:'Record physical receiving assertion only'}).click();
  const receiving=b.locator('section[aria-label="Purchased stock request and receipt history"]')
    .getByRole('status').filter({hasText:'Receiving record'});
  await expect(receiving).toBeVisible({timeout:30000});
  const receivingId=(await receiving.textContent())?.match(/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/i)?.[0];
  expect(receivingId).toMatch(/^[0-9a-f-]{36}$/i);
  await expect(b.locator('form[aria-label="Independently review physical receiving"]'))
    .toHaveCount(0); // hall-assigned manager is not the independent Owner

  await inventoryFocus(o,'Stock & counts');
  const review=o.locator('form[aria-label="Independently review physical receiving"]');
  await expect(review).toBeVisible({timeout:30000});
  const history=o.locator('section[aria-label="Purchased stock request and receipt history"]');
  await history.getByRole('button',{name:'Refresh purchase claims'}).click();
  await review.getByRole('combobox',{name:'Receiving assertion'}).selectOption(receivingId);
  await review.getByRole('textbox',{name:'Review notes'})
    .fill('Third-party synthetic independent Owner checked original receipt count; no payment posted');
  await review.getByRole('button',{name:'Record independent review only'}).click();
  await expect(history.getByRole('status').filter({hasText:'Review '}))
    .toBeVisible({timeout:30000});
  const exact=history.locator('article').filter({hasText:'Claim ID: '+purchaseId});
  await expect(exact).toContainText('Owned inventory posted: NO. Financially posted: NO.');
  await expect(review.getByRole('combobox',{name:'Receiving assertion'})
    .locator('option[value="'+receivingId+'"]')).toHaveCount(0);

  await inventoryFocus(b,'Stock & counts');
  const stock=b.locator('section[aria-label="Physical inventory stocktake"]');
  await stock.getByRole('combobox',{name:'Audit type'}).selectOption('monthly');
  await stock.getByRole('button',{name:'+ Add item row'}).click();
  await stock.locator('select[wire\\:model="countRows.0.itemId"]')
    .selectOption({label:'Purchase and Count Soup Bowls'});
  await stock.locator('select[wire\\:model="countRows.0.ownership"]').selectOption('owned');
  await stock.locator('input[wire\\:model="countRows.0.usableCount"]').fill('20');
  await stock.locator('input[wire\\:model="countRows.0.damagedCount"]').fill('0');
  await stock.getByRole('textbox',{name:'Count reason / observations'})
    .fill('Monthly physical count evidence before final privately verified purchase stock acceptance');
  await stock.getByRole('button',{name:'Save physical count evidence'}).click();
  await expect(stock.getByRole('status')).toContainText('Inventory balances were not adjusted.',{timeout:30000});
  await expect(b.locator('section[aria-label="Hall physical count history"]'))
    .toContainText('Purchase and Count Soup Bowls (owned): 20 usable, 0 damaged');
  for(const page of [a,o]){
    await inventoryFocus(page,'Overview');
    const download=page.waitForEvent('download');
    await page.getByRole('button',{name:'Download hall book CSV'}).click();
    const file=await download;
    expect(file.suggestedFilename()).toMatch(/\.csv$/);
    const csv=(await readFile(await file.path())).toString('utf8');
    expect(csv).toContain('Purchase and Count Soup Bowls');
  }
  await o.screenshot({path:'test-results/phase12-purchase-independent.png',fullPage:true});
 } finally {
  await Promise.all([original.ctx.close(),receiver.ctx.close(),reviewer.ctx.close()]);
 }
});
