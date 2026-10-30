import {test, expect} from '@playwright/test';
import {inventoryLogin} from './inventory-auth.mjs';

const OWNER = {
  email: 'browser-e2e-inventory-owner-c@example.test',
  password: 'synthetic-inventory-owner-c-only',
  secret: 'KRSXG5AUMVZWK4TT',
};

test('published venue website renders all approved layouts at desktop tablet and phone widths', async ({browser}) => {
  test.setTimeout(900_000);
  const {ctx, page} = await inventoryLogin(browser, OWNER.email, OWNER.password, OWNER.secret,
    {width: 1280, height: 900});
  await page.request.get('/__test/license-window/valid');
  const layouts = [
    ['editorial', 'theme-editorial'],
    ['minimal', 'theme-minimal'],
    ['classic', 'theme-classic'],
  ];
  const publicPage = await ctx.newPage();
  for (const [preset, bodyClass] of layouts) {
    const published = await page.request.get('/__test/site-layout/'+preset);
    expect(published.ok(), 'Owner publication must succeed for '+preset).toBe(true);
    for (const viewport of [
      {width: 1280, height: 900},
      {width: 768, height: 1024},
      {width: 360, height: 800},
    ]) {
      await publicPage.setViewportSize(viewport);
      await publicPage.goto('http://wizard.localhost:8000/');
      await expect(publicPage.locator('body')).toHaveClass(new RegExp(bodyClass));
      await expect(publicPage.getByRole('heading', {name: 'Phase 10 celebration venue'})).toBeVisible();
      await expect(publicPage.getByRole('link', {name: 'Halls'}).first()).toHaveAttribute('href', '/halls');
      await expect(publicPage.getByText('Synthetic Test Hall').first()).toBeVisible();
      await expect(publicPage.getByText('12 Synthetic Mall Road, Lahore').first()).toBeVisible();
      await expect(publicPage.getByRole('link', {name: /Get directions/i}).first()).toBeVisible();
      await expect(publicPage.getByRole('link', {name: '+92 300 1234567'}).first())
        .toHaveAttribute('href', 'tel:+923001234567');
      await expect(publicPage.getByRole('link', {name: '+92 300 7654321'}).first())
        .toHaveAttribute('href', 'https://wa.me/923007654321');
      const overflow = await publicPage.evaluate(() =>
        document.documentElement.scrollWidth > window.innerWidth + 1);
      expect(overflow, preset+' must not overflow horizontally at '+viewport.width+'px').toBe(false);
    }
  }
  await publicPage.close();
  await ctx.close();
});