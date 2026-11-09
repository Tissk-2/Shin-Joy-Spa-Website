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
        canAccessAdmin: false,
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

const verifiedPerPerson: PackageData[] = [
  {
    ...packages[0],
    pricingBasis: "per_person",
    minGuests: 1,
    maxGuests: 4,
    pricingVerified: true,
  },
];

const verifiedPerBooking: PackageData[] = [
  {
    ...packages[0],
    pricingBasis: "per_booking",
    minGuests: 2,
    maxGuests: 4,
    pricingVerified: true,
  },
];

const submissionKey = "018f2f7c-9d0a-7b1b-9a45-3c1f9d0b5a10";

function mountBooking(catalog: PackageData[] = packages) {
  return mount(Booking, {
    props: { packages: catalog, selection, submissionKey },
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
  it("returns a fresh twelve-key scalar payload including reviewed price without mutating the input", () => {
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
      expected_total_price_idr: 340000,
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
      expected_total_price_idr: 340000,
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
      "expected_total_price_idr",
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
    expect(wrapper.find("#f-email").exists()).toBe(false);
    expect(wrapper.get("label[for='f-phone']").text()).toBe(
      "WhatsApp number (optional)",
    );

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toEqual([]);
    expect(wrapper.text()).toContain("No reservation has been submitted yet");
    expect(wrapper.text()).toContain("Confirm booking");
    const initialRecap = wrapper.get(".booking-recap");
    expect(initialRecap.text()).toContain("Number of guests");
    expect(initialRecap.text()).toContain("2 people");
    expect(initialRecap.text()).toContain("Full name");
    expect(initialRecap.text()).toContain("Nadia Lestari");
    expect(initialRecap.text()).toContain("+62 812-3456-7890");
    expect(initialRecap.text()).toContain("Prefer quiet room.");
    expect(initialRecap.text()).toContain("Rp 340.000");
    expect(initialRecap.text()).toContain(
      "This estimate covers the selected treatment and any extension. Guest count does not multiply it; the total for all guests has not been confirmed.",
    );

    await wrapper.get("[data-booking-edit]").trigger("click");
    await wrapper.vm.$nextTick();
    expect(wrapper.get<HTMLInputElement>("#f-name").element.value).toBe(
      " Nadia Lestari ",
    );
    expect(wrapper.get<HTMLInputElement>("#f-phone").element.value).toBe(
      "+62 812-3456-7890",
    );
    expect(wrapper.get<HTMLSelectElement>("#f-guests").element.value).toBe("2");
    expect(wrapper.get<HTMLTextAreaElement>("#f-notes").element.value).toBe(
      " Prefer quiet room. ",
    );
    expect(inertia.forms.at(-1)?.submission_key).toBe(submissionKey);

    const editedName = "Nadia Lestari, updated for the booking review";
    const editedPhone = "+62 812-7654-3210";
    const editedNotes = "Updated <img src=x onerror=alert(1)> note";
    await wrapper.get<HTMLInputElement>("#f-name").setValue(editedName);
    await wrapper.get<HTMLInputElement>("#f-phone").setValue(editedPhone);
    await wrapper.get<HTMLSelectElement>("#f-guests").setValue("3");
    await wrapper.get<HTMLTextAreaElement>("#f-notes").setValue(editedNotes);
    await wrapper.get("form").trigger("submit");

    const editedRecap = wrapper.get(".booking-recap");
    expect(editedRecap.text()).toContain("3 people");
    expect(editedRecap.text()).toContain(editedName);
    expect(editedRecap.text()).toContain(editedPhone);
    expect(editedRecap.text()).toContain(editedNotes);
    expect(editedRecap.find("img").exists()).toBe(false);
    expect(editedRecap.get(".summary-total .amt").text()).toBe("Rp 340.000");

    await wrapper.get("[data-booking-confirm]").trigger("click");

    expect(inertia.posts).toEqual([
      {
        url: "/bookings",
        data: {
          name: editedName,
          phone: editedPhone,
          service: "nezuko",
          mode: "regular",
          duration: 90,
          extension: "nezuko-regular-extra-60",
          date: "2099-09-06",
          time: "09:00",
          guests: 3,
          notes: editedNotes,
          submission_key: submissionKey,
          expected_total_price_idr: 340000,
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

  it("multiplies verified per-person totals by guests and submits the reviewed total", async () => {
    const wrapper = mountBooking(verifiedPerPerson);

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");

    const recap = wrapper.get(".booking-recap");
    expect(recap.get(".summary-total").text()).toContain("For 2 guests");
    expect(recap.get(".summary-total").text()).not.toContain("Per person");
    expect(recap.text()).toContain("Rp 680.000");
    expect(recap.text()).toContain(
      "This total multiplies the per-guest price by the number of guests and covers the selected treatment and any extension.",
    );

    await wrapper.get("[data-booking-edit]").trigger("click");
    await wrapper.get<HTMLSelectElement>("#f-guests").setValue("3");
    await wrapper.get("form").trigger("submit");
    expect(wrapper.get(".booking-recap").text()).toContain("Rp 1.020.000");

    await wrapper.get("[data-booking-confirm]").trigger("click");
    expect(inertia.posts[0].data.guests).toBe(3);
    expect(inertia.posts[0].data.expected_total_price_idr).toBe(1020000);
  });

  it("restricts verified guest coverage and keeps one per-booking total", async () => {
    const wrapper = mountBooking(verifiedPerBooking);
    const guestSelect = wrapper.get<HTMLSelectElement>("#f-guests");

    expect(
      guestSelect.findAll("option").map((option) => option.attributes("value")),
    ).toEqual(["2", "3", "4"]);
    expect(guestSelect.element.value).toBe("2");

    await completeDetails(wrapper);
    await wrapper.get<HTMLSelectElement>("#f-guests").setValue("3");
    await wrapper.get("form").trigger("submit");

    const recap = wrapper.get(".booking-recap");
    expect(recap.text()).toContain("3 people");
    expect(recap.get(".summary-total").text()).toContain("For 3 guests");
    expect(recap.text()).toContain("Rp 340.000");
    expect(recap.text()).toContain(
      "This total covers the selected treatment and any extension for the whole booking; it does not multiply by guest count.",
    );

    await wrapper.get("[data-booking-confirm]").trigger("click");
    expect(inertia.posts[0].data.expected_total_price_idr).toBe(340000);
  });

  it.each(["en", "id"])(
    "labels the per-person group estimate for all guests in %s",
    async (locale) => {
      inertia.page.props.locale = locale;
      const wrapper = mountBooking(verifiedPerPerson);
      await completeDetails(wrapper);
      const expected = locale === "id" ? "Untuk 2 tamu" : "For 2 guests";
      expect(wrapper.get(".summary-total").text()).toContain(expected);
      expect(wrapper.get(".summary-total").text()).toContain("Rp 680.000");
      expect(wrapper.get(".package-configurator__total").text()).toContain(
        expected,
      );
      expect(wrapper.get(".package-configurator__total").text()).toContain(
        "Rp 680.000",
      );
      await wrapper.get("form").trigger("submit");
      expect(wrapper.get(".booking-recap .summary-total").text()).toContain(
        expected,
      );
    },
  );

  it("shows one guest, empty notes, and Indonesian estimate scope in the review", async () => {
    inertia.page.props.locale = "id";
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    await wrapper.get<HTMLSelectElement>("#f-guests").setValue("1");
    await wrapper.get<HTMLTextAreaElement>("#f-notes").setValue("");
    await wrapper.get("form").trigger("submit");

    const recap = wrapper.get(".booking-recap");
    expect(recap.text()).toContain("Jumlah tamu");
    expect(recap.text()).toContain("1 orang");
    expect(recap.text()).toContain("Nama lengkap");
    expect(recap.text()).toContain("Nomor WhatsApp (opsional)");
    expect(recap.text()).toContain("Catatan (opsional)");
    expect(recap.text()).toContain("Belum ada catatan");
    expect(recap.text()).toContain(
      "Estimasi ini mencakup perawatan yang dipilih dan tambahan apa pun. Jumlah tamu tidak mengalikan jumlah tersebut; total untuk semua tamu belum dikonfirmasi.",
    );
  });

  it("labels empty notes in English in the review", async () => {
    inertia.page.props.locale = "en";
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    await wrapper.get<HTMLTextAreaElement>("#f-notes").setValue("");
    await wrapper.get("form").trigger("submit");

    expect(wrapper.get(".summary-row--notes").text()).toContain(
      "No notes provided",
    );
    expect(wrapper.get(".booking-recap").text()).toContain(
      "This estimate covers the selected treatment and any extension. Guest count does not multiply it; the total for all guests has not been confirmed.",
    );
  });

  it("tells the customer that staff approval comes next on the review step", async () => {
    inertia.page.props.locale = "en";
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");

    expect(wrapper.get("[data-booking-approval-note]").text()).toBe(
      "Once sent, your booking waits for staff approval. Nothing is reserved until staff confirm it.",
    );
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
    expect(wrapper.get("[data-booking-restart]").attributes("href")).toContain(
      "/booking?",
    );
    expect(wrapper.get("[data-booking-existing]").attributes("href")).toBe(
      "/bookings",
    );
    await wrapper.get("form").trigger("submit");
    expect(inertia.posts).toHaveLength(1);
  });

  it("blocks a stale price and offers a fresh review route", async () => {
    inertia.nextPostError = {
      expected_total_price_idr: "The treatment price has changed.",
    };
    const wrapper = mountBooking();

    await completeDetails(wrapper);
    await wrapper.get("form").trigger("submit");
    await wrapper.get("[data-booking-confirm]").trigger("click");
    await wrapper.vm.$nextTick();

    expect(wrapper.get("[role='alert']").text()).toContain("price has changed");
    expect(wrapper.get("[data-booking-restart]").attributes("href")).toContain(
      "/booking?",
    );
    await wrapper.get("form").trigger("submit");
    expect(inertia.posts).toHaveLength(1);
  });

  it("only offers start times whose selected treatment ends by closing", async () => {
    const wrapper = mountBooking();
    const times = wrapper
      .findAll<HTMLSelectElement>("#f-time option")
      .map((item) => item.element.value);

    expect(times).toContain("18:30");
    expect(times).not.toContain("19:00");
    expect(times).not.toContain("20:00");
  });

  it("offers time choices before a treatment duration is selected", async () => {
    const wrapper = mount(Booking, {
      props: {
        packages,
        selection: {
          service: "nezuko",
          mode: "",
          duration: null,
          extension: "",
        },
        submissionKey,
      },
    });

    const times = () =>
      wrapper
        .get("#f-time")
        .findAll("option")
        .map((option) => option.attributes("value"));

    expect(times()).toContain("09:00");
    expect(times()).toContain("20:00");

    await wrapper.get<HTMLSelectElement>("#f-time").setValue("20:00");
    await wrapper.get('button[data-mode="regular"]').trigger("click");
    await wrapper.get('button[data-duration="90"]').trigger("click");

    expect(times()).not.toContain("20:00");
    expect(wrapper.get<HTMLSelectElement>("#f-time").element.value).toBe("");
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

  it("submits a cleared WhatsApp number as an empty string and keeps the review flow intact", async () => {
    const wrapper = mountBooking();

    await wrapper.get<HTMLInputElement>("#f-name").setValue("Ayu Lestari");
    await wrapper.get<HTMLInputElement>("#f-phone").setValue("  ");
    await wrapper.get<HTMLInputElement>("#f-date").setValue("2099-09-06");
    await wrapper.get<HTMLSelectElement>("#f-time").setValue("09:00");
    await wrapper.get<HTMLSelectElement>("#f-guests").setValue("2");

    await wrapper.get("form").trigger("submit");
    await wrapper.get("[data-booking-confirm]").trigger("click");

    expect(inertia.posts).toHaveLength(1);
    expect(inertia.posts[0].data.phone).toBe("  ");
    expect(wrapper.text()).not.toContain("Account email");
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
