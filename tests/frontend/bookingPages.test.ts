import { mount, type VueWrapper } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import MainLayout from "@/layouts/MainLayout.vue";
import Index from "@/pages/bookings/Index.vue";
import Show from "@/pages/bookings/Show.vue";
import Profile from "@/pages/Profile.vue";
import type { BookingDetailData, BookingSummaryData, Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/bookings",
    props: {
      auth: {
        user: {
          id: 7,
          name: "Nadia Lestari",
          email: "nadia@example.test",
          phone: "+62 812-3456-7890",
          email_verified_at: "2026-08-01",
        },
      },
      locale: "en" as Locale,
      flash: {},
      catalog: { packages: [] },
    },
  },
  posts: [] as Array<{ url: string; data: Record<string, unknown> }>,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive(inertia.page);
  inertia.page = page;

  return {
    Head: "span",
    Link: "a",
    router: {
      post: vi.fn((url: string, data: Record<string, unknown>) => {
        inertia.posts.push({ url, data });
      }),
    },
    usePage: () => inertia.page,
  };
});

const pending: BookingSummaryData = {
  reference: "SJ-01K4DR9M7JQ2ABCD3EFGHJKL10",
  status: "pending",
  package: {
    slug: "nezuko",
    nameId: "Nezuko Tenang",
    nameEn: "Calm Nezuko",
    isVip: false,
  },
  treatmentMode: "regular",
  appointment: {
    date: "2026-09-06",
    time: "09:00",
    timezone: "Asia/Jakarta",
  },
  guests: 2,
  totalDurationMinutes: 150,
  totalPriceIdr: 340000,
};

const confirmed: BookingSummaryData = {
  ...pending,
  reference: "SJ-01K4DR9M7JQ2ABCD3EFGHJKL11",
  status: "confirmed",
  package: {
    slug: "nezuko-vip",
    nameId: "Nezuko VIP",
    nameEn: "Nezuko VIP",
    isVip: true,
  },
  treatmentMode: "double_therapist",
  appointment: {
    date: "2026-10-07",
    time: "13:30",
    timezone: "Asia/Jakarta",
  },
  guests: 1,
  totalDurationMinutes: 90,
  totalPriceIdr: 450000,
};

const completed: BookingSummaryData = {
  ...pending,
  reference: "SJ-01K4DR9M7JQ2ABCD3EFGHJKL12",
  status: "completed",
  appointment: {
    date: "2026-08-21",
    time: "18:00",
    timezone: "Asia/Jakarta",
  },
  totalDurationMinutes: 60,
  totalPriceIdr: 199000,
};

const cancelled: BookingSummaryData = {
  ...confirmed,
  reference: "SJ-01K4DR9M7JQ2ABCD3EFGHJKL13",
  status: "cancelled",
  appointment: {
    date: "2026-08-19",
    time: "10:30",
    timezone: "Asia/Makassar",
  },
};

const withExtension: BookingDetailData = {
  ...confirmed,
  customer: {
    name: "Nadia Lestari",
    email: "nadia@example.test",
    phone: "+62 812-3456-7890",
  },
  base: {
    choiceCode: "nezuko-vip-double-60",
    durationMinutes: 60,
    priceIdr: 350000,
  },
  extension: {
    choiceCode: "nezuko-vip-double-extra-30",
    durationMinutes: 30,
    priceIdr: 100000,
  },
  notes: "Please prepare a quiet room.",
};

const withoutExtension: BookingDetailData = {
  ...pending,
  customer: {
    name: "Ayu Prameswari",
    email: "ayu@example.test",
    phone: "+62 821-0000-1111",
  },
  base: {
    choiceCode: "nezuko-regular-150",
    durationMinutes: 150,
    priceIdr: 340000,
  },
  extension: null,
  notes: null,
};

function setLocale(locale: Locale): void {
  inertia.page.props.locale = locale;
  document.documentElement.lang = locale;
}

async function switchLocale(
  wrapper: VueWrapper,
  locale: Locale,
): Promise<void> {
  setLocale(locale);
  await wrapper.vm.$nextTick();
  await nextTick();
}

