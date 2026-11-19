import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import path from "node:path";
import { resetTestingDatabase } from "./testing-environment";

test.beforeAll(resetTestingDatabase);

const locales = [
  { locale: "id", aboutHeading: "Istirahat sebentar dari kesibukan" },
  { locale: "en", aboutHeading: "A break from the busy city" },
] as const;

const viewports = [
  { width: 320, height: 800, name: "small-mobile" },
  { width: 390, height: 844, name: "mobile" },
  { width: 1440, height: 960, name: "desktop" },
] as const;

test("homepage shows only sourced claims and no stock-photo gallery", async ({
  page,
}) => {
  const screenshotDirectory = path.resolve(
    process.cwd(),
    "tests/e2e/screenshots/home-credibility",
  );
  await mkdir(screenshotDirectory, { recursive: true });

  for (const viewport of viewports) {
    await page.setViewportSize(viewport);

    for (const copy of locales) {
      await page.goto("/");
      const languageButton = page.getByRole("button", {
        name: copy.locale.toUpperCase(),
        exact: true,
      });
      if ((await languageButton.getAttribute("aria-pressed")) !== "true") {
        await languageButton.click();
      }
      await expect(languageButton).toHaveAttribute("aria-pressed", "true");

      const hero = page.locator(".hero");
      const menu = page.locator("#menu");
      const publishedPackages = menu.locator("[data-package-card]");
      await expect(hero).toBeVisible();
      await expect(publishedPackages).toHaveCount(2);
      await expect(hero.locator(".hero__meta")).toHaveCount(0);
      await expect(hero).not.toContainText(
        /4\.9|2,000\+|2\.000\+|average rating|rata-rata ulasan|guests pampered|tamu dimanjakan|packages available|paket tersedia/i,
      );
      await expect(page.locator(".badge-float")).toHaveCount(0);

      await expect(page.locator(".tst-grid")).toHaveCount(0);
      for (const author of [
        "Dewi Anggraini",
        "Putri Maharani",
        "Rangga Saputra",
      ]) {
        await expect(page.getByText(author, { exact: true })).toHaveCount(0);
      }
      for (const historicalTreatment of [
        "Hot Stone Massage",
        "Brightening Facial",
        "Serenity Package",
      ]) {
        await expect(
          page.getByText(historicalTreatment, { exact: true }),
        ).toHaveCount(0);
      }

      // Stock photos are not shown as the spa: no gallery, no About photo.
      await expect(page.locator("#gallery, .gallery, .lightbox")).toHaveCount(
        0,
      );
      const about = page.locator("#about");
      await expect(about.locator("img")).toHaveCount(0);
      await expect(about.getByRole("heading", { level: 2 })).toHaveText(
        copy.aboutHeading,
      );
      await expect(page.locator(".cta-band a")).toHaveAttribute(
        "href",
        "/booking",
      );

      const noHorizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      );
      expect(noHorizontalOverflow).toBe(true);

      const suffix = `${copy.locale}-${viewport.name}`;
      if (
        (copy.locale === "id" && viewport.name === "small-mobile") ||
        (copy.locale === "en" && viewport.name === "desktop")
      ) {
        await hero.screenshot({
          path: path.join(screenshotDirectory, `hero-${suffix}.png`),
        });
      }
    }
  }
});
