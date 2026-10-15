import { test, expect } from '@playwright/test';

test('desktop demo adds, drags and previews typed columns without publishing real content', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/website-editor.html');
  await expect(page.getByText('PROTOTYPE ONLY')).toBeVisible();
  await expect(page.locator('#page-preview')).toHaveClass(/desktop/);

  const count = await page.locator('#outline .outline-item').count();
  expect(count).toBeGreaterThan(0);
  await page.locator('#new-section').selectOption('columns');
  await page.locator('#add-section').click();
  await expect(page.locator('#outline .outline-item')).toHaveCount(count + 1);
  await expect(page.locator('#outline .outline-item').last().locator('.outline-head')).toContainText('Column Layout');
  await expect(page.locator('#inspector')).toContainText('Column 1');

  await page.locator('#outline .outline-item').last().dragTo(page.locator('#outline .outline-item').first());
  await expect(page.locator('#outline .outline-item').first().locator('.outline-head')).toContainText('Column Layout');
  await page.locator('#publish').click();
  await expect(page.locator('#editor-status')).toContainText('Published DEMO version');
  await expect(page.locator('#history')).toContainText('Demo publication');
  await expect(page.locator('.editor-banner')).toContainText('No accounts, media, pages or payments are saved');
});

test('phone preview responds to owner branding and keyboard-friendly section arrows', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/website-editor.html');
  await page.locator('button[data-device="mobile"]').click();
  await expect(page.locator('#page-preview')).toHaveClass(/mobile/);
  await page.locator('#brand-name').fill('Silver Celebration Venue');
  await expect(page.locator('#page-preview .mock-brand strong')).toHaveText('Silver Celebration Venue');

  const before = await page.locator('#outline .outline-head').allTextContents();
  expect(before.length).toBeGreaterThan(1);
  const arrow = page.locator('#outline .outline-item').first().getByTitle('Move down');
  await arrow.focus();
  await page.keyboard.press('Enter');
  const after = await page.locator('#outline .outline-head').allTextContents();
  expect(after[0].split('. ')[1]).toBe(before[1].split('. ')[1]);

  await page.locator('#save-draft').click();
  await expect(page.locator('#editor-status')).toContainText('No server save');
});
