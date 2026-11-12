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

test("owner creates and edits persisted rooms, retains conflicts and staff stays read-only", async ({
  page,
  context,
  browser,
}) => {
  const fixture = JSON.parse(
    execFileSync("php", ["tests/e2e/fixtures/room-management.php"], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );
  await login(page, fixture.ownerEmail, "calmspa9");
  await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await page.setViewportSize({ width: 1440, height: 1080 });
  await page.goto("/admin/rooms");
  await page.getByRole("link", { name: /Tambah ruangan|Add room/i }).click();
  await expect(page.locator("#capacity")).toHaveValue("");
  await expect(page.locator("#cleaningMinutes")).toHaveValue("");
  await expect(page.locator("#isActive")).not.toBeChecked();
  await page.locator("#code").fill("B3E-NEW");
  await page.locator("#name").fill("New actual room");
  await page.locator("#capacity").fill("8");
  await page.locator("#cleaningMinutes").fill("25");
  await page.locator(`input[value="${fixture.variantId}"]`).check();
  const save = () =>
    page.getByRole("button", { name: /Simpan ruangan|Save room/i }).click();
  await save();
  await expect(page).toHaveURL(/\/admin\/rooms\/\d+\/edit$/);
  await expect(page.getByRole("status")).toContainText(
    /berhasil disimpan|saved successfully/i,
  );
  const editUrl = page.url();
  // Immediate second save catches create-success id/version synchronization errors.
  await page.locator("#name").fill("Saved twice");
  await page.locator("#cleaningMinutes").fill("35");
  await save();
  await expect(page.locator("#name")).toHaveValue("Saved twice");
  await expect(page.getByRole("alert")).toHaveCount(0);
  await page.reload();
  await expect(page.locator("#name")).toHaveValue("Saved twice");
  await expect(page.locator("#cleaningMinutes")).toHaveValue("35");
  await expect(
    page.locator(`input[value="${fixture.variantId}"]`),
  ).toBeChecked();
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-management-desktop.png",
    ),
    fullPage: true,
  });
  await page.locator("#code").fill("B3E-PROTECTED");
  await page.locator("#name").fill("Preserved unique-code draft");
  await save();
  await expect(page.locator("#code-error")).toContainText(
    /sudah digunakan|already in use/i,
  );
  await expect(page.locator("#name")).toHaveValue(
    "Preserved unique-code draft",
  );
  await page.locator("#code").fill("B3E-NEW");
  const competitor = await context.newPage();
  await competitor.goto(editUrl);
  await competitor.locator("#name").fill("Other owner tab saved");
  await competitor
    .getByRole("button", { name: /Simpan ruangan|Save room/i })
    .click();
  await expect(competitor.getByRole("status")).toContainText(
    /berhasil disimpan|saved successfully/i,
  );
  await page.locator("#name").fill("My stale draft");
  await save();
  await expect(page.getByRole("alert")).toContainText(
    /telah berubah|has changed/i,
  );
  await expect(page.locator("#name")).toHaveValue("My stale draft");
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-management-stale.png",
    ),
    fullPage: true,
  });
  await competitor.close();
  await page.goto(`/admin/rooms/${fixture.protectedRoomId}/edit`);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator("#name").fill("Conflict draft kept");
  await page.locator("#isActive").uncheck();
  await save();
  await expect(page.getByRole("alert")).toContainText("B3E-PROTECTED-BOOKING");
  await expect(page.locator("#name")).toHaveValue("Conflict draft kept");
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-management-mobile-conflict.png",
    ),
    fullPage: true,
  });
  await page.reload();
  await expect(page.locator("#name")).toHaveValue("Protected physical room");
  await expect(page.locator("#isActive")).toBeChecked();
  const staffContext = await browser.newContext();
  const staff = await staffContext.newPage();
  await login(staff, fixture.staffEmail, "calmspa9");
  await expect(staff).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  await staff.goto("/admin/rooms");
  await expect(
    staff.locator('a[href="/admin/rooms/create"], a[href$="/edit"]'),
  ).toHaveCount(0);
  const denied = await staff.goto(
    `/admin/rooms/${fixture.protectedRoomId}/edit`,
  );
  expect(denied?.status()).toBe(403);
  await staffContext.close();
});
