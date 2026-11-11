import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

import {
  expect,
  test,
  type Locator,
  type Page,
  type Request,
} from "@playwright/test";

import {
  customerPassword,
  login,
  registerCustomer,
  verifyCustomer,
} from "./helpers/auth";
import { projectRoot, testingEnvironment } from "./testing-environment";

const screenshotsDirectory = path.join(
  projectRoot,
  ".superpowers/sdd/2026-09-07-admin-booking-console/task-11-screenshots",
);
const bookingReferencePattern = /^SJ-[0-9A-HJKMNP-TV-Z]{26}$/;

async function createNezukoBooking(
  page: Page,
  details: { name: string; phone: string; date: string; time: string },
): Promise<void> {
  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
  );
  await page.getByLabel(/nama lengkap|full name/i).fill(details.name);
  await page.getByLabel(/nomor whatsapp|whatsapp number/i).fill(details.phone);
  await page.getByLabel(/^tanggal$|^date$/i).fill(details.date);
  await page.getByLabel(/^waktu$|^time$/i).selectOption(details.time);
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();
  await page
    .getByRole("button", { name: /konfirmasi reservasi|confirm booking/i })
    .click();
  await expect(page).toHaveURL(/\/bookings\/SJ-/);
}

async function logout(page: Page): Promise<void> {
  await page.locator('button[aria-controls="account-menu"]').click();
  await page.getByRole("button", { name: /keluar|log out/i }).click();
  await expect(page).toHaveURL("/");
}

