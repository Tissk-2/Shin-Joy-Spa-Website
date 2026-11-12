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
): Promise<void> {
  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
  );
  await page.getByLabel(/nama lengkap|full name/i).fill(details.name);
  await page.getByLabel(/nomor whatsapp|whatsapp number/i).fill("+628122223333");
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
}

async function logout(page: Page): Promise<void> {
  await page.locator('button[aria-controls="account-menu"]').click();
  await page.getByRole("button", { name: /keluar|log out/i }).click();
  await expect(page).toHaveURL("/");
}

test.beforeAll(() => {
  mkdirSync(screenshotDirectory, { recursive: true });
});

test("dashboard upcoming panel shows a future customer booking", async ({
  page,
}) => {
  const customerEmail = "dashboard-upcoming-customer@example.com";
  const adminEmail = "dashboard-upcoming-admin@example.com";
  const customerName = "Dashboard Upcoming Customer";

  await registerCustomer(page, customerEmail);
  await verifyCustomer(page, customerEmail);
  await createBooking(page, {
    name: customerName,
    date: futureDate(3),
  });
  await logout(page);

  await registerCustomer(page, adminEmail);
  await verifyCustomer(page, adminEmail);
  execFileSync("php", ["artisan", "admin:grant", adminEmail, "--yes"], {
    cwd: projectRoot,
    env: { ...process.env, ...testingEnvironment },
    stdio: "pipe",
  });
  await logout(page);

  await page.goto("/admin/login");
  await expect(page).toHaveURL("/login");
  await page.getByLabel(/^email$/i).fill(adminEmail);
  await page.getByLabel(/^kata sandi$|^password$/i).fill(customerPassword);
  await page.getByRole("button", { name: /^masuk$|^log in$/i }).click();
  await expect(page).toHaveURL("/admin/bookings");
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto("/admin");

  const upcoming = page.locator('[data-dash-panel="upcoming"]');
  await expect(upcoming.getByRole("heading", { name: /reservasi mendatang|upcoming bookings/i })).toBeVisible();
  await expect(upcoming.getByText(customerName, { exact: true })).toBeVisible();
  await expect(upcoming.getByText("Nezuko", { exact: true })).toBeVisible();
  await upcoming.screenshot({
    path: path.join(screenshotDirectory, "admin-dashboard-upcoming.png"),
  });
});
