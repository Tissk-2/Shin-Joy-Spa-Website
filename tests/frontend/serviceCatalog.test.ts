import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import ServiceCard from "@/components/ServiceCard.vue";
import Booking from "@/pages/Booking.vue";
import Home from "@/pages/Home.vue";
import ServiceDetail from "@/pages/ServiceDetail.vue";
import type { ServiceCategoryData, ServiceData } from "@/types";

const inertia = vi.hoisted(() => ({
  page: {
    url: "/",
    props: {
      auth: { user: null },
      locale: "en",
      flash: {},
      catalog: { categories: [] },
    },
  },
}));

vi.mock("@inertiajs/vue3", () => ({
  Head: "span",
  Link: "a",
  router: { post: vi.fn() },
  usePage: () => inertia.page,
}));

const categories: ServiceCategoryData[] = [
  {
    slug: "ritual",
    nameId: "Ritual Tenang",
    nameEn: "Calm Rituals",
    image: "/img/ritual.jpg",
  },
  {
    slug: "facial",
    nameId: "Perawatan Wajah",
    nameEn: "Facial Care",
    image: "/img/facial.jpg",
  },
];

const stoneRitual: ServiceData = {
  slug: "stone-ritual",
  category: "ritual",
  nameId: "Ritual Batu Hangat",
  nameEn: "Warm Stone Ritual",
  descId: "Batu hangat untuk tubuh yang kembali ringan.",
  descEn: "Warm stones restore lightness to tired muscles.",
  longId: "Ritual batu hangat dengan minyak yang menenangkan.",
  longEn: "A soothing warm stone ritual with aromatic oil.",
  goodId: "Otot lelah",
  goodEn: "Tired muscles",
  image: "/img/stone.jpg",
  gallery: ["/img/stone.jpg", "/img/oil.jpg"],
  duration: 90,
  price: 275000,
  popularity: 98,
};

const glowFacial: ServiceData = {
  slug: "glow-facial",
  category: "facial",
  nameId: "Facial Cahaya",
  nameEn: "Glow Facial",
  descId: "Perawatan wajah untuk kulit yang segar.",
  descEn: "A facial that refreshes tired skin.",
  longId: "Perawatan wajah yang menyegarkan dan mencerahkan.",
  longEn: "A refreshing and brightening facial treatment.",
  goodId: "Kulit kusam",
  goodEn: "Dull skin",
  image: "/img/glow.jpg",
  gallery: ["/img/glow.jpg"],
  duration: 60,
  price: 185000,
  popularity: 93,
};

function setPage(url = "/", locale: "id" | "en" = "en"): void {
  inertia.page.url = url;
  inertia.page.props = {
    auth: { user: null },
    locale,
    flash: {},
    catalog: { categories },
  };
}

beforeEach(() => {
  setPage();
  Element.prototype.scrollIntoView = vi.fn();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("Laravel service catalog pages", () => {
  it("filters Home services from its supplied category props without mutating them", async () => {
    const suppliedServices = [stoneRitual, glowFacial];
    const wrapper = mount(Home, {
      props: { categories, services: suppliedServices },
    });

    expect(wrapper.text()).toContain("Warm Stone Ritual");
    expect(wrapper.text()).toContain("Glow Facial");
    expect(wrapper.get('a[href="/#services?cat=ritual"]').text()).toBe(
      "Calm Rituals",
    );
    await wrapper.get(".cat-card").trigger("click");

    expect(wrapper.text()).toContain("Warm Stone Ritual");
    expect(wrapper.text()).not.toContain("Glow Facial");
    expect(suppliedServices.map(({ slug }) => slug)).toEqual([
      "stone-ritual",
      "glow-facial",
    ]);
  });

  it("renders the matching Indonesian or English server-supplied catalog names", () => {
    setPage("/", "id");
    const indonesian = mount(ServiceCard, {
      props: { service: stoneRitual, category: categories[0] },
    });
    setPage("/", "en");
    const english = mount(ServiceCard, {
      props: { service: stoneRitual, category: categories[0] },
    });

    expect(indonesian.text()).toContain("Ritual Batu Hangat");
    expect(indonesian.text()).toContain("Ritual Tenang");
    expect(english.text()).toContain("Warm Stone Ritual");
    expect(english.text()).toContain("Calm Rituals");
    expect(english.get("a.btn").attributes("href")).toBe(
      "/booking?service=stone-ritual",
    );
  });

  it("uses detail props for its gallery, related links, and booking deep link", async () => {
    const wrapper = mount(ServiceDetail, {
      props: {
        service: stoneRitual,
        category: categories[0],
        related: [glowFacial],
      },
    });

    expect(wrapper.get(".detail-media > img").attributes("src")).toBe(
      "/img/stone.jpg",
    );
    expect(wrapper.findAll(".detail-thumbs button")).toHaveLength(2);
    await wrapper.get(".detail-thumbs button:nth-child(2)").trigger("click");
    expect(wrapper.get(".detail-media > img").attributes("src")).toBe(
      "/img/oil.jpg",
    );
    expect(wrapper.text()).toContain("Glow Facial");
    expect(wrapper.get(".detail-info a.btn").attributes("href")).toBe(
      "/booking?service=stone-ritual",
    );
    expect(wrapper.get(".svc-card a.ulink").attributes("href")).toBe(
      "/services/glow-facial",
    );
  });

  it("accepts only an active server-selected booking service and shows its summary", () => {
    const wrapper = mount(Booking, {
      props: {
        services: [stoneRitual, glowFacial],
        selectedService: "stone-ritual",
      },
    });

    const optionValues = wrapper
      .findAll("#f-service option")
      .map((option) => option.element.getAttribute("value"));
    expect(optionValues).toEqual(["", "stone-ritual", "glow-facial"]);
    expect((wrapper.get("#f-service").element as HTMLSelectElement).value).toBe(
      "stone-ritual",
    );
    expect(wrapper.text()).toContain("Warm Stone Ritual");
    expect(wrapper.text()).toContain("90 min");
    expect(wrapper.text()).toContain("Rp 275.000");
  });

  it("does not select a server-provided booking slug absent from the active services", () => {
    const wrapper = mount(Booking, {
      props: { services: [glowFacial], selectedService: "stone-ritual" },
    });

    expect((wrapper.get("#f-service").element as HTMLSelectElement).value).toBe(
      "",
    );
    expect(wrapper.text()).toContain("—");
  });
});
