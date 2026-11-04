import { expect, test, type Page } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import path from "node:path";

import { registerVerifiedAdmin } from "./helpers/admin";
import { projectRoot } from "./testing-environment";

const addressId = "Jl. Mawar & Melati No. 8, Malang";
const addressEn = "8 Rose & Jasmine Street, Malang";
const phoneId = "+62 811-2345-6789";
const phoneEn = "+62 812-9876-5432";

async function saveContact(
  page: Page,
  addressIndonesian: string,
  addressEnglish: string,
  phoneIndonesian: string,
  phoneEnglish: string,
): Promise<void> {
  await page.goto("/admin/content");
  await page.locator("#content-loc-address-id").fill(addressIndonesian);
  await page.locator("#content-loc-address-en").fill(addressEnglish);
  await page.locator("#content-loc-phone-id").fill(phoneIndonesian);
  await page.locator("#content-loc-phone-en").fill(phoneEnglish);
  await page.locator("[data-content-save]").click();
  await expect(page.getByRole("status")).toBeVisible();
}

async function expectMapQuery(page: Page, address: string): Promise<void> {
  const iframe = new URL(
    (await page.locator("#contact iframe").getAttribute("src")) ?? "",
  );
  const directions = new URL(
    (await page
      .locator('#contact a[href*="google.com/maps"]')
      .getAttribute("href")) ?? "",
  );

  expect(iframe.searchParams.get("q")).toBe(address);
  expect(directions.searchParams.get("q")).toBe(address);
}

async function captureContactScreenshots(page: Page): Promise<void> {
  const outputDirectory = path.join(projectRoot, "outputs/contact-information");
  await mkdir(outputDirectory, { recursive: true });

  for (const viewport of [
    { name: "mobile", width: 390, height: 844 },
    { name: "desktop", width: 1440, height: 900 },
  ]) {
    await page.setViewportSize({
      width: viewport.width,
      height: viewport.height,
    });
    await page.goto("/");
    const contactDetails = page.locator("#contact .split__body");
    await contactDetails.scrollIntoViewIfNeeded();
    await contactDetails.screenshot({
      path: path.join(outputDirectory, `home-contact-${viewport.name}.png`),
    });

    await page.goto("/booking?service=nezuko");
    const summary = page.locator(".summary-card");
    await summary.scrollIntoViewIfNeeded();
    await summary.screenshot({
      path: path.join(outputDirectory, `booking-contact-${viewport.name}.png`),
    });
  }
}

test("CMS contact edits reach public links, booking action, and the downloaded booking card", async ({
  page,
}) => {
  await page.addInitScript(() => {
    Object.defineProperty(window, "__contactCardText", {
      configurable: false,
      value: [] as string[],
    });
    const nativeFillText = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (text, ...args) {
      (
        window as Window & { __contactCardText: string[] }
      ).__contactCardText.push(String(text));
      nativeFillText.call(this, text, ...args);
    };
  });

  await registerVerifiedAdmin(page, "contact-consistency-admin@example.com");
  await saveContact(page, addressId, addressEn, phoneId, phoneEn);

  await page.goto("/");
  await expect(page.locator("#contact")).toContainText(addressId);
  await expect(page.locator("#contact")).toContainText(phoneId);
  await expect(page.locator(".catalog-menu-footer")).toContainText(addressId);
  await expect(page.locator(".footer-contact")).toContainText(addressId);
  await expect(page.locator(".footer-contact")).toContainText(phoneId);
  await expectMapQuery(page, addressId);

  await page.getByRole("button", { name: "EN", exact: true }).click();
  await expect(page.locator("#contact")).toContainText(addressEn);
  await expect(page.locator("#contact")).toContainText(phoneEn);
  await expect(page.locator(".catalog-menu-footer")).toContainText(addressEn);
  await expect(page.locator(".footer-contact")).toContainText(addressEn);
  await expect(page.locator(".footer-contact")).toContainText(phoneEn);
  await expectMapQuery(page, addressEn);

  await captureContactScreenshots(page);

  await page.goto("/services");
  await expect(page.locator(".catalog-menu-footer")).toContainText(addressEn);
  await expect(page.locator(".footer-contact")).toContainText(phoneEn);

  await page.goto("/booking?service=nezuko");
  await expect(page.locator(".summary-card__help a")).toHaveAttribute(
    "href",
    "https://wa.me/6281298765432",
  );
  const configurator = page.locator(".package-configurator");
  await configurator.getByRole("radio", { name: /regular|reguler/i }).click();
  await configurator.getByRole("radio", { name: /60/ }).click();
  await page.getByLabel(/nama lengkap|full name/i).fill("Ayu Contact Test");
  await page
    .getByLabel(/nomor whatsapp|whatsapp number/i)
    .fill("+62 821-0000-1111");
  await page.getByLabel(/^tanggal$|^date$/i).fill("2099-09-11");
  await page.getByLabel(/^waktu$|^time$/i).selectOption("10:30");
  await page.getByLabel(/jumlah tamu|number of guests/i).selectOption("1");
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();
  await page
    .getByRole("button", { name: /konfirmasi reservasi|confirm booking/i })
    .click();

  await expect(page).toHaveURL(/\/bookings\/SJ-/);
  await page
    .getByRole("button", { name: /download booking card|unduh ringkasan/i })
    .click();
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          (window as Window & { __contactCardText: string[] })
            .__contactCardText,
      ),
    )
    .toContain(addressEn);
  expect(
    await page.evaluate(
      () =>
        (window as Window & { __contactCardText: string[] }).__contactCardText,
    ),
  ).not.toContain(`${addressEn}, Malang`);

  for (const invalidPhone of [
    "62 0812-3456-7890",
    "+62 0812-3456-7890",
    "0062 0812-3456-7890",
  ]) {
    await saveContact(page, addressId, addressEn, phoneId, invalidPhone);
    await page.goto("/booking?service=nezuko");
    await expect(page.locator(".summary-card__help a")).toHaveCount(0);
  }
});

test.afterEach(async ({ page }) => {
  await page.goto("/admin/content");
  if ((await page.locator("#content-loc-address-id").count()) === 0) return;

  await saveContact(page, "", "", "", "");
});
