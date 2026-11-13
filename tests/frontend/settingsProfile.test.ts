import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import Profile from "@/pages/settings/Profile.vue";

const inertia = vi.hoisted(() => ({
  patches: [] as Array<{ url: string; data: Record<string, unknown> }>,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive({
    props: {
      auth: {
        user: {
          name: "Ayu",
          email: "ayu@example.com",
          email_verified_at: "2026-11-01T00:00:00Z",
        },
      },
    },
  });

  return {
    Head: "span",
    Link: "a",
    usePage: () => page,
    // Models Inertia 3.7's useForm: a successful submit copies the submitted
    // data into the defaults (unless onSuccess set them), then runs onFinish;
    // reset() restores fields from the defaults.
    useForm: (initial: Record<string, unknown>) => {
      let defaults = { ...initial };
      const data = () =>
        Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]));
      const form: Record<string, unknown> = reactive({
        ...initial,
        errors: {},
        processing: false,
        recentlySuccessful: false,
        patch: (
          url: string,
          options?: { onSuccess?: () => void; onFinish?: () => void },
        ) => {
          inertia.patches.push({ url, data: data() });
          options?.onSuccess?.();
          defaults = data();
          options?.onFinish?.();
        },
        defaults: (field: string, value: unknown) => {
          defaults = { ...defaults, [field]: value };
          return form;
        },
        reset: (...keys: string[]) => {
          keys.forEach((key) => {
            form[key] = defaults[key];
          });
        },
      });

      return form;
    },
  };
});

vi.stubGlobal("route", (name: string) => `/${name}`);

const stubs = {
  AppLayout: { template: "<div><slot /></div>" },
  SettingsLayout: { template: "<div><slot /></div>" },
  DeleteUser: true,
  TransitionRoot: true,
};

describe("profile settings", () => {
  it("asks for the current password only while the email changes, sends it, then clears it", async () => {
    const wrapper = mount(Profile, {
      props: { mustVerifyEmail: true },
      global: { stubs },
    });
    expect(wrapper.find("#current_password").exists()).toBe(false);

    await wrapper.get("#email").setValue("moved@example.com");
    const password = wrapper.get("#current_password");
    expect(password.attributes("type")).toBe("password");
    expect(password.attributes("autocomplete")).toBe("current-password");
    await password.setValue("pass-1234");
    await wrapper.get("form").trigger("submit");

    expect(inertia.patches).toEqual([
      {
        url: "/profile.update",
        data: {
          name: "Ayu",
          email: "moved@example.com",
          current_password: "pass-1234",
        },
      },
    ]);
    // Cleared after a successful save, and still empty when the email is
    // edited again, so a later change asks for the password anew.
    expect(
      (wrapper.get("#current_password").element as HTMLInputElement).value,
    ).toBe("");
    await wrapper.get("#email").setValue("moved-again@example.com");
    expect(
      (wrapper.get("#current_password").element as HTMLInputElement).value,
    ).toBe("");

    await wrapper.get("#email").setValue("ayu@example.com");
    expect(wrapper.find("#current_password").exists()).toBe(false);
  });
});
