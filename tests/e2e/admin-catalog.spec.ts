import { expect, test, type Locator, type Page } from "@playwright/test";
import path from "node:path";

import { registerVerifiedAdmin } from "./helpers/admin";
import { registerCustomer, verifyCustomer } from "./helpers/auth";
import { projectRoot, resetTestingDatabase } from "./testing-environment";

test.beforeAll(resetTestingDatabase);

const catalogFixture = (name: string): string =>
  path.join(projectRoot, "tests/e2e/fixtures/catalog", name);

const category = {
  slug: "package",
  nameId: "Paket Spa",
  nameEn: "Spa Packages",
};
const servicePackage = {
  slug: "nezuko",
  nameId: "Nezuko",
  nameEn: "Nezuko",
  variantCode: "nezuko-regular-60",
  extensionCode: "nezuko-regular-extra-60",
  basePrice: 199_000,
  extensionPrice: 75_000,
};
const bookingTotal = "Total: 120 min · Rp 274.000";
const bookingPrice = "Rp 274.000";

function packageRow(page: Page): Locator {
  return page.locator(`[data-catalog-package="${servicePackage.slug}"]`);
}

async function expectNoHorizontalPageOverflow(page: Page): Promise<void> {
  const metrics = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));

  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth);
}

async function expectHorizontallyReachable(
  page: Page,
  control: Locator,
): Promise<void> {
  await control.scrollIntoViewIfNeeded();
  await expect(control).toBeVisible();
  const box = await control.boundingBox();
  const viewport = page.viewportSize();

  expect(box).not.toBeNull();
  expect(viewport).not.toBeNull();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(viewport!.width);
}

async function confirmLifecycleAction(
  row: Locator,
  action: "publish" | "archive" | "restore",
): Promise<void> {
  const labels = {
    publish: /^publikasikan$|^publish$/i,
    archive: /^arsipkan$|^archive$/i,
    restore: /^pulihkan$|^restore$/i,
  };
  await row.getByRole("button", { name: labels[action] }).click();
  const dialog = row.page().getByRole("alertdialog");
  await expect(dialog).toBeVisible();
  await dialog.locator("[data-lifecycle-confirm]").click();
}

async function restoreArchivedPackage(page: Page): Promise<void> {
  await page.goto("/admin/catalog");
  const row = packageRow(page);
  const status = await row
    .locator("[data-catalog-status]")
    .getAttribute("data-catalog-status");

  if (status === "archived") {
    await confirmLifecycleAction(row, "restore");
    await expect(
      row.locator('[data-catalog-status="published"]'),
    ).toBeVisible();
  }
}

async function expectPublicVisibility(
  page: Page,
  pathname: "/" | "/services",
  visible: boolean,
): Promise<void> {
  await page.goto(pathname);
  const publicCategory = page.locator(
    `[data-catalog-category="${category.slug}"]`,
  );

  if (visible) {
    await expect(publicCategory).toBeVisible();
    await expect(
      publicCategory.getByText(servicePackage.nameId, { exact: true }),
    ).toBeVisible();
  } else {
    await expect(publicCategory).toHaveCount(1);
    await expect(
      page.getByText(servicePackage.nameId, { exact: true }),
    ).toHaveCount(0);
  }
}

async function csrfToken(page: Page): Promise<string> {
  const cookie = (await page.context().cookies()).find(
    ({ name }) => name === "XSRF-TOKEN",
  );
  expect(cookie).toBeDefined();

  return decodeURIComponent(cookie!.value);
}

async function submitHiddenPackageQuote(
  page: Page,
  submissionKey: string,
): Promise<void> {
  await page.goto("/");
  const response = await page.request.post("/bookings", {
    data: {
      name: "Catalog Journey Guest",
      phone: "+6281234567890",
      service: servicePackage.slug,
      mode: "regular",
      duration: 60,
      extension: servicePackage.extensionCode,
      expected_total_price_idr:
        servicePackage.basePrice + servicePackage.extensionPrice,
      date: "2099-10-10",
      time: "10:30",
      guests: 1,
      notes: "E2E catalog visibility boundary",
      submission_key: submissionKey,
    },
    headers: {
      Accept: "application/json",
      "X-XSRF-TOKEN": await csrfToken(page),
    },
  });

  expect(response.status()).toBe(422);
  const body = await response.json();
  expect(body).toMatchObject({
    errors: { service: [expect.any(String)] },
  });
  expect(body.errors.expected_total_price_idr).toBeUndefined();
}

