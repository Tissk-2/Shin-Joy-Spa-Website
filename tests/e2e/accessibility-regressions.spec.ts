import { expect, test } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";

import { registerCustomer, verifyCustomer } from "./helpers/auth";
import { resetTestingDatabase } from "./testing-environment";

test.beforeAll(resetTestingDatabase);

const screenshotDirectory = path.resolve(
  "outputs/first-corrective-pass-2026-10-11",
);

function rgbComponents(color: string): number[] {
  return color.match(/[\d.]+/g)?.map(Number) ?? [];
}

function linearChannel(channel: number): number {
  const value = channel / 255;
  return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
}

function contrastRatio(foreground: string, background: string): number {
  const fg = rgbComponents(foreground);
  const bg = rgbComponents(background);
  const luminance = (color: number[]) =>
    0.2126 * linearChannel(color[0]!) +
    0.7152 * linearChannel(color[1]!) +
    0.0722 * linearChannel(color[2]!);
  const light = Math.max(luminance(fg), luminance(bg));
  const dark = Math.min(luminance(fg), luminance(bg));

  return (light + 0.05) / (dark + 0.05);
}

async function noHorizontalOverflow(page: import("@playwright/test").Page) {
  return page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
}

test("closed mobile navigation is skipped and Escape restores focus", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");

  const brand = page.getByRole("link", { name: "Shin Joy Spa Malang" }).first();
  const toggle = page.locator(".nav-toggle");
  await brand.focus();
  await page.keyboard.press("Tab");
  expect(
    await page.evaluate(
      () => document.activeElement?.closest("#primary-nav") !== null,
    ),
  ).toBe(false);

  await toggle.focus();
  await page.keyboard.press("Enter");
  await expect(toggle).toHaveAttribute("aria-expanded", "true");
  await expect(page.locator("#primary-nav .nav-link").first()).toBeFocused();

  const lastDrawerControl = page
    .locator("#primary-nav a[href], #primary-nav button:not([disabled])")
    .last();
  await lastDrawerControl.focus();
  await page.keyboard.press("Tab");
  await expect(toggle).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(page.locator("#primary-nav .nav-link").first()).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(toggle).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(lastDrawerControl).toBeFocused();

  await page.keyboard.press("Escape");

  await expect(toggle).toHaveAttribute("aria-expanded", "false");
  await expect(toggle).toBeFocused();
  expect(await page.evaluate(() => document.body.style.overflow)).toBe("");

  await page.keyboard.press("Enter");
  await expect(page.locator("#primary-nav .nav-link").first()).toBeFocused();
  await toggle.click();
  await expect(toggle).toHaveAttribute("aria-expanded", "false");
  await expect(toggle).toBeFocused();
  expect(await page.evaluate(() => document.body.style.overflow)).toBe("");

  await page.keyboard.press("Tab");
  expect(
    await page.evaluate(
      () => document.activeElement?.closest("#primary-nav") !== null,
    ),
  ).toBe(false);
});

test("mobile drawer links have separate touch rows and a visible close icon", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");

  const toggle = page.locator(".nav-toggle");
  await toggle.click();
  const navLinks = page.locator("#primary-nav .nav-link");
  const rows = await navLinks.evaluateAll((links) =>
    links.map((link) => {
      const { y, height } = link.getBoundingClientRect();
      return { y, height };
    }),
  );
  expect(rows.length).toBeGreaterThan(1);
  for (const row of rows) expect(row.height).toBeGreaterThanOrEqual(44);
  for (let index = 0; index < rows.length - 1; index += 1) {
    expect(rows[index]!.y + rows[index]!.height).toBeLessThanOrEqual(
      rows[index + 1]!.y,
    );
  }

  const closeContrast = await toggle.evaluate((button) => {
    const drawer = document.querySelector("#primary-nav")!;
    return {
      foreground: getComputedStyle(button).color,
      background: getComputedStyle(drawer).backgroundColor,
    };
  });
  expect(
    contrastRatio(closeContrast.foreground, closeContrast.background),
  ).toBeGreaterThanOrEqual(3);

  for (const width of [320, 390, 768]) {
    await page.setViewportSize({ width, height: 844 });
    const overflow = await noHorizontalOverflow(page);
    expect(
      overflow.scrollWidth,
      "open drawer at " + width + "px",
    ).toBeLessThanOrEqual(overflow.clientWidth);
  }

  await page.setViewportSize({ width: 390, height: 844 });
  mkdirSync(screenshotDirectory, { recursive: true });
  await page.screenshot({
    path: path.join(screenshotDirectory, "mobile-drawer-open.png"),
  });
});

