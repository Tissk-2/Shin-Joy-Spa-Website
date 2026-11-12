import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import {
  projectRoot,
  resetTestingDatabase,
  testingEnvironment,
} from "./testing-environment";

test.beforeAll(resetTestingDatabase);

const fixture = (args: string[] = []) =>
  JSON.parse(
    execFileSync("php", ["tests/e2e/fixtures/booking-settings.php", ...args], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );

async function screenshot(page: Page, name: string) {
  await page.evaluate(() => {
    (document.activeElement as HTMLElement | null)?.blur();
    window.scrollTo({ top: 0, behavior: "instant" });
  });
  await expect
    .poll(() =>
      page.evaluate(async () => {
        // A reload may finish restoring its scroll after the form first appears.
        window.scrollTo({ top: 0, behavior: "instant" });
        await new Promise<void>((resolve) =>
          requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
        );
        return window.scrollY;
      }),
    )
    .toBe(0);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: path.join(projectRoot, `storage/framework/testing/b3h-${name}.png`),
    fullPage: true,
  });
}

test("owner persists required settings, keeps stale drafts, and capable staff cannot access", async ({
  page,
  context,
  browser,
}) => {
  const data = fixture();
  await login(page, data.ownerEmail, "calmspa9");
  await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await page.setViewportSize({ width: 1440, height: 1080 });
  await page.goto("/admin/rooms");
  await page.locator('nav a[href="/admin/settings/booking"]').click();
  await expect(page).toHaveURL(/\/admin\/settings\/booking$/);
  await expect(page.locator("#booking_horizon_days")).toBeVisible();
  await page.locator('[data-admin-locale="en"]').click();
  await expect(
    page.getByRole("heading", { name: "Booking settings" }),
  ).toBeVisible();
  await expect(page.getByRole("status")).toContainText("not configured");
  await expect(page.locator("#booking_horizon_days")).toHaveValue("");
  await expect(page.locator("#minimum_lead_minutes")).toHaveValue("");
  await expect(page.locator("[data-review-interval]")).toHaveCount(0);
  await page.locator("#booking_horizon_days").fill("30");
  await page.locator("#minimum_lead_minutes").fill("120");
  await page.locator("#review_target_minutes").fill("60");
  await page.locator("#minimum_checkout_minutes").fill("15");
  for (const [index, day, start, end] of [
    [0, "2", "09:00", "17:00"],
    [1, "1", "13:00", "17:00"],
    [2, "1", "09:00", "13:00"],
  ] as const) {
    await page.locator("[data-add-interval]").click();
    await page.locator(`#interval-${index}-weekday`).selectOption(day);
    await page.locator(`#interval-${index}-start`).fill(start);
    await page.locator(`#interval-${index}-end`).fill(end);
  }
  const save = () => page.locator('form button[type="submit"]').click();
  await save();
  await expect(page.getByRole("status")).toContainText("saved successfully");
  await expect(page.locator("#interval-0-start")).toHaveValue("09:00");
  // Immediate update verifies successful first-save version synchronization.
  await page.locator("#booking_horizon_days").fill("45");
  await save();
  await expect(page.locator('form button[type="submit"]')).toBeEnabled();
  await expect(page.getByRole("alert")).toHaveCount(0);
  await page.reload();
  await expect(page.locator("#booking_horizon_days")).toHaveValue("45");
  await expect(page.locator("[data-review-interval]")).toHaveCount(3);
  await screenshot(page, "desktop-configured-en");
  // A valid HTML time with invalid domain ordering must return precise feedback.
  await page.locator("#interval-0-end").fill("08:00");
  await save();
  await expect(page.locator("#interval-0-end-error")).toContainText(
    "end must follow",
  );
  await expect(page.locator("#interval-0-end")).toHaveValue("08:00");
  await page.locator("#interval-0-end").fill("13:00");
  const competitor = await context.newPage();
  await competitor.goto("/admin/settings/booking");
  await competitor.locator("#booking_horizon_days").fill("50");
  await competitor.locator('form button[type="submit"]').click();
  await expect(competitor.getByRole("status")).toContainText(
    "saved successfully",
  );
  await page.locator("#booking_horizon_days").fill("70");
  await save();
  await expect(page.getByRole("alert")).toContainText("have changed");
  await expect(page.locator("#booking_horizon_days")).toHaveValue("70");
  await expect(page.getByRole("alert").getByRole("link")).toContainText(
    "Reload",
  );
  await screenshot(page, "desktop-stale-en");
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('[data-admin-locale="id"]').click();
  await expect(
    page.getByRole("heading", { name: "Pengaturan pemesanan" }),
  ).toBeVisible();
  await expect(page.locator("#booking_horizon_days")).toHaveValue("70");
  await screenshot(page, "mobile-stale-id");
  await competitor.close();
  const facts = fixture(["facts"]);
  expect(facts.version).toBe(3);
  expect(facts.horizon).toBe(50);
  expect(facts.intervals).toHaveLength(3);
  expect(facts.schedulingEnabled).toBe(false);
  const deniedContext = await browser.newContext();
  const staff = await deniedContext.newPage();
  await login(staff, data.staffEmail, "calmspa9");
  await expect(staff).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await staff.goto("/admin/rooms");
  await expect(
    staff.locator('nav a[href="/admin/settings/booking"]'),
  ).toHaveCount(0);
  expect((await staff.goto("/admin/settings/booking"))?.status()).toBe(403);
  const csrf = (await deniedContext.cookies()).find(
    (cookie) => cookie.name === "XSRF-TOKEN",
  )!;
  const response = await staff.request.patch("/admin/settings/booking", {
    headers: {
      "X-XSRF-TOKEN": decodeURIComponent(csrf.value),
      Accept: "application/json",
    },
    data: { version: 3, booking_horizon_days: 1 },
  });
  expect(response.status()).toBe(403);
  expect(fixture(["facts"])).toEqual(facts);
  await deniedContext.close();
});
