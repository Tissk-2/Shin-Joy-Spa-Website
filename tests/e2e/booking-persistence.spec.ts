import { expect, test, type Page, type Request } from "@playwright/test";

import { registerCustomer, verifyCustomer } from "./helpers/auth";

const bookingSelectionUrl =
  /\/booking\?(?=.*service=nezuko)(?=.*mode=regular)(?=.*duration=90)(?=.*extension=nezuko-regular-extra-60)/;
const bookingReferencePattern = /^SJ-[0-9A-HJKMNP-TV-Z]{26}$/;

async function chooseNezukoRegularWithExtension(page: Page): Promise<void> {
  await page.goto("/");
  const nezuko = page.getByRole("article", { name: "Nezuko", exact: true });

  await nezuko.getByRole("radio", { name: /regular|reguler/i }).click();
  await nezuko.getByRole("radio", { name: /90/ }).click();
  await nezuko.getByRole("checkbox", { name: /60/ }).check();
  await nezuko.getByRole("link", { name: /book|pesan/i }).click();
}

async function registerAndVerifyFromBookingRedirect(
  page: Page,
  email: string,
): Promise<void> {
  await expect(page).toHaveURL(/\/login$/);
  await page
    .getByRole("link", { name: /create an account|buat akun/i })
    .click();
  await registerCustomer(page, email, { navigate: false });
  await verifyCustomer(page, email, bookingSelectionUrl);
}

async function fillBookingDetails(page: Page): Promise<void> {
  await page.getByLabel(/nama lengkap|full name/i).fill("Ayu Lestari Flow");
  await page
    .getByLabel(/nomor whatsapp|whatsapp number/i)
    .fill("+62 812-2222-3333");
  await page.getByLabel(/^tanggal$|^date$/i).fill("2099-09-06");
  await page.getByLabel(/^waktu$|^time$/i).selectOption("10:30");
  await page.getByLabel(/jumlah tamu|number of guests/i).selectOption("2");
  await page.getByLabel(/catatan|notes/i).fill("Focus on shoulders, please.");
}

async function openMyBookingsFromAccountMenu(page: Page): Promise<void> {
  await page.locator('button[aria-controls="account-menu"]').click();
  await page
    .locator("#account-menu")
    .getByRole("link", { name: /reservasi saya|my bookings/i })
    .click();
  await expect(page).toHaveURL("/bookings");
}

async function logoutFromAccountMenu(page: Page): Promise<void> {
  await page.locator('button[aria-controls="account-menu"]').click();
  const logoutResponsePromise = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/logout",
  );

  await page
    .locator("#account-menu")
    .getByRole("button", { name: /keluar|log out/i })
    .click();

  const logoutResponse = await logoutResponsePromise;
  expect(logoutResponse.status()).toBeGreaterThanOrEqual(300);
  expect(logoutResponse.status()).toBeLessThan(400);
  await expect(page).toHaveURL("/");
}

function bookingPostRequestsOnly(requests: Request[]): Request[] {
  return requests.filter(
    (request) =>
      request.method() === "POST" &&
      new URL(request.url()).pathname === "/bookings",
  );
}

test("verified customers persist one booking and cannot view another customer's reference", async ({
  page,
}) => {
  const firstEmail = "booking-flow@example.com";
  const secondEmail = "booking-flow-other@example.com";
  const bookingPostRequests: Request[] = [];
  let releaseBookingPost!: () => void;
  const allowBookingPost = new Promise<void>((resolve) => {
    releaseBookingPost = resolve;
  });

  await page.route("**/bookings", async (route) => {
    const request = route.request();

    if (
      request.method() === "POST" &&
      new URL(request.url()).pathname === "/bookings"
    ) {
      bookingPostRequests.push(request);
      await allowBookingPost;
    }

    await route.continue();
  });

  await chooseNezukoRegularWithExtension(page);
  await registerAndVerifyFromBookingRedirect(page, firstEmail);

  await expect(page).toHaveURL(bookingSelectionUrl);
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();

  await fillBookingDetails(page);
  await page
    .getByRole("button", { name: /tinjau reservasi|review booking/i })
    .click();

  await expect(
    page.getByRole("heading", {
      name: /tinjau reservasi anda|review your booking/i,
    }),
  ).toBeVisible();
  await expect(
    page.getByText(
      /reservasi belum (dikirim|dibuat)|no reservation has been (submitted|created)/i,
    ),
  ).toBeVisible();
  expect(bookingPostRequestsOnly(bookingPostRequests)).toHaveLength(0);

  const postResponsePromise = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/bookings",
  );
  const confirmButton = page.getByRole("button", {
    name: /konfirmasi reservasi|confirm booking/i,
  });

  await confirmButton.click();

  const processingButton = page.getByRole("button", {
    name: /mengirim reservasi|submitting booking/i,
  });
  await expect(processingButton).toBeDisabled();

  const buttonBox = await processingButton.boundingBox();
  expect(buttonBox).not.toBeNull();
  await page.mouse.click(
    buttonBox!.x + buttonBox!.width / 2,
    buttonBox!.y + buttonBox!.height / 2,
  );
  expect(bookingPostRequestsOnly(bookingPostRequests)).toHaveLength(1);

  releaseBookingPost();

  const postResponse = await postResponsePromise;
  expect(postResponse.status()).toBe(303);
  await expect(page).toHaveURL(/\/bookings\/SJ-/);
  expect(bookingPostRequestsOnly(bookingPostRequests)).toHaveLength(1);

  const reference = new URL(page.url()).pathname.split("/").pop() ?? "";
  expect(reference).toMatch(bookingReferencePattern);

  await expect(page.getByRole("heading", { name: reference })).toBeVisible();
  await expect(page.getByText(/menunggu|pending/i)).toBeVisible();
  await expect(
    page.locator("main").getByText("Nezuko", { exact: true }),
  ).toBeVisible();
  await expect(page.getByText(/150 (menit|min)/i)).toBeVisible();
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();

  await openMyBookingsFromAccountMenu(page);
  await expect(
    page.getByRole("heading", {
      name: /reservasi mendatang|upcoming bookings/i,
    }),
  ).toBeVisible();
  await expect(page.getByText(reference, { exact: true })).toHaveCount(1);
  await expect(page.getByText(/150 (menit|min)/i)).toBeVisible();
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();

  await logoutFromAccountMenu(page);
  await registerCustomer(page, secondEmail);
  await verifyCustomer(page, secondEmail);

  const crossAccountResponse = await page.goto(`/bookings/${reference}`);
  expect(crossAccountResponse?.status()).toBe(404);
  await expect(page.getByText(reference, { exact: true })).toHaveCount(0);
  await expect(page.getByText("Ayu Lestari Flow", { exact: true })).toHaveCount(
    0,
  );
  await expect(page.getByText("Focus on shoulders, please.")).toHaveCount(0);
});
