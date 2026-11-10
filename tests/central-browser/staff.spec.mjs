import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
const SECRET='JBSWY3DPEHPK3PXP';
function totp(secret){const c=Buffer.alloc(8);c.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const d=createHmac('sha1',Buffer.from(secret)).update(c).digest();const o=d[19]&15;
 return String((d.readUInt32BE(o)&0x7fffffff)%1000000).padStart(6,'0');}
async function login(page, email, secret) {
  await page.goto('/staff/login');
  await expect(page.getByRole('heading', { name: 'HAFZATECH staff' })).toBeVisible();
  await page.getByLabel('Staff email').fill(email);
  await page.getByLabel('Password').fill('central-browser-password');
  await page.getByLabel('Authenticator code').fill(totp(secret));
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByRole('link', { name: 'Open Central Control Center' })).toBeVisible({ timeout: 20000 });
  await page.getByRole('link', { name: 'Open Central Control Center' }).click();
  await expect(page).toHaveURL(/\/staff\/home$/);
}
test('authenticated Central operator journey through the real entrypoint', async ({ page }) => {
  await login(page, 'central-browser@example.test', 'JBSWY3DPEHPK3PXP');
  await expect(page.getByRole('link', { name: 'Customers & installations' })).toBeVisible();
  await expect(page.locator('link[rel="stylesheet"]')).toHaveAttribute('href','/staff.css');
  await page.screenshot({ path: 'docs/evidence/central-home-desktop.png', fullPage: true });
  await page.getByRole('link', { name: 'Customers & installations' }).click();
  await expect(page.getByText('Synthetic Central Venue').first()).toBeVisible();
  await expect(page.getByText('Foreign Central Venue')).toHaveCount(0);
  await page.screenshot({ path: 'docs/evidence/central-directory-desktop.png', fullPage: true });
  await page.getByRole('link', { name: 'Open workspace' }).first().click();
  await expect(page.getByText('central-browser.example.test').first()).toBeVisible();
  await expect(page.locator('section[aria-label="Registered installations"]')).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-customer-desktop.png', fullPage: true });
  await page.getByRole('link', { name: 'Licensing and renewals' }).click();
  await expect(page.locator('section[aria-label="Private HAFZATECH customer licenses"]')).toBeVisible();
  await expect(page.getByText('central-browser.example.test').first()).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-licensing-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'docs/evidence/central-customer-mobile.png', fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto('/staff/plans');
  await expect(page.locator('section[aria-label="Private HAFZATECH plan catalog"]')).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-plans-desktop.png', fullPage: true });
  await page.getByLabel('Plan code').fill('browser_plan');
  await page.getByLabel('Price in minor units').fill('12000');
  await page.getByRole('button', { name: 'Publish next plan version' }).click();
  await expect(page.getByText('Plan version published')).toBeVisible({ timeout: 20000 });
  await page.goto('/staff/plans');
  await expect(page.getByText('browser_plan').first()).toBeVisible({ timeout: 20000 });
  await page.screenshot({ path: 'docs/evidence/central-plan-published-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/staff/plans');
  await page.screenshot({ path: 'docs/evidence/central-plans-mobile.png', fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto('/staff/team');
  await expect(page.locator('section[aria-label="Private HAFZATECH team dashboard"]')).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-team-desktop.png', fullPage: true });
  await page.getByLabel('Staff email').fill('central-pending@example.test');
  await page.getByLabel('Display name').fill('Pending Central Operator');
  await page.getByLabel('Reason').fill('Browser acceptance for pending staff');
  await page.getByRole('button', { name: 'Prepare pending staff identity' }).click();
  await expect(page.getByText('Pending staff identity prepared')).toBeVisible({ timeout: 20000 });
  await page.goto('/staff/staff');
  await expect(page.getByText('central-pending@example.test')).toBeVisible({ timeout: 20000 });
  await page.screenshot({ path: 'docs/evidence/central-staff-desktop.png', fullPage: true });

  await page.goto('/staff/audit');
  await expect(page.locator('section[aria-label="Private HAFZATECH staff audit"]')).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-audit-desktop.png', fullPage: true });

  const foreign = await page.goto('/staff/customers/22222222-2222-4222-8222-222222222222');
  expect(foreign.status()).toBeGreaterThanOrEqual(400);
  await expect(page.getByText('Foreign Central Venue')).toHaveCount(0);
  await page.goto('/staff/home');
  await page.getByRole('button', { name: 'Sign out' }).first().click();
  await expect(page.getByText(/signed out|HAFZATECH staff sign in/i)).toBeVisible({ timeout: 20000 });
});
test('separate billing operator sees invoices and support but not staff administration', async ({ page }) => {
  await login(page, 'central-billing@example.test', 'JBSWY3DPEHPK3PXP');
  await page.goto('/staff/customers/11111111-1111-4111-8111-111111111111/billing');
  await expect(page.locator('section[aria-label="Private HAFZATECH vendor invoices"]')).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-billing-desktop.png', fullPage: true });
  await page.goto('/staff/customers/11111111-1111-4111-8111-111111111111/support');
  await expect(page.locator('section[aria-label="Private HAFZATECH support queue"]')).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-support-desktop.png', fullPage: true });
  const denied = await page.goto('/staff/team');
  expect(denied.status()).toBeGreaterThanOrEqual(400);
  await expect(page.getByText('Private HAFZATECH team dashboard')).toHaveCount(0);
});



test('role request requires an independent approver and never self-approves', async ({ page }) => {
  await login(page, 'central-requester@example.test', 'KRSXG5AUMVZWK4TT');
  await page.goto('/staff/team');
  await page.getByLabel('Target staff ID').fill('abababab-abab-4bab-8bab-abababababab');
  await page.getByLabel('Requested role').selectOption('support_agent');
  await page.getByLabel('Customer scope ID').fill('11111111-1111-4111-8111-111111111111');
  await page.getByLabel('Reason').fill('Independent browser role approval fixture');
  await page.getByRole('button', { name: 'Submit for independent approval' }).click();
  await expect(page.getByText('Role proposal recorded')).toBeVisible({ timeout: 20000 });
  await page.goto('/staff/team');
  await page.getByRole('button', { name: 'Pending independent role reviews' }).click();
  await expect(page.getByText('central-target@example.test')).toHaveCount(0);
  await page.goto('/staff/home');
  await page.getByRole('button', { name: 'Sign out' }).first().click();
  await expect(page.getByText(/signed out|HAFZATECH staff sign in/i)).toBeVisible({ timeout: 20000 });

  await login(page, 'central-approver@example.test', 'MZXW6YTBOI234567');
  await page.goto('/staff/team');
  await page.getByRole('button', { name: 'Pending independent role reviews' }).click();
  await expect(page.getByText('central-target@example.test')).toBeVisible({ timeout: 20000 });
  const decisionRow = page.locator('tr', { hasText: 'central-target@example.test' });
  await decisionRow.getByLabel('Outcome').selectOption('approved');
  await decisionRow.getByLabel('Decision reason').fill('Independent browser approval');
  await decisionRow.getByRole('button', { name: 'Record decision' }).click();
  await expect(page.getByText(/Staff role request approved/i)).toBeVisible({ timeout: 20000 });
  await page.goto('/staff/team');
  await page.getByRole('button', { name: 'Pending independent role reviews' }).click();
  await expect(page.getByText('central-target@example.test')).toHaveCount(0);
  await page.goto('/staff/staff');
  await expect(page.getByText('central-target@example.test')).toBeVisible();

  await page.goto('/staff/home');
  await page.getByRole('button', { name: 'Sign out' }).first().click();
  await page.goto('/staff/login');
  await page.getByLabel('Staff email').fill('central-target@example.test');
  await page.getByLabel('Password').fill('central-browser-password');
  await page.getByLabel('Authenticator code').fill(totp('JBSWY3DPEHPK3PXP'));
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByRole('link', { name: 'Open Central Control Center' })).toHaveCount(0);
});
