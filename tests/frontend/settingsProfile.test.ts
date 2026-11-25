import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";

import DeleteUser from "@/components/DeleteUser.vue";
import Profile from "@/pages/Profile.vue";

const inertia = vi.hoisted(() => ({
  requests: [] as Array<{
    method: string;
    url: string;
    data: Record<string, unknown>;
  }>,
  options: [] as Array<Record<string, unknown> | undefined>,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive({
    url: "/profile",
    props: {
      locale: "en",
      auth: {
        user: {
          name: "Ayu",
          email: "ayu@example.com",
          phone: "+62 812-3456-7890",
          email_verified_at: "2026-11-01T00:00:00Z",
        },
      },
    },
  });

  return {
    Head: "span",
    Link: "a",
    router: { post: vi.fn() },
    usePage: () => page,
    // Models Inertia 3.7's useForm: a successful submit copies the submitted
    // data into the defaults (unless onSuccess set them), then runs onFinish;
    // reset() restores fields from the defaults.
    useForm: (initial: Record<string, unknown>) => {
      let defaults = { ...initial };
      const data = () =>
        Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]));
      const submit =
        (method: string) =>
        (
          url: string,
          options?: { onSuccess?: () => void; onFinish?: () => void },
        ) => {
          inertia.requests.push({ method, url, data: data() });
          inertia.options.push(options);
          options?.onSuccess?.();
          defaults = data();
          options?.onFinish?.();
        };
      const form: Record<string, unknown> = reactive({
        ...initial,
        errors: {},
        processing: false,
        recentlySuccessful: false,
        patch: submit("patch"),
        put: submit("put"),
        delete: submit("delete"),
        clearErrors: () => form,
        defaults: (field: string, value: unknown) => {
          defaults = { ...defaults, [field]: value };
          return form;
        },
        reset: (...keys: string[]) => {
          (keys.length ? keys : Object.keys(initial)).forEach((key) => {
            form[key] = keys.length ? defaults[key] : initial[key];
          });
        },
      });

      return form;
    },
  };
});

const stubs = {
  MainLayout: { template: "<main><slot /></main>" },
  DeleteUser: true,
};

describe("account page", () => {
  it("is one page in the site layout: details, password, bookings and deletion", () => {
    const wrapper = mount(Profile, { global: { stubs } });

    expect(wrapper.get("h1").text()).toBe("My Account");
    expect(wrapper.findAll("h2").map((heading) => heading.text())).toEqual([
      "Account details",
      "Password",
      "Booking history",
    ]);
    expect(wrapper.get('a[href="/bookings"]').text()).toBe("View My Bookings");
    expect((wrapper.get("#phone").element as HTMLInputElement).value).toBe(
      "+62 812-3456-7890",
    );
    expect(wrapper.findComponent({ name: "DeleteUser" }).exists()).toBe(true);
  });

  it("asks for the current password only while the email changes, sends it, then clears it", async () => {
    inertia.requests = [];
    const wrapper = mount(Profile, { global: { stubs } });
    expect(wrapper.find("#current_password").exists()).toBe(false);

    await wrapper.get("#email").setValue("moved@example.com");
    const password = wrapper.get("#current_password");
    expect(password.attributes("type")).toBe("password");
    expect(password.attributes("autocomplete")).toBe("current-password");
    await password.setValue("pass-1234");
    await wrapper.get("#email").element.form!.requestSubmit();

    expect(inertia.requests).toEqual([
      {
        method: "patch",
        url: "/settings/profile",
        data: {
          name: "Ayu",
          email: "moved@example.com",
          phone: "+62 812-3456-7890",
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

  // Flow review F10: the WhatsApp number could only be set at sign-up.
  it("saves a new WhatsApp number without asking for the password", async () => {
    inertia.requests = [];
    const wrapper = mount(Profile, { global: { stubs } });
    const phone = wrapper.get("#phone");
    expect(phone.attributes("type")).toBe("tel");
    expect(phone.attributes("autocomplete")).toBe("tel");

    await phone.setValue("+62 811 2222 3333");
    expect(wrapper.find("#current_password").exists()).toBe(false);
    await wrapper.get("#phone").element.form!.requestSubmit();

    expect(inertia.requests).toEqual([
      {
        method: "patch",
        url: "/settings/profile",
        data: {
          name: "Ayu",
          email: "ayu@example.com",
          phone: "+62 811 2222 3333",
          current_password: "",
        },
      },
    ]);
  });

  it("changes the password from the same page and clears the fields", async () => {
    inertia.requests = [];
    const wrapper = mount(Profile, { global: { stubs } });

    await wrapper.get("#password_current").setValue("old-pass-1");
    await wrapper.get("#password_new").setValue("new-pass-22");
    await wrapper.get("#password_confirmation").setValue("new-pass-22");
    await wrapper.get("#password_new").element.form!.requestSubmit();

    expect(inertia.requests).toEqual([
      {
        method: "put",
        url: "/settings/password",
        data: {
          current_password: "old-pass-1",
          password: "new-pass-22",
          password_confirmation: "new-pass-22",
        },
      },
    ]);
    expect(
      (wrapper.get("#password_new").element as HTMLInputElement).value,
    ).toBe("");
  });

  it("confirms account deletion with one submit button, not a button inside a button", async () => {
    const wrapper = mount(DeleteUser, { attachTo: document.body });
    await wrapper.get("button").trigger("click");
    await flushPromises();

    const dialog = document.body.querySelector('[role="alertdialog"]');
    expect(dialog).not.toBeNull();
    const submit = dialog!.querySelector<HTMLButtonElement>(
      'form button[type="submit"]',
    );
    expect(submit?.textContent?.trim()).toBe("Delete account");
    expect(document.body.querySelectorAll("button button")).toHaveLength(0);

    // Flow review F11: after deleting, the home page opens at the top, where the notice is read;
    // only a wrong password keeps the scroll position.
    inertia.options = [];
    submit!.click();
    await flushPromises();
    expect(inertia.options.at(-1)?.preserveScroll).toBe("errors");
    wrapper.unmount();
  });
});
