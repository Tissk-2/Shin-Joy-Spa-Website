import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import Create from "@/pages/admin/bookings/Create.vue";
import type { Locale, PackageData } from "@/types";

interface RecordedPost {
  url: string;
  data: Record<string, unknown>;
  options: {
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
  };
}

const inertia = vi.hoisted(() => ({
  page: {
    url: "/admin/bookings/create",
    props: {
      auth: {
        user: {
          id: 4,
          name: "Rani",
          email: "rani@shinjoy.test",
          phone: "0812",
          email_verified_at: "2026-09-01",
        },
        canAccessAdmin: true,
      },
      locale: "en" as Locale,
      flash: {},
      errors: {},
      catalog: { packages: [] },
    },
  },
  posts: [] as RecordedPost[],
}));

vi.mock("@inertiajs/vue3", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@inertiajs/vue3")>();
  const { reactive, defineComponent, h } = await import("vue");
  inertia.page = reactive(inertia.page);
  vi.spyOn(actual.router, "post").mockImplementation((url, data, options) => {
    inertia.posts.push({
      url: String(url),
      data: data as Record<string, unknown>,
      options: options as RecordedPost["options"],
    });
  });
  return {
    ...actual,
    Head: "span",
    usePage: () => inertia.page,
    Link: defineComponent({
      props: { href: String },
      setup:
        (props, { slots }) =>
        () =>
          h("a", { href: props.href }, slots.default?.()),
    }),
  };
});

const packages: PackageData[] = [
  {
    slug: "nezuko",
    nameId: "Nezuko",
    nameEn: "Nezuko",
    isVip: false,
    descId: "",
    descEn: "",
    longId: "",
    longEn: "",
    benefitId: null,
    benefitEn: null,
    image: "",
    gallery: [],
    variants: [
      {
        code: "nezuko-regular-90",
        mode: "regular",
        duration: 90,
        price: 265000,
      },
      {
        code: "nezuko-double-60",
        mode: "double_therapist",
        duration: 60,
        price: 250000,
      },
    ],
    extensions: [
      {
        code: "nezuko-regular-extra-60",
        mode: "regular",
        duration: 60,
        price: 75000,
      },
      {
        code: "nezuko-double-extra-30",
        mode: "double_therapist",
        duration: 30,
        price: 100000,
      },
    ],
  },
];

const submissionKey = "018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a30";

const mounted: ReturnType<typeof mount>[] = [];

function mountPage(availabilityEnabled?: boolean) {
  const wrapper = mount(Create, {
    props: {
      packages,
      submissionKey,
      sources: ["walk_in", "phone", "whatsapp"],
      ...(availabilityEnabled === undefined ? {} : { availabilityEnabled }),
    },
    attachTo: document.body,
  });
  mounted.push(wrapper);
  return wrapper;
}

async function fillDetails(wrapper: ReturnType<typeof mountPage>) {
  await wrapper.get("#staff-name").setValue("Siti");
  await wrapper.get("#staff-phone").setValue("+62 812-1111-2222");
  await wrapper.get("#staff-date").setValue("2026-12-01");
  const options = wrapper.get("#staff-time").findAll("option");
  await wrapper.get("#staff-time").setValue(options[1]!.attributes("value"));
  return options[1]!.attributes("value");
}

beforeEach(() => {
  inertia.posts = [];
  inertia.page.props.locale = "en";
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        ({
          ok: true,
          status: 200,
          json: async () => ({
            generated_at: "2026-12-01T12:00:00Z",
            slots: [],
          }),
        }) as Response,
    ),
  );
});

afterEach(async () => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount();
  await flushPromises();
  document.body.innerHTML = "";
  vi.unstubAllGlobals();
  vi.clearAllMocks();
});

