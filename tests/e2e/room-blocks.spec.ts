import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import {
  projectRoot,
  resetTestingDatabase,
  testingEnvironment,
} from "./testing-environment";

test.beforeAll(resetTestingDatabase);

test.skip(
  process.env.E2E_BOOKING_SCHEDULING_ENABLED !== "true",
  "Explicit isolated scheduling opt-in is required.",
);
test("capable staff saves blocks, retains stale/conflict drafts and atomically closes existing rooms", async ({
  page,
  context,
  browser,
}) => {
  const fixture = JSON.parse(
    execFileSync("php", ["tests/e2e/fixtures/room-blocks.php"], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );
  await login(page, fixture.staffEmail, "calmspa9");
  await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await page.setViewportSize({ width: 1440, height: 1080 });
  await page.goto("/admin/rooms");
  await expect(page.locator('a[href="/admin/rooms/create"]')).toHaveCount(0);
  await page.locator('a[href="/admin/room-blocks/create"]').click();
  const save = () =>
    page
      .getByRole("button", {
        name: /Simpan periode pemeliharaan|Save maintenance period/i,
      })
      .click();
  const fillPeriod = async (start: string, end: string, reason: string) => {
    await page.locator("#startsAt").fill(start);
    await page.locator("#endsAt").fill(end);
    await page.locator("#reason").fill(reason);
  };
  await expect(page.locator("#roomId")).toHaveValue("");
  await page.locator("#roomId").selectOption(String(fixture.roomId));
  await fillPeriod(
    "2099-10-25T10:00",
    "2099-10-25T12:00",
    "Real maintenance period",
  );
  await save();
  await expect(page).toHaveURL(/\/admin\/room-blocks\/\d+\/edit$/);
  await expect(page.getByRole("status")).toContainText(
    /berhasil disimpan|saved successfully/i,
  );
  const editUrl = page.url();
  await page.locator("#reason").fill("Saved twice and persisted");
  await page.locator("#endsAt").fill("2099-10-25T13:00");
  await save();
  await expect(page.getByRole("alert")).toHaveCount(0);
  await page.reload();
  await expect(page.locator("#reason")).toHaveValue(
    "Saved twice and persisted",
  );
  await expect(page.locator("#startsAt")).toHaveValue("2099-10-25T10:00");
  const competitor = await context.newPage();
  await competitor.goto(editUrl);
  await competitor.locator("#reason").fill("Another staff edit");
  await competitor
    .getByRole("button", {
      name: /Simpan periode pemeliharaan|Save maintenance period/i,
    })
    .click();
  await expect(competitor.getByRole("status")).toContainText(
    /berhasil disimpan|saved successfully/i,
  );
  await page.locator("#reason").fill("Stale draft retained");
  await save();
  await expect(page.getByRole("alert")).toContainText(
    /telah berubah|has changed/i,
  );
  await expect(page.locator("#reason")).toHaveValue("Stale draft retained");
  await competitor.close();
  await page.evaluate(() => {
    (document.activeElement as HTMLElement | null)?.blur();
    window.scrollTo({ top: 0, behavior: "instant" });
  });
  await page.evaluate(
    () =>
      new Promise<void>((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
      ),
  );
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-blocks-desktop-stale.png",
    ),
    fullPage: true,
  });
  await page.goto(`/admin/bookings/${fixture.bookingReference}`);
  await page.locator('[data-next-status="confirmed"]').click();
  const dialog = page.getByRole("alertdialog");
  await dialog.locator('input[name="operationalConfirmed"]').check();
  await dialog.locator('button[type="submit"]').click();
  await expect(dialog.getByRole("alert")).toContainText(/ruang|room/i);
  await expect(page.locator(".admin-status--pending")).toBeVisible();
  await page.goto("/admin/room-blocks/create");
  await page.locator("#roomId").selectOption(String(fixture.roomId));
  await fillPeriod(
    "2099-10-25T11:00",
    "2099-10-25T14:00",
    "Conflict draft retained",
  );
  await save();
  await expect(page.getByRole("alert")).toContainText(
    /bert[l]?abrakan|conflicts/i,
  );
  await expect(page.locator("#reason")).toHaveValue("Conflict draft retained");
  await page.setViewportSize({ width: 390, height: 844 });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.evaluate(() => {
    (document.activeElement as HTMLElement | null)?.blur();
    window.scrollTo({ top: 0, behavior: "instant" });
  });
  await page.evaluate(
    () =>
      new Promise<void>((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
      ),
  );
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-blocks-mobile-conflict.png",
    ),
    fullPage: true,
  });
  await page.locator("#scope").selectOption("all");
  await fillPeriod(
    "2099-11-01T11:30",
    "2099-11-01T12:00",
    "Atomic conflict retains draft",
  );
  await save();
  await expect(page.getByRole("alert")).toContainText("B3F-PROTECTED");
  await page.goto("/admin/room-blocks");
  await expect(page.getByText("Atomic conflict retains draft")).toHaveCount(0);
  await page.goto("/admin/room-blocks/create");
  await page.locator("#scope").selectOption("all");
  await fillPeriod(
    "2099-12-01T10:00",
    "2099-12-01T12:00",
    "All existing rooms closure",
  );
  await save();
  await expect(page).toHaveURL(/\/admin\/room-blocks$/);
  await expect(
    page.getByText("All existing rooms closure", { exact: true }),
  ).toHaveCount(fixture.roomCount);
  await expect(
    page.getByText("Far future recorded period", { exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.evaluate(() => {
    (document.activeElement as HTMLElement | null)?.blur();
    window.scrollTo({ top: 0, behavior: "instant" });
  });
  await page.evaluate(
    () =>
      new Promise<void>((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
      ),
  );
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-blocks-mobile-list.png",
    ),
    fullPage: true,
  });
  await page.setViewportSize({ width: 1440, height: 1080 });
  await page.evaluate(() => {
    (document.activeElement as HTMLElement | null)?.blur();
    window.scrollTo({ top: 0, behavior: "instant" });
  });
  await page.evaluate(
    () =>
      new Promise<void>((resolve) =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
      ),
  );
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-blocks-desktop-list.png",
    ),
    fullPage: true,
  });
  await page.goto(`/admin/room-blocks?roomId=${fixture.inactiveRoomId}`);
  await expect(
    page.getByText("All existing rooms closure", { exact: true }),
  ).toHaveCount(1);
  const deniedContext = await browser.newContext();
  const denied = await deniedContext.newPage();
  await login(denied, fixture.deniedEmail, "calmspa9");
  await expect(denied).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await denied.goto("/admin/room-blocks");
  await expect(
    denied.locator('a[href$="/edit"], a[href="/admin/room-blocks/create"]'),
  ).toHaveCount(0);
  expect((await denied.goto(editUrl))?.status()).toBe(403);
  expect((await denied.goto("/admin/room-blocks/create"))?.status()).toBe(403);
  await deniedContext.close();
});
