import { expect, test } from "@playwright/test";

import { registerCustomer, verifyCustomer } from "./helpers/auth";
import { resetTestingDatabase } from "./testing-environment";

test.beforeAll(resetTestingDatabase);

test("Nezuko card goes straight to booking and regular 90 plus extension survives verified login", async ({
  page,
}) => {
  await page.goto("/");
  const card = page.getByRole("article", { name: "Nezuko", exact: true });
  await card.getByRole("link", { name: /reservasi|book/i }).click();
  await expect(page).toHaveURL(/\/login$/);

  await page
    .getByRole("link", { name: /create an account|buat akun/i })
    .click();
  await registerCustomer(page, "menu-direct@example.com", {
    navigate: false,
  });
  await verifyCustomer(
    page,
    "menu-direct@example.com",
    /\/booking\?(?=.*service=nezuko)/,
  );

  await expect(page).toHaveURL(/\/booking\?(?=.*service=nezuko)/);
  expect(new URL(page.url()).searchParams.get("service")).toBe("nezuko");

  const configurator = page.locator(".package-configurator");
  await configurator.getByRole("radio", { name: /regular|reguler/i }).click();
  await configurator.getByRole("radio", { name: /90/ }).click();
  await configurator.getByRole("checkbox", { name: /60/ }).check();
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();
});

test("Nezuko VIP double therapist plus 30 minutes totals 90 minutes and Rp450.000", async ({
  page,
}) => {
  await registerCustomer(page, "menu-vip@example.com");
  await verifyCustomer(page, "menu-vip@example.com");

  await page.goto("/booking?service=nezuko-vip");
  const configurator = page.locator(".package-configurator");
  await configurator
    .getByRole("radio", { name: /double therapist|dua terapis/i })
    .click();
  await configurator.getByRole("radio", { name: /60/ }).click();
  await configurator.getByRole("checkbox", { name: /30/ }).check();

  await expect(
    configurator.getByText("Total: 90 min · Rp 450.000"),
  ).toBeVisible();
});

test("locale switching preserves a valid Nezuko selection", async ({
  page,
}) => {
  await registerCustomer(page, "menu-locale@example.com");
  await verifyCustomer(page, "menu-locale@example.com");

  await page.goto("/booking?service=nezuko");
  const configurator = page.locator(".package-configurator");
  await configurator.getByRole("radio", { name: /regular|reguler/i }).click();
  await configurator.getByRole("radio", { name: /90/ }).click();
  await configurator.getByRole("checkbox", { name: /60/ }).check();

  await page.getByRole("button", { name: "EN", exact: true }).click();
  await expect(page).toHaveURL(/\/booking\?(?=.*service=nezuko)/);
  const english = page.locator(".package-configurator");
  await expect(
    english.getByRole("radio", { name: "Regular", exact: true }),
  ).toBeChecked();
  await expect(english.getByRole("radio", { name: /90/ })).toBeChecked();
  await expect(
    english.getByRole("checkbox", { name: /Extension \+60/ }),
  ).toBeChecked();
  await expect(
    english.getByText("Total: 150 min · Rp 340.000"),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: /review booking/i }),
  ).toBeVisible();
});

test("Gallery anchor and booking round trip keep browsing cards and lower content visible without reveal initialization", async ({
  page,
}) => {
  await registerCustomer(page, "menu-reveal@example.com");
  await verifyCustomer(page, "menu-reveal@example.com");

  await page.addInitScript(() => {
    Object.defineProperty(window, "IntersectionObserver", {
      configurable: true,
      value: undefined,
    });
  });
  await page.goto("/");

  const cards = page.locator("#menu").getByRole("article");
  await expect(cards.first()).toBeVisible();
  for (const card of await cards.all()) {
    await expect(card.getByRole("link")).toHaveCount(1);
    await expect(
      card.getByRole("link", { name: /reservasi|book/i }),
    ).toHaveAttribute("href", /^\/booking\?service=[a-z0-9-]+$/);
    await expect(card.getByRole("radio")).toHaveCount(0);
    await expect(card.getByRole("checkbox")).toHaveCount(0);
    await expect(card.getByRole("button")).toHaveCount(0);
  }

  const navigation = page.getByRole("navigation", {
    name: /primary navigation|navigasi utama/i,
  });
  await navigation.getByRole("link", { name: /^(gallery|galeri)$/i }).click();
  await expect(page).toHaveURL(/\/#gallery$/);
  await expect(page.locator("#gallery h2")).toBeInViewport();

  const expectLowerContentVisible = async () => {
    for (const section of ["#about", "#gallery", "#faq", "#contact"]) {
      const reveals = page.locator(`${section} .reveal`);
      await expect(reveals.first()).toBeVisible();
      for (const reveal of await reveals.all()) {
        await expect(reveal).not.toHaveClass(/in/);
        await expect(reveal).toBeVisible();
        await expect(reveal).toHaveCSS("opacity", "1");
      }
    }
  };
  await expectLowerContentVisible();

  await page
    .getByRole("article", { name: "Nezuko", exact: true })
    .getByRole("link", { name: /reservasi|book/i })
    .click();
  await expect(page).toHaveURL(/\/booking\?(?=.*service=nezuko)/);
  await expect(page.locator(".package-configurator")).toBeVisible();

  await navigation.getByRole("link", { name: /^(home|beranda)$/i }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(
    page.getByRole("article", { name: "Nezuko", exact: true }),
  ).toBeVisible();
  await expectLowerContentVisible();
});

test("hostile booking query values clear selection and cannot alter official totals", async ({
  page,
}) => {
  await page.goto("/register");
  await registerCustomer(page, "hostile-menu@example.com", {
    navigate: false,
  });
  await verifyCustomer(page, "hostile-menu@example.com");

  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
  );
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();

  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=999&extension=nezuko-vip-double-extra-30",
  );
  await expect(page.getByText("Rp 340.000", { exact: true })).toHaveCount(0);
  await expect(
    page.getByText("Total: 0 min · Rp 0", { exact: true }),
  ).toHaveCount(0);
  await expect(page.getByRole("status")).toContainText(
    /choose a (treatment type|duration) first|pilih (jenis perawatan|durasi) terlebih dahulu/i,
  );
});