async function expectDetailVisibility(
  page: Page,
  visible: boolean,
): Promise<void> {
  const response = await page.request.get("/services");
  expect(response.status()).toBe(200);
  const body = await response.text();

  if (visible) {
    expect(body).toContain(`"slug":"${servicePackage.slug}"`);
    await page.goto("/services");
    await expect(
      page.getByRole("heading", { name: servicePackage.nameId, exact: true }),
    ).toBeVisible();
  } else {
    expect(body).not.toContain(`"slug":"${servicePackage.slug}"`);
  }
}

async function fillPackageForm(page: Page): Promise<void> {
  // Published packages lock their slug and published child codes; every other
  // field of the seeded package stays editable through the update contract.
  await expect(page.getByLabel(/slug paket|package slug/i)).toHaveAttribute(
    "readonly",
    "",
  );
  await page
    .getByLabel(/^kategori$|^category$/i)
    .selectOption({ label: `${category.nameId} / ${category.nameEn}` });
  await page
    .getByLabel(/nama paket.*bahasa indonesia|indonesian package name/i)
    .fill(servicePackage.nameId);
  await page
    .getByLabel(/nama paket.*bahasa inggris|english package name/i)
    .fill(servicePackage.nameEn);
  await page
    .getByLabel(/^deskripsi bahasa indonesia$|^indonesian description$/i)
    .fill("Pijatan tenang dengan ritme khas untuk memulihkan tubuh.");
  await page
    .getByLabel(/^deskripsi bahasa inggris$|^english description$/i)
    .fill("A calm massage with a signature rhythm that restores the body.");
  await page
    .getByLabel(
      /deskripsi panjang bahasa indonesia|indonesian long description/i,
    )
    .fill("Perawatan E2E lengkap untuk tubuh yang membutuhkan istirahat.");
  await page
    .getByLabel(/deskripsi panjang bahasa inggris|english long description/i)
    .fill("A complete E2E treatment for a body that needs deep rest.");
  await page
    .getByLabel(/^manfaat bahasa indonesia$|^indonesian benefit$/i)
    .fill("Membantu tubuh terasa ringan.");
  await page
    .getByLabel(/^manfaat bahasa inggris$|^english benefit$/i)
    .fill("Helps the body feel lighter.");
  await page.locator("#catalog-package-displayOrder").fill("1");
  await page
    .getByLabel(/foto sampul|cover image/i)
    .setInputFiles(catalogFixture("cover.png"));
  await page
    .getByLabel(/galeri foto|image gallery/i)
    .setInputFiles(catalogFixture("gallery.webp"));

  const variant = page
    .getByRole("group", { name: /pilihan utama|base options/i })
    .locator('[data-choice-row="variants.0"]');
  await expect(variant.getByLabel(/^kode$|^code$/i)).toHaveAttribute(
    "readonly",
    "",
  );
  await variant.getByLabel(/^mode$/i).selectOption("regular");
  await variant.getByLabel(/durasi menit|duration minutes/i).fill("60");
  await variant
    .getByLabel(/harga idr|price idr/i)
    .fill(String(servicePackage.basePrice));
  await variant.getByLabel(/urutan tampilan|display order/i).fill("1");

  const extension = page
    .getByRole("group", { name: /^tambahan$|^extensions$/i })
    .locator('[data-choice-row="extensions.0"]');
  await expect(extension.getByLabel(/^kode$|^code$/i)).toHaveAttribute(
    "readonly",
    "",
  );
  await extension.getByLabel(/^mode$/i).selectOption("regular");
  await extension.getByLabel(/durasi menit|duration minutes/i).fill("60");
  await extension
    .getByLabel(/harga idr|price idr/i)
    .fill(String(servicePackage.extensionPrice));
  await extension.getByLabel(/urutan tampilan|display order/i).fill("1");
}

