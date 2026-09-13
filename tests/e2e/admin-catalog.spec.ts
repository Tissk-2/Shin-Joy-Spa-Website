import { expect, test, type Locator, type Page } from "@playwright/test";
import path from "node:path";

import { registerVerifiedAdmin } from "./helpers/admin";
import { registerCustomer, verifyCustomer } from "./helpers/auth";
import { projectRoot } from "./testing-environment";

const catalogFixture = (name: string): string =>
  path.join(projectRoot, "tests/e2e/fixtures/catalog", name);

const category = {
  slug: "e2e-ritual-care",
  nameId: "Ritual Perawatan E2E",
  nameEn: "E2E Ritual Care",
};
const servicePackage = {
  slug: "e2e-forest-reset",
  nameId: "Pemulihan Hutan E2E",
  nameEn: "E2E Forest Reset",
  variantCode: "e2e-forest-regular-75",
  extensionCode: "e2e-forest-extra-30",
  basePrice: 190_000,
  extensionPrice: 50_000,
};

function catalogRow(page: Page, kind: "category" | "package"): Locator {
  const slug = kind === "category" ? category.slug : servicePackage.slug;
  return page.locator(`[data-catalog-${kind}="${slug}"]`);
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
    await expect(publicCategory).toHaveCount(0);
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
      duration: 75,
      extension: servicePackage.extensionCode,
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
  expect(await response.json()).toMatchObject({
    errors: { service: [expect.any(String)] },
  });
}

async function expectDetailVisibility(
  page: Page,
  visible: boolean,
): Promise<void> {
  const response = await page.request.get(`/services/${servicePackage.slug}`);
  expect(response.status()).toBe(visible ? 200 : 404);

  if (visible) {
    await page.goto(`/services/${servicePackage.slug}`);
    await expect(
      page.getByRole("heading", { name: servicePackage.nameId }),
    ).toBeVisible();
  }
}

async function fillPackageForm(page: Page): Promise<void> {
  await page
    .getByLabel(/^kategori$|^category$/i)
    .selectOption({ label: `${category.nameId} / ${category.nameEn}` });
  await page.getByLabel(/slug paket|package slug/i).fill(servicePackage.slug);
  await page
    .getByLabel(/nama paket.*bahasa indonesia|indonesian package name/i)
    .fill(servicePackage.nameId);
  await page
    .getByLabel(/nama paket.*bahasa inggris|english package name/i)
    .fill(servicePackage.nameEn);
  await page
    .getByLabel(/^deskripsi bahasa indonesia$|^indonesian description$/i)
    .fill("Ritual pemulihan tenang dengan sentuhan hutan.");
  await page
    .getByLabel(/^deskripsi bahasa inggris$|^english description$/i)
    .fill("A calm recovery ritual with a forest-inspired touch.");
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
  await page.locator("#catalog-package-displayOrder").fill("41");
  await page
    .getByLabel(/foto sampul|cover image/i)
    .setInputFiles(catalogFixture("cover.png"));
  await page
    .getByLabel(/galeri foto|image gallery/i)
    .setInputFiles(catalogFixture("gallery.webp"));

  const variants = page.getByRole("group", {
    name: /pilihan utama|base options/i,
  });
  await variants
    .getByRole("button", { name: /tambah pilihan|add option/i })
    .click();
  const variant = variants.locator('[data-choice-row="variants.0"]');
  await variant.getByLabel(/^kode$|^code$/i).fill(servicePackage.variantCode);
  await variant.getByLabel(/^mode$/i).selectOption("regular");
  await variant.getByLabel(/durasi menit|duration minutes/i).fill("75");
  await variant
    .getByLabel(/harga idr|price idr/i)
    .fill(String(servicePackage.basePrice));
  await variant.getByLabel(/urutan tampilan|display order/i).fill("0");

  const extensions = page.getByRole("group", {
    name: /^tambahan$|^extensions$/i,
  });
  await extensions
    .getByRole("button", { name: /tambah pilihan|add option/i })
    .click();
  const extension = extensions.locator('[data-choice-row="extensions.0"]');
  await extension
    .getByLabel(/^kode$|^code$/i)
    .fill(servicePackage.extensionCode);
  await extension.getByLabel(/^mode$/i).selectOption("regular");
  await extension.getByLabel(/durasi menit|duration minutes/i).fill("30");
  await extension
    .getByLabel(/harga idr|price idr/i)
    .fill(String(servicePackage.extensionPrice));
  await extension.getByLabel(/urutan tampilan|display order/i).fill("0");
}

