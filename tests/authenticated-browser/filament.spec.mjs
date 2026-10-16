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

test('private proposal route requires authentication and does not reveal customer data', async ({ page }) => {
  await page.goto('/admin/booking-proposals');
  await expect(page).toHaveURL(/\/admin\/login/, { timeout: 20_000 });
  await expect(page.getByText('Synthetic Browser Customer')).toHaveCount(0);
  await expect(page.getByText('browser-e2e-private@example.test')).toHaveCount(0);
});

test('real owner password and TOTP flow protects mobile proposal editing without booking', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/admin/login');
  await expect(page.locator('input[type="email"]')).toBeVisible();
  await page.locator('input[type="email"]').fill('browser-e2e-owner@example.test');
  await page.getByLabel(/Password/).fill('synthetic-browser-password-only');
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();

  const code = page.locator(
    'input[autocomplete="one-time-code"]:visible, input[id*="code"]:visible, input[name*="code"]:visible, input[inputmode="numeric"]:visible',
  ).first();
  await expect(code).toBeVisible({ timeout: 25_000 });
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
      await page.waitForURL(authenticated, { timeout: 30_000 });
    } catch {
      if (!authenticated(page.url())) {
        const verify = page.getByRole('button', {
          name: /confirm sign in|verify|continue|sign in/i,
        }).last();
        await expect(verify).toBeEnabled({ timeout: 30_000 });
        if (!authenticated(page.url())) await verify.click();
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
});
