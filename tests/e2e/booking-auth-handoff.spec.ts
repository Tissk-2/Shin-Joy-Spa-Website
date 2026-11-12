import { expect, test } from "@playwright/test";

import {
  customerPassword,
  registerCustomer,
  verifyCustomer,
} from "./helpers/auth";
import { mkdir } from "node:fs/promises";
import path from "node:path";
import { resetTestingDatabase } from "./testing-environment";

test.beforeAll(resetTestingDatabase);

const contextSelector = "[data-booking-auth-context]";

async function assertNoHorizontalOverflow(page: import("@playwright/test").Page): Promise<void> {
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true);
}

async function assertKeyboardFocusVisible(
  page: import("@playwright/test").Page,
  tabCount: number,
): Promise<void> {
  for (let index = 0; index < tabCount; index += 1) {
    await page.keyboard.press("Tab");
    const focusState = await page.evaluate(() => {
        const activeElement = document.activeElement;
        if (!(activeElement instanceof HTMLElement)) return null;

        const bounds = activeElement.getBoundingClientRect();
        const style = window.getComputedStyle(activeElement);
        const measure = (selector: string) => {
          const element = document.querySelector<HTMLElement>(selector);
          if (!element) return null;
          const rect = element.getBoundingClientRect();
          const elementStyle = window.getComputedStyle(element);
          return {
            top: rect.top,
            bottom: rect.bottom,
            height: rect.height,
            minHeight: elementStyle.minHeight,
            flexShrink: elementStyle.flexShrink,
            overflowY: elementStyle.overflowY,
          };
        };

        return {
          visible:
            bounds.width > 0 &&
            bounds.height > 0 &&
            bounds.top >= -1 &&
            bounds.bottom <= window.innerHeight + 1 &&
            bounds.left >= -1 &&
            bounds.right <= window.innerWidth + 1 &&
            style.visibility !== "hidden",
          active: { tag: activeElement.tagName, text: activeElement.textContent?.trim() },
          bounds: { top: bounds.top, bottom: bounds.bottom },
          scrollY: window.scrollY,
          documentHeight: document.documentElement.scrollHeight,
          bodyHeight: document.body.scrollHeight,
          viewport: { width: window.innerWidth, height: window.innerHeight },
          auth: measure(".auth"),
          panel: measure(".auth__panel"),
          card: measure(".auth-card"),
          form: measure(".auth-form"),
        };
      });
    expect(focusState?.visible, JSON.stringify(focusState)).toBe(true);
  }
}

test("booking handoff shows published starting prices for both packages and preserves language changes", async ({
  page,
}) => {
  for (const treatment of [
    { slug: "nezuko", name: "Nezuko", price: "Rp 199.000" },
    { slug: "nezuko-vip", name: "Nezuko VIP", price: "Rp 299.000" },
  ]) {
    await page.goto(
      "/booking?service=" +
        treatment.slug +
        "&name=Forged%20treatment&starting_price=1",
    );
    await expect(page).toHaveURL(/\/login$/);

    const context = page.locator(contextSelector);
    await expect(context).toBeVisible();
    await expect(context.getByText(treatment.name, { exact: true })).toBeVisible();
    await expect(context.getByText(treatment.price, { exact: true })).toBeVisible();
    await expect(context.getByText(/mulai dari/i)).toBeVisible();
    await expect(page.getByText("Forged treatment", { exact: true })).toHaveCount(0);

    await page.getByRole("button", { name: "EN", exact: true }).click();
    await expect(context.getByText("Starting price", { exact: true })).toBeVisible();
    await expect(
      context.getByText(/sign in and continue|create an account/i),
    ).toBeVisible();
    await page.getByRole("button", { name: "ID", exact: true }).click();
    await expect(context.getByText(/Mulai dari/i)).toBeVisible();
  }
});

test("existing customers sign in and return to the selected treatment", async ({
  page,
}) => {
  const email = "booking-handoff-existing@example.com";

  await registerCustomer(page, email);
  await verifyCustomer(page, email);

  await page.locator('button[aria-controls="account-menu"]').click();
  await page
    .locator("#account-menu")
    .getByRole("button", { name: /keluar|log out/i })
    .click();
  await expect(page).toHaveURL("/");

  await page.goto("/booking?service=nezuko-vip");
  await expect(page).toHaveURL(/\/login$/);
  const context = page.locator(contextSelector);
  await expect(context.getByText("Nezuko VIP", { exact: true })).toBeVisible();
  await expect(
    context.getByText(/already have an account|sudah punya akun/i),
  ).toBeVisible();

  await page.getByLabel(/^email$/i).fill(email);
  await page.getByLabel(/^kata sandi$|^password$/i).fill(customerPassword);
  await page.getByRole("button", { name: /^masuk$|^log in$/i }).click();

  await expect(page).toHaveURL(/\/booking\?(?=.*service=nezuko-vip)/);
  expect(new URL(page.url()).searchParams.get("service")).toBe("nezuko-vip");
});

