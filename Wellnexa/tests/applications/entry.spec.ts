import { expect, test } from '@playwright/test'
test('opt-in joining link sends applicants to the configured clinic portal', async ({ page }) => {
  await page.route('**/api/public/**', route => route.fulfill({ json: { data: [] } }))
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('http://localhost:5177/join')
  await expect(page.getByRole('link', { name: 'Apply or check your application →' })).toHaveAttribute('href', 'https://wellnexa.copihue.ca/staff/apply')
  await expect(page.getByRole('link', { name: 'Ask about joining →' })).toBeVisible()
  await expect(page.getByText(/Clinic approval and account activation are separate steps/)).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
})
