import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Home from "@/pages/Home.vue";
import Booking from "@/pages/Booking.vue";
import Show from "@/pages/bookings/Show.vue";
import { normalizeContactPhone } from "@/lib/contactInformation";
import type { BookingDetailData, Locale } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/",
    props: {
      auth: { user: null, canAccessAdmin: false },
      locale: "id" as Locale,
      flash: {},
      catalog: { packages: [] },
      content: {} as Record<string, { id?: string; en?: string }>,
    },
  },
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive: makeReactive } = await import("vue");
  const page = makeReactive(inertia.page);
  inertia.page = page;

  return {
    Head: "span",
    Link: "a",
    router: { post: vi.fn() },
    usePage: () => inertia.page,
    useForm: (data: Record<string, unknown>) =>
      makeReactive({ ...data, processing: false, errors: {} }),
  };
});

const booking: BookingDetailData = {
  reference: "SJ-01K4DR9M7JQ2ABCD3EFGHJKL10",
  status: "pending",
  package: {
    slug: "nezuko",
    nameId: "Nezuko",
    nameEn: "Nezuko",
    isVip: false,
  },
  treatmentMode: "regular",
  appointment: { date: "2099-09-11", time: "10:30", timezone: "Asia/Jakarta" },
  guests: 1,
  totalDurationMinutes: 60,
  totalPriceIdr: 199000,
  customer: { name: "Ayu Lestari", email: "ayu@example.test", phone: "" },
  base: {
    choiceCode: "nezuko-regular-60",
    durationMinutes: 60,
    priceIdr: 199000,
  },
  extension: null,
  notes: null,
};

function setContactContent(
  values: Record<string, { id?: string; en?: string }>,
): void {
  inertia.page.props.content = values;
}

function setLocale(locale: Locale): void {
  inertia.page.props.locale = locale;
  document.documentElement.lang = locale;
}

function createCanvasContext(drawnText: string[]): CanvasRenderingContext2D {
  return {
    fillText: vi.fn((value: string) => drawnText.push(value)),
    measureText: vi.fn((value: string) => ({ width: value.length * 20 })),
    fillRect: vi.fn(),
    beginPath: vi.fn(),
    roundRect: vi.fn(),
    fill: vi.fn(),
    stroke: vi.fn(),
    moveTo: vi.fn(),
    lineTo: vi.fn(),
  } as unknown as CanvasRenderingContext2D;
}

