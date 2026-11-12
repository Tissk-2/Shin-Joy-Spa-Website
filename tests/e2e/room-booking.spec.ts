import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import {
  projectRoot,
  resetTestingDatabase,
  testingDatabase,
  testingEnvironment,
} from "./testing-environment";

test.beforeAll(resetTestingDatabase);

test.skip(
  process.env.E2E_BOOKING_SCHEDULING_ENABLED !== "true",
  "Explicit isolated scheduling opt-in is required.",
);
test.use({ timezoneId: "America/Los_Angeles" });

async function screenshot(page: Page, name: string): Promise<void> {
  await page.evaluate(() => {
    (document.activeElement as HTMLElement | null)?.blur();
    window.scrollTo({ top: 0, behavior: "instant" });
  });
  await expect
    .poll(() =>
      page.evaluate(async () => {
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
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: path.join(projectRoot, `storage/framework/testing/b3j-${name}.png`),
    fullPage: true,
  });
}
test("customer observes real availability, keyboard time selection, safe review, refresh recovery and occupied mobile ID", async ({
  page,
}) => {
  const fixture = JSON.parse(
    execFileSync("php", ["tests/e2e/fixtures/room-booking.php"], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );
  expect(path.normalize(fixture.database)).toBe(
    path.normalize(testingDatabase),
  );
  expect(fixture.schedulingEnabled).toBe(true);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page, fixture.email, "calmspa9");
  await expect(page).toHaveURL(/\/profile$/);
  await page.getByRole("button", { name: "EN", exact: true }).click();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  await page.goto("/booking?service=b3j-treatment&mode=regular&duration=90");
  const availability = page.locator("[data-room-availability]");
  await expect(availability).toContainText("Choose treatment");
  const apiResponse = page.waitForResponse(
    (response) =>
      response.url().includes("/booking/availability?") &&
      response.status() === 200,
  );
  await page.locator("#f-date").fill(fixture.date);
  const payload = await (await apiResponse).json();
  expect(payload.slots[0].start_at).toMatch(/T02:00:00/);
  expect(
    payload.slots[0].rooms.map(
      (room: { available: boolean }) => room.available,
    ),
  ).toEqual([true, false]);
  await expect(page.locator('#f-time input[value="09:00"]')).toHaveCount(1);
  // Times are radio buttons: pick 09:00 with the keyboard.
  await page.locator('#f-time input[value="09:00"]').focus();
  await page.keyboard.press("Space");
  await expect(page.locator('#f-time input[value="09:00"]')).toBeChecked();
  await expect(availability).toContainText("Quiet room");
  await expect(availability).toContainText("Garden room");
  await expect(availability).toContainText("Unavailable");
  await expect(availability).toContainText("Staff approval reserves");
  await availability.scrollIntoViewIfNeeded();
  await screenshot(page, "desktop-en");
  await page.locator("#f-notes").fill("Retain this draft");
  await page
    .getByRole("button", { name: "Review booking", exact: true })
    .click();
  await expect(page.locator("[data-booking-confirm]")).toBeEnabled();
  await page.route(
    "**/booking/availability?**",
    (route) =>
      route.fulfill({
        status: 503,
        contentType: "application/json",
        body: '{"message":"unavailable"}',
      }),
    { times: 1 },
  );
  await page.locator("[data-availability-refresh]").click();
  await expect(availability).toContainText("could not be loaded");
  await expect(page.locator("[data-booking-confirm]")).toBeDisabled();
  await screenshot(page, "review-error");
  await page.locator("[data-availability-refresh]").click();
  await expect(availability).toContainText("Choose a time");
  await expect(page.locator("[data-booking-confirm]")).toBeDisabled();
  await page.locator("[data-booking-edit]").click();
  await expect(page.locator("#f-notes")).toHaveValue("Retain this draft");
  await page.locator("#f-guests").selectOption("3");
  await expect(availability).toContainText("No compatible rooms");
  await expect(page.locator('#f-time input[type="radio"]')).toHaveCount(0);
  await page.locator("#f-guests").selectOption("1");
  await expect(availability).toContainText("Choose a time");
  await page.getByRole("button", { name: "ID", exact: true }).click();
  await expect(page.locator("html")).toHaveAttribute("lang", "id");
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload();
  await page.locator("#f-date").fill(fixture.occupiedDate);
  await expect(availability).toContainText(
    "Semua ruangan yang sesuai sudah terisi",
  );
  await expect(page.locator('#f-time input[type="radio"]')).toHaveCount(0);
  await availability.scrollIntoViewIfNeeded();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await screenshot(page, "mobile-id-occupied");
  await page.locator("#f-date").fill(fixture.date);
  await expect(page.locator('#f-time input[value="09:00"]')).toHaveCount(1);
  await page.locator('#f-time input[value="09:00"]').check();
  await expect(availability).toContainText("Tersedia");
  await expect(availability).toContainText("Tidak tersedia");
  await expect(availability).toContainText("Persetujuan staf");
  await availability.scrollIntoViewIfNeeded();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  await screenshot(page, "mobile-id-rooms");
});
