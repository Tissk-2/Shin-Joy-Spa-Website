import { execFileSync } from "node:child_process";
import { writeFileSync } from "node:fs";
import path from "node:path";
import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import {
  projectRoot,
  resetTestingDatabase,
  testingEnvironment,
} from "./testing-environment";

test.beforeAll(resetTestingDatabase);

const fixture = (args: string[] = []) =>
  JSON.parse(
    execFileSync("php", ["tests/e2e/fixtures/package-pricing.php", ...args], {
      cwd: projectRoot,
      env: { ...process.env, ...testingEnvironment },
      encoding: "utf8",
    }),
  );

test("owner saves visible prices before attestation and customer reviews the total for all guests", async ({
  page,
  context,
  browser,
}) => {
  test.setTimeout(240_000);
  const localOnly = async (route: import("@playwright/test").Route) => {
    if (
      ["127.0.0.1", "localhost"].includes(
        new URL(route.request().url()).hostname,
      )
    )
      await route.continue();
    else await route.abort();
  };
  await context.route("**/*", localOnly);
  const data = fixture();
  await login(page, data.owner, "calmspa9");
  await expect(page).toHaveURL(/\/profile$|\/admin(?:\/|$)/);
  const editor = "/admin/catalog/packages/nezuko/edit";
  await page.goto(editor);
  await page.locator('[data-admin-locale="en"]').click();
  await expect(page.locator('[name="variants.0.priceIdr"]')).toHaveValue(
    "199000",
  );
  await page.locator('[name="variants.0.priceIdr"]').fill("240000");
  await page.locator('[name="pricingBasis"]').selectOption("per_person");
  await page.locator('[name="minGuests"]').fill("2");
  await page.locator('[name="maxGuests"]').fill("4");
  await expect(page.locator("[data-pricing-attest]")).toBeDisabled();
  await expect(page.locator("#pricing-attestation-help")).toContainText(
    "Save your other changes",
  );
  expect(fixture(["facts"])).toMatchObject({ price: 199000, verified: false });
  await page.getByRole("button", { name: "Save changes", exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/catalog(?:\?|$)/);
  expect(fixture(["facts"])).toMatchObject({ price: 240000, verified: false });
  await page.goto(editor);
  await expect(page.locator('[name="variants.0.priceIdr"]')).toHaveValue(
    "240000",
  );
  await expect(page.locator("[data-pricing-attest]")).toBeEnabled();
  await page.locator("[data-pricing-attest]").click();
  await expect(page).toHaveURL(/\/admin\/catalog(?:\?|$)/);
  expect(fixture(["facts"])).toMatchObject({
    price: 240000,
    basis: "per_person",
    verified: true,
    verifier: data.owner,
  });

  const customerContext = await browser.newContext();
  await customerContext.route("**/*", localOnly);
  try {
    const customer = await customerContext.newPage();
    await login(customer, data.customer, "calmspa9");
    await expect(customer).toHaveURL(/\/profile$/);
    await customer.getByRole("button", { name: "EN", exact: true }).click();
    await customer.goto("/services");
    await expect(
      customer.locator(
        '[data-package-card][aria-label="Nezuko"] [data-pricing-scope]',
      ),
    ).toHaveText("Prices per person · 2–4 guests");
    await customer.goto(
      "/booking?service=nezuko&mode=regular&duration=60&extension=nezuko-regular-extra-60",
    );
    await expect(customer.locator("#f-guests")).toHaveValue("2");
    await expect(customer.locator(".summary-total")).toContainText(
      "For 2 guests",
    );
    await expect(customer.locator(".summary-total")).toContainText(
      "Rp 630.000",
    );
    await expect(
      customer.locator(".package-configurator__total"),
    ).toContainText("For 2 guests");
    await customer.locator("#f-date").fill("2099-10-10");
    await customer.locator('#f-time input[value="10:00"]').check();
    await customer
      .getByRole("button", { name: "Review booking", exact: true })
      .click();
    await expect(
      customer.locator(".booking-recap .summary-total"),
    ).toContainText("For 2 guests");
    await customer.screenshot({
      path: path.join(
        projectRoot,
        "storage/framework/testing/b2-review-pricing-en.png",
      ),
      fullPage: true,
    });
    await customer.locator("[data-booking-edit]").click();
    await customer.getByRole("button", { name: "ID", exact: true }).click();
    await customer.setViewportSize({ width: 390, height: 844 });
    await expect(customer.locator(".summary-total")).toContainText(
      "Untuk 2 tamu",
    );
    await expect(customer.locator(".summary-total")).toContainText(
      "Rp 630.000",
    );
    await customer.screenshot({
      path: path.join(
        projectRoot,
        "storage/framework/testing/b2-review-pricing-id-mobile.png",
      ),
      fullPage: true,
    });
    await customer
      .getByRole("button", { name: "Tinjau reservasi", exact: true })
      .click();
    await customer.locator("[data-booking-confirm]").click();
    await expect(customer).toHaveURL(/\/bookings\/SJ-/);
    const facts = fixture(["facts"]);
    expect(facts).toMatchObject({
      bookingTotal: 630000,
      bookingBasis: "per_person",
      bookingGuests: 2,
      reservationCount: 0,
    });
    writeFileSync(
      path.join(
        projectRoot,
        "storage/framework/testing/b2-review-pricing-facts.json",
      ),
      JSON.stringify(facts, null, 2),
    );
  } finally {
    await customerContext.close();
  }
});
