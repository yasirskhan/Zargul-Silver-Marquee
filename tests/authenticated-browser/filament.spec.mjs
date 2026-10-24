import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';

const fixtureSecret = 'JBSWY3DPEHPK3PXP';

function base32(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = 0;
  let value = 0;
  const bytes = [];
  for (const char of input.toUpperCase().replace(/=+$/, '')) {
    const digit = alphabet.indexOf(char);
    if (digit < 0) throw new Error('Invalid synthetic TOTP secret');
    value = (value << 5) | digit;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      bytes.push((value >>> bits) & 255);
    }
  }
  return Buffer.from(bytes);
}

function totp(secret) {
  const step = Math.floor(Date.now() / 30_000);
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const digest = createHmac('sha1', base32(secret)).update(counter).digest();
  const offset = digest[19] & 0x0f;
  const number = (digest.readUInt32BE(offset) & 0x7fff_ffff) % 1_000_000;
  return String(number).padStart(6, '0');
}

async function waitForLoginHydration(page) {
  // A displayed Filament form is not necessarily interactive. Wait for the
  // actual Livewire login component, not the unrelated notifications widget.
  // This keeps genuine MFA mandatory while avoiding a lost early click.
  await page.waitForFunction(() =>
    Boolean(window.Livewire?.all?.().some(component =>
      component.el?.querySelector?.('input[type="email"]'))),
  null, { timeout: 30_000 });
}

async function signInWithTotp(page, email, password, secret, attempt = 0) {
  if (attempt > 2) throw new Error('Real MFA login was repeatedly throttled');
  await page.goto('/admin/login');
  await waitForLoginHydration(page);
  await page.locator('input[type="email"]').fill(email);
  await page.getByLabel(/Password/).fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();
  await page.waitForTimeout(400);
  const throttle = page.getByText(/Too many login attempts/i).first();
  if (await throttle.isVisible().catch(() => false)) {
    const message = String(await throttle.textContent());
    const seconds = Number(message.match(/(\d+)\s+seconds?/i)?.[1] ?? 65);
    await page.waitForTimeout((Math.min(120, Math.max(1, seconds)) + 2) * 1000);
    return signInWithTotp(page, email, password, secret, attempt + 1);
  }
  const code = page.locator(
    'input[autocomplete="one-time-code"]:visible, input[id*="code"]:visible, input[name*="code"]:visible, input[inputmode="numeric"]:visible',
  ).first();
  await expect(code).toBeVisible({ timeout: 25_000 });
  const remaining = 30_000 - (Date.now() % 30_000);
  if (remaining < 10_000) await page.waitForTimeout(remaining + 350);
  await code.fill(totp(secret));
  const authenticated = (url) => {
    const path = new URL(url).pathname;
    return path.startsWith('/admin')
      && !['/admin/login', '/admin/multi-factor-authentication', '/admin/password-reset']
        .some((blocked) => path.startsWith(blocked));
  };
  try {
    await page.waitForURL(authenticated, { timeout: 15_000 });
    return;
  } catch {
    if (await throttle.isVisible().catch(() => false)) {
      const message = String(await throttle.textContent());
      const seconds = Number(message.match(/(\d+)\s+seconds?/i)?.[1] ?? 65);
      await page.waitForTimeout((Math.min(120, Math.max(1, seconds)) + 2) * 1000);
      return signInWithTotp(page, email, password, secret, attempt + 1);
    }
    const verify = page.getByRole('button', {
      name: /confirm sign in|verify|continue|sign in/i,
    }).last();
    if (await verify.isVisible().catch(() => false)) {
      await expect(verify).toBeEnabled({ timeout: 10_000 });
      await verify.click();
    }
    await page.waitForURL(authenticated, { timeout: 30_000 });
  }
}

test('private proposal route requires authentication and does not reveal customer data', async ({ page }) => {
  await page.goto('/admin/booking-proposals');
  await expect(page).toHaveURL(/\/admin\/login/, { timeout: 20_000 });
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
});

