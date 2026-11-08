import { test, expect } from '@playwright/test';
import { createHmac } from 'node:crypto';
const SECRET='JBSWY3DPEHPK3PXP';
function totp(){const c=Buffer.alloc(8);c.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const d=createHmac('sha1',Buffer.from(SECRET)).update(c).digest();const o=d[19]&15;
 return String((d.readUInt32BE(o)&0x7fffffff)%1000000).padStart(6,'0');}
test('authenticated Central operator journey through the real entrypoint', async ({ page, request, context }) => {
  await page.goto('/staff/login');
  await expect(page.getByRole('heading', { name: 'HAFZATECH staff' })).toBeVisible();
  const login = await request.post('/staff/session/login', {
    headers: { Origin: 'https://lichall.hafzatech.com', 'Sec-Fetch-Site': 'same-origin' },
    form: { email: 'central-browser@example.test',
      password: 'central-browser-password', totp: totp() } });
  console.log('LOGIN_STATUS', login.status(), (await login.text()).slice(0,300));
  expect(login.status()).toBe(200);
  const setCookie = login.headers()['set-cookie'] || '';
  expect(setCookie).toContain('__Host-HafzatechStaff=');
  expect(setCookie).toContain('Secure');
  expect(setCookie).toContain('HttpOnly');
  expect(setCookie).toContain('SameSite=Strict');
  const bearer = setCookie.match(/__Host-HafzatechStaff=([a-f0-9]{64})/)?.[1];
  expect(bearer).toBeTruthy();
  await context.addCookies([{ name: '__Host-HafzatechStaff', value: bearer,
    domain: '127.0.0.1', path: '/', secure: false, httpOnly: true, sameSite: 'Strict' }]);
  await page.goto('/staff/home');
  await expect(page).toHaveURL(/\/staff\/home$/);
  await expect(page.getByRole('link', { name: 'Customers & installations' })).toBeVisible();
  await expect(page.locator('link[rel="stylesheet"]')).toHaveAttribute('href','/staff.css');
  await page.screenshot({ path: 'docs/evidence/central-home-desktop.png', fullPage: true });
  await page.getByRole('link', { name: 'Customers & installations' }).click();
  await expect(page.getByText('Synthetic Central Venue').first()).toBeVisible();
  await expect(page.getByText('Foreign Central Venue')).toHaveCount(0);
  await page.screenshot({ path: 'docs/evidence/central-directory-desktop.png', fullPage: true });
  await page.getByRole('link', { name: 'Open workspace' }).first().click();
  await expect(page.getByText('central-browser.example.test').first()).toBeVisible();
  await expect(page.getByText('Registered installations')).toBeVisible();
  await page.screenshot({ path: 'docs/evidence/central-customer-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'docs/evidence/central-customer-mobile.png', fullPage: true });
  const foreign = await page.goto('/staff/customers/22222222-2222-4222-8222-222222222222');
  expect(foreign.status()).toBeGreaterThanOrEqual(400);
  await expect(page.getByText('Foreign Central Venue')).toHaveCount(0);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/staff/home');
  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByText(/signed out|HAFZATECH staff sign in/i)).toBeVisible({ timeout: 20000 });
});