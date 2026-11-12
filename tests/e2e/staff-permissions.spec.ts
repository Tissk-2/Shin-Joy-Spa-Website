import { execFileSync } from "node:child_process";
import { writeFileSync } from "node:fs";
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
    execFileSync("php", ["tests/e2e/fixtures/staff-permissions.php", ...args], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );
async function capture(page: Page, name: string) {
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.screenshot({
    path: path.join(projectRoot, `storage/framework/testing/b1b-${name}.png`),
    fullPage: true,
  });
}

test("owner grant and revoke immediately govern a separate retained staff session", async ({
  page,
  context,
  browser,
}) => {
  test.setTimeout(240_000);
  const localOnly = async (route: import("@playwright/test").Route) => {
    const url = new URL(route.request().url());
    if (["127.0.0.1", "localhost"].includes(url.hostname))
      await route.continue();
    else await route.abort();
  };
  await context.route("**/*", localOnly);
  const data = fixture();
  const staffContext = await browser.newContext();
  await staffContext.route("**/*", localOnly);
  try {
    const staff = await staffContext.newPage();
    await login(staff, data.staffEmail, "calmspa9");
    await expect(staff).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
    await staff.goto(`/admin/bookings/${data.reference}`);
    await staff.locator('[data-admin-locale="en"]').click();
    await expect(staff.locator("[data-next-status]")).toHaveCount(0);
    await expect(staff.locator('nav a[href="/admin/staff"]')).toHaveCount(0);
    await login(page, data.ownerEmail, "calmspa9");
    await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
    await page.goto("/admin/staff");
    await page.locator('[data-admin-locale="en"]').click();
    await page.setViewportSize({ width: 1440, height: 1000 });
    const row = page.locator(`[data-staff-id='${data.staffId}']`);
    await expect(row).toContainText("Not allowed");
    await capture(page, "desktop-en");
    await page.locator('[data-admin-locale="id"]').click();
    await expect(row).toContainText("Tidak diizinkan");
    await capture(page, "desktop-id");
    await page.setViewportSize({ width: 390, height: 844 });
    await capture(page, "mobile-id");
    await page.locator('[data-admin-locale="en"]').click();
    await expect(row).toContainText("Not allowed");
    await capture(page, "mobile-en");
    await row.getByRole("button", { name: "Grant booking management" }).click();
    const dialog = page.getByRole("alertdialog");
    await expect(dialog).toContainText(data.staffEmail);
    await capture(page, "grant-confirmation-en");
    await dialog.getByRole("button", { name: "Confirm change" }).click();
    await expect(dialog).toHaveCount(0);
    await expect(row).toContainText("Allowed");
    await staff.reload();
    await expect(staff.locator('[data-next-status="confirmed"]')).toBeVisible();
    await staff.locator('[data-next-status="confirmed"]').click();
    await expect(staff.getByRole("alertdialog")).toBeVisible();
    // Revoke through the real owner UI while staff retains its loaded form/session.
    await row
      .getByRole("button", { name: "Revoke booking management" })
      .click();
    await dialog.getByRole("button", { name: "Confirm change" }).click();
    await expect(row).toContainText("Not allowed");
    const deniedResponse = staff.waitForResponse(
      (response) =>
        response.request().method() === "PATCH" &&
        response.url().includes("/status"),
    );
    await staff
      .getByRole("alertdialog")
      .getByRole("button", { name: "Confirm change" })
      .click();
    expect((await deniedResponse).status()).toBe(403);
    await staff.reload();
    await expect(staff.locator("[data-next-status]")).toHaveCount(0);
    await expect(
      staff.getByRole("heading", { name: data.reference }),
    ).toBeVisible();
    await capture(staff, "revoked-staff-en");
    const facts = fixture(["facts"]);
    expect(facts).toEqual({
      allowed: false,
      status: "pending",
      audit: [
        { was_allowed: 0, is_allowed: 1, channel: "dashboard" },
        { was_allowed: 1, is_allowed: 0, channel: "dashboard" },
      ],
      statusChanges: 0,
      reservations: 0,
      schedulingEnabled: false,
    });
    writeFileSync(
      path.join(
        projectRoot,
        "storage/framework/testing/b1b-browser-facts.json",
      ),
      JSON.stringify(facts, null, 2),
    );
  } finally {
    await staffContext.close();
  }
});
