import { expect, test, type Page, type Request } from "@playwright/test";
import { mkdir, readFile } from "node:fs/promises";
import path from "node:path";

import { registerCustomer, verifyCustomer } from "./helpers/auth";
import { projectRoot } from "./testing-environment";

const bookingPageUrl = /\/booking\?(?=.*service=nezuko)/;
const bookingReferencePattern = /^SJ-[0-9A-HJKMNP-TV-Z]{26}$/;
const longBookingNotes = "Focus on shoulders and upper back, please. ".repeat(7).trim();

async function chooseNezukoCard(page: Page): Promise<void> {
  await page.goto("/");
  const nezuko = page.getByRole("article", { name: "Nezuko", exact: true });

  await nezuko.getByRole("link", { name: /reservasi|book/i }).click();
  await expect(page).toHaveURL(/\/login$/);
}

async function configureNezukoRegularWithExtension(page: Page): Promise<void> {
  const configurator = page.locator(".package-configurator");
  await configurator.getByRole("radio", { name: /regular|reguler/i }).click();
  await configurator.getByRole("radio", { name: /90/ }).click();
  await configurator.getByRole("checkbox", { name: /60/ }).check();
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
  await verifyCustomer(page, email, bookingPageUrl);
}

async function fillBookingDetails(page: Page): Promise<void> {
  await page.getByLabel(/nama lengkap|full name/i).fill("Ayu Lestari Flow");
  await page
    .getByLabel(/nomor whatsapp|whatsapp number/i)
    .fill("+62 812-2222-3333");
  // Keep this flow's date separate from the admin booking fixture.
  await page.getByLabel(/^tanggal$|^date$/i).fill("2099-09-11");
  await page.getByLabel(/^waktu$|^time$/i).selectOption("10:30");
  await page.getByLabel(/jumlah tamu|number of guests/i).selectOption("2");
  await page
    .getByLabel(/catatan|notes/i)
    .fill(longBookingNotes);
}

async function waitForScrollToSettle(page: Page): Promise<void> {
  await expect
    .poll(() =>
      page.evaluate(
        () =>
          new Promise<boolean>((resolve) => {
            let previous = window.scrollY;
            let stableFrames = 0;
            let frames = 0;
            const checkFrame = () => {
              const current = window.scrollY;
              stableFrames = current === previous ? stableFrames + 1 : 0;
              previous = current;
              frames += 1;

              if (stableFrames >= 4 || frames >= 120) {
                resolve(stableFrames >= 4);
                return;
              }

              requestAnimationFrame(checkFrame);
            };

            requestAnimationFrame(checkFrame);
          }),
      ),
    )
    .toBe(true);
}