describe("customer booking index", () => {
  beforeEach(() => {
    inertia.page.url = "/bookings";
    setLocale("en");
    inertia.posts = [];
    document.body.innerHTML = "";
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("renders localized appointment ledgers, links, statuses, and empty states without changing the stored day", async () => {
    const wrapper = mount(Index, {
      props: {
        upcoming: [pending, confirmed],
        past: [completed, cancelled],
      },
    });

    expect(wrapper.get("h1").text()).toBe("My Bookings");
    expect(wrapper.get('nav[aria-label="Breadcrumb"]')).toBeTruthy();
    expect(
      wrapper.get('a[href="/bookings/SJ-01K4DR9M7JQ2ABCD3EFGHJKL10"]'),
    ).toBeTruthy();
    expect(wrapper.text()).toContain("Upcoming bookings (2)");
    expect(wrapper.text()).toContain("Past bookings (2)");
    expect(wrapper.text()).toContain("Calm Nezuko");
    expect(wrapper.text()).toContain("Nezuko VIP");
    expect(wrapper.text()).toContain("VIP");
    expect(wrapper.text()).toContain("Regular");
    expect(wrapper.text()).toContain("Double therapist");
    expect(wrapper.text()).toContain("6 September 2026");
    expect(wrapper.text()).toContain("09:00 WIB");
    expect(wrapper.text()).toContain("19 August 2026");
    expect(wrapper.text()).toContain("10:30 Asia/Makassar");
    expect(wrapper.text()).toContain("2 guests");
    expect(wrapper.text()).toContain("150 min");
    expect(wrapper.text()).toContain("Rp 340.000");
    expect(
      wrapper.findAll(".booking-status").map((item) => item.text()),
    ).toEqual(["Pending", "Confirmed", "Completed", "Cancelled"]);

    await switchLocale(wrapper, "id");

    expect(wrapper.get("h1").text()).toBe("Reservasi Saya");
    expect(wrapper.text()).toContain("Reservasi mendatang (2)");
    expect(wrapper.text()).toContain("Riwayat reservasi (2)");
    expect(wrapper.text()).toContain("Nezuko Tenang");
    expect(wrapper.text()).toContain("Reguler");
    expect(wrapper.text()).toContain("Dua terapis");
    expect(wrapper.text()).toContain("6 September 2026");
    expect(wrapper.text()).toContain("2 tamu");
    expect(wrapper.text()).toContain("150 menit");
    expect(
      wrapper.findAll(".booking-status").map((item) => item.text()),
    ).toEqual(["Menunggu", "Terkonfirmasi", "Selesai", "Dibatalkan"]);
  });

  it("gives upcoming and past groups independent booking actions when either list is empty", async () => {
    const wrapper = mount(Index, {
      props: {
        upcoming: [],
        past: [],
      },
    });

    const links = wrapper.findAll('.booking-empty a[href="/booking"]');
    expect(wrapper.text()).toContain(
      "No upcoming bookings yet. Choose a treatment when you are ready.",
    );
    expect(wrapper.text()).toContain(
      "Past bookings will appear here after your visit.",
    );
    expect(links).toHaveLength(2);
    expect(links.map((link) => link.text())).toEqual([
      "Reserve a treatment",
      "Reserve a treatment",
    ]);

    await switchLocale(wrapper, "id");

    expect(wrapper.text()).toContain(
      "Belum ada reservasi mendatang. Pilih perawatan saat Anda siap.",
    );
    expect(wrapper.text()).toContain(
      "Riwayat reservasi akan muncul di sini setelah kunjungan Anda.",
    );
    expect(
      wrapper
        .findAll('.booking-empty a[href="/booking"]')
        .map((link) => link.text()),
    ).toEqual(["Reservasi perawatan", "Reservasi perawatan"]);
  });
});

describe("customer booking detail", () => {
  beforeEach(() => {
    inertia.page.url = "/bookings/SJ-01K4DR9M7JQ2ABCD3EFGHJKL11";
    setLocale("en");
    inertia.posts = [];
  });

  it("renders the full localized detail, customer snapshot, extension, totals, notes, and return actions", async () => {
    const wrapper = mount(Show, {
      props: { booking: withExtension },
    });

    expect(wrapper.get("h1").text()).toBe("SJ-01K4DR9M7JQ2ABCD3EFGHJKL11");
    expect(wrapper.text()).toContain("Confirmed");
    expect(wrapper.text()).toContain("Nezuko VIP");
    expect(wrapper.text()).toContain("VIP");
    expect(wrapper.text()).toContain("Double therapist");
    expect(wrapper.text()).toContain("7 October 2026");
    expect(wrapper.text()).toContain("13:30 WIB");
    expect(wrapper.text()).toContain("1 guest");
    expect(wrapper.text()).toContain("Base treatment");
    expect(wrapper.text()).toContain("nezuko-vip-double-60");
    expect(wrapper.text()).toContain("60 min");
    expect(wrapper.text()).toContain("Rp 350.000");
    expect(wrapper.text()).toContain("Extension");
    expect(wrapper.text()).toContain("nezuko-vip-double-extra-30");
    expect(wrapper.text()).toContain("30 min");
    expect(wrapper.text()).toContain("Rp 100.000");
    expect(wrapper.text()).toContain("90 min");
    expect(wrapper.text()).toContain("Rp 450.000");
    expect(wrapper.text()).toContain("Nadia Lestari");
    expect(wrapper.text()).toContain("nadia@example.test");
    expect(wrapper.text()).toContain("+62 812-3456-7890");
    expect(wrapper.text()).toContain("Please prepare a quiet room.");
    expect(wrapper.get('.booking-actions a[href="/bookings"]').text()).toBe(
      "Back to My Bookings",
    );
    expect(wrapper.get('.booking-actions a[href="/booking"]').text()).toBe(
      "Reserve another treatment",
    );

    await switchLocale(wrapper, "id");

    expect(wrapper.text()).toContain("Terkonfirmasi");
    expect(wrapper.text()).toContain("Dua terapis");
    expect(wrapper.text()).toContain("7 Oktober 2026");
    expect(wrapper.text()).toContain("1 tamu");
    expect(wrapper.text()).toContain("Perawatan dasar");
    expect(wrapper.text()).toContain("Tambahan");
    expect(wrapper.text()).toContain("90 menit");
    expect(wrapper.get('.booking-actions a[href="/bookings"]').text()).toBe(
      "Kembali ke Reservasi Saya",
    );
    expect(wrapper.get('.booking-actions a[href="/booking"]').text()).toBe(
      "Reservasi perawatan lain",
    );
  });

  it("states no extension and no notes explicitly", () => {
    const wrapper = mount(Show, {
      props: { booking: withoutExtension },
    });

    expect(wrapper.text()).toContain("No extension selected");
    expect(wrapper.text()).toContain("No notes were added");
    expect(wrapper.text()).toContain("Ayu Prameswari");
    expect(wrapper.text()).toContain("Rp 340.000");
    expect(wrapper.text()).not.toContain("undefined");
  });
});

describe("customer booking navigation and profile handoff", () => {
  beforeEach(() => {
    inertia.page.url = "/bookings/SJ-01K4DR9M7JQ2ABCD3EFGHJKL11";
    setLocale("en");
    inertia.posts = [];
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("adds My Bookings before Profile in the authenticated account menu, marks booking routes current, and closes on navigation", async () => {
    const wrapper = mount(MainLayout, {
      slots: { default: "<p>content</p>" },
    });

    await wrapper.get(".account-toggle").trigger("click");

    const accountLinks = wrapper.findAll("#account-menu a");
    expect(accountLinks.map((link) => link.text())).toEqual([
      "My Bookings",
      "Profile",
    ]);
    expect(accountLinks[0].attributes("href")).toBe("/bookings");
    expect(accountLinks[0].attributes("aria-current")).toBe("page");

    accountLinks[0].element.addEventListener("click", (event) =>
      event.preventDefault(),
    );
    await accountLinks[0].trigger("click");
    await wrapper.vm.$nextTick();

    expect(wrapper.find("#account-menu").exists()).toBe(false);

    await switchLocale(wrapper, "id");
    await wrapper.get(".account-toggle").trigger("click");
    expect(
      wrapper.findAll("#account-menu a").map((link) => link.text()),
    ).toEqual(["Reservasi Saya", "Profil"]);
  });

  it("keeps Profile focused on account settings and points booking history to My Bookings", async () => {
    inertia.page.url = "/profile";
    const wrapper = mount(Profile);

    expect(wrapper.get("h1").text()).toBe("My Profile");
    expect(wrapper.text()).toContain(
      "Manage your account details. Your booking history is kept on a separate page.",
    );
    expect(wrapper.text()).toContain("Booking history");
    expect(wrapper.get('a[href="/bookings"]').text()).toBe("View My Bookings");
    expect(wrapper.find("#upcoming-history-title").exists()).toBe(false);
    expect(wrapper.find("#past-history-title").exists()).toBe(false);
    expect(wrapper.text()).not.toContain("No upcoming reservations yet.");

    await switchLocale(wrapper, "id");

    expect(wrapper.text()).toContain(
      "Kelola detail akun Anda. Riwayat reservasi tersedia di halaman terpisah.",
    );
    expect(wrapper.get('a[href="/bookings"]').text()).toBe(
      "Lihat Reservasi Saya",
    );
  });
});