test("landscape mobile drawer scrolls to and activates its final action", async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: "no-preference" });
  const cases = [
    { width: 568, height: 320 },
    { width: 844, height: 390 },
  ];
  const routes = ["/services", "/"];
  const screenshots = path.resolve(
    "outputs/mobile-drawer-landscape-2026-10-11",
  );

  mkdirSync(screenshots, { recursive: true });

  for (const viewport of cases) {
    for (const route of routes) {
      await page.setViewportSize(viewport);
      await page.goto(route);

      const toggle = page.locator(".nav-toggle");
      const drawer = page.locator("#primary-nav");
      const finalAction = drawer.locator('a[href="/login"]');
      await toggle.click();
      await expect(toggle).toHaveAttribute("aria-expanded", "true");
      await expect
        .poll(
          async () => {
            const bounds = await drawer.boundingBox();
            return bounds
              ? Math.abs(bounds.x + bounds.width - viewport.width)
              : Number.POSITIVE_INFINITY;
          },
          { timeout: 2_000 },
        )
        .toBeLessThan(0.5);
      await expect(drawer.locator(".nav-link").first()).toBeFocused();

      for (let tab = 0; tab < 5; tab += 1) {
        await page.keyboard.press("Tab");
        const focusedBounds = await page.evaluate(() => {
          const bounds = document.activeElement!.getBoundingClientRect();
          return {
            left: bounds.left,
            top: bounds.top,
            right: bounds.right,
            bottom: bounds.bottom,
          };
        });
        expect(focusedBounds.left).toBeGreaterThanOrEqual(0);
        expect(focusedBounds.top).toBeGreaterThanOrEqual(0);
        expect(focusedBounds.right).toBeLessThanOrEqual(viewport.width);
        expect(focusedBounds.bottom).toBeLessThanOrEqual(viewport.height);
      }
      await expect(finalAction).toBeFocused();

      const focusedActionBounds = await finalAction.boundingBox();
      expect(focusedActionBounds).not.toBeNull();
      expect(focusedActionBounds!.x).toBeGreaterThanOrEqual(0);
      expect(focusedActionBounds!.y).toBeGreaterThanOrEqual(0);
      expect(
        focusedActionBounds!.x + focusedActionBounds!.width,
      ).toBeLessThanOrEqual(viewport.width);
      expect(
        focusedActionBounds!.y + focusedActionBounds!.height,
      ).toBeLessThanOrEqual(viewport.height);

      const initialCloseBounds = await toggle.boundingBox();
      expect(initialCloseBounds).not.toBeNull();
      expect(initialCloseBounds!.x).toBeGreaterThanOrEqual(0);
      expect(initialCloseBounds!.y).toBeGreaterThanOrEqual(0);
      expect(
        initialCloseBounds!.x + initialCloseBounds!.width,
      ).toBeLessThanOrEqual(viewport.width);
      expect(
        initialCloseBounds!.y + initialCloseBounds!.height,
      ).toBeLessThanOrEqual(viewport.height);

      await page.keyboard.press("Escape");
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(toggle).toBeFocused();
      expect(await page.evaluate(() => document.body.style.overflow)).toBe("");
      expect(
        await drawer.evaluate((element) => (element as HTMLElement).inert),
      ).toBe(true);

      await page.reload();
      await toggle.click();
      await expect(toggle).toHaveAttribute("aria-expanded", "true");

      await expect
        .poll(
          async () => {
            const bounds = await drawer.boundingBox();
            return bounds
              ? Math.abs(bounds.x + bounds.width - viewport.width)
              : Number.POSITIVE_INFINITY;
          },
          { timeout: 2_000 },
        )
        .toBeLessThan(0.5);
      const drawerBounds = await drawer.boundingBox();
      expect(drawerBounds).not.toBeNull();
      const wheelPoint = {
        x: drawerBounds!.x + drawerBounds!.width / 2,
        y: drawerBounds!.y + Math.min(drawerBounds!.height / 2, 160),
      };
      expect(wheelPoint.x).toBeGreaterThanOrEqual(0);
      expect(wheelPoint.x).toBeLessThan(viewport.width);
      expect(wheelPoint.y).toBeGreaterThanOrEqual(0);
      expect(wheelPoint.y).toBeLessThan(viewport.height);
      const wheelTarget = await page.evaluate(({ x, y }) => {
        const target = document.elementFromPoint(x, y);
        const nav = document.querySelector("#primary-nav");
        return {
          insideViewport:
            x >= 0 && x < window.innerWidth && y >= 0 && y < window.innerHeight,
          hitsDrawer: Boolean(
            nav && target && (target === nav || nav.contains(target)),
          ),
        };
      }, wheelPoint);
      expect(wheelTarget.insideViewport).toBe(true);
      expect(wheelTarget.hitsDrawer).toBe(true);

      await page.mouse.move(wheelPoint.x, wheelPoint.y);
      await page.mouse.wheel(0, 480);
      await expect
        .poll(() => drawer.evaluate((element) => element.scrollTop), {
          timeout: 2_000,
        })
        .toBeGreaterThan(0);

      const finalActionBounds = await finalAction.boundingBox();
      expect(finalActionBounds).not.toBeNull();
      expect(finalActionBounds!.x).toBeGreaterThanOrEqual(0);
      expect(finalActionBounds!.y).toBeGreaterThanOrEqual(0);
      expect(
        finalActionBounds!.x + finalActionBounds!.width,
      ).toBeLessThanOrEqual(viewport.width);
      expect(
        finalActionBounds!.y + finalActionBounds!.height,
      ).toBeLessThanOrEqual(viewport.height);

      const closeBounds = await toggle.boundingBox();
      expect(closeBounds).not.toBeNull();
      expect(closeBounds!.x).toBeGreaterThanOrEqual(0);
      expect(closeBounds!.y).toBeGreaterThanOrEqual(0);
      expect(closeBounds!.x + closeBounds!.width).toBeLessThanOrEqual(
        viewport.width,
      );
      expect(closeBounds!.y + closeBounds!.height).toBeLessThanOrEqual(
        viewport.height,
      );

      await page.screenshot({
        path: path.join(
          screenshots,
          `${viewport.width}x${viewport.height}-${route === "/" ? "home" : "services"}.png`,
        ),
      });
      await finalAction.click();
      await expect(page).toHaveURL(/\/login$/);
    }
  }
});

