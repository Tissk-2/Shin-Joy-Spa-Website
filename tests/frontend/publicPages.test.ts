import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

const pageRoot = resolve(import.meta.dirname, "../../resources/js/pages");
const resourceRoot = resolve(import.meta.dirname, "../../resources/js");
const readPage = (name: string): string =>
  readFileSync(resolve(pageRoot, name), "utf8");

describe("Laravel-owned public pages", () => {
  it("uses the Inertia shell, navigation, and document head on every page", () => {
    for (const name of [
      "Home.vue",
      "ServiceDetail.vue",
      "Booking.vue",
      "Profile.vue",
    ]) {
      const source = readPage(name);

      expect(source).toMatch(/MainLayout/);
      expect(source).toMatch(/<Head/);
      expect(source).toMatch(/<Link/);
      expect(source).not.toMatch(/vue-router|RouterLink|useRoute/);
    }
  });

  it("preserves the bilingual home sections and accessible lightbox controls", () => {
    const source = readPage("Home.vue");

    expect(source).toMatch(/useLanguage/);
    expect(source).toMatch(/id="services"/);
    expect(source).toMatch(/id="about"/);
    expect(source).toMatch(/id="gallery"/);
    expect(source).toMatch(/id="contact"/);
    expect(source).toMatch(/role="dialog"/);
    expect(source).toMatch(/aria-modal="true"/);
    expect(source).toMatch(/handleLightboxKeydown/);
    expect(source).toMatch(/URLSearchParams/);
    expect(source).toMatch(/\.get\(["']cat["']\)/);
  });

  it("reads a typed service slug and safely renders unknown services", () => {
    const source = readPage("ServiceDetail.vue");

    expect(source).toMatch(/defineProps<\{\s*slug:\s*string/);
    expect(source).toMatch(/v-if="!service"/);
    expect(source).toMatch(/d\.notfoundt/);
    expect(source).toMatch(/service\.value\?/);
  });

  it("keeps guest booking validation without browser persistence or auth storage", () => {
    const source = readPage("Booking.vue");

    expect(source).toMatch(/validateBooking/);
    expect(source).toMatch(/timeSlots/);
    expect(source).toMatch(/todayIso/);
    expect(source).toMatch(/novalidate/);
    expect(source).toMatch(/aria-describedby="f-name-error"/);
    expect(source).not.toMatch(
      /localStorage|bookingHistory|createBookingStore|useAuth/,
    );
  });

  it("shows the safe server-shared profile identity with no booking history storage", () => {
    const source = readPage("Profile.vue");

    expect(source).toMatch(/usePage<AppPageProps>/);
    expect(source).toMatch(/props\.auth\.user/);
    expect(source).toMatch(/profile\.email/);
    expect(source).toMatch(/profile\.phone/);
    expect(source).not.toMatch(
      /localStorage|bookingHistory|createBookingStore|useAuth/,
    );
  });

  it("does not advertise an unauthorized administrator path in the public shell", () => {
    const layout = readFileSync(
      resolve(resourceRoot, "layouts/MainLayout.vue"),
      "utf8",
    );

    expect(layout).not.toMatch(/admin-login|href=["']\/admin/);
  });
});
