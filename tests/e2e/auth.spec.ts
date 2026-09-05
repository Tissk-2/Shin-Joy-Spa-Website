import { expect, test, type Page } from "@playwright/test";

import {
  customerPassword,
  login,
  registerCustomer,
  testingLink,
  verifyCustomer,
} from "./helpers/auth";

async function logoutFromProfile(page: Page): Promise<void> {
  await page.locator('button[aria-controls="account-menu"]').click();
  const logoutResponsePromise = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/logout",
  );
  await page.getByRole("button", { name: /keluar|log out/i }).click();
  const logoutResponse = await logoutResponsePromise;

  expect(logoutResponse.status()).toBeGreaterThanOrEqual(300);
  expect(logoutResponse.status()).toBeLessThan(400);
  expect(
    new URL(logoutResponse.headers().location ?? "", page.url()).pathname,
  ).toBe("/");
  await expect(page).toHaveURL("/");
  const protectedProfile = await page.request.get("/profile", {
    maxRedirects: 0,
  });

  expect(protectedProfile.status()).toBeGreaterThanOrEqual(300);
  expect(protectedProfile.status()).toBeLessThan(400);
  expect(
    new URL(protectedProfile.headers().location ?? "", page.url()).pathname,
  ).toBe("/login");
}

test("customer registers, verifies, logs out, and logs back in with a normalized email", async ({
  page,
}) => {
  const email = "ayu@example.com";

  await registerCustomer(page, email);
  await verifyCustomer(page, email.toUpperCase());
  await logoutFromProfile(page);
  await login(page, " AYU@EXAMPLE.COM ", customerPassword);

  await expect(page).toHaveURL("/profile");
  await expect(page.getByText(email, { exact: true })).toBeVisible();
});

test("invalid credentials remain on login with a generic error", async ({
  page,
}) => {
  await login(page, "missing@example.com", "wrong-password");

  await expect(page).toHaveURL("/login");
  await expect(page.locator("#email-error")).toHaveText(
    /email atau kata sandi salah|email or password is incorrect/i,
  );
});

test("an unverified customer is denied profile access", async ({ page }) => {
  await registerCustomer(page, "unverified@example.com");
  await page.goto("/profile");

  await expect(page).toHaveURL("/verify-email");
  await expect(
    page.getByRole("heading", { name: /verifikasi email|verify your email/i }),
  ).toBeVisible();
});

test("known and unknown password requests are neutral and a customer can reset the password", async ({
  page,
}) => {
  const email = "reset@example.com";
  const newPassword = "calmspa10";

  await registerCustomer(page, email);
  await page.getByRole("button", { name: /keluar|log out/i }).click();
  await expect(page).toHaveURL("/");

  await page.goto("/forgot-password");
  await page.getByLabel(/^email$/i).fill(email);
  await page
    .getByRole("button", { name: /kirim tautan|send password reset link/i })
    .click();
  const knownStatus = await page.getByRole("status").textContent();

  await page.getByLabel(/^email$/i).fill("unknown@example.com");
  await page
    .getByRole("button", { name: /kirim tautan|send password reset link/i })
    .click();
  await expect(page.getByRole("status")).toHaveText(knownStatus ?? "");

  await page.goto(await testingLink(page, "reset-link", email.toUpperCase()));
  await page.getByLabel(/^kata sandi$|^password$/i).fill(newPassword);
  await page
    .getByLabel(/konfirmasi kata sandi|confirm password/i)
    .fill(newPassword);
  await page
    .getByRole("button", { name: /atur ulang kata sandi|reset password/i })
    .click();

  await expect(page).toHaveURL("/login");
  await expect(page.getByRole("status")).toBeVisible();

  await login(page, email.toUpperCase(), newPassword);
  await expect(page).toHaveURL("/verify-email");
});

test("locale switching is persisted by the server for Indonesian and English", async ({
  page,
}) => {
  await page.goto("/login");
  await expect(
    page.getByRole("heading", { name: "Masuk ke akun Anda" }),
  ).toBeVisible();

  await page.getByRole("button", { name: "EN", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Log in to your account" }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Log in to your account" }),
  ).toBeVisible();

  await page.getByRole("button", { name: "ID", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Masuk ke akun Anda" }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("heading", { name: "Masuk ke akun Anda" }),
  ).toBeVisible();
});

test("a hostile external intended redirect is rejected after login", async ({
  page,
}) => {
  const email = "redirect@example.com";

  await registerCustomer(page, email);
  await verifyCustomer(page, email);
  await logoutFromProfile(page);

  const intended = await page.request.get("/profile", {
    headers: { Host: "attacker.example" },
    maxRedirects: 0,
  });
  expect(intended.status()).toBe(302);
  expect(intended.headers().location).toMatch(/\/login$/);

  await login(page, email, customerPassword);

  await expect(page).toHaveURL("/profile");
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:8000");
});
