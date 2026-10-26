import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
import fs from 'node:fs';

function base32(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = 0;
  let value = 0;
  const bytes = [];
  for (const char of input.toUpperCase().replace(/=+$/, '')) {
    const digit = alphabet.indexOf(char);
    if (digit < 0) throw new Error('Invalid TOTP secret');
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
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30_000)));
  const digest = createHmac('sha1', base32(secret)).update(counter).digest();
  const offset = digest[19] & 0x0f;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

async function waitForLoginHydration(page) {
  await page.waitForFunction(() =>
    Boolean(window.Livewire?.all?.().some(component =>
      component.el?.querySelector?.('input[type="email"]'))),
  null, { timeout: 30_000 });
}

async function signIn(page, email, password) {
  await page.goto('/admin/login');
  await waitForLoginHydration(page);
  await page.locator('input[type="email"]').fill(email);
  await page.getByLabel(/Password/).fill(password);
  await page.getByRole('button', { name: /sign in|log in/i }).first().click();
}

function latestLogUrl(pattern) {
  const log = fs.readFileSync('backend/storage/logs/laravel.log', 'utf8');
  const matches = [...log.matchAll(new RegExp(pattern.source, 'g'))];
  if (matches.length === 0) throw new Error(`No log match for ${pattern}`);
  return matches.at(-1)[0];
}

test('Owner invites staff who accepts verifies email enrols MFA and is revoked', async ({ page }) => {
  test.setTimeout(600_000);
  await page.waitForTimeout(65_000);
  await signIn(page, 'browser-e2e-inventory-owner-c@example.test',
    'synthetic-inventory-owner-c-only');
  const code = page.locator('input[autocomplete="one-time-code"]:visible, input[id*="code"]:visible')
    .first();
  await expect(code).toBeVisible({ timeout: 25_000 });
  await code.fill(totp('KRSXG5AUMVZWK4TT'));
  await page.waitForURL(url => !new URL(url).pathname.startsWith('/admin/login')
    && !new URL(url).pathname.startsWith('/admin/multi-factor-authentication'));

  await page.goto('/admin/staff-management');
  await page.getByLabel('Email').fill('staff-browser@example.test');
  await page.getByLabel('Role').selectOption('operations');
  await page.getByLabel('Reason').fill('Synthetic staff onboarding acceptance');
  await page.getByLabel('Synthetic Test Hall').check();
  await page.getByRole('button', { name: 'Create invitation' }).click();
  await expect(page.getByText('Invitation sent', { exact: false })).toBeVisible({ timeout: 20_000 });

  await page.waitForTimeout(1_500);
  const acceptUrl = latestLogUrl(/http:\/\/127\.0\.0\.1:8000\/staff-invitation\/[a-f0-9]{64}/)
    ;
  const staffContext = await page.context().browser().newContext();
  const staff = await staffContext.newPage();
  await staff.goto(acceptUrl);
  await staff.getByLabel(/Your name/).fill('Synthetic Staff');
  await staff.getByLabel(/^Password/).fill('synthetic-staff-password-only');
  await staff.getByLabel(/Confirm password/).fill('synthetic-staff-password-only');
  await staff.getByRole('button', { name: 'Accept invitation' }).click();
  await expect(staff).toHaveURL(/admin\/login/);

  await signIn(staff, 'staff-browser@example.test', 'synthetic-staff-password-only');
  await staff.waitForTimeout(2_000);
  await staff.waitForTimeout(1_500);
  const verifyUrl = latestLogUrl(/http:\/\/127\.0\.0\.1:8000\/email\/verify\/[^\s"'<>]+/)
    .replaceAll('&amp;', '&');
  await staff.goto(verifyUrl);
  await staff.waitForTimeout(1_500);

  if (staff.url().includes('/admin/login')) {
    await signIn(staff, 'staff-browser@example.test', 'synthetic-staff-password-only');
  }
  await expect(staff.getByText(/multi-factor|two-factor|authenticator|set up/i).first())
    .toBeVisible({ timeout: 25_000 });
  await staff.getByText('Set up', { exact: true }).click();
  await staff.waitForTimeout(5_000);
  const body = await staff.locator('body').innerText();
  const secret = body.match(/\b[A-Z2-7]{16}\b/)?.[0];
  if (!secret) throw new Error('MFA setup secret not shown');
  const setupCode = staff.locator('input[autocomplete="one-time-code"]:visible, input[id*="code"]:visible')
    .first();
  await setupCode.fill(totp(secret));
  const currentPassword = staff.getByLabel(/Current password/);
  if (await currentPassword.isVisible().catch(() => false)) {
    await currentPassword.fill('synthetic-staff-password-only');
  }
  await staff.getByRole('button', { name: /next/i }).last().click();
  await expect(staff.getByRole('button', { name: /enable authenticator app/i }))
    .toBeVisible({ timeout: 20_000 });
  await staff.getByRole('button', { name: /enable authenticator app/i }).click();
  await staff.waitForTimeout(2_000);
  await staff.goto('/admin/hall-calendar');
  await expect(staff.getByRole('heading', { name: 'Hall Calendar' })).toBeVisible({ timeout: 20_000 });

  await page.reload();
  await page.getByLabel('Reason').fill('Staff access revoked by owner');
  await page.getByRole('button', { name: 'Revoke' }).first().click();
  await expect(page.getByText('Invitation revoked', { exact: false })).toBeVisible({ timeout: 15_000 });
  await staff.goto('/admin/hall-calendar');
  await expect(staff.getByRole('heading', { name: 'Hall Calendar' })).toHaveCount(0);
  await staffContext.close();
});