test('real owner password and TOTP flow protects mobile proposal editing without booking', async ({ page }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/admin/login');
  await expect(page.locator('input[type="email"]')).toBeVisible();
  await waitForLoginHydration(page);
  await page.locator('input[type="email"]').fill('browser-e2e-owner@example.test');
  await page.getByLabel(/Password/).fill('synthetic-browser-password-only');
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();

  const code = page.locator(
    'input[autocomplete="one-time-code"]:visible, input[id*="code"]:visible, input[name*="code"]:visible, input[inputmode="numeric"]:visible',
  ).first();
  await expect(code).toBeVisible({ timeout: 25_000 });
  const remainingOwnerTotpMs = 30_000 - (Date.now() % 30_000);
  if (remainingOwnerTotpMs < 10_000) await page.waitForTimeout(remainingOwnerTotpMs + 350);
  await code.fill(totp(fixtureSecret));

  // Filament may auto-submit its MFA code and navigate immediately. Never
  // click a stale disabled login button after the authenticated redirect.
  const authenticated = (url) => {
    const path = new URL(url).pathname;
    return path.startsWith('/admin') &&
      !['/admin/login', '/admin/multi-factor-authentication', '/admin/password-reset']
        .some((blocked) => path.startsWith(blocked));
  };
  // Filament can auto-submit a completed TOTP challenge. Give Livewire time
  // to complete the server-side authentication before attempting an explicit
  // verification click; never click a button during its disabled transition.
  if (!authenticated(page.url())) {
    try {
      await page.waitForURL(authenticated, { timeout: 1_200 });
    } catch {
      if (!authenticated(page.url())) {
        const verify = page.getByRole('button', {
          name: /confirm sign in|verify|continue|sign in/i,
        }).last();
        // A real Filament MFA auto-submit can redirect between the URL
        // check and locating the manual verification button. A disappearing
        // button is success ONLY when the authenticated destination exists.
        try {
          await expect(verify).toBeEnabled({ timeout: 12_000 });
          if (!authenticated(page.url())) await verify.click();
        } catch (error) {
          if (!authenticated(page.url())) throw error;
        }
        await page.waitForURL(authenticated, { timeout: 30_000 });
      }
    }
  }

  await page.goto('/admin/booking-proposals');
  await expect(page.getByText('Internal booking proposals')).toBeVisible();
  // Wait for Livewire hydration, not just the server-rendered table.
  await page.waitForFunction(
    () => Boolean(window.Livewire?.all?.().length),
    null,
    { timeout: 30_000 },
  );
  // A different synthetic staff member requested an internal approval before
  // the owner signed in. It must appear in the protected panel after MFA,
  // not as a customer-facing or pre-authentication alert.
  const ownerNotice = page.getByRole('dialog', { name: 'Pending owner approval requests' });
  await expect(ownerNotice).toBeVisible({ timeout: 30_000 });
  await expect(ownerNotice.getByText('Synthetic Marquee Venue')).toBeVisible();
  await expect(ownerNotice.getByText('1 pending request')).toBeVisible();
  await expect(ownerNotice.getByText('browser-e2e-private@example.test')).toHaveCount(0);
  await expect(ownerNotice.getByText('Synthetic Browser Customer')).toHaveCount(0);
  await ownerNotice.getByRole('button', { name: 'Dismiss for this session' }).click();
  await expect(ownerNotice).toHaveCount(0);
  await expect(page.locator('tbody').getByText('Synthetic Test Hall')).toBeVisible();
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);

  // Real 360px MFA-authenticated overview must surface role-scoped counts and
  // navigate straight back to the owner's current company proposal workflow.
  await page.goto('/admin/overview');
  await expect(page.locator('section[aria-label="Private venue overview"]'))
    .toBeVisible({ timeout: 20_000 });
  await expect(page.getByText('Unconfirmed company proposals')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Review booking proposals' })).toBeVisible();
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);
  await page.getByRole('link', { name: 'Review booking proposals' }).click();
  await expect(page.getByText('Internal booking proposals')).toBeVisible({ timeout: 20_000 });
  await page.waitForFunction(
    () => Boolean(window.Livewire?.all?.().length), null, { timeout: 30_000 }
  );

  // The responsive table is intentionally wider than a 360px phone. Scroll
  // its actual overflow container, then exercise its keyboard-accessible
  // review action. Playwright's pointer click can otherwise report the last
  // column outside the viewport even though the button is usable.
  const scroller = page.locator(
    'section[aria-label="Private company proposal results"] .overflow-x-auto',
  );
  await expect(scroller).toBeVisible();
  await scroller.evaluate((element) => {
    element.scrollLeft = element.scrollWidth - element.clientWidth;
  });
  const reviewButton = page.getByRole('button', { name: 'Review/edit' }).first();
  await expect(reviewButton).toBeEnabled();
  await reviewButton.focus();
  await expect(reviewButton).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Internal proposal review' })).toBeVisible();
  await page.getByRole('spinbutton', { name: 'Expected guests' }).fill('135');
  await page.getByRole('textbox', { name: /Required change reason/ }).fill('Synthetic owner browser audit check');
  await page.getByRole('button', { name: 'Save draft changes' }).click();
  await expect(page.getByText('Saved revision 2')).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText('No action here reserves a hall')).toBeVisible();
  // Prove a saved internal draft can reach the new nonbinding finance review
  // without confirming a booking, invoicing a client, or claiming a hall.
  await page.getByRole('textbox', { name: /Required change reason/ })
    .fill('Browser owner submitted a saved internal draft');
  await page.getByRole('button', { name: 'Submit saved draft for review' }).click();
  await expect(page.getByText('State: under review')).toBeVisible({ timeout: 20_000 });

  await page.goto('/admin/finance-review');
  await expect(page.getByText('Finance review is internal only.')).toBeVisible();
  await page.waitForFunction(() => Boolean(window.Livewire?.all?.().length), null, { timeout: 30_000 });
  await expect(page.getByText('Financial posting is disabled', { exact: false })).toBeVisible();
  await page.getByLabel('Internal proposal under review').selectOption({ index: 1 });
  await page.getByLabel('Internal purpose (no customer contact)')
    .fill('Synthetic owner review of frozen rental-only price');
  await page.getByRole('button', { name: 'Save immutable internal preview' }).click();
  await expect(page.getByText('Nonbinding PKR 0 minor units')).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);

  // The approval is a separate, still-non-applying exception proposal. The
  // owner may request one but cannot approve their own request.
  await page.goto('/admin/approval-center');
  await expect(page.getByText('Internal, company-scoped exception requests.')).toBeVisible();
  await page.waitForFunction(() => Boolean(window.Livewire?.all?.().length), null, { timeout: 30_000 });
  await page.getByLabel('Linked internal proposal (required except for expense)').selectOption({ index: 1 });
  await page.getByLabel('Proposed amount in minor currency units').fill('100');
  await page.getByLabel('ISO currency code').fill('PKR');
  await page.getByLabel('Operational reason (no personal/contact data)')
    .fill('Synthetic approval proposal without finance mutation');
  await page.getByRole('button', { name: 'Submit non-applying approval request' }).click();
  await expect(page.getByText('Synthetic approval proposal without finance mutation'))
    .toBeVisible({ timeout: 20_000 });
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);

  // CMS-004/009: exercise REAL owner-authorized Website Workspace at 360px,
  // using a distinct synthetic page with no customer data or public release.
  await page.goto('/admin/website');
  await expect(page.locator('section[aria-label="Website page status"]'))
    .toContainText('Synthetic Home Page', { timeout: 25_000 });
  await page.waitForFunction(() => Boolean(window.Livewire?.all?.().length), null, { timeout: 30_000 });
  const pageStatus = page.locator('section[aria-label="Website page status"]');
  const pageSections = page.locator('section[aria-label="Draft page sections"]');
  const reason = page.getByRole('textbox', { name: 'Reason for this change (required)' });

  await expect(pageStatus).toContainText('Not published');
  await pageSections.getByRole('button', { name: '+ Text' }).click();
  await pageSections.getByRole('textbox', { name: 'Title', exact: true })
    .fill('Synthetic mobile publishing acceptance');
  const seoTitle = page.getByRole('textbox', { name: 'Search title override (optional)' });
  await seoTitle.fill('Synthetic published marquee search title');
  await reason.fill('Browser owner saves approved synthetic introduction');
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await expect(pageStatus).toContainText('Editor loaded revision: 1', { timeout: 25_000 });
  await expect(pageStatus).toContainText('Not published');

  await reason.fill('Browser owner publishes saved synthetic introduction');
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Publish saved draft' }).click();
  await expect(pageStatus).toContainText('A published snapshot exists', { timeout: 25_000 });
  await expect(page.getByText('Revision 1 is live')).toBeVisible();
  await expect(seoTitle).toHaveValue('Synthetic published marquee search title');

  await reason.fill('Browser owner temporarily takes synthetic page offline');
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Take published page offline' }).click();
  await expect(pageStatus).toContainText('Not published', { timeout: 25_000 });
  await expect(page.getByRole('button', { name: 'Restore revision 1' })).toBeVisible();

  await reason.fill('Browser owner restores previously approved synthetic revision');
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Restore revision 1' }).click();
  await expect(pageStatus).toContainText('A published snapshot exists', { timeout: 25_000 });
  await expect(page.getByText('Revision 1 is live')).toBeVisible();
  await page.getByRole('button', { name: 'Reload latest draft' }).click();
  await expect(seoTitle).toHaveValue('Synthetic published marquee search title');
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);

  // CMS-005: perform actual owner mobile branding changes and separate
  // publication. The test-only fixture has no external domain or live launch.
  const branding = page.locator('section[aria-label="Website branding"]');
  await expect(branding).toContainText('Branding draft revision: 0');
  await branding.getByRole('textbox', { name: 'Public venue name' })
    .fill('Synthetic Silver Marquee');
  await branding.getByRole('textbox', { name: 'Public footer message (optional)' })
    .fill('Synthetic approved venue footer');
  await branding.getByRole('textbox', { name: 'Search description (optional)' })
    .fill('Synthetic approved wedding and events marquee');
  await branding.getByRole('button', { name: 'Hide /gallery from menu' }).click();
  await expect(branding.getByRole('button', { name: 'Show /gallery' })).toBeVisible();
  await branding.getByRole('button', { name: 'Move /about menu link earlier' }).click();
  await branding.getByRole('textbox', { name: 'Reason for branding change or approval' })
    .fill('Prepare safe mobile footer and menu draft');
  await branding.getByRole('button', { name: 'Save branding draft' }).click();
  await expect(branding).toContainText('Branding draft revision: 1', { timeout: 25_000 });
  await expect(branding).toContainText('No published branding yet.');

  await branding.getByRole('textbox', { name: 'Reason for branding change or approval' })
    .fill('Owner approves synthetic branding for website preview');
  page.once('dialog', dialog => dialog.accept());
  await branding.getByRole('button', { name: 'Publish branding' }).click();
  await expect(branding).toContainText('An approved branding snapshot exists.', { timeout: 25_000 });
  await expect(branding.getByRole('button', { name: 'Show /gallery' })).toBeVisible();
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);

  // CMS-003/004: real owner columns, desktop widths/order and independent phone order.
  await pageSections.getByRole('button', { name: '+ 2 columns' }).click();
  await expect(pageSections.getByText('Desktop column 1 (6/12)')).toBeVisible();
  await pageSections.getByRole('button', { name: 'Wide left 8 / 4' }).click();
  await expect(pageSections.getByText('Desktop column 1 (8/12)')).toBeVisible();
  await pageSections.getByRole('button', { name: 'Desktop left' }).last().click();
  await expect(pageSections.getByText('Desktop column 1 (4/12)')).toBeVisible();
  await expect(pageSections.getByText('Phone order: 2').first()).toBeVisible();
  await pageSections.getByRole('button', { name: 'Phone earlier' }).first().click();
  await expect(pageSections.getByText('Phone order: 1').first()).toBeVisible();
  await reason.fill('Browser owner arranges responsive columns');
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await expect(pageStatus).toContainText('Editor loaded revision: 2', { timeout: 25_000 });
  await page.getByRole('button', { name: 'Reload latest draft' }).click();
  await expect(pageSections.getByText('Desktop column 1 (4/12)')).toBeVisible();
  await expect(pageSections.getByText('Phone order: 1').first()).toBeVisible();

  // CMS-002: real owner upload, private thumbnail, approval and crop/focal controls.
  const mediaLibrary = page.locator('section[aria-label="Website media library"]');
  const syntheticPng = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAE0lEQVR4nGPcsqCCAQaY4Cy8HABbGAHU4FlqOwAAAABJRU5ErkJggg==',
    'base64',
  );
  await mediaLibrary.locator('input[type="file"]').setInputFiles({
    name: 'synthetic-venue.png', mimeType: 'image/png', buffer: syntheticPng,
  });
  await page.waitForTimeout(1_500);
  const mediaAltField = mediaLibrary.getByRole('textbox', { name: 'Image description for accessibility' });
  const mediaReasonField = mediaLibrary.getByRole('textbox', { name: 'Media upload or approval reason' });
  const stageButton = mediaLibrary.getByRole('button', { name: 'Stage image privately' });
  await mediaAltField.fill('Synthetic owner uploaded venue photograph');
  await mediaReasonField.fill('Synthetic browser owner uploads a private venue image');
  await stageButton.click();
  await page.waitForTimeout(800);
  if ((await mediaReasonField.inputValue()) !== '') {
    // Livewire temp-upload hydration can still be in flight on a slow runner.
    await page.waitForTimeout(1_500);
    await mediaAltField.fill('Synthetic owner uploaded venue photograph');
    await mediaReasonField.fill('Synthetic browser owner uploads a private venue image');
    await stageButton.click();
  }
  await expect(mediaReasonField).toHaveValue('');
  const uploadedThumb = mediaLibrary.getByRole('img', { name: 'Synthetic owner uploaded venue photograph' });
  await expect(uploadedThumb).toBeVisible({ timeout: 25_000 });
  const thumbWidth = await uploadedThumb.evaluate(async img => {
    try { await img.decode(); } catch { /* fall through to naturalWidth */ }
    return img.naturalWidth;
  });
  expect(thumbWidth).toBeGreaterThan(0);

  page.once('dialog', dialog => dialog.accept());
  await mediaLibrary.getByRole('textbox', { name: 'Media upload or approval reason' })
    .fill('Owner approves the synthetic venue photograph');
  await mediaLibrary.getByRole('button', { name: 'Approve image' }).first().click();
  await expect(mediaLibrary.getByText(/Approved/).first()).toBeVisible({ timeout: 25_000 });
  await mediaLibrary.getByRole('button', { name: 'Add to draft' }).first().click();
  const cropControls = page.locator('div[aria-label="Image crop and focal point"]').last();
  await expect(cropControls).toBeVisible();
  await cropControls.getByRole('spinbutton', { name: 'Focal X (%)' }).fill('35');
  await cropControls.getByRole('spinbutton', { name: 'Focal Y (%)' }).fill('65');
  await cropControls.locator('select').selectOption('4:3');
  await reason.fill('Browser owner sets approved photo crop and focal point');
  await page.getByRole('button', { name: 'Save draft', exact: true }).click();
  await expect(pageStatus).toContainText('Editor loaded revision: 3', { timeout: 25_000 });
  await page.getByRole('button', { name: 'Reload latest draft' }).click();
  const reloadedCrop = page.locator('div[aria-label="Image crop and focal point"]').last();
  await expect(reloadedCrop.getByRole('spinbutton', { name: 'Focal X (%)' })).toHaveValue('35');
  await expect(reloadedCrop.getByRole('spinbutton', { name: 'Focal Y (%)' })).toHaveValue('65');
  await expect(reloadedCrop.locator('select')).toHaveValue('4:3');
});

