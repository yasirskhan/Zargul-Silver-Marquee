import {test, expect} from '@playwright/test';

test('customer books a hall through quote hold verified deposit and status at three viewports', async ({page}) => {
  test.setTimeout(600_000);
  await page.request.get('/__test/license-window/valid');
  const fixture = await page.request.get('/__test/booking-fixture');
  expect(fixture.ok()).toBe(true);
  const {companyId} = await fixture.json();

  for (const viewport of [
    {width: 1280, height: 900},
    {width: 768, height: 1024},
    {width: 360, height: 800},
  ]) {
    await page.request.post('/__test/booking-fixture/reset');
    await page.setViewportSize(viewport);
    await page.goto('http://wizard.localhost:8000/book');
    await page.locator('input[name="event_date"]').fill('2027-12-12');
    await page.locator('select[name="program"]').selectOption('evening');
    await page.locator('input[name="guests"]').fill('120');
    await page.getByRole('button', {name: 'See available halls and price'}).click();
    await expect(page.getByRole('heading', {name: 'Step 2 of 3 — choose a hall'})).toBeVisible();
    const hallCard = page.locator('.card').filter({hasText: 'Phase 10 Booking Hall'}).first();
    await expect(hallCard).toContainText('Phase 10 Booking Hall');
    await hallCard.getByRole('button', {name: 'Review this hall'}).click();
    await expect(page.getByRole('heading', {name: 'Step 3 of 3 — review and request hold'})).toBeVisible();
    await page.locator('input[name="customer_name"]').fill('Browser Booking Customer');
    await page.locator('input[name="email"]').fill('browser-booking@example.test');
    await page.getByRole('button', {name: 'Request temporary hold'}).click();
    await expect(page.getByRole('heading', {name: 'Temporary hold created'})).toBeVisible();
    const holdId = await page.locator('#hold-id').textContent();
    const token = await page.locator('#hold-token').textContent();
    const verified = await page.request.post('/__test/booking-fixture/verify', {form: {
      company_id: companyId,
      hold_id: holdId,
      reference: 'SYNTH-'+token.slice(0, 12),
    }});
    expect(verified.ok()).toBe(true);
    await page.getByRole('button', {name: 'Confirm booking after verified deposit'}).click();
    await expect(page.getByRole('heading', {name: 'Booking confirmed'})).toBeVisible();
    await expect(page.getByText('verified 5% deposit')).toBeVisible();
    await page.goto('http://wizard.localhost:8000/book/status?token='+token);
    await expect(page.getByText('Status: confirmed')).toBeVisible();
    const overflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflow, 'booking must not overflow at '+viewport.width+'px').toBe(false);
    await page.request.post('/__test/booking-fixture/reset');
  }
});