test("desktop navigation remains usable after crossing from an open tablet drawer", async ({
  page,
}) => {
  await page.setViewportSize({ width: 768, height: 900 });
  await page.goto("/");
  const toggle = page.locator(".nav-toggle");
  await toggle.click();
  await expect(toggle).toHaveAttribute("aria-expanded", "true");

  await page.setViewportSize({ width: 1440, height: 900 });
  await expect(toggle).toBeHidden();
  await expect(toggle).toHaveAttribute("aria-expanded", "false");
  await expect(page.locator("#primary-nav .nav-link").first()).toBeVisible();
  expect(await page.evaluate(() => document.body.style.overflow)).toBe("");
  const overflow = await noHorizontalOverflow(page);
  expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth);

  await page.locator('#primary-nav a[href="/services"]').click();
  await expect(page).toHaveURL(/\/services$/);
});

test("gallery dialog receives focus, traps it, isolates the page, and restores its opener", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/");

  const thumbnail = page.locator("#gallery .gallery a").first();
  await thumbnail.scrollIntoViewIfNeeded();
  await thumbnail.focus();
  await page.keyboard.press("Enter");

  const dialog = page.locator(".lightbox");
  const close = dialog.locator(".lightbox__close");
  await expect(dialog).toHaveAttribute("aria-hidden", "false");
  expect
    .soft(await close.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await page.keyboard.press("Escape");
  await page.waitForTimeout(50);
  expect.soft(await dialog.getAttribute("aria-hidden")).toBe("true");
  expect
    .soft(
      await thumbnail.evaluate((element) => document.activeElement === element),
    )
    .toBe(true);

  if ((await dialog.getAttribute("aria-hidden")) === "true") {
    await thumbnail.focus();
    await page.keyboard.press("Enter");
    await expect(dialog).toHaveAttribute("aria-hidden", "false");
  }

  await close.focus();
  const previous = dialog.locator(".lightbox__prev");
  const next = dialog.locator(".lightbox__next");
  await page.keyboard.press("Shift+Tab");
  await expect(next).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(close).toBeFocused();

  const backgroundFocus = await thumbnail.evaluate((element) => {
    (element as HTMLElement).focus();
    return {
      inert: element.closest("[inert]") !== null,
      focusStayedInDialog:
        document.activeElement === document.querySelector(".lightbox__close"),
    };
  });
  expect.soft(backgroundFocus.inert).toBe(true);
  expect.soft(backgroundFocus.focusStayedInDialog).toBe(true);

  await close.focus();
  const image = dialog.locator(".lightbox__img");
  const firstImage = await image.getAttribute("src");
  await next.click();
  await expect(image).not.toHaveAttribute("src", firstImage ?? "");
  await previous.click();
  await expect(image).toHaveAttribute("src", firstImage ?? "");
  await close.click();
  await expect(dialog).toHaveAttribute("aria-hidden", "true");
  await expect(thumbnail).toBeFocused();

  await page.emulateMedia({ reducedMotion: "reduce" });
  await thumbnail.focus();
  await page.keyboard.press("Enter");
  await expect(dialog).toHaveAttribute("aria-hidden", "false");
  expect(
    await close.evaluate((element) => document.activeElement === element),
  ).toBe(true);
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveAttribute("aria-hidden", "true");
  await expect(thumbnail).toBeFocused();
});

