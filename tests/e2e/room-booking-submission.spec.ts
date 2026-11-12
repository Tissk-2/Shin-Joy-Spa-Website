import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test } from "@playwright/test";
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

function fixture(file: string, args: string[] = []) {
  return JSON.parse(
    execFileSync("php", [`tests/e2e/fixtures/${file}.php`, ...args], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );
}

test("stale availability rejects real confirmation, preserves focused draft, then refresh recovers to pending without a hold", async ({
  page,
}) => {
  const seed = fixture("room-booking");
  expect(path.normalize(seed.database)).toBe(path.normalize(testingDatabase));
  expect(seed.schedulingEnabled).toBe(true);
  await page.route("**/*", (route) => {
    const hostname = new URL(route.request().url()).hostname;
    return hostname === "127.0.0.1" || hostname === "localhost"
      ? route.continue()
      : route.abort();
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page, seed.email, "calmspa9");
  await expect(page).toHaveURL(/\/profile$/);
  await page.getByRole("button", { name: "EN", exact: true }).click();
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  await page.goto("/booking?service=b3j-treatment&mode=regular&duration=90");
  await page.locator("#f-date").fill(seed.date);
  await expect(page.locator('#f-time input[value="09:00"]')).toHaveCount(1);
  await page.locator('#f-time input[value="09:00"]').check();
  await page.locator("#f-notes").fill("B3K preserved submission draft");
  await page
    .getByRole("button", { name: "Review booking", exact: true })
    .click();
  await expect(page.locator("[data-booking-confirm]")).toBeEnabled();

  const blocked = fixture("room-booking-submission", ["block"]);
  expect(blocked.bookings).toBe(0);
  const rejected = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/bookings",
  );
  await page.locator("[data-booking-confirm]").click();
  expect((await rejected).status()).toBe(302);
  await expect(page.locator("#f-time-error")).toHaveText(
    "The selected time is no longer available. Choose another time.",
  );
  await expect(page.locator("#f-time")).toBeFocused();
  await expect(page.locator("#f-notes")).toHaveValue(
    "B3K preserved submission draft",
  );
  await expect(page.locator("#f-date")).toHaveValue(seed.date);
  const failedState = fixture("room-booking-submission", ["inspect"]);
  expect(failedState.bookings).toBe(0);
  expect(failedState.reservations).toBe(0);
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/b3k-time-error.png",
    ),
    fullPage: true,
  });

  fixture("room-booking-submission", ["unblock"]);
  await page.locator("[data-availability-refresh]").click();
  await expect(page.locator('#f-time input[value="09:00"]')).toHaveCount(1);
  await expect(page.locator("#f-time input:checked")).toHaveCount(0);
  await page.locator('#f-time input[value="09:00"]').check();
  await page
    .getByRole("button", { name: "Review booking", exact: true })
    .click();
  await expect(page.locator("[data-booking-confirm]")).toBeEnabled();
  const accepted = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/bookings",
  );
  await page.locator("[data-booking-confirm]").click();
  expect((await accepted).status()).toBe(303);
  await expect(page).toHaveURL(/\/bookings\/SJ-[0-9A-HJKMNP-TV-Z]{26}$/);
  const pending = fixture("room-booking-submission", ["inspect"]);
  expect(pending.bookings).toBe(1);
  expect(pending.statuses).toEqual(["pending"]);
  expect(pending.reservations).toBe(0);
  expect(page.url()).toContain(pending.references[0]);
  await page.screenshot({
    path: path.join(projectRoot, "storage/framework/testing/b3k-pending.png"),
    fullPage: true,
  });
});
