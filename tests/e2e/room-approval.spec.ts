import { existsSync, readFileSync, writeFileSync, rmSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { test, expect } from "@playwright/test";
import { login } from "./helpers/auth";
import {
  projectRoot,
  testingEnvironment,
  viteHotFile,
} from "./testing-environment";
test.skip(
  process.env.E2E_BOOKING_SCHEDULING_ENABLED !== "true",
  "Room approval requires the explicit isolated scheduling opt-in.",
);
let previousHot: Buffer | undefined;
test.beforeAll(() => {
  previousHot = existsSync(viteHotFile) ? readFileSync(viteHotFile) : undefined;
});
test.afterAll(() => {
  if (previousHot) writeFileSync(viteHotFile, previousHot);
  else rmSync(viteHotFile, { force: true });
});

test("staff attests and reserves the last room; conflicting approval remains pending", async ({
  page,
}) => {
  writeFileSync(viteHotFile, "http://127.0.0.1:5174");
  const fixture = JSON.parse(
    execFileSync("php", ["tests/e2e/fixtures/room-approval.php"], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );
  await login(page, fixture.email, "calmspa9");
  await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  for (const [index, reference] of [fixture.first, fixture.second].entries()) {
    await page.goto(`/admin/bookings/${reference}`);
    await page.locator('[data-next-status="confirmed"]').click();
    const dialog = page.getByRole("alertdialog");
    await expect(
      dialog.locator('input[name="operationalConfirmed"]'),
    ).not.toBeChecked();
    await dialog.locator('input[name="operationalConfirmed"]').check();
    await dialog.locator('button[type="submit"]').click();
    if (index === 0) {
      await expect(dialog).not.toBeVisible();
      await expect(page.locator("[data-assigned-room]")).toContainText("Small");
      await expect(
        page.locator("[data-operational-attestation]"),
      ).toBeVisible();
    } else {
      await expect(dialog.getByRole("alert")).toContainText(/ruang|room/i);
      await expect(page.locator(".admin-status--pending")).toBeVisible();
      await expect(page.locator("[data-assigned-room]")).toHaveCount(0);
    }
  }
});
