import { expect, type Page } from "@playwright/test";

export const customerPassword = "calmspa9";

export async function registerCustomer(
  page: Page,
  email: string,
  options: { navigate?: boolean } = {},
): Promise<void> {
  if (options.navigate !== false) await page.goto("/register");

  await page.getByLabel(/nama lengkap|full name/i).fill("Ayu Lestari");
  await page.getByLabel(/^email$/i).fill(email);
  await page
    .getByLabel(/nomor whatsapp|whatsapp number/i)
    .fill("+6281234567890");
  await page.getByLabel(/^kata sandi$|^password$/i).fill(customerPassword);
  await page
    .getByLabel(/konfirmasi kata sandi|confirm password/i)
    .fill(customerPassword);
  await page.getByRole("button", { name: /buat akun|create account/i }).click();
  await expect(page).toHaveURL(/\/verify-email$/);
}

export async function testingLink(
  page: Page,
  endpoint: "verification-link" | "reset-link",
  email: string,
): Promise<string> {
  const response = await page.request.get(
    `/__testing/${endpoint}?email=${encodeURIComponent(email)}`,
  );
  const payload = (await response.json()) as { url: string };

  expect(response.ok()).toBe(true);
  expect(payload).toEqual({ url: expect.any(String) });

  return payload.url;
}

export async function verifyCustomer(
  page: Page,
  email: string,
  expectedUrl: string | RegExp = /\/profile\?verified=1$/,
): Promise<void> {
  await page.goto(await testingLink(page, "verification-link", email));
  await expect(page).toHaveURL(expectedUrl);
}

export async function login(
  page: Page,
  email: string,
  password: string,
): Promise<void> {
  await page.goto("/login");
  await page.getByLabel(/^email$/i).fill(email);
  await page.getByLabel(/^kata sandi$|^password$/i).fill(password);
  await page.getByRole("button", { name: /^masuk$|^log in$/i }).click();
}
