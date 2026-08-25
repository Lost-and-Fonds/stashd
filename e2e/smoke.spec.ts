import { expect, test } from '@playwright/test'

const admin = { username: 'e2e-owner', password: 'e2e-password' }

test('UI-v2 boots, authenticates, navigates, and survives a hard reload', async ({ page }) => {
  const consoleErrors: string[] = []
  let protectedRequestsBeforeSetup = 0
  page.on('request', request => {
    if (/\/api\/v1\/(stashes|system\/health|jobs|activity)/.test(new URL(request.url()).pathname)) protectedRequestsBeforeSetup++
  })
  page.on('console', message => {
    // The auth guard probes /auth/me while signed out; its expected 401/403 is
    // reported by Chromium as a failed resource, not as an application error.
    const expectedAuthProbe = message.text().includes('status of 401') || message.text().includes('status of 403')
    if (message.type() === 'error' && !expectedAuthProbe) consoleErrors.push(message.text())
  })
  page.on('pageerror', error => consoleErrors.push(String(error)))

  await page.goto('/')
  await expect(page).toHaveURL(/\/login(?:\?.*)?$/)
  await expect(page.getByRole('button', { name: 'Create admin' })).toBeVisible()
  expect(protectedRequestsBeforeSetup).toBe(0)

  await page.goto('/stashes')
  await expect(page).toHaveURL(/\/login(?:\?.*)?$/)
  await expect(page.getByRole('button', { name: 'Create admin' })).toBeVisible()
  expect(protectedRequestsBeforeSetup).toBe(0)

  await page.getByLabel('Username').fill(admin.username)
  await page.getByLabel('Password').fill(admin.password)
  await page.getByRole('button', { name: 'Create admin' }).click()

  await page.waitForURL('**/stashes')
  await expect(page.getByRole('heading', { name: 'Stashes' })).toBeVisible()

  await page.goto('/status')
  await expect(page.getByRole('heading', { name: 'Status' })).toBeVisible()

  await page.reload()
  await expect(page).toHaveURL(/\/status$/)
  await expect(page.getByRole('heading', { name: 'Status' })).toBeVisible()
  expect(consoleErrors).toEqual([])
})
