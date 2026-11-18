import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

import { expect, test, type Page } from "@playwright/test";

import {
  customerPassword,
  registerCustomer,
  verifyCustomer,
} from "./helpers/auth";
import {
  projectRoot,
  resetTestingDatabase,
  testingEnvironment,
} from "./testing-environment";

test.beforeAll(resetTestingDatabase);

const screenshotDirectory = path.join(projectRoot, "tests/e2e/screenshots");

function futureDate(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

async function createBooking(
  page: Page,
  details: { name: string; date: string },
): Promise<string> {
  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
  );
  await page.getByLabel(/nama lengkap|full name/i).fill(details.name);
  await page
    .getByLabel(/nomor whatsapp|whatsapp number/i)
    .fill("+628122223333");
  await page.getByLabel(/^tanggal$|^date$/i).fill(details.date);
  await page
    .getByRole("radiogroup", { name: /^waktu$|^time$/i })
    .getByRole("radio", { name: "10:30" })
    .check();
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();
  await page
    .getByRole("button", { name: /konfirmasi reservasi|confirm booking/i })
    .click();
  await expect(page).toHaveURL(/\/bookings\/SJ-/);
  await expect(page.getByText(details.name, { exact: true })).toBeVisible();

  return new URL(page.url()).pathname.split("/").at(-1) ?? "";
}

async function logout(page: Page): Promise<void> {
  await page.locator('button[aria-controls="account-menu"]').click();
  await page.getByRole("button", { name: /keluar|log out/i }).click();
  await expect(page).toHaveURL("/");
}

test.beforeAll(() => {
  mkdirSync(screenshotDirectory, { recursive: true });
});

test("Today opens on a waiting request that staff approve from the queue", async ({
  page,
}) => {
  const customerEmail = "today-queue-customer@example.com";
  const ownerEmail = "today-queue-owner@example.com";
  const customerName = "Today Queue Customer";

  await registerCustomer(page, customerEmail);
  await verifyCustomer(page, customerEmail);
  const reference = await createBooking(page, {
    name: customerName,
    date: futureDate(3),
  });
  await logout(page);

  await registerCustomer(page, ownerEmail);
  await verifyCustomer(page, ownerEmail);
  execFileSync("php", ["artisan", "owner:grant", ownerEmail, "--yes"], {
    cwd: projectRoot,
    env: { ...process.env, ...testingEnvironment },
    stdio: "pipe",
  });
  await logout(page);

  await page.goto("/admin/login");
  await page.getByLabel(/^email$/i).fill(ownerEmail);
  await page.getByLabel(/^kata sandi$|^password$/i).fill(customerPassword);
  await page.getByRole("button", { name: /^masuk$|^log in$/i }).click();
  await expect(page).toHaveURL(/\/admin/);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto("/admin");

  await expect(
    page.getByRole("heading", { level: 1, name: /^hari ini$|^today$/i }),
  ).toBeVisible();
  // The waiting request is counted on the Bookings link of every admin page.
  await expect(
    page.locator('aside a[href="/admin/bookings"] .admin-nav-badge'),
  ).toHaveText(/^[1-9]\d*$/);

  const queue = page.locator("[data-queue]");
  const row = queue.locator(`[data-booking-row="${reference}"]`);
  await expect(row).toContainText(customerName);
  await expect(row).toContainText(/diminta|requested/i);
  await queue.screenshot({
    path: path.join(screenshotDirectory, "admin-today-queue.png"),
  });

  await row.locator('[data-answer="confirmed"]').click();
  const dialog = page.getByRole("alertdialog");
  await expect(
    dialog.getByRole("heading", {
      name: /setujui reservasi ini\?|approve this booking\?/i,
    }),
  ).toBeFocused();
  await expect(dialog).toContainText(customerName);
  const consent = dialog.locator('input[name="operationalConfirmed"]');
  if (await consent.count()) await consent.check();
  const patch = page.waitForResponse(
    (response) =>
      response.request().method() === "PATCH" &&
      new URL(response.url()).pathname.endsWith("/status"),
  );
  await dialog.locator('button[type="submit"]').click();
  expect((await patch).status()).toBe(303);

  // Staff stay on Today, the request leaves the queue, and the change is announced.
  await expect(dialog).toBeHidden();
  await expect(page).toHaveURL("/admin");
  await expect(row).toHaveCount(0);
  await expect(
    page.getByRole("status").filter({ hasText: /berhasil|updated/i }),
  ).toBeVisible();

  await page.goto(`/admin/bookings/${reference}`);
  await expect(page.locator(".admin-status--confirmed")).toBeVisible();
});
