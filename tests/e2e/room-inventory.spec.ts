import { execFileSync } from "node:child_process";
import { writeFileSync } from "node:fs";
import path from "node:path";
import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import {
  projectRoot,
  testingEnvironment,
  viteHotFile,
} from "./testing-environment";

test("staff reads persisted room inventory, filters it, and sees the same details on mobile", async ({
  page,
}) => {
  // The runner must preserve the user's hot file outside this harness before startup.
  writeFileSync(viteHotFile, "http://127.0.0.1:5174");
  const fixture = JSON.parse(
    execFileSync("php", ["tests/e2e/fixtures/room-inventory.php"], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );
  const mutationRequests: string[] = [];
  await login(page, fixture.email, "calmspa9");
  await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  page.on("request", (request) => {
    if (request.url().includes("/admin/rooms") && request.method() !== "GET")
      mutationRequests.push(request.method());
  });
  await page.setViewportSize({ width: 1440, height: 1080 });
  await page.goto("/admin/rooms");
  await expect(page.locator(".admin-rooms-table")).toBeVisible();
  const first = page.locator("tbody tr").filter({ hasText: "B3D-A" });
  await expect(first).toContainText("Inventory room A");
  await expect(first).toContainText("Pijat Inventaris");
  await expect(first).toContainText("B3D saved current repair");
  await expect(first).toContainText("B3D future cleaning");
  await expect(first).toContainText("Katalog tidak aktif");
  await expect(
    page.getByText("Penjadwalan ruangan belum aktif."),
  ).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: /Tambah Ruangan|Ubah Ruangan|Blokir|Aktifkan|Simpan/i,
    }),
  ).toHaveCount(0);
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-inventory-desktop.png",
    ),
    fullPage: true,
  });
  await page.locator("#room-status").selectOption("disabled");
  await expect(page.locator("tbody tr")).toHaveCount(1);
  await expect(page.locator("tbody tr")).toContainText("B3D-B");
  await expect(page.locator("tbody tr")).toContainText("Sedang diblokir");
  await page.locator("#room-search").fill("no-such-room");
  await expect(page.getByRole("status")).toContainText(
    "Tidak ada ruangan yang cocok",
  );
  await page.locator("#room-search").fill("");
  await page.locator("#room-status").selectOption("all");
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.locator(".admin-rooms-table")).not.toBeVisible();
  const cards = page.locator(".admin-booking-cards > li");
  await expect(cards).toHaveCount(2);
  await expect(cards.first()).toBeVisible();
  await expect(cards.first()).toContainText("B3D saved current repair");
  await expect(cards.first()).toContainText("B3D future cleaning");
  await expect(cards.first()).toContainText("WIB");
  await page.screenshot({
    path: path.join(
      projectRoot,
      "storage/framework/testing/room-inventory-mobile.png",
    ),
    fullPage: true,
  });
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
  expect(mutationRequests).toEqual([]);
});