test("new customers keep treatment context through registration errors and verification", async ({
  page,
}) => {
  const email = "booking-handoff-new@example.com";

  await page.goto("/");
  const card = page.getByRole("article", { name: "Nezuko", exact: true });
  await expect(card.locator("[data-booking-auth-note]")).toBeVisible();
  await expect(card.locator("[data-booking-auth-note]")).toContainText(
    /akun|account/i,
  );
  await card.getByRole("link", { name: /reservasi|book/i }).click();
  await expect(page).toHaveURL(/\/login$/);

  const loginContext = page.locator(contextSelector);
  await expect(loginContext.getByText("Nezuko", { exact: true })).toBeVisible();
  await expect(
    loginContext.getByText(/new customers|pelanggan baru/i),
  ).toBeVisible();
  await page.getByRole("link", { name: /buat akun|create an account/i }).click();

  const registerContext = page.locator(contextSelector);
  await expect(registerContext.getByText("Nezuko", { exact: true })).toBeVisible();
  await expect(
    registerContext.getByText(/verification link|tautan verifikasi/i),
  ).toBeVisible();
  await page.getByRole("button", { name: /buat akun|create account/i }).click();
  await expect(page).toHaveURL(/\/register$/);
  await expect(page.getByText(/required|wajib/i).first()).toBeVisible();
  await expect(registerContext.getByText("Nezuko", { exact: true })).toBeVisible();

  await registerCustomer(page, email, { navigate: false });
  await expect(page).toHaveURL(/\/verify-email$/);

  const verifyContext = page.locator(contextSelector);
  await expect(verifyContext.getByText("Nezuko", { exact: true })).toBeVisible();
  await expect(
    verifyContext.getByText(/check your email|periksa email/i),
  ).toBeVisible();
  await verifyCustomer(page, email, /\/booking\?(?=.*service=nezuko)/);
  expect(new URL(page.url()).searchParams.get("service")).toBe("nezuko");
});

test("booking-context auth layouts and keyboard focus work at 320, 390, and 1440 pixels", async ({
  page,
}, testInfo) => {
  const screenshotDirectory = testInfo.outputPath("auth-context-screenshots");
  await mkdir(screenshotDirectory, { recursive: true });

  for (const viewport of [
    { width: 320, height: 640 },
    { width: 390, height: 844 },
    { width: 1440, height: 900 },
  ]) {
    await page.setViewportSize(viewport);
    await page.goto("/booking?service=nezuko");
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator(contextSelector)).toBeVisible();
    await assertNoHorizontalOverflow(page);
    await assertKeyboardFocusVisible(page, 6);

    if (viewport.width === 390) {
      await page.screenshot({
        path: path.join(screenshotDirectory, "booking-context-login.png"),
        fullPage: true,
      });
    }

    await page.goto("/register");
    const registrationContext = page.locator(contextSelector);
    await expect(registrationContext).toBeVisible();
    await expect(
      registrationContext.getByText(/verification link|tautan verifikasi/i),
    ).toBeVisible();
    await assertNoHorizontalOverflow(page);
    await assertKeyboardFocusVisible(page, 8);

    if (viewport.width === 390) {
      await page.screenshot({
        path: path.join(screenshotDirectory, "booking-context-register.png"),
        fullPage: true,
      });
    }
  }

  await registerCustomer(page, "booking-handoff-layout@example.com", {
    navigate: false,
  });
  await expect(page).toHaveURL(/\/verify-email$/);

  for (const viewport of [
    { width: 320, height: 640 },
    { width: 390, height: 844 },
    { width: 1440, height: 900 },
  ]) {
    await page.setViewportSize(viewport);
    const verificationContext = page.locator(contextSelector);
    await expect(verificationContext).toBeVisible();
    await expect(
      verificationContext.getByText(/check your email|periksa email/i),
    ).toBeVisible();
    await assertNoHorizontalOverflow(page);
    await assertKeyboardFocusVisible(page, 6);

    if (viewport.width === 390) {
      await page.screenshot({
        path: path.join(screenshotDirectory, "booking-context-verification.png"),
        fullPage: true,
      });
    }
  }
});
