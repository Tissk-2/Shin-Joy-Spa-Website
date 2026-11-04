import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import path from "node:path";

const locales = [
  {
    locale: "id",
    galleryHeading: "Detail spa dan perawatan",
    gallerySubtitle: "Gambar detail perawatan dan suasana spa.",
    galleryDescription:
      "Gambar-gambar ini menampilkan detail spa dan perawatan; kami belum dapat memastikan bahwa gambar tersebut memperlihatkan lokasi Shin Joy Spa.",
    packageLabel: "Paket tersedia",
    captions: [
      "Minyak pijat diaplikasikan saat perawatan.",
      "Perawatan wajah sedang dilakukan.",
      "Handuk, botol, lilin, dan bunga.",
      "Batu panas diletakkan di atas punggung.",
      "Handuk, lilin, dan diffuser.",
      "Seseorang bersantai di dalam bathtub.",
      "Tangan diletakkan di atas handuk.",
      "Minyak pijat dituangkan ke telapak tangan.",
    ],
  },
  {
    locale: "en",
    galleryHeading: "Spa and treatment details",
    gallerySubtitle: "Images of spa treatments and settings.",
    galleryDescription:
      "These images show spa settings and treatments; we have not verified that they depict Shin Joy Spa.",
    packageLabel: "Packages available",
    captions: [
      "Massage oil is applied during a treatment.",
      "A facial treatment in progress.",
      "A towel, bottle, candle, and flowers.",
      "Hot stones rest on a back.",
      "Towels, candles, and a diffuser.",
      "A person relaxing in a bathtub.",
      "Hands resting on a towel.",
      "Massage oil is poured into an open palm.",
    ],
  },
] as const;

const viewports = [
  { width: 320, height: 800, name: "small-mobile" },
  { width: 390, height: 844, name: "mobile" },
  { width: 1440, height: 960, name: "desktop" },
] as const;

test("homepage shows only sourced claims and describes unverified gallery imagery", async ({
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
      await expect(hero.locator(".hero__meta b")).toHaveText("2");
      await expect(hero.locator(".hero__meta")).toContainText(
        copy.packageLabel,
      );
      await expect(hero.locator(".hero__meta")).not.toContainText(
        /4\.9|2,000\+|2\.000\+|average rating|rata-rata ulasan|guests pampered|tamu dimanjakan/i,
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

      const gallery = page.locator("#gallery");
      await expect(
        gallery.getByRole("heading", { level: 2, name: copy.galleryHeading }),
      ).toBeVisible();
      await expect(
        gallery.getByText(copy.gallerySubtitle, { exact: true }),
      ).toBeVisible();
      await expect(gallery.getByRole("note")).toHaveText(
        copy.galleryDescription,
      );
      await expect(gallery.locator(".gallery__item")).toHaveCount(8);
      const captions = await gallery
        .locator(".gallery__caption")
        .evaluateAll((elements) =>
          elements.map((element) => element.textContent?.trim() ?? ""),
        );
      expect(captions).toEqual(copy.captions);
      await expect(
        gallery.locator(".gallery__item img").first(),
      ).toHaveAttribute("alt", copy.captions[0]);
      const finalGalleryItem = gallery.locator(".gallery__item").last();
      await expect(finalGalleryItem.locator(".gallery__caption")).toHaveText(
        copy.captions[7],
      );
      await expect(finalGalleryItem.locator("img")).toHaveAttribute(
        "alt",
        copy.captions[7],
      );
      await expect(
        gallery.getByRole("heading", { level: 2 }),
      ).not.toContainText(/our space|ruangan kami/i);

      const noHorizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      );
      expect(noHorizontalOverflow).toBe(true);

      const suffix = `${copy.locale}-${viewport.name}`;
      await gallery.scrollIntoViewIfNeeded();
      await expect(finalGalleryItem).toBeVisible();
      await finalGalleryItem.click();
      const lightbox = page.getByRole("dialog");
      await expect(lightbox).toBeVisible();
      await expect(lightbox.locator(".lightbox__img")).toHaveAttribute(
        "alt",
        copy.captions[7],
      );
      await page.keyboard.press("Escape");
      await expect(lightbox).toBeHidden();
      if (
        (copy.locale === "id" && viewport.name === "small-mobile") ||
        (copy.locale === "en" && viewport.name === "desktop")
      ) {
        await hero.screenshot({
          path: path.join(screenshotDirectory, `hero-${suffix}.png`),
        });
        await gallery.screenshot({
          path: path.join(screenshotDirectory, `gallery-${suffix}.png`),
          style: "header { position: static !important; }",
        });
      }
    }
  }
});
