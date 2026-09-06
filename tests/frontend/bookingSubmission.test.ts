import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  buildBookingSubmissionPayload,
  type BookingSubmissionInput,
} from "@/lib/booking";
import Booking from "@/pages/Booking.vue";
import type { PackageData } from "@/types";

interface FormDouble {
  errors: Record<string, string>;
  processing: boolean;
  post: ReturnType<typeof vi.fn>;
  transform: ReturnType<typeof vi.fn>;
  lastPayload: Record<string, unknown>;
  [key: string]: unknown;
}

const inertia = vi.hoisted(() => ({
  forms: [] as FormDouble[],
  page: {
    url: "/booking",
    props: {
      auth: {
        user: {
          id: 7,
          name: "Nadia",
          phone: "+62 812-3456-7890",
          email: "nadia@example.test",
          email_verified_at: "2026-08-01",
        },
      },
      locale: "en",
      flash: {},
      catalog: { packages: [] },
    },
  },
  posts: [] as Array<{ url: string; data: Record<string, unknown> }>,
  holdProcessing: false,
  nextPostError: null as Record<string, string> | null,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive(inertia.page);
  inertia.page = page;

  return {
    Head: "span",
    Link: "a",
    router: { post: vi.fn() },
    usePage: () => inertia.page,
    useForm: (initial: Record<string, unknown>) => {
      const form = reactive({
        ...initial,
        errors: {},
        processing: false,
        lastPayload: { ...initial },
        wasTransformed: false,
        transform: vi.fn(),
        post: vi.fn(),
      }) as FormDouble & { wasTransformed: boolean };

      const currentPayload = () =>
        form.wasTransformed
          ? form.lastPayload
          : Object.fromEntries(
              Object.keys(initial).map((key) => [key, form[key]]),
            );

      form.transform.mockImplementation(
        (
          callback: (data: Record<string, unknown>) => Record<string, unknown>,
        ) => {
          form.lastPayload = callback(
            Object.fromEntries(
              Object.keys(initial).map((key) => [key, form[key]]),
            ),
          );
          form.wasTransformed = true;
          return form;
        },
      );

      form.post.mockImplementation(
        (
          url: string,
          options?: {
            onError?: (errors: Record<string, string>) => void;
            onFinish?: () => void;
          },
        ) => {
          form.processing = true;
          inertia.posts.push({ url, data: currentPayload() });

          if (inertia.nextPostError) {
            form.errors = inertia.nextPostError;
            options?.onError?.(inertia.nextPostError);
            inertia.nextPostError = null;
          }

          if (!inertia.holdProcessing) {
            form.processing = false;
            options?.onFinish?.();
          }
        },
      );

      inertia.forms.push(form);
      return form;
    },
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

const selection = {
  service: "nezuko",
  mode: "regular" as const,
  duration: 90,
  extension: "nezuko-regular-extra-60",
};

const submissionKey = "018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10";

function mountBooking() {
  return mount(Booking, {
    props: { packages, selection, submissionKey },
  });
}

async function completeDetails(wrapper: ReturnType<typeof mountBooking>) {
  await wrapper.get<HTMLInputElement>("#f-name").setValue(" Nadia Lestari ");
  await wrapper.get<HTMLInputElement>("#f-phone").setValue("+62 812-3456-7890");
  await wrapper.get<HTMLInputElement>("#f-date").setValue("2099-09-06");
  await wrapper.get<HTMLSelectElement>("#f-time").setValue("09:00");
  await wrapper.get<HTMLSelectElement>("#f-guests").setValue("2");
  await wrapper
    .get<HTMLTextAreaElement>("#f-notes")
    .setValue(" Prefer quiet room. ");
}

describe("booking submission payload", () => {
  it("returns a fresh eleven-key scalar payload without mutating the input", () => {
    const input: BookingSubmissionInput & Record<string, unknown> = {
      name: " Nadia Lestari ",
      phone: "+62 812-3456-7890",
      service: "nezuko",
      mode: "regular",
      duration: 90,
      extension: "nezuko-regular-extra-60",
      date: "2099-09-06",
      time: "09:00",
      guests: 2,
      notes: " Prefer quiet room. ",
      submission_key: submissionKey,
      email: "nadia@example.test",
      user_id: 7,
      status: "confirmed",
      totalPriceIdr: 1,
      totalDurationMinutes: 1,
      reference: "SJ-HOSTILE",
    };
    const before = { ...input };

    const payload = buildBookingSubmissionPayload(input);

    expect(payload).toEqual({
      name: " Nadia Lestari ",
      phone: "+62 812-3456-7890",
      service: "nezuko",
      mode: "regular",
      duration: 90,
      extension: "nezuko-regular-extra-60",
      date: "2099-09-06",
      time: "09:00",
      guests: 2,
      notes: " Prefer quiet room. ",
      submission_key: submissionKey,
    });
    expect(Object.keys(payload)).toEqual([
      "name",
      "phone",
      "service",
      "mode",
      "duration",
      "extension",
      "date",
      "time",
      "guests",
      "notes",
      "submission_key",
    ]);
    expect(payload).not.toBe(input);
    expect(input).toEqual(before);
  });
});

describe("reviewed booking submission", () => {
  beforeEach(() => {
    inertia.forms = [];
    inertia.posts = [];
    inertia.holdProcessing = false;
    inertia.nextPostError = null;
    inertia.page.props.locale = "en";
    inertia.page.props.auth.user = {
      id: 7,
      name: "Nadia",
      phone: "+62 812-3456-7890",
      email: "nadia@example.test",
      email_verified_at: "2026-08-01",
    };
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("prefills account identity, reviews locally first, edits without rotating the key, and confirms only the allow-listed payload", async () => {
    const wrapper = mountBooking();

    expect(inertia.forms.at(-1)?.submission_key).toBe(submissionKey);
    expect(wrapper.get<HTMLInputElement>("#f-name").element.value).toBe(
      "Nadia",
    );
    expect(wrapper.get<HTMLInputElement>("#f-phone").element.value).toBe(
      "+62 812-3456-7890",
    );
    expect(wrapper.get<HTMLInputElement>("#f-email").element.readOnly).toBe(
      true,
    );

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toEqual([]);
    expect(wrapper.text()).toContain("No reservation has been submitted yet");
    expect(wrapper.text()).toContain("Confirm booking");

    await wrapper.get("[data-booking-edit]").trigger("click");
    await wrapper.vm.$nextTick();
    expect(wrapper.get<HTMLInputElement>("#f-name").element.value).toBe(
      " Nadia Lestari ",
    );
    expect(inertia.forms.at(-1)?.submission_key).toBe(submissionKey);

    await wrapper.get("form").trigger("submit");
    await wrapper.get("[data-booking-confirm]").trigger("click");

    expect(inertia.posts).toEqual([
      {
        url: "/bookings",
        data: {
          name: " Nadia Lestari ",
          phone: "+62 812-3456-7890",
          service: "nezuko",
          mode: "regular",
          duration: 90,
          extension: "nezuko-regular-extra-60",
          date: "2099-09-06",
          time: "09:00",
          guests: 2,
          notes: " Prefer quiet room. ",
          submission_key: submissionKey,
        },
      },
    ]);
    expect(inertia.posts[0].data).not.toHaveProperty("email");
    expect(inertia.posts[0].data).not.toHaveProperty("user_id");
    expect(inertia.posts[0].data).not.toHaveProperty("status");
    expect(inertia.posts[0].data).not.toHaveProperty("reference");
    expect(inertia.posts[0].data).not.toHaveProperty("totalPriceIdr");
    expect(inertia.posts[0].data).not.toHaveProperty("totalDurationMinutes");
  });

  it("returns to the editable form with associated server errors while preserving values and the submission key", async () => {
    inertia.nextPostError = {
      date: "Please choose a future appointment date.",
      service: "The selected service is no longer available.",
      submission_key: "This booking form has already been used.",
    };
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");
    await wrapper.get("[data-booking-confirm]").trigger("click");
    await wrapper.vm.$nextTick();

    expect(wrapper.find("[data-booking-confirm]").exists()).toBe(false);
    expect(wrapper.get<HTMLInputElement>("#f-date").element.value).toBe(
      "2099-09-06",
    );
    expect(
      wrapper.get<HTMLInputElement>("#f-date").attributes("aria-invalid"),
    ).toBe("true");
    expect(
      wrapper.get<HTMLInputElement>("#f-date").attributes("aria-describedby"),
    ).toContain("f-date-error");
    expect(wrapper.get("#f-date-error").text()).toBe(
      "Please choose a future appointment date.",
    );
    expect(
      wrapper
        .get("[data-booking-service-group]")
        .attributes("aria-describedby"),
    ).toContain("booking-service-error");
    expect(wrapper.get("#booking-service-error").text()).toBe(
      "The selected service is no longer available.",
    );
    expect(wrapper.get("[role='alert']").text()).toContain(
      "This booking form has already been used.",
    );
    expect(inertia.forms.at(-1)?.submission_key).toBe(submissionKey);
  });

  it("shows every distinct server selection error in the associated configuration group", async () => {
    inertia.nextPostError = {
      mode: "The selected treatment type is unavailable.",
      duration: "The selected base duration is unavailable.",
      extension: "The selected extension is unavailable.",
    };
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");
    await wrapper.get("[data-booking-confirm]").trigger("click");
    await wrapper.vm.$nextTick();

    const selectionGroup = wrapper.get(
      "[role='group'][aria-describedby='booking-selection-error']",
    );
    expect(selectionGroup.attributes("aria-describedby")).toContain(
      "booking-selection-error",
    );

    const selectionErrors = wrapper
      .findAll("#booking-selection-error li")
      .map((item) => item.text());
    expect(selectionErrors).toEqual([
      "The selected treatment type is unavailable.",
      "The selected base duration is unavailable.",
      "The selected extension is unavailable.",
    ]);
  });

  it("disables review and confirmation controls while processing and ignores repeated confirm clicks", async () => {
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    const form = inertia.forms.at(-1)!;
    form.processing = true;
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();

    form.processing = false;
    await wrapper.get("form").trigger("submit");
    inertia.holdProcessing = true;
    await wrapper.get("[data-booking-confirm]").trigger("click");
    await wrapper.get("[data-booking-confirm]").trigger("click");

    expect(inertia.posts).toHaveLength(1);
    expect(
      wrapper.get("[data-booking-confirm]").attributes("disabled"),
    ).toBeDefined();
    expect(
      wrapper.get("[data-booking-edit]").attributes("disabled"),
    ).toBeDefined();
    expect(wrapper.get("[role='status']").attributes("aria-live")).toBe(
      "polite",
    );
    expect(wrapper.text()).toContain("Submitting booking");
  });

  it("keeps review, confirm, pending, and server-error recovery copy bilingual", async () => {
    inertia.page.props.locale = "id";
    inertia.nextPostError = { time: "Jam yang dipilih tidak tersedia." };
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");

    expect(wrapper.text()).toContain("Reservasi belum dikirim");
    expect(wrapper.text()).toContain("Konfirmasi reservasi");

    await wrapper.get("[data-booking-confirm]").trigger("click");
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain("Periksa kembali detail reservasi Anda.");
    expect(wrapper.get("#f-time-error").text()).toBe(
      "Jam yang dipilih tidak tersedia.",
    );
  });
});