test('real owner configures hall schedule seating and add-ons without booking', async ({ page }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await signInWithTotp(
    page, 'browser-e2e-inventory-owner-b@example.test',
    'synthetic-inventory-owner-b-only', 'MFRGGZDFMZTWQ2LK',
  );
  await page.goto('/admin/hall-programs');
  const form = page.locator('form[aria-label="Edit three daily hall programs"]');
  await expect(form).toBeVisible({ timeout: 25_000 });

  const morning = form.locator('fieldset').filter({ hasText: 'Morning' }).first();
  await morning.getByRole('spinbutton', { name: 'Rental rate (minor units)' }).fill('150000');
  await page.getByLabel('Reason for this schedule or price change')
    .fill('Synthetic owner approves the morning rental rate');
  await page.getByRole('button', { name: 'Save program rules' }).click();
  await expect(page.getByText('Hall program rules saved with audit history'))
    .toBeVisible({ timeout: 25_000 });
  await page.reload();
  const reloadedMorning = page.locator('form[aria-label="Edit three daily hall programs"] fieldset')
    .filter({ hasText: 'Morning' }).first();
  await expect(reloadedMorning.getByRole('spinbutton', { name: 'Rental rate (minor units)' }))
    .toHaveValue('150000');

  await page.getByRole('spinbutton', { name: 'Maximum guests' }).fill('350');
  await page.getByRole('spinbutton', { name: 'Chairs included' }).fill('300');
  await page.getByRole('spinbutton', { name: 'Tables included' }).fill('20');
  await page.getByRole('spinbutton', { name: 'Additional chair price (minor units)' }).fill('500');
  await page.getByRole('spinbutton', { name: 'Additional table price (minor units)' }).fill('2000');
  await page.getByLabel('Seating rate currency code').fill('PKR');
  await page.getByLabel('Reason for seating or rate change')
    .fill('Synthetic owner approves seating capacity and unit prices');
  await page.getByRole('button', { name: 'Save seating rules' }).click();
  await expect(page.getByText('Hall seating and unit prices saved with audit history'))
    .toBeVisible({ timeout: 25_000 });

  await page.getByRole('button', { name: 'Add optional service' }).click();
  const addon = page.locator('fieldset').filter({ hasText: 'Optional service 1' }).first();
  await addon.getByLabel('Unique code').fill('stage_lighting');
  await addon.getByLabel('Service name').fill('Stage lighting');
  await addon.getByLabel('Category').selectOption('lighting');
  await addon.getByLabel('Charge unit').selectOption('flat');
  await addon.getByLabel('Rate (minor units)').fill('25000');
  await addon.getByLabel('Available for selection').check();
  await page.getByLabel('Catalog currency code').fill('PKR');
  await page.getByLabel('Change reason')
    .fill('Synthetic owner approves the stage lighting catalog item');
  await page.getByRole('button', { name: 'Save optional services' }).click();
  await expect(page.getByText('Hall add-on catalog saved with audit history'))
    .toBeVisible({ timeout: 25_000 });
  await page.reload();
  await expect(page.locator('fieldset').filter({ hasText: 'Optional service 1' })
    .getByLabel('Service name')).toHaveValue('Stage lighting');
});

