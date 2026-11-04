import { mount } from "@vue/test-utils";
import { reactive } from "vue";
import { beforeEach, expect, it, vi } from "vitest";
import Form from "@/pages/admin/rooms/Form.vue";

type Options = {
  onError: (errors: Record<string, string>) => void;
  onFinish: () => void;
  onSuccess: (page: { props: { room: typeof room } }) => void;
};
const state = vi.hoisted(() => ({
  page: { props: { locale: "en", flash: { success: "" } } },
  calls: [] as Array<{
    method: string;
    url: string;
    data: Record<string, unknown>;
    options: Options;
  }>,
}));
vi.mock("@inertiajs/vue3", () => ({
  usePage: () => state.page,
  router: Object.fromEntries(
    ["post", "patch"].map((method) => [
      method,
      (url: string, data: Record<string, unknown>, options: Options) =>
        state.calls.push({ method, url, data, options }),
    ]),
  ),
}));
vi.mock("@/layouts/admin/AdminLayout.vue", () => ({
  default: {
    props: ["title"],
    template: "<main><h1>{{ title }}</h1><slot /></main>",
  },
}));
const room = {
  id: 4,
  code: "R4",
  name: "Existing",
  type: "Suite",
  capacity: 8,
  cleaningMinutes: 15,
  isActive: true,
  version: 9,
  compatibleVariantIds: [6],
};
const props = {
  management: { isOwner: true, ready: true },
  room: null,
  variants: [
    {
      id: 6,
      code: "V6",
      serviceNameId: "Pijat",
      serviceNameEn: "Massage",
      treatmentMode: "regular" as const,
      durationMinutes: 60,
      variantStatus: "draft" as const,
      serviceStatus: "archived" as const,
      categoryStatus: "published" as const,
    },
  ],
};
beforeEach(() => {
  state.calls = [];
  state.page = reactive({ props: { locale: "en", flash: { success: "" } } });
});
it("requires explicit physical values, defaults inactive and explains empty compatibility", () => {
  const wrapper = mount(Form, { props });
  for (const field of ["code", "name", "capacity", "cleaningMinutes"])
    expect(wrapper.get(`#${field}`).element).toHaveProperty("value", "");
  expect(wrapper.get("#isActive").element).toHaveProperty("checked", false);
  expect(wrapper.text()).toContain("cannot be allocated");
  expect(wrapper.text()).toContain("Massage");
  expect(wrapper.text()).toContain("Draft");
  expect(wrapper.text()).toContain("Archived");
});
it("loads edit values and sends versioned payload only once while busy, preserving draft on stale errors", async () => {
  const wrapper = mount(Form, { props: { ...props, room } });
  expect(wrapper.get("#capacity").element).toHaveProperty("value", "8");
  expect(wrapper.get('input[value="6"]').element).toHaveProperty(
    "checked",
    true,
  );
  await wrapper.get("#name").setValue("Draft name");
  await wrapper.get("form").trigger("submit");
  await wrapper.get("form").trigger("submit");
  expect(state.calls).toHaveLength(1);
  expect(state.calls[0]).toMatchObject({
    method: "patch",
    url: "/admin/rooms/4",
    data: {
      name: "Draft name",
      capacity: "8",
      version: 9,
      compatibleVariantIds: [6],
    },
  });
  state.calls[0].options.onError({
    version: "Reload and review latest values",
    room: "Booking ABC protected",
  });
  state.calls[0].options.onFinish();
  await wrapper.vm.$nextTick();
  expect(wrapper.text()).toContain("Booking ABC protected");
  expect(wrapper.text()).toContain("Reload and review latest values");
  expect(wrapper.get("#name").element).toHaveProperty("value", "Draft name");
  expect(
    wrapper.get('button[type="submit"]').attributes("disabled"),
  ).toBeUndefined();
});
it("creates without version and shows field errors", async () => {
  const wrapper = mount(Form, { props });
  await wrapper.get("form").trigger("submit");
  expect(state.calls[0]).toMatchObject({ method: "post", url: "/admin/rooms" });
  expect(state.calls[0].data).not.toHaveProperty("version");
  state.calls[0].options.onError({
    code: "Code required",
    "compatibleVariantIds.0": "Invalid choice",
  });
  await wrapper.vm.$nextTick();
  expect(wrapper.text()).toContain("Code required");
  expect(wrapper.text()).toContain("Invalid choice");
});
it("uses the successful response id and version for the next edit without reloading", async () => {
  const wrapper = mount(Form, { props });
  await wrapper.get("form").trigger("submit");
  state.calls[0].options.onSuccess({ props: { room } });
  state.calls[0].options.onFinish();
  await wrapper.vm.$nextTick();
  expect(wrapper.get("#name").element).toHaveProperty("value", "Existing");
  await wrapper.get("#name").setValue("Second save");
  await wrapper.get("form").trigger("submit");
  expect(state.calls[1]).toMatchObject({
    method: "patch",
    url: "/admin/rooms/4",
    data: { version: 9, name: "Second save" },
  });
});
it.each(["en", "id"])(
  "shows authorized unavailable form without save controls in %s",
  (locale) => {
    state.page.props.locale = locale;
    const wrapper = mount(Form, {
      props: { ...props, management: { isOwner: true, ready: false } },
    });
    expect(wrapper.find("form").exists()).toBe(false);
    expect(wrapper.text()).toContain(
      locale === "id"
        ? "Pengeditan ruangan belum tersedia"
        : "Room editing is unavailable",
    );
  },
);
