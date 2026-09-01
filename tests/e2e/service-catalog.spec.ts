import { expect, test } from "@playwright/test";

test("a database-backed service card preserves its selection in booking", async ({
  page,
}) => {
  const serviceName = "Pijat Tradisional Jawa";
  const serviceSlug = "javanese-massage";
  const servicePrice = "Rp 185.000";
  const serviceDuration = "90 menit";

  await page.goto("/");
  const serviceLink = page.getByRole("link", { name: serviceName }).first();
  await expect(serviceLink).toBeVisible({ timeout: 5_000 });
  await serviceLink.click();

  await expect(page).toHaveURL(`/services/${serviceSlug}`);
  await expect(page.getByRole("heading", { name: serviceName })).toBeVisible();
  await expect(page.getByText(servicePrice, { exact: true })).toBeVisible();
  await expect(
    page
      .getByRole("listitem")
      .filter({ hasText: serviceDuration })
      .getByText(serviceDuration, { exact: true }),
  ).toBeVisible();

  await page.getByRole("link", { name: "Pesan perawatan ini" }).click();

  await expect(page).toHaveURL(`/booking?service=${serviceSlug}`);

  const serviceSelect = page.getByLabel("Pilih perawatan");
  await expect(serviceSelect).toHaveValue(serviceSlug);
  await expect(
    serviceSelect.getByRole("option", {
      name: `${serviceName} · ${servicePrice}`,
    }),
  ).toHaveAttribute("value", serviceSlug);

  const bookingSummary = page.getByRole("complementary", {
    name: "Ringkasan reservasi",
  });
  await expect(
    bookingSummary.getByText(serviceDuration, { exact: true }),
  ).toBeVisible();
  await expect(
    bookingSummary.getByText(servicePrice, { exact: true }),
  ).toBeVisible();
});
