import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";
import path from "node:path";

const viewports = [
  { width: 320, height: 860 },
  { width: 390, height: 844 },
  { width: 768, height: 900 },
  { width: 1440, height: 960 },
] as const;

const menuRoutes = [
  { path: "/", section: "#menu", name: "home-menu" },
  {
    path: "/services",
    section: ".public-catalog--page",
    name: "services-menu",
  },
] as const;

test("treatment menus compare published prices, disclose extras accessibly, and book both packages", async ({
  page,
}) => {
  const screenshotDirectory = path.resolve(
    process.cwd(),
    "outputs/catalog-presentation/after",
  );
  await mkdir(screenshotDirectory, { recursive: true });

  for (const route of menuRoutes) {
    for (const viewport of [viewports[1], viewports[3]]) {
      await page.setViewportSize(viewport);
      await page.goto(route.path);
      const idLanguage = page.getByRole("button", { name: "ID", exact: true });
      if ((await idLanguage.getAttribute("aria-pressed")) !== "true") {
        await idLanguage.click();
      }
      await expect(idLanguage).toHaveAttribute("aria-pressed", "true");
      const section = page.locator(route.section);
      await expect(section).toBeVisible();
      await section.scrollIntoViewIfNeeded();
      await section.screenshot({
        path: path.join(
          screenshotDirectory,
          `${route.name}-${viewport.width}.png`,
        ),
      });
      const disclosures = section.locator(".package-menu-card__optional");
      for (const disclosure of await disclosures.all()) {
        await disclosure.locator("summary").click();
        await expect(disclosure).toHaveAttribute("open", "");
      }
      await section.screenshot({
        path: path.join(
          screenshotDirectory,
          `${route.name}-${viewport.width}-expanded.png`,
        ),
        style: "header { position: static !important; }",
      });
    }
  }

  for (const route of menuRoutes) {
    for (const viewport of viewports) {
      await page.setViewportSize(viewport);
      await page.goto(route.path);
      const section = page.locator(route.section);
      await section.scrollIntoViewIfNeeded();
      const hasNoHorizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      );
      expect(hasNoHorizontalOverflow).toBe(true);

      const cards = section.locator("[data-package-card]");
      await expect(cards).toHaveCount(2);

    for (const [index, expected] of [
      {
        slug: "nezuko",
        name: "Nezuko",
        durations: [
          "60 menit Rp 199.000",
          "90 menit Rp 265.000",
          "120 menit Rp 315.000",
        ],
        optionalPrice: "Rp 250.000",
        extensions: [
          "Dua terapis 30 menit Rp 100.000",
          "Reguler 60 menit Rp 75.000",
        ],
      },
      {
        slug: "nezuko-vip",
        name: "Nezuko VIP",
        durations: [
          "60 menit Rp 299.000",
          "90 menit Rp 349.000",
          "120 menit Rp 499.000",
        ],
        optionalPrice: "Rp 350.000",
        extensions: [
          "Dua terapis 30 menit Rp 100.000",
          "Reguler 60 menit Rp 100.000",
        ],
      },
    ].entries()) {
      const card = cards.nth(index);
      await expect(card.getByRole("heading", { name: expected.name })).toBeVisible();
      const comparison = card.getByRole("table").first();
      await expect(comparison).toBeVisible();
      const rows = await comparison.locator("tbody tr").evaluateAll((elements) =>
        elements.map((row) =>
          Array.from(row.querySelectorAll("th, td"))
            .map((cell) => cell.textContent?.replace(/\s+/g, " ").trim())
            .filter(Boolean)
            .join(" "),
        ),
      );
      expect(rows).toEqual(expected.durations);
      await expect(comparison.getByRole("button")).toHaveCount(0);

      const bookingLink = card.getByRole("link", {
        name: new RegExp(expected.name, "i"),
      });
      await expect(bookingLink).toHaveAttribute(
        "href",
        `/booking?service=${expected.slug}`,
      );
      expect((await bookingLink.boundingBox())?.height).toBeGreaterThanOrEqual(48);
      await expect(card.getByRole("note")).toContainText(/akun|account/i);

      const optionalPrice = expected.optionalPrice;
      const doubleDetails = card.locator("details").first();
      const doubleSummary = doubleDetails.locator("summary");
      await bookingLink.scrollIntoViewIfNeeded();
      await bookingLink.press("Tab");
      const keyboardFocusedSummary = await doubleSummary.evaluate(
        (element) => document.activeElement === element,
      );
      expect(keyboardFocusedSummary).toBe(true);
      await page.keyboard.press("Enter");
      await expect(doubleDetails).toHaveAttribute("open", "");
      const summaryFocusState = await doubleSummary.evaluate(
        (element) => {
          const bounds = element.getBoundingClientRect();
          const style = getComputedStyle(element);
          return {
            focused: document.activeElement === element,
            visible: bounds.top >= 0 && bounds.bottom <= window.innerHeight,
            outlineStyle: style.outlineStyle,
            outlineWidth: style.outlineWidth,
            top: bounds.top,
            bottom: bounds.bottom,
          };
        },
      );
      expect(summaryFocusState, JSON.stringify(summaryFocusState)).toMatchObject({
        focused: true,
        visible: true,
      });
      expect(Number.parseFloat(summaryFocusState.outlineWidth)).toBeGreaterThan(0);
      await expect(doubleDetails.getByText(optionalPrice, { exact: true })).toBeVisible();
      await page.keyboard.press("Space");
      await expect(doubleDetails).not.toHaveAttribute("open", "");

      const extensionDetails = card.locator("details").nth(1);
      const extensionSummary = extensionDetails.locator("summary");
      await page.keyboard.press("Tab");
      expect(
        await extensionSummary.evaluate((element) => document.activeElement === element),
      ).toBe(true);
      await page.keyboard.press("Enter");
      await expect(extensionDetails).toHaveAttribute("open", "");
      const extensionRows = await extensionDetails
        .getByRole("table")
        .locator("tbody tr")
        .evaluateAll((elements) =>
          elements.map((row) =>
            Array.from(row.querySelectorAll("th, td"))
              .map((cell) => cell.textContent?.replace(/\s+/g, " ").trim())
              .filter(Boolean)
              .join(" "),
          ),
        );
      expect(extensionRows).toEqual(expected.extensions);
      await page.keyboard.press("Space");
      await expect(extensionDetails).not.toHaveAttribute("open", "");

      const bookingPrecedesDetails = await card.evaluate((element) => {
        const action = element.querySelector<HTMLAnchorElement>(
          'a[href^="/booking?service="]',
        );
        const details = element.querySelector("details");

        return Boolean(
          action &&
            details &&
            action.compareDocumentPosition(details) &
              Node.DOCUMENT_POSITION_FOLLOWING,
        );
      });
      expect(bookingPrecedesDetails).toBe(true);
    }

      await expect(section.locator("address")).toHaveCount(1);
      await expect(
        section.getByRole("note").filter({ hasText: /air panas|hot[- ]water/i }),
      ).toHaveCount(1);

      const contrast = await page.evaluate(() => {
      const parseColor = (value: string) => {
        const channels = value.match(/[\d.]+/g)?.map(Number) ?? [];
        return channels.length >= 3
          ? [channels[0], channels[1], channels[2]]
          : null;
      };
      const luminance = (rgb: number[]) => {
        const channels = rgb.map((channel) => channel / 255).map((channel) =>
          channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4,
        );
        return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
      };
      const ratio = (element: Element) => {
        const foreground = parseColor(getComputedStyle(element).color);
        let ancestor: Element | null = element;
        let background: number[] | null = null;

        while (ancestor && !background) {
          const color = getComputedStyle(ancestor).backgroundColor;
          const alpha = Number(color.match(/[\d.]+/g)?.[3] ?? 1);
          if (alpha > 0) background = parseColor(color);
          ancestor = ancestor.parentElement;
        }

        if (!foreground || !background) return null;
        const values = [luminance(foreground), luminance(background)].sort(
          (left, right) => right - left,
        );
        return (values[0] + 0.05) / (values[1] + 0.05);
      };

      return [
        ...Array.from(document.querySelectorAll(".package-menu-card h3, .public-catalog__category-heading h2")),
        ...Array.from(document.querySelectorAll(".package-menu-card__benefit")),
        ...Array.from(document.querySelectorAll(".package-menu-card__description")),
        ...Array.from(document.querySelectorAll(".package-menu-card__type")),
        ...Array.from(document.querySelectorAll(".package-menu-card__comparison td")),
        ...Array.from(document.querySelectorAll(".package-menu-card details summary")),
        ...Array.from(document.querySelectorAll(".package-menu-card__booking-link")),
        ...Array.from(document.querySelectorAll(".catalog-menu-footer address, .catalog-menu-footer [role=note]")),
      ].map((element) => ({ text: element.textContent?.trim(), ratio: ratio(element) }));
      });
      expect(contrast.length).toBeGreaterThan(0);
      expect(contrast.every(({ ratio }) => ratio !== null && ratio >= 4.5), JSON.stringify(contrast)).toBe(true);
    }
  }

  for (const route of menuRoutes) {
    for (const slug of ["nezuko", "nezuko-vip"]) {
      await page.goto(route.path);
      const section = page.locator(route.section);
      const card = section
        .locator("[data-package-card]")
        .filter({
          has: page.getByRole("heading", {
            name: slug === "nezuko" ? "Nezuko" : "Nezuko VIP",
            exact: true,
          }),
        });
      await card.getByRole("link", { name: /reservasi|book/i }).click();
      await expect(page).toHaveURL(/\/login$/);
      await expect(page.locator("[data-booking-auth-context]")).toContainText(
        slug === "nezuko" ? "Nezuko" : "Nezuko VIP",
      );
      await page.context().clearCookies();
    }
  }
});

