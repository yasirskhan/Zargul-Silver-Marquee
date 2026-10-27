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

test('real owner saves and resumes installation wizard without false completion', async ({ page }) => {
  test.setTimeout(300_000);
  await page.waitForTimeout(65_000);
  await page.setViewportSize({ width: 1366, height: 768 });
  await signInWithTotp(
    page, 'browser-e2e-inventory-owner-c@example.test',
    'synthetic-inventory-owner-c-only', 'KRSXG5AUMVZWK4TT',
  );
  await page.goto('/admin/venue-setup');
  await expect(page.getByText('Only an active, verified company Owner can complete this setup.'))
    .toBeVisible({ timeout: 25_000 });

  await page.getByLabel('Legal venue name').fill('Synthetic Wizard Venue');
  await page.getByLabel('Contact email').fill('wizard-owner@example.test');
  await page.getByLabel('ISO operating currency').fill('PKR');
  await page.getByLabel('IANA timezone').selectOption('Asia/Karachi');
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('2. Halls', { exact: true })).toBeVisible({ timeout: 25_000 });

  await page.getByLabel('Hall name').first().fill('Wizard Hall One');
  await page.getByLabel('Hall address').first().fill('First hall address');
  await page.getByLabel('Hall phone').first().fill('+1 555 0101');
  await page.getByLabel('Capacity').first().fill('180');
  await page.getByRole('button', { name: 'Add another hall' }).click();
  await page.getByLabel('Hall name').nth(1).fill('Wizard Hall Two');
  await page.getByLabel('Hall address').nth(1).fill('Second hall address');
  await page.getByLabel('Hall phone').nth(1).fill('+1 555 0102');
  await page.getByLabel('Capacity').nth(1).fill('90');
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('3. Programs', { exact: true })).toBeVisible({ timeout: 25_000 });
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('4. Pricing', { exact: true })).toBeVisible({ timeout: 25_000 });
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('5. Add-ons', { exact: true })).toBeVisible({ timeout: 25_000 });
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('6. Branding', { exact: true })).toBeVisible({ timeout: 25_000 });
  await page.getByLabel('Website display name').fill('Synthetic Wizard Website');
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('7. Payments', { exact: true })).toBeVisible({ timeout: 25_000 });
  await page.getByLabel('Approved deposit percentage (5% where applicable)').fill('5');
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('8. Staff', { exact: true })).toBeVisible({ timeout: 25_000 });
  await page.getByLabel('Staff setup').selectOption('owner_only');
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('9. Licensing', { exact: true })).toBeVisible({ timeout: 25_000 });
  await expect(page.getByRole('heading', { name: 'HAFZATECH installation license' })).toBeVisible();
  await page.getByRole('button', { name: 'Save and continue' }).click();
  await expect(page.getByText('10. Review', { exact: true })).toBeVisible({ timeout: 25_000 });
  await expect(page.getByText('Wizard Hall One')).toBeVisible();
  await expect(page.getByText('Wizard Hall Two')).toBeVisible();
  await expect(page.getByText('Venue setup completed')).toHaveCount(0);

  await page.reload();
  await expect(page.getByText('10. Review', { exact: true })).toBeVisible({ timeout: 25_000 });
  await expect(page.getByText('Wizard Hall One')).toBeVisible();
  await expect(page.getByText('Wizard Hall Two')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Review' })).toBeVisible();
  await expect(page.getByText('Venue setup completed')).toHaveCount(0);
  await page.getByRole('button', { name: 'Complete installation' }).click();
  await expect(page.getByText('Venue setup completed').first()).toBeVisible({ timeout: 25_000 });
  await page.reload();
  await expect(page.getByText('Venue setup completed').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Complete installation' })).toHaveCount(0);
});



