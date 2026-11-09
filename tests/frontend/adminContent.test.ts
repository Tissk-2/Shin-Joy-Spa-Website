import { mount, type VueWrapper } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import Content from "@/pages/admin/Content.vue";

type PostCall = { url: string; data: unknown; options: unknown };
type EditorValues = Record<string, { id?: string; en?: string }>;

const inertia = vi.hoisted(() => ({
  page: {
    props: {
      locale: "en",
      flash: {},
      errors: {},
      auth: { user: null, canAccessAdmin: true },
    },
  },
  posts: [] as PostCall[],
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  inertia.page = reactive(inertia.page);
  const useForm = (initial: {
    revision?: number;
    values: Record<string, { id: string; en: string }>;
  }) => {
    const form = reactive({
      ...initial,
      values: initial.values,
      errors: {} as Record<string, string>,
      processing: false,
      transform(callback: () => unknown) {
        return {
          post: (url: string, options: unknown) => {
            inertia.posts.push({ url, data: callback(), options });
          },
        };
      },
      post(url: string, options: unknown) {
        inertia.posts.push({ url, data: { values: form.values }, options });
      },
    });

    return form;
  };

  return {
    Link: { template: "<a><slot /></a>" },
    useForm,
    usePage: () => inertia.page,
    router: {
      post: (url: string, data: unknown, options: unknown) =>
        inertia.posts.push({ url, data, options }),
    },
  };
});

const sections = { hero: ["hero.heading", "hero.sub"] };

let wrappers: VueWrapper[] = [];

function mountEditor(values: EditorValues, revision = 3) {
  const wrapper = mount(Content, {
    attachTo: document.body,
    props: { sections, values, revision },
    global: {
      stubs: { AdminLayout: { template: "<section><slot /></section>" } },
    },
  });
  wrappers.push(wrapper);
  return wrapper;
}

beforeEach(() => {
  inertia.posts = [];
  inertia.page.props.locale = "en";
  inertia.page.props.errors = {};
});

afterEach(() => {
  wrappers.forEach((wrapper) => wrapper.unmount());
  wrappers = [];
  document.body.innerHTML = "";
  vi.restoreAllMocks();
});

describe("admin content editor", () => {
  it("posts the revision and only the key whose value changed", async () => {
    const wrapper = mountEditor({
      "hero.heading": { id: "Lama", en: "Old" },
      "hero.sub": { id: "Sub", en: "Sub title" },
    });

    await wrapper.get("#content-hero-heading-en").setValue("Fresh headline");
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toHaveLength(1);
    expect(inertia.posts[0].url).toBe("/admin/content");
    expect(inertia.posts[0].data).toEqual({
      revision: 3,
      values: {
        "hero.heading": { id: "Lama", en: "Fresh headline" },
      },
    });
  });

  it("does not post when nothing changed", async () => {
    const wrapper = mountEditor({
      "hero.heading": { id: "Lama", en: "Old" },
      "hero.sub": { id: "Sub", en: "Sub title" },
    });

    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toHaveLength(0);
  });

  it("keeps the admin's typing when a save comes back with errors", async () => {
    const wrapper = mountEditor({
      "hero.heading": { id: "Lama", en: "Old" },
      "hero.sub": { id: "Sub", en: "Sub title" },
    });

    await wrapper.get("#content-hero-heading-en").setValue("My unsaved edit");
    inertia.page.props.errors = { revision: "Someone else saved first." };
    await wrapper.setProps({
      values: {
        "hero.heading": { id: "Lama", en: "Their newer text" },
        "hero.sub": { id: "Sub", en: "Sub title" },
      },
      revision: 4,
    });

    expect(
      (wrapper.get("#content-hero-heading-en").element as HTMLInputElement)
        .value,
    ).toBe("My unsaved edit");
  });

  it("shows a stale-revision error raised by the reset request", async () => {
    inertia.page.props.errors = { revision: "Someone else saved first." };
    const wrapper = mountEditor({});

    expect(wrapper.text()).toContain("Someone else saved first.");
  });

  it("disables saving until a field changes", async () => {
    const wrapper = mountEditor({
      "hero.heading": { id: "Lama", en: "Old" },
      "hero.sub": { id: "Sub", en: "Sub title" },
    });
    const save = () =>
      wrapper.get("[data-content-save]").element as HTMLButtonElement;

    expect(save().disabled).toBe(true);
    await wrapper.get("#content-hero-heading-en").setValue("Changed");
    expect(save().disabled).toBe(false);
  });

  it("posts the current revision when resetting", async () => {
    const wrapper = mountEditor({}, 9);

    await wrapper.get("[data-content-reset]").trigger("click");
    await nextTick();
    document
      .querySelector<HTMLButtonElement>("[data-content-reset-confirm]")!
      .click();
    await nextTick();

    expect(inertia.posts).toEqual([
      {
        url: "/admin/content/reset",
        data: { revision: 9 },
        options: { preserveScroll: true },
      },
    ]);
  });
});