test("catalog prices and shared address meet normal-text contrast", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1100 });
  await page.goto("/services");
  const cards = page.locator("[data-package-card]");
  await expect(cards.first()).toBeVisible();

  const measurements = await cards.evaluateAll((elements) => {
    const parseColor = (value: string): [number, number, number, number] => {
      const numbers = value.match(/[\d.]+/g)?.map(Number) ?? [];
      return [
        numbers[0] ?? 0,
        numbers[1] ?? 0,
        numbers[2] ?? 0,
        numbers[3] ?? 1,
      ];
    };
    const over = (
      foreground: [number, number, number, number],
      background: [number, number, number, number],
    ): [number, number, number, number] => {
      const alpha = foreground[3] + background[3] * (1 - foreground[3]);
      if (!alpha) return [0, 0, 0, 0];
      return [
        (foreground[0] * foreground[3] +
          background[0] * background[3] * (1 - foreground[3])) /
          alpha,
        (foreground[1] * foreground[3] +
          background[1] * background[3] * (1 - foreground[3])) /
          alpha,
        (foreground[2] * foreground[3] +
          background[2] * background[3] * (1 - foreground[3])) /
          alpha,
        alpha,
      ];
    };
    const linear = (channel: number) => {
      const value = channel / 255;
      return value <= 0.04045
        ? value / 12.92
        : ((value + 0.055) / 1.055) ** 2.4;
    };
    const luminance = (color: [number, number, number, number]) =>
      0.2126 * linear(color[0]) +
      0.7152 * linear(color[1]) +
      0.0722 * linear(color[2]);
    const contrast = (left: number, right: number) =>
      (Math.max(left, right) + 0.05) / (Math.min(left, right) + 0.05);
    const measure = (element: Element, label: string) => {
      const style = getComputedStyle(element);
      const layers: string[] = [];
      for (
        let ancestor: Element | null = element;
        ancestor;
        ancestor = ancestor.parentElement
      ) {
        layers.push(getComputedStyle(ancestor).backgroundColor);
        if (ancestor === document.body) break;
      }
      let background: [number, number, number, number] = [255, 255, 255, 1];
      for (const layer of layers.reverse()) {
        const color = parseColor(layer);
        if (color[3] > 0) background = over(color, background);
      }
      const foreground = over(parseColor(style.color), background);
      return {
        label,
        foreground: style.color,
        background:
          "rgb(" + background.slice(0, 3).map(Math.round).join(", ") + ")",
        ratio: contrast(luminance(foreground), luminance(background)),
      };
    };

    const packageMeasurements = elements.flatMap((card) => {
      const name = card.getAttribute("aria-label") ?? "package";
      return [
        ["duration heading", ".package-menu-card__comparison thead th"],
        ["duration row", ".package-menu-card__comparison tbody th"],
        ["full price", ".package-menu-card__comparison tbody td"],
        ["benefit", ".package-menu-card__benefit"],
      ].flatMap(([label, selector]) =>
        Array.from(card.querySelectorAll(selector)).map((element, index) =>
          measure(element, `${name} ${label} ${index + 1}`),
        ),
      );
    });
    const addressMeasurements = Array.from(
      document.querySelectorAll(".catalog-menu-footer address strong, .catalog-menu-footer address span"),
    ).map((element, index) => measure(element, `shared address ${index + 1}`));
    return [...packageMeasurements, ...addressMeasurements];
  });

  expect(measurements.map(({ label }) => label)).toEqual(
    expect.arrayContaining([
      "Nezuko duration heading 1",
      "Nezuko duration row 1",
      "Nezuko full price 1",
      "Nezuko VIP duration heading 1",
      "Nezuko VIP duration row 1",
      "Nezuko VIP full price 1",
      "shared address 1",
      "shared address 2",
    ]),
  );
  for (const measurement of measurements) {
    expect(
      measurement.ratio,
      measurement.label +
        ": " +
        measurement.foreground +
        " on " +
        measurement.background,
    ).toBeGreaterThanOrEqual(4.5);
  }

  mkdirSync(screenshotDirectory, { recursive: true });
  writeFileSync(
    path.join(screenshotDirectory, "pricing-contrast-ratios.json"),
    JSON.stringify(measurements, null, 2) + "\n",
  );

  for (const card of await cards.all()) {
    for (const price of await card
      .locator(".package-menu-card__comparison tbody td:last-child")
      .all()) {
      expect((await price.textContent())?.replace(/\s/g, "")).toMatch(
        /^Rp\d+\.\d{3}$/,
      );
    }
  }

  mkdirSync(screenshotDirectory, { recursive: true });
  await page
    .locator(".public-catalog__grid")
    .first()
    .screenshot({
      path: path.join(screenshotDirectory, "pricing-cards.png"),
    });
});