async function expectNoHorizontalPageOverflow(page: Page): Promise<void> {
  const metrics = await page.evaluate(() => {
    const clientWidth = document.documentElement.clientWidth;
    const overflowingElements = [...document.body.querySelectorAll("*")]
      .map((element) => ({
        element: `${element.tagName.toLowerCase()}${
          element.id ? `#${element.id}` : ""
        }${
          typeof element.className === "string" && element.className
            ? `.${element.className.trim().replaceAll(/\s+/g, ".")}`
            : ""
        }`,
        left: Math.round(element.getBoundingClientRect().left),
        right: Math.round(element.getBoundingClientRect().right),
        clientWidth: (element as HTMLElement).clientWidth,
        scrollWidth: (element as HTMLElement).scrollWidth,
      }))
      .filter(
        ({ left, right, clientWidth: elementWidth, scrollWidth }) =>
          left < -1 ||
          right > clientWidth + 1 ||
          scrollWidth > elementWidth + 1,
      )
      .slice(0, 10);

    return {
      clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      overflowingElements,
    };
  });

  expect(
    metrics.scrollWidth,
    JSON.stringify(metrics, null, 2),
  ).toBeLessThanOrEqual(metrics.clientWidth);
}

async function expectFitsViewport(locator: Locator, page: Page): Promise<void> {
  await expect(locator).toBeVisible();
  const box = await locator.boundingBox();
  const viewport = page.viewportSize();

  expect(box).not.toBeNull();
  expect(viewport).not.toBeNull();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(viewport!.width);
  expect(box!.y).toBeGreaterThanOrEqual(0);
  expect(box!.y + box!.height).toBeLessThanOrEqual(viewport!.height);
}

async function expectDialogIsolation(page: Page): Promise<void> {
  const dialog = page.getByRole("alertdialog");
  const heading = dialog.getByRole("heading", {
    name: /konfirmasi perubahan status|confirm status change/i,
  });

  await expect(dialog).toBeVisible();
  await expect(heading).toBeFocused();
  await expect(page.locator("#app")).toHaveAttribute("aria-hidden", "true");

  for (let press = 0; press < 6; press += 1) {
    await page.keyboard.press(press === 0 ? "Shift+Tab" : "Tab");
    expect(
      await page.evaluate(
        () => document.activeElement?.closest('[role="alertdialog"]') !== null,
      ),
    ).toBe(true);
  }
}

function statusPatchRequestsOnly(requests: Request[]): Request[] {
  return requests.filter(
    (request) =>
      request.method() === "PATCH" &&
      new URL(request.url()).pathname.endsWith("/status"),
  );
}

test.beforeAll(() => {
  mkdirSync(screenshotsDirectory, { recursive: true });
});

test("administrator finds and confirms a real customer booking", async ({
  page,
  context,
}) => {
  const customerEmail = "admin-flow-customer@example.com";
  const adminEmail = "admin-flow-staff@example.com";
  const customerName = "Browser Booking Customer";
  const customerPhone = "+628122223333";
  const appointmentDate = "2099-09-06";

  await registerCustomer(page, customerEmail);
  await verifyCustomer(page, customerEmail);
  await createNezukoBooking(page, {
    name: customerName,
    phone: customerPhone,
    date: appointmentDate,
    time: "10:30",
  });

  const reference = new URL(page.url()).pathname.split("/").at(-1) ?? "";
  expect(reference).toMatch(bookingReferencePattern);
  await expect(page.locator(".booking-status")).toHaveText(/menunggu|pending/i);
  await expect(
    page.getByRole("heading", { name: /riwayat status|status history/i }),
  ).toHaveCount(0);
  await logout(page);

  await registerCustomer(page, adminEmail);
  await verifyCustomer(page, adminEmail);
  execFileSync("php", ["artisan", "admin:grant", adminEmail, "--yes"], {
    cwd: projectRoot,
    env: { ...process.env, ...testingEnvironment },
    stdio: "pipe",
  });
  execFileSync(
    "php",
    ["tests/e2e/fixtures/staff-permissions.php", "booking-console-staff"],
    {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      stdio: "pipe",
    },
  );
  await logout(page);

  await page.goto("/admin/login?redirect=https://attacker.example");
  await expect(page).toHaveURL("/login");
  await page.getByLabel(/^email$/i).fill(adminEmail);
  await page.getByLabel(/^kata sandi$|^password$/i).fill(customerPassword);
  await page.getByRole("button", { name: /^masuk$|^log in$/i }).click();
  await expect(page).toHaveURL("/admin/bookings");
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:8000");

  await expect(page.getByRole("heading", { name: "Reservasi" })).toBeVisible();
  await page.locator('[data-admin-locale="en"]').click();
  await expect(page.getByRole("heading", { name: "Bookings" })).toBeVisible();

  await page.setViewportSize({ width: 375, height: 812 });
  await expectNoHorizontalPageOverflow(page);
  await expect(page.locator(".admin-booking-cards")).toBeVisible();
  await expect(page.getByLabel(/search/i)).toBeEditable();
  await expect(page.getByLabel(/^status$/i)).toBeEnabled();
  await expect(page.getByLabel(/from date/i)).toBeEditable();
  await expect(page.getByLabel(/to date/i)).toBeEditable();

  await page.getByRole("button", { name: "All", exact: true }).click();
  await page.getByLabel(/search/i).fill(customerEmail);
  await page.getByLabel(/^status$/i).selectOption("pending");
  await page.getByLabel(/from date/i).fill("2099-09-01");
  await page.getByLabel(/to date/i).fill("2099-09-30");
  await page.getByRole("button", { name: /apply filters/i }).click();
  await expect(page).toHaveURL(/dateTo=2099-09-30/);

  const filteredUrl = new URL(page.url());
  expect(Object.fromEntries(filteredUrl.searchParams)).toEqual({
    scope: "all",
    status: "pending",
    q: customerEmail,
    dateFrom: "2099-09-01",
    dateTo: "2099-09-30",
  });
  const mobileBookings = page.locator(".admin-booking-cards");
  await expect(
    mobileBookings.getByText(reference, { exact: true }),
  ).toHaveCount(1);
  await expect(
    mobileBookings.getByText(customerEmail, { exact: true }),
  ).toHaveCount(1);
  await page.screenshot({
    path: path.join(screenshotsDirectory, "admin-index-375.png"),
    fullPage: true,
  });

  await page.getByRole("link", { name: new RegExp(reference) }).click();
  await expect(page).toHaveURL(`/admin/bookings/${reference}`);
  await expectNoHorizontalPageOverflow(page);
  await expect(page.getByText(customerName, { exact: true })).toBeVisible();
  await expect(page.getByText(customerEmail, { exact: true })).toBeVisible();
  await expect(page.getByText(customerPhone, { exact: true })).toBeVisible();
  await expect(page.getByText("Nezuko", { exact: true })).toBeVisible();
  await expect(
    page.getByText(/nezuko-regular-90.*90 min.*Rp 265\.000/i),
  ).toBeVisible();
  await expect(
    page.getByText(/nezuko-regular-extra-60.*60 min.*Rp 75\.000/i),
  ).toBeVisible();
  await expect(page.getByText(/150 min/i)).toBeVisible();
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();

  const backUrl = new URL(
    (await page.locator("[data-back-bookings]").getAttribute("href")) ?? "",
    page.url(),
  );
  expect(backUrl.pathname).toBe("/admin/bookings");
  expect(Object.fromEntries(backUrl.searchParams)).toEqual({
    scope: "all",
    status: "pending",
    q: customerEmail,
    dateFrom: "2099-09-01",
    dateTo: "2099-09-30",
  });
  await page.screenshot({
    path: path.join(screenshotsDirectory, "admin-detail-375.png"),
    fullPage: true,
  });

  const stalePage = await context.newPage();
  await stalePage.goto(`/admin/bookings/${reference}`);
  await expect(stalePage.locator(".admin-status--pending")).toBeVisible();

  await page.locator('[data-admin-locale="id"]').click();
  await expect(page.getByRole("heading", { name: reference })).toBeVisible();
  await expect(page.getByText("Data tamu", { exact: true })).toBeVisible();

  const confirmedTrigger = page.locator('[data-next-status="confirmed"]');
  await confirmedTrigger.click();
  await expectDialogIsolation(page);
  await expectNoHorizontalPageOverflow(page);
  await expectFitsViewport(page.getByRole("alertdialog"), page);
  await page.screenshot({
    path: path.join(screenshotsDirectory, "admin-dialog-375.png"),
  });
  await page.keyboard.press("Escape");
  await expect(page.getByRole("alertdialog")).toBeHidden();
  await expect(confirmedTrigger).toBeFocused();

  const firstPageRequests: Request[] = [];
  page.on("request", (request) => firstPageRequests.push(request));
  await confirmedTrigger.click();
  const patch = page.waitForResponse(
    (response) =>
      response.request().method() === "PATCH" &&
      new URL(response.url()).pathname.endsWith("/status"),
  );
  await page.getByRole("button", { name: /^konfirmasi perubahan$/i }).click();
  expect((await patch).status()).toBe(303);
  expect(statusPatchRequestsOnly(firstPageRequests)).toHaveLength(1);
  await expect(page.locator(".admin-status--confirmed")).toBeVisible();

  await stalePage.locator('[data-next-status="confirmed"]').click();
  const stalePatch = stalePage.waitForResponse(
    (response) =>
      response.request().method() === "PATCH" &&
      new URL(response.url()).pathname.endsWith("/status"),
  );
  await stalePage
    .getByRole("button", {
      name: /^confirm change$|^konfirmasi perubahan$/i,
    })
    .click();
  expect((await stalePatch).status()).toBe(303);
  await expect(
    stalePage.getByText(
      /status (reservasi ini telah berubah|has changed).*?(muat ulang|refresh)/i,
    ),
  ).toBeVisible();
  await expect(
    stalePage.locator("[data-status-confirmation] [role=alert]").last(),
  ).toBeFocused();
  await stalePage.close();

  await page.reload();
  // Notices share the history item's class, so they are counted apart.
  const historyItems = page.locator(".admin-history-item:not([data-notice])");
  await expect(historyItems).toHaveCount(1);
  await expect(historyItems).toContainText(
    /menunggu.*terkonfirmasi|pending.*confirmed/i,
  );
  // The approval's two notices: the staff Telegram message and the customer email.
  await expect(page.locator("[data-notice]")).toHaveCount(2);

  // Chromium does not expose a stable browser-chrome Ctrl+ zoom API. A 640 CSS
  // pixel viewport is the reflow equivalent of a 1280 px viewport at 200% zoom.
  const zoomPage = await context.newPage();
  await zoomPage.setViewportSize({ width: 640, height: 720 });
  await zoomPage.goto(
    `/admin/bookings?scope=all&q=${encodeURIComponent(reference)}`,
  );
  expect(await zoomPage.evaluate(() => window.innerWidth)).toBe(640);
  await expectNoHorizontalPageOverflow(zoomPage);
  await expect(zoomPage.getByLabel(/cari|search/i)).toBeEditable();
  await zoomPage.getByLabel(/cari|search/i).fill(reference);
  await zoomPage
    .getByRole("button", { name: /terapkan filter|apply filters/i })
    .click();
  await zoomPage.getByRole("link", { name: new RegExp(reference) }).click();
  await expect(zoomPage).toHaveURL(`/admin/bookings/${reference}`);
  await expectNoHorizontalPageOverflow(zoomPage);
  await expect(
    zoomPage.getByText(customerEmail, { exact: true }),
  ).toBeVisible();
  const completedTrigger = zoomPage.locator('[data-next-status="completed"]');
  await expect(completedTrigger).toBeEnabled();
  await completedTrigger.click();
  await expectDialogIsolation(zoomPage);
  await expectNoHorizontalPageOverflow(zoomPage);
  await expectFitsViewport(zoomPage.getByRole("alertdialog"), zoomPage);
  await zoomPage.screenshot({
    path: path.join(
      screenshotsDirectory,
      "admin-dialog-200-percent-equivalent.png",
    ),
  });
  await zoomPage.keyboard.press("Escape");
  await expect(completedTrigger).toBeFocused();
  await zoomPage.close();

  await page.locator("[data-admin-logout]").click();
  await expect(page).toHaveURL("/");
  await login(page, customerEmail, customerPassword);
  await expect(page).toHaveURL("/profile");
  await page.goto(`/bookings/${reference}`);
  await expect(page.locator(".booking-status")).toHaveText(
    /terkonfirmasi|confirmed/i,
  );
  await expect(
    page.getByRole("heading", { name: /riwayat status|status history/i }),
  ).toHaveCount(0);
  await expect(page.getByText(adminEmail, { exact: true })).toHaveCount(0);
});

test("verified customer receives no administrator booking data", async ({
  page,
}) => {
  const customerEmail = "admin-denied-customer@example.com";
  const contactCanary = "E2E ADMIN CONTACT CANARY";

  await registerCustomer(page, customerEmail);
  await verifyCustomer(page, customerEmail);
  await createNezukoBooking(page, {
    name: contactCanary,
    phone: "+628199998888",
    date: "2099-09-07",
    time: "11:30",
  });

  const response = await page.request.get("/admin/bookings", {
    maxRedirects: 0,
  });
  const body = await response.text();

  expect(response.status()).toBe(403);
  expect(body).not.toContain(contactCanary);
});