test("optional treatment sections show stateful indicators across locales and viewports", async ({
  page,
}) => {
  for (const locale of ["id", "en"] as const) {
    for (const route of menuRoutes) {
      for (const viewport of viewports) {
        await page.setViewportSize(viewport);
        await page.goto(route.path);

        const languageButton = page.getByRole("button", {
          name: locale.toUpperCase(),
          exact: true,
        });
        if ((await languageButton.getAttribute("aria-pressed")) !== "true") {
          await languageButton.click();
        }
        await expect(languageButton).toHaveAttribute("aria-pressed", "true");

        const section = page.locator(route.section);
        await section.scrollIntoViewIfNeeded();
        const cards = section.locator("[data-package-card]");
        const packages = [
          {
            slug: "nezuko",
            name: "Nezuko",
            durations: [199000, 265000, 315000],
            doublePrice: "Rp 250.000",
            extensions: [100000, 75000],
          },
          {
            slug: "nezuko-vip",
            name: "Nezuko VIP",
            durations: [299000, 349000, 499000],
            doublePrice: "Rp 350.000",
            extensions: [100000, 100000],
          },
        ] as const;
        const unit = locale === "id" ? "menit" : "min";
        const regularLabel = locale === "id" ? "Reguler" : "Regular";
        const doubleLabel = locale === "id" ? "Dua terapis" : "Double therapist";

        await expect(cards).toHaveCount(packages.length);
        for (const [index, expected] of packages.entries()) {
          const card = cards.nth(index);
          await expect(
            card.getByRole("heading", { name: expected.name, exact: true }),
          ).toBeVisible();
          const mainRows = await card
            .getByRole("table")
            .first()
            .locator("tbody tr")
            .evaluateAll((rows) =>
              rows.map((row) =>
                Array.from(row.querySelectorAll("th, td"))
                  .map((cell) => cell.textContent?.replace(/\s+/g, " ").trim())
                  .filter(Boolean)
                  .join(" "),
              ),
            );
          expect(mainRows).toEqual(
            expected.durations.map(
              (price, durationIndex) =>
                `${(durationIndex + 1) * 30 + 30} ${unit} Rp ${new Intl.NumberFormat("id-ID").format(price)}`,
            ),
          );

          const bookingLink = card.getByRole("link", {
            name: new RegExp(expected.name, "i"),
          });
          await expect(bookingLink).toHaveAttribute(
            "href",
            `/booking?service=${expected.slug}`,
          );
          expect((await bookingLink.boundingBox())?.height).toBeGreaterThanOrEqual(48);
          await expect(card.getByRole("note")).toContainText(
            locale === "id" ? /akun|verifikasi/i : /account|verify/i,
          );

          const disclosures = card.locator("details");
          await expect(disclosures).toHaveCount(2);
          await bookingLink.scrollIntoViewIfNeeded();
          await bookingLink.press("Tab");

          for (const [disclosureIndex, details] of (await disclosures.all()).entries()) {
            const summary = details.locator("summary");
            await expect(summary).toBeFocused();
            const closedIndicator = await summary.evaluate((element) => {
              const indicator = getComputedStyle(element, "::after");
              const bounds = element.getBoundingClientRect();
              return {
                content: indicator.content,
                width: Number.parseFloat(indicator.width),
                height: Number.parseFloat(indicator.height),
                rotate: indicator.getPropertyValue("rotate"),
                color: indicator.color,
                summaryColor: getComputedStyle(element).color,
                targetHeight: bounds.height,
                top: bounds.top,
                bottom: bounds.bottom,
                viewportHeight: window.innerHeight,
                scrollY: window.scrollY,
                visible: bounds.top >= 0 && bounds.bottom <= window.innerHeight,
                outlineStyle: getComputedStyle(element).outlineStyle,
                outlineWidth: getComputedStyle(element).outlineWidth,
              };
            });
            expect(
              closedIndicator.content,
              JSON.stringify({ locale, route: route.path, viewport, expected, closedIndicator }),
            ).not.toBe("none");
            expect(closedIndicator.width).toBeGreaterThan(0);
            expect(closedIndicator.height).toBeGreaterThan(0);
            expect(closedIndicator.targetHeight).toBeGreaterThanOrEqual(44);
            expect(
              closedIndicator.visible,
              JSON.stringify({ locale, route: route.path, viewport, expected, closedIndicator }),
            ).toBe(true);
            expect(closedIndicator.color).toBe(closedIndicator.summaryColor);
            expect(closedIndicator.outlineStyle).not.toBe("none");
            expect(Number.parseFloat(closedIndicator.outlineWidth)).toBeGreaterThan(0);

            await page.keyboard.press("Enter");
            await expect(details).toHaveAttribute("open", "");
            const expandedTable = details.getByRole("table");
            await expect(expandedTable).toBeVisible();

            if (disclosureIndex === 0) {
              await expect(
                expandedTable.getByText(expected.doublePrice, { exact: true }),
              ).toBeVisible();
            } else {
              const extensionRows = await expandedTable
                .locator("tbody tr")
                .evaluateAll((rows) =>
                  rows.map((row) =>
                    Array.from(row.querySelectorAll("th, td"))
                      .map((cell) => cell.textContent?.replace(/\s+/g, " ").trim())
                      .filter(Boolean)
                      .join(" "),
                  ),
                );
              expect(extensionRows).toEqual([
                `${doubleLabel} 30 ${unit} Rp ${new Intl.NumberFormat("id-ID").format(expected.extensions[0])}`,
                `${regularLabel} 60 ${unit} Rp ${new Intl.NumberFormat("id-ID").format(expected.extensions[1])}`,
              ]);
            }

            await expect.poll(() =>
              summary.evaluate((element) =>
                getComputedStyle(element, "::after").getPropertyValue("rotate"),
              ),
            ).not.toBe(closedIndicator.rotate);
            await page.keyboard.press("Space");
            await expect(details).not.toHaveAttribute("open", "");
            if (disclosureIndex < (await disclosures.count()) - 1) {
              await page.keyboard.press("Tab");
            }
          }
        }

        const documentedPolicy =
          locale === "id"
            ? "Diskon 10% dapat berlaku saat terjadi gangguan air panas. Kebijakan ini bersifat informasi dan tidak dihitung otomatis; silakan konfirmasi kepada tim kami."
            : "A 10% discount may apply if hot water is disrupted. This is an informational policy and is not calculated automatically; please confirm with our team.";
        await expect(
          section.getByRole("note").filter({ hasText: documentedPolicy }),
        ).toHaveCount(1);
      }
    }
  }
});
