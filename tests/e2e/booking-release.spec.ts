import { execFileSync } from "node:child_process";

import { expect, test, type Page } from "@playwright/test";

import { registerCustomer, verifyCustomer } from "./helpers/auth";
import {
  projectRoot,
  resetTestingDatabase,
  testingEnvironment,
} from "./testing-environment";

test.beforeAll(resetTestingDatabase);

// The pay-at-spa release, end to end in the browser: what the customer is told, what staff decide, and what
// reaches the customer afterwards. Room scheduling stays off, as it does until the spa switches it on.
test.skip(
  process.env.E2E_BOOKING_SCHEDULING_ENABLED === "true",
  "This journey runs with room scheduling off; room-approval.spec.ts covers it on.",
);

function artisan(...args: string[]): void {
  execFileSync("php", ["artisan", ...args], {
    cwd: projectRoot,
    env: { ...process.env, ...testingEnvironment },
    stdio: "pipe",
  });
}

async function requestNezuko(
  page: Page,
  date: string,
  time: string,
): Promise<string> {
  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
  );
  // E1b: the number is optional and is not how the booking gets confirmed.
  await expect(page.locator("[data-booking-contact-note]")).toHaveText(
    /Staff may use this number|Staf dapat menggunakan nomor ini/,
  );
  await page.getByLabel(/nama lengkap|full name/i).fill("Ayu Lestari");
  await page
    .getByLabel(/nomor whatsapp|whatsapp number/i)
    .fill("+6281234567890");
  await page.getByLabel(/^tanggal$|^date$/i).fill(date);
  await page
    .getByRole("radiogroup", { name: /^waktu$|^time$/i })
    .getByRole("radio", { name: time })
    .check();
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();
  await page
    .getByRole("button", { name: /konfirmasi reservasi|confirm booking/i })
    .click();
  await expect(page).toHaveURL(/\/bookings\/SJ-/);

  return new URL(page.url()).pathname.split("/").at(-1) ?? "";
}

async function decide(
  staff: Page,
  reference: string,
  next: "confirmed" | "cancelled",
  reason?: string,
): Promise<void> {
  await staff.goto(`/admin/bookings/${reference}`);
  await staff.locator(`[data-next-status="${next}"]`).click();
  const dialog = staff.getByRole("alertdialog");
  if (reason) await dialog.getByLabel(/reason|alasan/i).fill(reason);
  await dialog.locator('button[type="submit"]').click();
  await expect(dialog).toBeHidden();
  await expect(staff.locator(`.admin-status--${next}`)).toBeVisible();
}

test("a pay-at-spa request reaches staff, and every decision reaches the customer", async ({
  page: customer,
  browser,
}) => {
  const customerEmail = "release-customer@example.com";
  const ownerEmail = "release-owner@example.com";

  // The customer reads the cancellation rule, then sends two requests.
  await registerCustomer(customer, customerEmail);
  await verifyCustomer(customer, customerEmail);
  await customer.goto("/");
  await expect(customer.locator("#faq")).toContainText(
    /make a new booking and ask us to cancel the old one|buat reservasi baru dan minta kami membatalkan reservasi lama/,
  );

  const kept = await requestNezuko(customer, "2099-10-06", "10:30");
  await expect(customer.locator(".booking-status")).toHaveText(
    /menunggu|pending/i,
  );
  await expect(customer.locator("[data-booking-status-note]")).toBeVisible();
  await expect(customer.locator("[data-booking-pay-at-spa]")).toBeVisible();
  await expect(customer.locator("[data-booking-changes]")).toContainText(
    /make a new booking and ask the spa to cancel this one|buat reservasi baru dan minta spa membatalkan reservasi ini/,
  );
  const declined = await requestNezuko(customer, "2099-10-07", "11:30");

  // The owner approves one request and declines the other from the dashboard.
  const staffContext = await browser.newContext();
  const staff = await staffContext.newPage();
  await registerCustomer(staff, ownerEmail);
  await verifyCustomer(staff, ownerEmail);
  artisan("owner:grant", ownerEmail, "--yes");
  await staff.goto("/admin/bookings");
  await staff.locator('[data-admin-locale="en"]').click();
  await expect(staff.getByRole("heading", { name: "Bookings" })).toBeVisible();

  await decide(staff, kept, "confirmed");
  await decide(staff, declined, "cancelled", "Fully booked that afternoon");

  // The scheduler's outbox run: the staff message is processed, and the customer email is skipped because
  // customer emails are switched off here.
  artisan("outbox:dispatch");
  await staff.goto(`/admin/bookings/${kept}`);
  await expect(staff.locator("[data-notice]")).toHaveCount(2);
  await expect(
    staff.locator('[data-notice][data-notice-state="completed"]'),
  ).toContainText("Processed");
  await expect(
    staff.locator('[data-notice][data-notice-state="skipped"]'),
  ).toContainText("Not sent: customer emails are switched off");

  // The customer sees each decision on their booking page.
  await customer.goto(`/bookings/${kept}`);
  await expect(customer.locator(".booking-status")).toHaveText(
    /terkonfirmasi|confirmed/i,
  );
  await expect(customer.locator("[data-booking-pay-at-spa]")).toBeVisible();
  await expect(customer.locator("[data-booking-changes]")).toBeVisible();

  await customer.goto(`/bookings/${declined}`);
  await expect(customer.locator(".booking-status")).toHaveText(
    /dibatalkan|cancelled/i,
  );
  await expect(customer.locator("[data-booking-status-note]")).toBeVisible();
  await expect(customer.locator("[data-booking-pay-at-spa]")).toHaveCount(0);
  await expect(customer.locator("[data-booking-changes]")).toHaveCount(0);
  await expect(customer.getByText("Fully booked that afternoon")).toHaveCount(
    0,
  );

  // The customer asks by WhatsApp to cancel; staff record it, and the booking page follows.
  await decide(staff, kept, "cancelled", "Guest asked by WhatsApp to cancel");
  await customer.reload();
  await expect(customer.locator(".booking-status")).toHaveText(
    /dibatalkan|cancelled/i,
  );
  await expect(customer.locator("[data-booking-changes]")).toHaveCount(0);

  // A walk-in is recorded and approved in one step.
  await staff.goto("/admin/bookings/create");
  await staff.locator("#staff-source").selectOption("walk_in");
  await staff.locator("#staff-name").fill("Walk-in Guest");
  await staff.locator("#staff-phone").fill("+628111222333");
  await staff.locator('[data-mode="regular"]').click();
  await staff.locator('[data-duration="90"]').click();
  await staff.locator("#staff-date").fill("2099-10-08");
  await staff.locator('#staff-time input[value="10:30"]').check();
  await staff.locator("#staff-approve").check();
  await staff.getByRole("button", { name: "Save and approve" }).click();
  await expect(staff).toHaveURL(/\/admin\/bookings\/SJ-/);
  await expect(staff.locator(".admin-status--confirmed")).toBeVisible();
  await expect(staff.getByText(/^Walk-in\s+· entered by /)).toBeVisible();

  // The walk-in has no email. With customer emails off its notice is skipped as switched off, so the page
  // asks no one to phone the guest: the operations guide says the reminder appears only when emails are on.
  artisan("outbox:dispatch");
  await staff.reload();
  await expect(
    staff.locator('[data-notice][data-notice-state="skipped"]'),
  ).toContainText("Not sent: customer emails are switched off");
  await expect(staff.locator("[data-contact-task]")).toHaveCount(0);

  await staffContext.close();
});
