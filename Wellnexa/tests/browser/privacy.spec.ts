import { expect, test } from '@playwright/test'

test('English privacy statement is public, linked, and names the record boundaries', async ({ page }) => {
  await page.goto('http://localhost:5177/')
  await page.getByRole('contentinfo').getByRole('link', { name: 'Privacy statement' }).click()
  await expect(page).toHaveURL('http://localhost:5177/privacy?lang=en')
  await expect(page.getByRole('heading', { name: 'Privacy statement' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.getByText(/Independent practitioners are responsible for notes and other records they keep separately/)).toBeVisible()
  await expect(page.getByRole('link', { name: 'privacy@copihue.ca' }).first()).toHaveAttribute('href', 'mailto:privacy@copihue.ca')
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Privacy statement' })).toBeVisible()
})

test('French privacy statement and language switch work on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('http://localhost:5177/privacy?lang=fr')
  await expect(page.getByRole('heading', { name: 'Déclaration de confidentialité' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr')
  await expect(page.getByText(/Les praticiens indépendants sont responsables des notes/)).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.getByRole('link', { name: 'Read this statement in English' }).click()
  await expect(page.getByRole('heading', { name: 'Privacy statement' })).toBeVisible()
})

test('practitioner enquiry points to the statement at collection', async ({ page }) => {
  await page.goto('http://localhost:5177/join')
  await expect(page.getByRole('link', { name: 'Read our privacy statement.' })).toHaveAttribute('href', '/privacy?lang=en')
})