async function createBookingWithPublishedPrice(page: Page): Promise<void> {
  await page.goto(
    `/booking?service=${servicePackage.slug}&mode=regular&duration=60&extension=${servicePackage.extensionCode}`,
  );
  await expect(page.getByText(bookingTotal, { exact: true })).toBeVisible();
  await page.getByLabel(/^tanggal$|^date$/i).fill("2099-10-10");
  await page
    .getByRole("radiogroup", { name: /^waktu$|^time$/i })
    .getByRole("radio", { name: "10:30" })
    .check();
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();
  await expect(page.getByText(bookingPrice, { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: /konfirmasi reservasi|confirm booking/i })
    .click();
  await expect(page).toHaveURL(/\/bookings\/SJ-/);
  await expect(page.getByText(bookingPrice, { exact: true })).toBeVisible();
}

test.describe.serial("administrator catalog publishing", () => {
  test("persists the complete administrator-to-customer catalog journey", async ({
    page,
    context,
  }) => {
    const adminEmail = "catalog-admin@example.com";
    const customerEmail = "catalog-customer@example.com";

    await registerVerifiedAdmin(page, adminEmail);

    await page.setViewportSize({ width: 320, height: 760 });
    await page.goto("/admin/catalog");
    await expectNoHorizontalPageOverflow(page);
    const editLink = packageRow(page).locator('a[href$="/edit"]');
    await expectHorizontallyReachable(page, editLink);

    await page.setViewportSize({ width: 640, height: 760 });
    // A 640 CSS-pixel viewport is the reflow equivalent of 1280 px at 200% zoom.
    await expectNoHorizontalPageOverflow(page);
    await editLink.click();
    const editUrl = `/admin/catalog/packages/${servicePackage.slug}/edit`;
    await expect(page).toHaveURL(editUrl);
    await expectNoHorizontalPageOverflow(page);
    await fillPackageForm(page);
    const saveChanges = page.getByRole("button", {
      name: /simpan perubahan|save changes/i,
    });
    await expectHorizontallyReachable(page, saveChanges);
    await saveChanges.click();
    await expect(page).toHaveURL("/admin/catalog");
    await expect(
      packageRow(page).locator('[data-catalog-status="published"]'),
    ).toBeVisible();

    await expectPublicVisibility(page, "/", true);
    await expectPublicVisibility(page, "/services", true);
    await expectDetailVisibility(page, true);
    await page.goto("/services");
    await page.getByRole("button", { name: "EN", exact: true }).click();
    await expect(
      page.getByText(servicePackage.nameEn, { exact: true }).first(),
    ).toBeVisible();
    await expect(
      page.getByText(category.nameEn, { exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "ID", exact: true }).click();
    await expect(
      page.getByText(servicePackage.nameId, { exact: true }).first(),
    ).toBeVisible();
    await expect(
      page.getByText(category.nameId, { exact: true }),
    ).toBeVisible();

    await createBookingWithPublishedPrice(page);

    try {
      await page.goto("/admin/catalog");
      await confirmLifecycleAction(packageRow(page), "archive");
      await expect(
        packageRow(page).locator('[data-catalog-status="archived"]'),
      ).toBeVisible();
      await expectPublicVisibility(page, "/", false);
      await expectPublicVisibility(page, "/services", false);
      await expectDetailVisibility(page, false);
      await page.goto(
        `/booking?service=${servicePackage.slug}&mode=regular&duration=60&extension=${servicePackage.extensionCode}`,
      );
      await expect(
        page.getByText(servicePackage.nameId, { exact: true }),
      ).toHaveCount(0);
      await submitHiddenPackageQuote(
        page,
        "11111111-1111-4111-8111-111111111111",
      );

      await page.goto("/admin/catalog");
      await confirmLifecycleAction(packageRow(page), "restore");
      await expect(
        packageRow(page).locator('[data-catalog-status="published"]'),
      ).toBeVisible();
      await expectPublicVisibility(page, "/services", true);
      await expectDetailVisibility(page, true);
    } finally {
      await restoreArchivedPackage(page);
    }

    await page.goto(editUrl);
    const stalePage = await context.newPage();
    await stalePage.setViewportSize({ width: 640, height: 760 });
    await stalePage.goto(editUrl);

    const firstBenefit = "Manfaat tersimpan dari editor pertama.";
    const staleBenefit = "Perubahan dari editor kedaluwarsa.";
    await page
      .getByLabel(/^manfaat bahasa indonesia$|^indonesian benefit$/i)
      .fill(firstBenefit);
    await page
      .getByRole("button", { name: /simpan perubahan|save changes/i })
      .click();
    await expect(page).toHaveURL("/admin/catalog");

    await stalePage
      .getByLabel(/^manfaat bahasa indonesia$|^indonesian benefit$/i)
      .fill(staleBenefit);
    await stalePage
      .getByRole("button", { name: /simpan perubahan|save changes/i })
      .click();
    await expect(
      stalePage.getByRole("alert").filter({ hasText: /berubah|changed/i }),
    ).toBeVisible();
    await stalePage.reload();
    await expect(
      stalePage.getByLabel(/^manfaat bahasa indonesia$|^indonesian benefit$/i),
    ).toHaveValue(firstBenefit);
    await expectNoHorizontalPageOverflow(stalePage);
    await stalePage.close();

    await page.locator("[data-admin-logout]").click();
    await expect(page).toHaveURL("/");
    await registerCustomer(page, customerEmail);
    await verifyCustomer(page, customerEmail);
    const denied = await page.request.get("/admin/catalog", {
      maxRedirects: 0,
    });
    const deniedBody = await denied.text();
    expect(denied.status()).toBe(403);
    expect(deniedBody).not.toContain(category.nameId);
    expect(deniedBody).not.toContain(servicePackage.nameEn);
  });
});