async function expectFocusVisibleBelowHeader(
  page: Page,
  selector: string,
): Promise<void> {
  const target = page.locator(selector);
  await expect(target).toBeVisible();
  await expect
    .poll(() => target.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await waitForScrollToSettle(page);

  const bounds = await target.evaluate((element) => {
    const targetBounds = element.getBoundingClientRect();
    const headerBounds = document
      .querySelector(".site-header")
      ?.getBoundingClientRect();

    return {
      targetTop: targetBounds.top,
      targetBottom: targetBounds.bottom,
      headerBottom: headerBounds?.bottom ?? 0,
      viewportHeight: window.innerHeight,
    };
  });

  expect(bounds.targetTop).toBeGreaterThanOrEqual(bounds.headerBottom);
  expect(bounds.targetTop).toBeGreaterThanOrEqual(0);
  expect(bounds.targetBottom).toBeLessThanOrEqual(bounds.viewportHeight);
}

async function tabNaturallyUntilFocused(
  page: Page,
  selector: string,
): Promise<void> {
  const target = page.locator(selector);

  for (let tabCount = 0; tabCount < 80; tabCount += 1) {
    await page.keyboard.press("Tab");
    if (await target.evaluate((element) => document.activeElement === element)) {
      return;
    }
  }

  throw new Error(`Could not reach ${selector} using Tab navigation.`);
}

async function captureBookingReview(
  page: Page,
  phase: "entry" | "complete",
  fullPage: boolean,
): Promise<void> {
  const screenshotDirectory = path.join(projectRoot, "outputs/booking-review");
  await mkdir(screenshotDirectory, { recursive: true });
  const originalViewport = page.viewportSize();
  const review = page.locator(".form-success");

  for (const viewport of [
    { width: 320, height: 860 },
    { width: 390, height: 844 },
    { width: 1440, height: 960 },
  ]) {
    await page.setViewportSize(viewport);
    await expect(review).toBeVisible();
    await expectFocusVisibleBelowHeader(page, ".form-success h2");
    const hasNoHorizontalOverflow = await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    );
    expect(hasNoHorizontalOverflow).toBe(true);
    const priceFits = await review
      .locator(".summary-total .amt")
      .evaluate((element) => element.scrollWidth <= element.clientWidth);
    expect(priceFits).toBe(true);
    const notesLayout = await review
      .locator(".summary-row--notes")
      .evaluate((row) => {
        const label = row.querySelector("span:first-child")!;
        const value = row.querySelector("span:last-child")!;
        const labelBounds = label.getBoundingClientRect();
        const valueBounds = value.getBoundingClientRect();

        return {
          labelBottom: labelBounds.bottom,
          labelLeft: labelBounds.left,
          valueTop: valueBounds.top,
          valueLeft: valueBounds.left,
          valueWidth: valueBounds.width,
          rowWidth: row.getBoundingClientRect().width,
          textAlign: getComputedStyle(value).textAlign,
        };
      });
    expect(notesLayout.valueTop).toBeGreaterThanOrEqual(notesLayout.labelBottom);
    expect(notesLayout.valueLeft).toBeCloseTo(notesLayout.labelLeft, 0);
    expect(notesLayout.valueWidth).toBeGreaterThanOrEqual(
      notesLayout.rowWidth - 2,
    );
    expect(notesLayout.textAlign).toBe("left");

    if (viewport.width <= 390) {
      for (const selector of [".summary-row--name", ".summary-row--phone"]) {
        const fieldBounds = await review.locator(selector).evaluate((row) => {
          const label = row.querySelector("span:first-child")!;
          const value = row.querySelector("span:last-child")!;

          return {
            labelBottom: label.getBoundingClientRect().bottom,
            valueTop: value.getBoundingClientRect().top,
          };
        });
        expect(fieldBounds.valueTop).toBeGreaterThanOrEqual(
          fieldBounds.labelBottom,
        );
      }
    }

    const screenshotPath = path.join(
      screenshotDirectory,
      `review-${phase}-${fullPage ? "full" : "viewport"}-${viewport.width}.png`,
    );
    if (fullPage) {
      await page.screenshot({
        path: screenshotPath,
        fullPage: true,
        style: ".site-header { position: absolute !important; }",
      });
    } else {
      await page.screenshot({ path: screenshotPath });
    }
  }

  if (originalViewport) await page.setViewportSize(originalViewport);
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
}, testInfo) => {
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

  await chooseNezukoCard(page);
  await registerAndVerifyFromBookingRedirect(page, firstEmail);

  await expect(page).toHaveURL(bookingPageUrl);
  await configureNezukoRegularWithExtension(page);
  await expect(page.getByText("Rp 340.000", { exact: true })).toBeVisible();

  await fillBookingDetails(page);
  await page.keyboard.press("Tab");
  const reviewAction = page.getByRole("button", {
    name: /tinjau reservasi|review booking/i,
  });
  await expect
    .poll(() => reviewAction.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await page.keyboard.press("Enter");

  const reviewHeading = page.getByRole("heading", {
    name: /tinjau reservasi anda|review your booking/i,
  });
  await expectFocusVisibleBelowHeader(page, ".form-success h2");
  await expect(reviewHeading).toBeVisible();
  await expect(
    page.getByText(
      /reservasi belum (dikirim|dibuat)|no reservation has been (submitted|created)/i,
    ),
  ).toBeVisible();
  const initialRecap = page.locator(".booking-recap");
  await expect(initialRecap).toContainText("2 orang");
  await expect(initialRecap).toContainText("Ayu Lestari Flow");
  await expect(initialRecap).toContainText("+62 812-2222-3333");
  expect(
    await initialRecap.locator(".summary-row--notes").textContent(),
  ).toContain(longBookingNotes);
  await expect(initialRecap).toContainText("Rp 340.000");
  await expect(initialRecap).toContainText(
    "Estimasi ini mencakup perawatan yang dipilih dan tambahan apa pun. Jumlah tamu tidak mengalikan jumlah tersebut; total untuk semua tamu belum dikonfirmasi.",
  );
  expect(bookingPostRequestsOnly(bookingPostRequests)).toHaveLength(0);
  await captureBookingReview(page, "entry", false);

  await page.getByRole("button", { name: "EN", exact: true }).click();
  await expect(initialRecap).toContainText("Number of guests");
  await expect(initialRecap).toContainText("Focus on shoulders and upper back, please.");
  await expect(initialRecap).toContainText(
    "This estimate covers the selected treatment and any extension. Guest count does not multiply it; the total for all guests has not been confirmed.",
  );

  const editButton = page.getByRole("button", { name: /edit details|ubah detail/i });
  await tabNaturallyUntilFocused(page, "[data-booking-edit]");
  await page.keyboard.press("Enter");
  await expectFocusVisibleBelowHeader(page, "#f-name");
  await expect(page.getByLabel(/nama lengkap|full name/i)).toHaveValue("Ayu Lestari Flow");
  await expect(page.getByLabel(/nomor whatsapp|whatsapp number/i)).toHaveValue(
    "+62 812-2222-3333",
  );
  await expect(page.getByLabel(/jumlah tamu|number of guests/i)).toHaveValue("2");
  await expect(page.getByLabel(/catatan|notes/i)).toHaveValue(
    longBookingNotes,
  );

  const editedName =
    "Ayu Lestari Flow updated for a deliberately long mobile booking review 1234567890";
  const editedNotes = "";
  await page.getByLabel(/nama lengkap|full name/i).fill(editedName);
  await page.getByLabel(/jumlah tamu|number of guests/i).selectOption("3");
  await page.getByLabel(/catatan|notes/i).fill(editedNotes);
  await page.keyboard.press("Tab");
  await expect
    .poll(() => reviewAction.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await page.keyboard.press("Enter");
  const editedRecap = page.locator(".booking-recap");
  await expectFocusVisibleBelowHeader(page, ".form-success h2");
  await expect(editedRecap).toContainText("3 people");
  await expect(editedRecap).toContainText(editedName);
  await expect(editedRecap).toContainText("+62 812-2222-3333");
  await expect(editedRecap).toContainText("No notes provided");
  await expect(editedRecap.locator(".summary-total .amt")).toHaveText(
    "Rp 340.000",
  );
  await expect(editedRecap).toContainText(
    "This estimate covers the selected treatment and any extension. Guest count does not multiply it; the total for all guests has not been confirmed.",
  );
  expect(bookingPostRequestsOnly(bookingPostRequests)).toHaveLength(0);
  await captureBookingReview(page, "complete", true);

  const postResponsePromise = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/bookings",
  );
  const confirmButton = page.getByRole("button", {
    name: /konfirmasi reservasi|confirm booking/i,
  });

  await page.keyboard.press("Tab");
  await expect
    .poll(() => editButton.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await page.keyboard.press("Tab");
  await expect
    .poll(() => confirmButton.evaluate((element) => document.activeElement === element))
    .toBe(true);
  await expectFocusVisibleBelowHeader(page, "[data-booking-confirm]");
  await page.keyboard.press("Enter");

  const processingButton = page.getByRole("button", {
    name: /mengirim reservasi|submitting booking/i,
  });
  await expect(processingButton).toBeDisabled();

  expect(bookingPostRequestsOnly(bookingPostRequests)).toHaveLength(1);
  expect(
    bookingPostRequestsOnly(bookingPostRequests)[0].postDataJSON(),
  ).toMatchObject({
    name: editedName,
    phone: "+62 812-2222-3333",
    guests: 3,
    notes: editedNotes,
    expected_total_price_idr: 340000,
  });

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

  const downloadPromise = page.waitForEvent("download");
  await page
    .getByRole("button", { name: /unduh ringkasan|download booking card/i })
    .click();
  const cardDownload = await downloadPromise;
  expect(cardDownload.suggestedFilename()).toBe(
    `shin-joy-booking-${reference}.png`,
  );
  const cardPath = testInfo.outputPath("booking-card.png");
  await cardDownload.saveAs(cardPath);
  const cardBytes = await readFile(cardPath);
  expect(cardBytes.subarray(0, 8)).toEqual(
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
  );
  expect(cardBytes.readUInt32BE(16)).toBe(1080);
  expect(cardBytes.readUInt32BE(20)).toBe(1350);

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
