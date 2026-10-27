import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';

function base32(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = 0; let value = 0; const bytes = [];
  for (const char of input.toUpperCase().replace(/=+$/, '')) {
    const digit = alphabet.indexOf(char);
    value = (value << 5) | digit; bits += 5;
    if (bits >= 8) { bits -= 8; bytes.push((value >>> bits) & 255); }
  }
  return Buffer.from(bytes);
}
function totp(secret) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)));
  const digest = createHmac('sha1', base32(secret)).update(counter).digest();
  const offset = digest[19] & 0x0f;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

test('Owner sees expired license restrictions', async ({ page }) => {
  await page.waitForTimeout(65_000);
  const windowResponse = await page.request.get('/__test/license-window/expired');
  expect(windowResponse.ok()).toBeTruthy();
  test.setTimeout(240_000);
  await page.goto('/admin/login');
  await page.locator('input[type="email"]').fill('browser-e2e-license-expired@example.test');
  await page.getByLabel(/Password/).fill('synthetic-license-expired-only');
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();
  const code = page.locator('input[autocomplete="one-time-code"]:visible').first();
  await expect(code).toBeVisible({ timeout: 25_000 });
  await code.fill(totp('MFRGGZDFMZTWQ2LK'));
  const confirm = page.getByRole('button', { name: 'Confirm sign in' });
  if (await confirm.isVisible().catch(() => false)
      && await confirm.isEnabled().catch(() => false)) {
    await confirm.click();
  }
  await page.waitForURL(url => !new URL(url).pathname.startsWith('/admin/login')
    && !new URL(url).pathname.startsWith('/admin/multi-factor-authentication'), { timeout: 25_000 });
  await page.goto('/admin/licensing');
  await expect(page.getByRole('heading', { name: 'Licensing' })).toBeVisible();
  await expect(page.getByText('expired or not yet valid', { exact: true })).toBeVisible();
  await expect(page.getByText('expired grace', { exact: true })).toHaveCount(0);
  await expect(page.getByText(/Public-site policy: maintenance/i)).toBeVisible();
  await expect(page.getByText(/Dashboard policy: (grace|restricted)/i)).toBeVisible();

  await page.getByRole('button', { name: 'Verify License' }).click();
  await expect(page.getByText('Last authenticated HAFZATECH verification (UTC)'))
    .toBeVisible();
  await expect(page.getByText('Never verified online')).toHaveCount(0, { timeout: 25_000 });
  await expect(page.getByText(/Central check due\s*No/)).toBeVisible();

  const term = page.getByLabel('Requested term');
  if (await term.count()) {
    await term.selectOption('6');
    await page.getByRole('button', { name: 'Send license review request' }).click();
    await expect(page.getByRole('status')).toBeVisible({ timeout: 20_000 });
  } else {
    await expect(page.getByText(/Online renewal requests are not yet enabled/))
      .toBeVisible();
  }
});