async function createBookingWithPublishedPrice(page: Page): Promise<void> {
  await page.goto(
    `/booking?service=${servicePackage.slug}&mode=regular&duration=75&extension=${servicePackage.extensionCode}`,
  );
  await expect(
    page.getByText("Total: 105 min · Rp 240.000", { exact: true }),
  ).toBeVisible();
  await page.getByLabel(/^tanggal$|^date$/i).fill("2099-10-10");
  await page.getByLabel(/^waktu$|^time$/i).selectOption("10:30");
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();
  await expect(page.getByText("Rp 240.000", { exact: true })).toBeVisible();
  await page
    .getByRole("button", { name: /konfirmasi reservasi|confirm booking/i })
    .click();
  await expect(page).toHaveURL(/\/bookings\/SJ-/);
  await expect(page.getByText("Rp 240.000", { exact: true })).toBeVisible();
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
    const addCategory = page.getByRole("button", {
      name: /tambah kategori|add category/i,
    });
    await expectHorizontallyReachable(page, addCategory);
    await addCategory.click();
    const categoryEditor = page.locator("[data-category-editor]");
    await categoryEditor
      .getByLabel(/slug kategori|category slug/i)
      .fill(category.slug);
    await categoryEditor
      .getByLabel(/nama kategori.*bahasa indonesia|indonesian category name/i)
      .fill(category.nameId);
    await categoryEditor
      .getByLabel(/nama kategori.*bahasa inggris|english category name/i)
      .fill(category.nameEn);
    await categoryEditor
      .getByLabel(/urutan tampilan|display order/i)
      .fill("40");
    await categoryEditor
      .getByLabel(/foto kategori|category image/i)
      .setInputFiles(catalogFixture("category.jpg"));
    await categoryEditor
      .getByRole("button", { name: /buat kategori|create category/i })
      .click();

    const draftCategory = catalogRow(page, "category");
    await expect(draftCategory).toBeVisible();
    await expect(
      draftCategory.locator('[data-catalog-status="draft"]'),
    ).toBeVisible();
    expect(
      (await page.request.get(`/services/${servicePackage.slug}`)).status(),
    ).toBe(404);

    await confirmLifecycleAction(draftCategory, "publish");
    await expect(
      catalogRow(page, "category").locator('[data-catalog-status="published"]'),
    ).toBeVisible();

    await page.setViewportSize({ width: 640, height: 760 });
    // A 640 CSS-pixel viewport is the reflow equivalent of 1280 px at 200% zoom.
    await expectNoHorizontalPageOverflow(page);
    const addPackage = page.getByRole("link", {
      name: /tambah paket|add package/i,
    });
    await expectHorizontallyReachable(page, addPackage);
    await addPackage.click();
    await expect(page).toHaveURL("/admin/catalog/packages/create");
    await expectNoHorizontalPageOverflow(page);
    await fillPackageForm(page);
    const saveDraft = page.getByRole("button", {
      name: /simpan draf|save draft/i,
    });
    await expectHorizontallyReachable(page, saveDraft);
    await saveDraft.click();
    await expect(page).toHaveURL("/admin/catalog");
    await expect(
      catalogRow(page, "package").locator('[data-catalog-status="draft"]'),
    ).toBeVisible();

    await expectPublicVisibility(page, "/", false);
    await expectPublicVisibility(page, "/services", false);
    await expectDetailVisibility(page, false);
    await page.goto(
      `/booking?service=${servicePackage.slug}&mode=regular&duration=75&extension=${servicePackage.extensionCode}`,
    );
    await expect(
      page.getByText(servicePackage.nameId, { exact: true }),
    ).toHaveCount(0);
    await submitHiddenPackageQuote(
      page,
      "11111111-1111-4111-8111-111111111111",
    );

    await page.goto("/admin/catalog");
    await confirmLifecycleAction(catalogRow(page, "package"), "publish");
    await expect(
      catalogRow(page, "package").locator('[data-catalog-status="published"]'),
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

    await createBookingWithPublishedPrice(page);

    await page.goto("/admin/catalog");
    await confirmLifecycleAction(catalogRow(page, "package"), "archive");
    await expect(
      catalogRow(page, "package").locator('[data-catalog-status="archived"]'),
    ).toBeVisible();
    await expectPublicVisibility(page, "/", false);
    await expectPublicVisibility(page, "/services", false);
    await expectDetailVisibility(page, false);
    await submitHiddenPackageQuote(
      page,
      "22222222-2222-4222-8222-222222222222",
    );

    await page.goto("/admin/catalog");
    await confirmLifecycleAction(catalogRow(page, "package"), "restore");
    await expect(
      catalogRow(page, "package").locator('[data-catalog-status="published"]'),
    ).toBeVisible();
    await expectPublicVisibility(page, "/services", true);
    await expectDetailVisibility(page, true);

    const editUrl = `/admin/catalog/packages/${servicePackage.slug}/edit`;
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
