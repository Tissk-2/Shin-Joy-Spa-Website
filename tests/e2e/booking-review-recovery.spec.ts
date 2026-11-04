import { expect, test, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import path from "node:path";

import { registerCustomer, verifyCustomer } from "./helpers/auth";
import { projectRoot } from "./testing-environment";

const bookingPageUrl = /\/booking\?(?=.*service=nezuko)/;

async function startBooking(page: Page, email: string): Promise<string> {
  await page.goto("/");
  await page
    .getByRole("article", { name: "Nezuko", exact: true })
    .getByRole("link", { name: /reservasi|book/i })
    .click();
  await expect(page).toHaveURL(/\/login$/);
  await page.getByRole("link", { name: /create an account|buat akun/i }).click();
  await registerCustomer(page, email, { navigate: false });
  await verifyCustomer(page, email, bookingPageUrl);
  await expect(page).toHaveURL(bookingPageUrl);

  return page.url();
}

async function fillValidDetails(page: Page, notes: string): Promise<void> {
  const configurator = page.locator(".package-configurator");
  await configurator.getByRole("radio", { name: /regular|reguler/i }).click();
  await configurator.getByRole("radio", { name: /90/ }).click();
  await configurator.getByRole("checkbox", { name: /60/ }).check();
  await page.getByLabel(/nama lengkap|full name/i).fill("Ayu Review Recovery");
  await page
    .getByLabel(/nomor whatsapp|whatsapp number/i)
    .fill("+62 812-2222-3333");
  await page.getByLabel(/^tanggal$|^date$/i).fill("2099-09-11");
  await page.getByLabel(/^waktu$|^time$/i).selectOption("10:30");
  await page.getByLabel(/jumlah tamu|number of guests/i).selectOption("2");
  await page.getByLabel(/catatan|notes/i).fill(notes);
}

async function enterReviewByKeyboard(page: Page): Promise<void> {
  const action = page.getByRole("button", {
    name: /tinjau reservasi|review booking/i,
  });
  await page.keyboard.press("Tab");
  await expect
    .poll(() => action.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await page.keyboard.press("Enter");
  await expect(page.locator(".form-success h2")).toBeVisible();
}

async function confirmByKeyboard(page: Page): Promise<void> {
  const confirm = page.locator("[data-booking-confirm]");
  await page.keyboard.press("Tab");
  await page.keyboard.press("Tab");
  await expect
    .poll(() => confirm.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await page.keyboard.press("Enter");
}

async function waitForScrollToSettle(page: Page): Promise<void> {
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          new Promise<boolean>((resolve) => {
            let previous = window.scrollY;
            let stableFrames = 0;
            let frames = 0;
            const checkFrame = () => {
              const current = window.scrollY;
              stableFrames = current === previous ? stableFrames + 1 : 0;
              previous = current;
              frames += 1;
              if (stableFrames >= 4 || frames >= 120) {
                resolve(stableFrames >= 4);
                return;
              }
              requestAnimationFrame(checkFrame);
            };
            requestAnimationFrame(checkFrame);
          }),
      ),
    )
    .toBe(true);
}

async function expectFocusedBelowHeader(page: Page, selector: string): Promise<void> {
  const target = page.locator(selector);
  await expect(target).toBeVisible();
  await expect
    .poll(
      () => target.evaluate((element) => document.activeElement === element),
      { timeout: 5_000 },
    )
    .toBe(true);
  await waitForScrollToSettle(page);
  const bounds = await target.evaluate((element) => {
    const targetBounds = element.getBoundingClientRect();
    const headerBottom =
      document.querySelector(".site-header")?.getBoundingClientRect().bottom ?? 0;
    return {
      top: targetBounds.top,
      bottom: targetBounds.bottom,
      headerBottom,
      viewportHeight: window.innerHeight,
    };
  });
  expect(bounds.top).toBeGreaterThanOrEqual(bounds.headerBottom);
  expect(bounds.top).toBeGreaterThanOrEqual(0);
  expect(bounds.bottom).toBeLessThanOrEqual(bounds.viewportHeight);
}

test("keyboard submission focuses stale-price and used-key recovery actions", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });

  let nextOverride: "invalid-name" | "stale-price" | "used-key" | null =
    "invalid-name";
  let previouslyUsedKey = "";
  let successfulSubmissionKey = "";
  await page.route("**/bookings", async (route) => {
    const request = route.request();
    if (
      request.method() !== "POST" ||
      new URL(request.url()).pathname !== "/bookings"
    ) {
      await route.continue();
      return;
    }

    const payload = request.postDataJSON() as Record<string, unknown>;
    if (nextOverride === null) {
      successfulSubmissionKey = String(payload.submission_key ?? "");
    }
    if (nextOverride === "invalid-name") {
      nextOverride = null;
      await route.continue({
        postData: JSON.stringify({ ...payload, name: "" }),
      });
      return;
    }
    if (nextOverride === "stale-price") {
      nextOverride = null;
      await route.continue({
        postData: JSON.stringify({ ...payload, expected_total_price_idr: 1 }),
      });
      return;
    }
    if (nextOverride === "used-key") {
      nextOverride = null;
      await route.continue({
        postData: JSON.stringify({ ...payload, submission_key: previouslyUsedKey }),
      });
      return;
    }

    await route.continue();
  });

  const bookingUrl = await startBooking(page, "booking-recovery@example.com");
  await fillValidDetails(page, "Please keep this note during recovery.");
  await enterReviewByKeyboard(page);
  await confirmByKeyboard(page);

  await expect(page.locator("#f-name")).toHaveAttribute("aria-invalid", "true");
  await expectFocusedBelowHeader(page, "#f-name");

  nextOverride = "stale-price";
  await fillValidDetails(page, "Please keep this note during recovery.");
  await enterReviewByKeyboard(page);
  await confirmByKeyboard(page);

  await expect(page.locator("[data-booking-restart]")).toBeVisible();
  const staleRecovery = page.locator("[data-booking-recovery]");
  await expect(staleRecovery).toBeVisible();
  await expectFocusedBelowHeader(page, "[data-booking-recovery]");
  await expect(page.getByLabel(/nama lengkap|full name/i)).toHaveValue(
    "Ayu Review Recovery",
  );
  await expect(page.getByLabel(/catatan|notes/i)).toHaveValue(
    "Please keep this note during recovery.",
  );
  await page.keyboard.press("Tab");
  await expectFocusedBelowHeader(page, "[data-booking-restart]");
  await expect(page.locator("[data-booking-restart]")).toContainText(
    /harga terbaru|current prices/i,
  );
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(bookingPageUrl);
  await expect(page.locator("[data-booking-recovery]")).toHaveCount(0);

  await fillValidDetails(page, "Please keep this note during recovery.");
  await enterReviewByKeyboard(page);
  const successfulPost = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/bookings",
  );
  await confirmByKeyboard(page);
  expect((await successfulPost).status()).toBe(303);
  await expect(page).toHaveURL(/\/bookings\/SJ-/);
  previouslyUsedKey = successfulSubmissionKey;
  expect(previouslyUsedKey).toMatch(/^[0-9a-f-]{36}$/i);

  await page.goto(bookingUrl);
  await expect(page).toHaveURL(bookingPageUrl);
  nextOverride = "used-key";
  await fillValidDetails(page, "A different note makes this a changed replay.");
  await enterReviewByKeyboard(page);
  await confirmByKeyboard(page);

  const usedKeyRecovery = page.locator("[data-booking-recovery]");
  await expect(usedKeyRecovery).toBeVisible();
  await expectFocusedBelowHeader(page, "[data-booking-recovery]");
  await expect(page.getByLabel(/nama lengkap|full name/i)).toHaveValue(
    "Ayu Review Recovery",
  );
  await expect(page.getByLabel(/catatan|notes/i)).toHaveValue(
    "A different note makes this a changed replay.",
  );
  await page.keyboard.press("Tab");
  await expectFocusedBelowHeader(page, "[data-booking-existing]");
  await expect(page.locator("[data-booking-existing]")).toHaveAttribute(
    "href",
    "/bookings",
  );

  const screenshotDirectory = path.join(projectRoot, "outputs/booking-review");
  await mkdir(screenshotDirectory, { recursive: true });
  await page.screenshot({
    path: path.join(screenshotDirectory, "recovery-used-submission-key.png"),
  });
});