describe("admin manual booking entry", () => {
  it("posts the staff booking payload with the client total and the page key", async () => {
    const wrapper = mountPage();
    await wrapper.get('button[data-mode="regular"]').trigger("click");
    await wrapper.get('button[data-duration="90"]').trigger("click");
    const approvedTime = await fillDetails(wrapper);

    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toHaveLength(1);
    const post = inertia.posts[0]!;
    expect(post.url).toBe("/admin/bookings");
    expect(post.data).toMatchObject({
      source: "walk_in",
      name: "Siti",
      phone: "+62 812-1111-2222",
      email: null,
      service: "nezuko",
      date: "2026-12-01",
      time: approvedTime,
      approve: false,
      submission_key: submissionKey,
      mode: "regular",
      duration: 90,
      expected_total_price_idr: 265000,
    });
    expect(post.data).not.toHaveProperty("operationalConfirmed");
    expect(
      `Rp ${new Intl.NumberFormat("id-ID").format(
        post.data.expected_total_price_idr as number,
      )}`,
    ).toBe(wrapper.get("[data-staff-total]").text());
  });

  it("asks for the operational confirmation only when approving with scheduling on", async () => {
    const withoutScheduling = mountPage();
    await withoutScheduling.get("#staff-approve").setValue(true);
    expect(withoutScheduling.find("#staff-operational").exists()).toBe(false);
    withoutScheduling.unmount();

    const withScheduling = mountPage(true);
    await withScheduling.get("#staff-approve").setValue(true);
    expect(withScheduling.find("#staff-operational").exists()).toBe(true);
  });

  it("shows field errors and a restart link for a stale key", async () => {
    const wrapper = mountPage();
    await fillDetails(wrapper);
    await wrapper.get("form").trigger("submit");

    const post = inertia.posts[0]!;
    post.options.onError?.({
      phone: "Phone or email required.",
      submission_key: "Already used.",
    });
    post.options.onFinish?.();
    await flushPromises();

    expect(wrapper.get("#staff-phone-error").text()).toBe(
      "Phone or email required.",
    );
    expect(wrapper.get("#staff-phone").attributes("aria-invalid")).toBe("true");
    expect(wrapper.get("[data-staff-errors]").text()).toContain(
      "Already used.",
    );
    expect(wrapper.get("a[data-staff-restart]").attributes("href")).toBe(
      "/admin/bookings/create",
    );
  });

  it("does not post twice while processing", async () => {
    const wrapper = mountPage();
    await fillDetails(wrapper);

    await wrapper.get("form").trigger("submit");
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toHaveLength(1);
  });

  // Review fix: the redirect back after a validation error re-renders the page with a fresh key.
  it("keeps the page key after a validation error and blocks a stale-key re-save", async () => {
    const wrapper = mountPage();
    await fillDetails(wrapper);
    await wrapper.get("form").trigger("submit");
    inertia.posts[0]!.options.onError?.({ name: "Too long." });
    inertia.posts[0]!.options.onFinish?.();
    await wrapper.setProps({
      submissionKey: "018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a99",
    });
    await wrapper.get("form").trigger("submit");
    expect(inertia.posts).toHaveLength(2);
    expect(inertia.posts[1]!.data.submission_key).toBe(submissionKey);

    inertia.posts[1]!.options.onError?.({ submission_key: "Already used." });
    inertia.posts[1]!.options.onFinish?.();
    await flushPromises();
    await wrapper.get("form").trigger("submit");
    expect(inertia.posts).toHaveLength(2);
  });

  // Review fix: re-sent packages must not wipe the chosen time, and a blocked save must say why.
  it("keeps the chosen available time across re-sent props and explains a blocked save", async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        generated_at: "2099-09-05T12:00:00Z",
        slots: [
          {
            start_at: "2099-09-06T02:00:00Z",
            rooms: [
              {
                code: "A",
                name: "Room A",
                type: null,
                capacity: 2,
                available: true,
              },
            ],
          },
        ],
      }),
    } as Response);
    const wrapper = mountPage(true);
    await wrapper.get('button[data-mode="regular"]').trigger("click");
    await wrapper.get('button[data-duration="90"]').trigger("click");
    await wrapper.get("#staff-name").setValue("Siti");
    await wrapper.get("#staff-phone").setValue("+62 812-1111-2222");

    await wrapper.get("form").trigger("submit");
    expect(inertia.posts).toHaveLength(0);
    expect(wrapper.find("#staff-time-error").exists()).toBe(true);
    expect(document.activeElement).toBe(wrapper.get("#staff-time").element);

    await wrapper.get("#staff-date").setValue("2099-09-06");
    await flushPromises();
    await wrapper.get("#staff-time").setValue("09:00");
    await wrapper.setProps({ packages: structuredClone(packages) });
    await flushPromises();
    expect(
      (wrapper.get("#staff-time").element as HTMLSelectElement).value,
    ).toBe("09:00");
    await wrapper.get("form").trigger("submit");
    expect(inertia.posts).toHaveLength(1);
    expect(inertia.posts[0]!.data.time).toBe("09:00");
  });

  // Review fix: keyboard and screen-reader users land on the first field the server rejected.
  it("moves focus to the first rejected field after a server error", async () => {
    const wrapper = mountPage();
    await fillDetails(wrapper);
    await wrapper.get("form").trigger("submit");
    inertia.posts[0]!.options.onError?.({ phone: "Phone or email required." });
    inertia.posts[0]!.options.onFinish?.();
    await flushPromises();
    expect(document.activeElement).toBe(wrapper.get("#staff-phone").element);
  });
});