test("account deletion wording explains retained booking details in both languages", async ({
  page,
}) => {
  const email = "account-deletion-copy@example.com";
  await registerCustomer(page, email);
  await verifyCustomer(page, email);
  await page.goto("/settings/profile");

  const setLocale = async (locale: "id" | "en") => {
    const xsrf = (await page.context().cookies()).find(
      ({ name }) => name === "XSRF-TOKEN",
    );
    expect(xsrf).toBeDefined();
    const response = await page.request.post("/locale", {
      data: { locale },
      headers: { "X-XSRF-TOKEN": decodeURIComponent(xsrf!.value) },
    });
    expect(response.ok()).toBe(true);
    await page.goto("/settings/profile");
  };
  const openDialog = async (locale: "id" | "en") => {
    await setLocale(locale);
    await page
      .getByRole("button", { name: /delete account|hapus akun/i })
      .first()
      .click();
    return page.getByRole("dialog");
  };

  const indonesian = await openDialog("id");
  const indonesianCopy = await indonesian.innerText();
  expect(indonesianCopy).toContain("Riwayat reservasi tetap disimpan");
  expect(indonesianCopy).toContain(
    "nama, alamat email, nomor telepon, dan catatan",
  );
  expect(indonesianCopy).not.toMatch(/seluruh data.*dihapus permanen/i);
  await page.keyboard.press("Escape");

  const english = await openDialog("en");
  const englishCopy = await english.innerText();
  expect(englishCopy).toContain("Booking history will remain");
  expect(englishCopy).toContain("name, email address, phone number, and notes");
  expect(englishCopy).not.toMatch(/all.*data.*permanently deleted/i);
});