describe("public contact information", () => {
  beforeEach(() => {
    inertia.page.url = "/";
    inertia.page.props.auth.user = null;
    inertia.page.props.catalog.packages = [];
    setContactContent({});
    setLocale("id");
    document.body.innerHTML = "";
  });

  it.each([
    {
      label: "rejects bare country code followed by a domestic prefix zero",
      input: "62 0812-3456-7890",
      expected: null,
    },
    {
      label: "rejects plus country code followed by a domestic prefix zero",
      input: "+62 0812-3456-7890",
      expected: null,
    },
    {
      label: "rejects 00 country code followed by a domestic prefix zero",
      input: "0062 0812-3456-7890",
      expected: null,
    },
    {
      label: "normalizes Indonesian national format",
      input: "0812-3456-7890",
      expected: "6281234567890",
    },
    {
      label: "keeps Indonesian country-code format",
      input: "62812-3456-7890",
      expected: "6281234567890",
    },
    {
      label: "keeps plus-prefixed Indonesian country-code format",
      input: "+62 812-3456-7890",
      expected: "6281234567890",
    },
    {
      label: "keeps 00-prefixed Indonesian country-code format",
      input: "0062 812-3456-7890",
      expected: "6281234567890",
    },
    {
      label: "preserves valid internal zeros",
      input: "+62 812-3000-4050",
      expected: "6281230004050",
    },
    {
      label: "keeps plus-prefixed international numbers",
      input: "+1 202-555-0100",
      expected: "12025550100",
    },
    {
      label: "keeps 00-prefixed international numbers",
      input: "0044 20 7946 0958",
      expected: "442079460958",
    },
    {
      label: "rejects plus-prefixed domestic format",
      input: "+0812-3456-7890",
      expected: null,
    },
  ])("$label", ({ input, expected }) => {
    expect(normalizeContactPhone(input)).toBe(expected);
  });

  it("uses CMS address and phone consistently on the homepage, catalog footer, global footer, and map destinations", () => {
    const address = "Unit 3, Jalan Mawar & Melati #8, Malang";
    const phone = "+62 (812) 3456-7890";
    setContactContent({
      "loc.address": { id: address, en: "Unit 3, Rose & Jasmine St. #8" },
      "loc.phone": { id: phone, en: "+62 813 9876 5432" },
    });

    const wrapper = mount(Home, { props: { categories: [] } });

    expect(wrapper.get("#contact").text()).toContain(address);
    expect(wrapper.get("#contact").text()).toContain(phone);
    expect(wrapper.get(".catalog-menu-footer").text()).toContain(address);
    expect(wrapper.get(".footer-contact").text()).toContain(address);
    expect(wrapper.get(".footer-contact").text()).toContain(phone);

    const embed = new URL(wrapper.get("#contact iframe").attributes("src"));
    const directions = new URL(
      wrapper.get('#contact a[href*="google.com/maps"]').attributes("href"),
    );
    expect(embed.searchParams.get("q")).toBe(address);
    expect(directions.searchParams.get("q")).toBe(address);
  });

  it("falls back per language when a CMS locale value is blank or absent", () => {
    setContactContent({
      "loc.address": { id: "Alamat CMS Indonesia", en: "   " },
      "loc.phone": { id: "+62 811 2222 3333" },
    });
    setLocale("en");

    const wrapper = mount(Home, { props: { categories: [] } });

    expect(wrapper.get("#contact").text()).toContain(
      "Jalan Terusan Batubara No. 12, Lantai 2",
    );
    expect(wrapper.get(".catalog-menu-footer").text()).toContain(
      "Jalan Terusan Batubara No. 12, Lantai 2",
    );
    expect(wrapper.get(".footer-contact").text()).toContain(
      "Jalan Terusan Batubara No. 12, Lantai 2",
    );
    expect(wrapper.get(".footer-contact").text()).toContain(
      "+62 812 3456 7890",
    );
  });

  it("normalizes valid CMS phones, falls back for blank values, and omits invalid-number links", () => {
    setContactContent({ "loc.phone": { id: "+62 812-3456-7890" } });
    const valid = mount(Booking, {
      props: {
        packages: [],
        selection: { service: "", mode: null, duration: null, extension: null },
        submissionKey: "00000000-0000-4000-8000-000000000001",
      },
    });
    expect(valid.get(".summary-card__help a").attributes("href")).toBe(
      "https://wa.me/6281234567890",
    );
    valid.unmount();

    setContactContent({ "loc.phone": { id: "   " } });
    const fallback = mount(Booking, {
      props: {
        packages: [],
        selection: { service: "", mode: null, duration: null, extension: null },
        submissionKey: "00000000-0000-4000-8000-000000000003",
      },
    });
    expect(fallback.get(".summary-card__help a").attributes("href")).toBe(
      "https://wa.me/6281234567890",
    );
    fallback.unmount();

    for (const phone of [
      "not a phone",
      "javascript:alert(1)",
      "+62 123",
      "+0812-3456-7890",
    ]) {
      setContactContent({ "loc.phone": { id: phone } });
      const wrapper = mount(Booking, {
        props: {
          packages: [],
          selection: {
            service: "",
            mode: null,
            duration: null,
            extension: null,
          },
          submissionKey: "00000000-0000-4000-8000-000000000002",
        },
      });
      expect(wrapper.find(".summary-card__help a").exists()).toBe(false);
      wrapper.unmount();
    }
  });

  it("uses the localized CMS address in the downloaded booking card without appending a hardcoded city", async () => {
    const address = "Room 5, Jalan Melati No. 9";
    setContactContent({
      "loc.address": { id: "Ruang 5, Jalan Melati No. 9", en: address },
    });
    setLocale("en");
    const drawnText: string[] = [];
    const contextSpy = vi
      .spyOn(HTMLCanvasElement.prototype, "getContext")
      .mockReturnValue(createCanvasContext(drawnText));
    const blobSpy = vi
      .spyOn(HTMLCanvasElement.prototype, "toBlob")
      .mockImplementation((callback) => {
        callback(new Blob(["png"], { type: "image/png" }));
      });
    const clickSpy = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(() => {});
    const originalCreateObjectUrl = URL.createObjectURL;
    const originalRevokeObjectUrl = URL.revokeObjectURL;
    URL.createObjectURL = vi.fn(() => "blob:booking-card");
    URL.revokeObjectURL = vi.fn();

    try {
      const wrapper = mount(Show, { props: { booking } });
      await wrapper
        .get('button[data-testid="download-booking-card"]')
        .trigger("click");
      await vi.waitFor(() => expect(clickSpy).toHaveBeenCalledTimes(1));

      expect(drawnText).toContain(address);
      expect(drawnText).not.toContain(`${address}, Malang`);
      wrapper.unmount();
    } finally {
      contextSpy.mockRestore();
      blobSpy.mockRestore();
      clickSpy.mockRestore();
      URL.createObjectURL = originalCreateObjectUrl;
      URL.revokeObjectURL = originalRevokeObjectUrl;
    }
  });
});