test('real owner creates an encrypted customer booking request without a hall claim', async ({ page }) => {
  test.setTimeout(240_000);
  await page.waitForTimeout(65_000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await signInWithTotp(
    page, 'browser-e2e-inventory-owner-c@example.test',
    'synthetic-inventory-owner-c-only', 'KRSXG5AUMVZWK4TT',
  );
  await page.goto('/admin/venue-reservations');
  const intake = page.locator('section[aria-label="Assigned staff customer and booking request intake"]');
  await expect(intake).toBeVisible({ timeout: 25_000 });
  await intake.getByLabel('Customer name').fill('Synthetic Phase Three Customer');
  await intake.getByLabel('Customer email (optional)').fill('phase3-browser@example.test');
  await intake.getByLabel('Hall').selectOption({ label: 'Synthetic Test Hall' });
  await intake.getByLabel('Event date').fill('2027-06-15');
  await intake.getByLabel('Event program').selectOption('noon');
  await intake.getByLabel('Guest count for new booking').fill('120');
  await intake.getByLabel('Internal reason (exclude customer personal details)')
    .fill('Synthetic Phase 3 browser booking request without a hall claim');
  await intake.getByRole('button', { name: 'Save customer and request owner review' }).click();
  await expect(page.getByText(/Unconfirmed proposal:/)).toBeVisible({ timeout: 30_000 });
});

test('real operations MFA grants only assigned-hall search and planning on mobile', async ({ page }) => {
  test.setTimeout(180_000);
  // The preceding owner journey uses the real login limiter from the same IP.
  await page.waitForTimeout(65_000);
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/admin/login');
  await waitForLoginHydration(page);
  await page.locator('input[type="email"]').fill('browser-e2e-operations@example.test');
  await page.getByLabel(/Password/).fill('synthetic-browser-operations-only');
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();

  const code = page.locator(
    'input[autocomplete="one-time-code"]:visible, input[id*="code"]:visible, input[name*="code"]:visible, input[inputmode="numeric"]:visible',
  ).first();
  await expect(code).toBeVisible({ timeout: 25_000 });
  const remainingOperationsTotpMs = 30_000 - (Date.now() % 30_000);
  if (remainingOperationsTotpMs < 10_000) await page.waitForTimeout(remainingOperationsTotpMs + 350);
  await code.fill(totp('MFRGGZDFMZTWQ2LK'));
  const authenticated = (url) => {
    const path = new URL(url).pathname;
    return path.startsWith('/admin') &&
      !['/admin/login', '/admin/multi-factor-authentication', '/admin/password-reset']
        .some((blocked) => path.startsWith(blocked));
  };
  if (!authenticated(page.url())) {
    try {
      await page.waitForURL(authenticated, { timeout: 1_200 });
    } catch {
      if (!authenticated(page.url())) {
        const verify = page.getByRole('button', {
          name: /confirm sign in|verify|continue|sign in/i,
        }).last();
        // A real Filament MFA auto-submit can redirect between the URL
        // check and locating the manual verification button. A disappearing
        // button is success ONLY when the authenticated destination exists.
        try {
          await expect(verify).toBeEnabled({ timeout: 12_000 });
          if (!authenticated(page.url())) await verify.click();
        } catch (error) {
          if (!authenticated(page.url())) throw error;
        }
        await page.waitForURL(authenticated, { timeout: 30_000 });
      }
    }
  }

  // A real MFA-authenticated operations user has no Inventory page role.
  // The server must deny the route, not merely hide a sidebar link.
  const deniedInventory = await page.goto('/admin/hall-inventory');
  expect([403, 404]).toContain(deniedInventory?.status());
  await expect(page.locator('[aria-label="Inventory workflow navigator"]')).toHaveCount(0);
  await page.goto('/admin/internal-search');
  await expect(page.getByText('Private role-scoped reference search')).toBeVisible();
  await page.waitForFunction(() => Boolean(window.Livewire?.all?.().length), null, { timeout: 30_000 });
  const recordType = page.getByRole('combobox', { name: 'Record type' });
  await expect(recordType).toBeVisible();
  // Native <option> elements are correctly hidden until the select opens.
  // Assert the policy-controlled choices and exercise the actual selection.
  await expect(recordType.locator('option[value="planning"]')).toHaveCount(1);
  await expect(recordType.locator('option[value="finance"]')).toHaveCount(0);
  await expect(recordType.locator('option[value="audit"]')).toHaveCount(0);
  await recordType.selectOption('planning');
  await expect(recordType).toHaveValue('planning');
  await page.getByRole('searchbox').fill('stage');
  await page.getByRole('button', { name: 'Search company records' }).click();
  await expect(page.getByText('Synthetic stage lighting checklist')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Open finance workspace' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Open approval workspace' })).toHaveCount(0);

  await page.getByRole('link', { name: 'Open planning workspace' }).click();
  await expect(page).toHaveURL(/\/admin\/operations-planning\?company=/, { timeout: 30_000 });
  await expect(page.getByText('Synthetic stage lighting checklist')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('Internal planning checklist only.')).toBeVisible();
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);

  // A verified operations user may inspect SAVED owner rules but cannot edit
  // them, authorize an extension, or silently produce an invoice or payment.
  await page.goto('/admin/hall-programs');
  const overtime = page.locator('section[aria-label="Nonbinding overtime and turnover preview"]');
  await expect(overtime).toBeVisible({ timeout: 30_000 });
  await page.waitForFunction(() => Boolean(window.Livewire?.all?.().length), null, { timeout: 30_000 });
  await expect(page.getByText('Assigned hall programs (read-only)')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save program rules' })).toHaveCount(0);
  await overtime.getByLabel('Program to check').selectOption('morning');
  await overtime.getByLabel('Simulated end time (same-day hall clock)').fill('11:21');
  await overtime.getByRole('button', { name: 'Calculate nonbinding overtime safety preview' }).click();
  await expect(overtime.getByText('TURNOVER CONFLICT: Extension must NOT be authorized.'))
    .toBeVisible({ timeout: 25_000 });
  await expect(overtime.getByText('Maximum simulated safe end before turnover: 11:20.'))
    .toBeVisible();
  await expect(overtime.getByText('Projected internal amount: PKR 0 minor units (NOT payable).'))
    .toBeVisible();
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);
});
