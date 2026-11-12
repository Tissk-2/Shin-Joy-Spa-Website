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

test.skip(
  process.env.E2E_BOOKING_SCHEDULING_ENABLED !== "true",
  "Explicit isolated scheduling opt-in is required.",
);
const fixture = (args: string[] = []) =>
  execFileSync(
    "php",
    ["tests/e2e/fixtures/room-block-termination.php", ...args],
    {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    },
  );
async function screenshot(page: Page, name: string) {
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
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: path.join(projectRoot, `storage/framework/testing/b3g-${name}.png`),
    fullPage: true,
  });
}

test("cancellation, immutable history, end now, failed drafts and read-only rejection", async ({
  page,
  context,
  browser,
}) => {
  test.setTimeout(240_000);
  const data = JSON.parse(fixture());
  await login(page, data.staffEmail, "calmspa9");
  await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await page.setViewportSize({ width: 1440, height: 1080 });
  await page.goto(`/admin/room-blocks/${data.futureId}/edit`);
  await page.locator('[data-terminate="cancel_future"]').click();
  await expect(page.locator("#termination-reason")).toBeFocused();
  await page.locator("#termination-reason").fill("Separate cancellation draft");
  const competitor = await context.newPage();
  await competitor.goto(`/admin/room-blocks/${data.futureId}/edit`);
  await competitor.locator("#reason").fill("Original reason updated by staff");
  await competitor.locator('form button[type="submit"]').first().click();
  await expect(competitor.getByRole("status")).toContainText(/disimpan|saved/i);
  await page.locator('[data-terminal-form] button[type="submit"]').click();
  await expect(page.getByRole("alert")).toContainText(/berubah|changed/i);
  await expect(page.locator("#termination-reason")).toHaveValue(
    "Separate cancellation draft",
  );
  await screenshot(page, "desktop-stale");
  await competitor.close();
  await page.reload();
  await page.locator('[data-terminate="cancel_future"]').click();
  await page
    .locator("#termination-reason")
    .fill("<b>Cancellation completed</b>");
  await page.locator('[data-terminal-form] button[type="submit"]').click();
  await expect(page.locator("#reason")).toHaveCount(0);
  await expect(
    page.getByText("<b>Cancellation completed</b>", { exact: true }),
  ).toBeVisible();
  await expect(page.locator("[data-terminate]")).toHaveCount(0);
  await page.reload();
  await expect(
    page.getByText("Original reason updated by staff", { exact: true }),
  ).toBeVisible();
  await page.goto(`/admin/room-blocks?roomId=${data.roomId}&view=history`);
  await expect(
    page.locator("dd").filter({ hasText: "<script>Terminal staff</script>" }),
  ).toBeVisible();
  await screenshot(page, "desktop-history");
  await page.goto(`/admin/room-blocks/${data.activeId}/edit`);
  await page.locator('[data-terminate="end_now"]').click();
  await page.locator("#termination-reason").fill("Active maintenance finished");
  await page.locator('[data-terminal-form] button[type="submit"]').click();
  await expect(page.locator("#reason")).toHaveCount(0);
  await expect(
    page.getByText("Active maintenance finished", { exact: true }),
  ).toBeVisible();
  await page.goto("/admin/rooms");
  const inventory = page.locator(`[data-room-id="${data.roomId}"]`);
  await expect(inventory).not.toContainText("Active maintenance");
  await expect(inventory).toContainText("Boundary maintenance");
  await expect(inventory.locator(".admin-status--pending")).toHaveCount(0);
  await page.goto(`/admin/room-blocks/${data.boundaryId}/edit`);
  await page.locator('[data-terminate="cancel_future"]').click();
  await page.locator("#termination-reason").fill("Lifecycle draft retained");
  fixture(["cross-start"]);
  await page.locator('[data-terminal-form] button[type="submit"]').click();
  await expect(page.getByRole("alert")).toContainText(/berubah|changed/i);
  await expect(page.getByRole("alert")).not.toContainText("admin.blocks.");
  await expect(page.locator("#termination-reason")).toHaveValue(
    "Lifecycle draft retained",
  );
  await expect(page.getByRole("region").getByRole("heading")).toContainText(
    /Batalkan|Cancel/i,
  );
  await page.setViewportSize({ width: 390, height: 844 });
  await screenshot(page, "mobile-lifecycle");
  const deniedContext = await browser.newContext({
    timezoneId: "America/New_York",
  });
  const denied = await deniedContext.newPage();
  await login(denied, data.deniedEmail, "calmspa9");
  await expect(denied).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await denied.goto(`/admin/room-blocks?roomId=${data.roomId}&view=history`);
  await expect(
    denied.getByText("<b>Cancellation completed</b>", { exact: true }),
  ).toBeVisible();
  await expect(
    denied.locator('time[datetime="2099-10-25T03:00:00.000000Z"]'),
  ).toContainText(/10[.:]00 WIB/);
  await expect(
    denied.locator('[data-terminate], a[href$="/edit"]'),
  ).toHaveCount(0);
  const csrf = (await deniedContext.cookies()).find(
    (cookie) => cookie.name === "XSRF-TOKEN",
  )!;
  const response = await denied.request.post(
    `/admin/room-blocks/${data.boundaryId}/terminate`,
    {
      headers: {
        "X-XSRF-TOKEN": decodeURIComponent(csrf.value),
        Accept: "application/json",
      },
      data: { intent: "end_now", version: 1, reason: "Denied action" },
    },
  );
  expect(response.status()).toBe(403);
  await denied.setViewportSize({ width: 390, height: 844 });
  await screenshot(denied, "mobile-history");
  await deniedContext.close();
});
