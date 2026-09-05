import { expect, test } from "@playwright/test";

import { registerCustomer, verifyCustomer } from "./helpers/auth";

test("Nezuko regular 90 plus extension survives verified login", async ({
  page,
}) => {
  await page.goto("/");
  const nezuko = page.getByRole("article", { name: "Nezuko", exact: true });
  await nezuko.getByRole("radio", { name: /regular|reguler/i }).click();
  await nezuko.getByRole("radio", { name: /90/ }).click();
  await nezuko.getByRole("checkbox", { name: /60/ }).check();
  await nezuko.getByRole("link", { name: /book|pesan/i }).click();
  await expect(page).toHaveURL(/\/login$/);

  await page
    .getByRole("link", { name: /create an account|buat akun/i })
    .click();
  await registerCustomer(page, "menu@example.com", { navigate: false });
  await verifyCustomer(
    page,
    "menu@example.com",
    /\/booking\?(?=.*service=nezuko)(?=.*mode=regular)(?=.*duration=90)(?=.*extension=nezuko-regular-extra-60)/,
  );

  await expect(page).toHaveURL(
    /\/booking\?(?=.*service=nezuko)(?=.*mode=regular)(?=.*duration=90)(?=.*extension=nezuko-regular-extra-60)/,
  );
  expect(new URL(page.url()).searchParams.get("extension")).toBe(
    "nezuko-regular-extra-60",
  );
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();
});

test("Nezuko VIP double therapist plus 30 minutes totals 90 minutes and Rp450.000", async ({
  page,
}) => {
  await page.goto("/");
  const vip = page.getByRole("article", {
    name: "Nezuko VIP",
    exact: true,
  });
  await vip
    .getByRole("radio", { name: /double therapist|dua terapis/i })
    .click();
  await vip.getByRole("radio", { name: /60/ }).click();
  await vip.getByRole("checkbox", { name: /30/ }).check();

  await expect(vip.getByText("Total: 90 min · Rp 450.000")).toBeVisible();
  await expect(vip.getByRole("link", { name: /book|pesan/i })).toHaveAttribute(
    "href",
    /\?(?=.*service=nezuko-vip)(?=.*mode=double_therapist)(?=.*duration=60)(?=.*extension=nezuko-vip-double-extra-30)/,
  );
});

test("locale switching preserves a valid Nezuko selection", async ({
  page,
}) => {
  await page.goto("/");
  const nezuko = page.getByRole("article", { name: "Nezuko", exact: true });
  await nezuko.getByRole("radio", { name: /regular|reguler/i }).click();
  await nezuko.getByRole("radio", { name: /90/ }).click();
  await nezuko.getByRole("checkbox", { name: /60/ }).check();

  await page.getByRole("button", { name: "EN", exact: true }).click();
  const englishNezuko = page.getByRole("article", {
    name: "Nezuko",
    exact: true,
  });
  await expect(
    englishNezuko.getByText("Total: 150 min · Rp 340.000"),
  ).toBeVisible();
  await expect(
    englishNezuko.getByRole("link", { name: "Book now" }),
  ).toHaveAttribute("href", /service=nezuko&mode=regular&duration=90/);
});

test("hostile booking query values clear selection and cannot alter official totals", async ({
  page,
}) => {
  await page.goto("/register");
  await registerCustomer(page, "hostile-menu@example.com", { navigate: false });
  await verifyCustomer(page, "hostile-menu@example.com");

  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=90&extension=nezuko-regular-extra-60",
  );
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();

  await page.goto(
    "/booking?service=nezuko&mode=regular&duration=999&extension=nezuko-vip-double-extra-30",
  );
  await expect(page.getByText("Rp 340.000", { exact: true })).toHaveCount(0);
  await expect(page.getByText("Rp 0", { exact: true })).toBeVisible();
});
