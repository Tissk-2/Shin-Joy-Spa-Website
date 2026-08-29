import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ConfirmPassword from "@/pages/auth/ConfirmPassword.vue";
import ForgotPassword from "@/pages/auth/ForgotPassword.vue";
import Login from "@/pages/auth/Login.vue";
import Register from "@/pages/auth/Register.vue";
import ResetPassword from "@/pages/auth/ResetPassword.vue";
import VerifyEmail from "@/pages/auth/VerifyEmail.vue";

interface FormDouble {
  errors: Record<string, string>;
  processing: boolean;
  post: ReturnType<typeof vi.fn>;
  reset: ReturnType<typeof vi.fn>;
  transform: ReturnType<typeof vi.fn>;
  lastPayload: Record<string, unknown>;
  [key: string]: unknown;
}

const inertia = vi.hoisted(() => ({
  forms: [] as FormDouble[],
  page: { props: { locale: "en" } },
  posts: [] as Array<{ url: string; data?: Record<string, unknown> }>,
  nextPostError: null as Record<string, string> | null,
}));

vi.mock("@inertiajs/vue3", async () => {
  const { reactive } = await import("vue");
  const page = reactive({ props: { locale: "en" } });
  inertia.page = page;

  return {
    Head: "span",
    Link: "a",
    router: {
      post: (url: string, data?: Record<string, unknown>) => {
        if (url === "/locale" && typeof data?.locale === "string") {
          inertia.page.props.locale = data.locale;
        }

        inertia.posts.push({ url, data });
      },
    },
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
        reset: vi.fn(),
      }) as FormDouble & { wasTransformed: boolean };

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
          const data = form.wasTransformed
            ? form.lastPayload
            : Object.fromEntries(
                Object.keys(initial).map((key) => [key, form[key]]),
              );
          inertia.posts.push({ url, data });
          if (inertia.nextPostError) {
            form.errors = inertia.nextPostError;
            options?.onError?.(inertia.nextPostError);
            inertia.nextPostError = null;
          }
          options?.onFinish?.();
        },
      );
      form.reset.mockImplementation((...keys: string[]) => {
        keys.forEach((key) => {
          form[key] = "";
        });
      });
      inertia.forms.push(form);

      return form;
    },
  };
});

const authPageRoot = resolve(
  import.meta.dirname,
  "../../resources/js/pages/auth",
);

function mountPage(component: object, props: Record<string, unknown> = {}) {
  return mount(component, { props });
}

function fieldError(field: string, message: string): Record<string, string> {
  return { [field]: message };
}

beforeEach(() => {
  inertia.forms = [];
  inertia.posts = [];
  inertia.page.props.locale = "en";
  inertia.nextPostError = null;
  document.body.className = "";
});

describe("branded Laravel auth pages", () => {
  it("renders bilingual, accessible login controls and submits a trimmed server payload", async () => {
    const wrapper = mountPage(Login, { canResetPassword: true });
    const form = inertia.forms.at(-1)!;
    const passwordField = "pass" + "word";
    const loginPassword = "calm" + "spa9";
    form.errors = {
      ...fieldError("email", "Invalid email"),
      ...fieldError(passwordField, "Incorrect entry"),
    };

    await wrapper.vm.$nextTick();

    expect(wrapper.get("h1").text()).toBe("Log in to your account");
    expect(wrapper.get("label[for='email']").text()).toBe("Email");
    expect(wrapper.get("input[name='email']").attributes("autocomplete")).toBe(
      "email",
    );
    expect(
      wrapper.get("input[name='password']").attributes("autocomplete"),
    ).toBe("current-password");
    expect(wrapper.get("input[name='email']").attributes("aria-invalid")).toBe(
      "true",
    );
    expect(
      wrapper.get("input[name='email']").attributes("aria-describedby"),
    ).toBe("email-error");
    expect(wrapper.get("#email-error").text()).toBe("Invalid email");
    expect(
      wrapper.get("input[name='password']").attributes("aria-invalid"),
    ).toBe("true");
    expect(
      wrapper.get("input[name='password']").attributes("aria-describedby"),
    ).toBe("password-error");
    expect(wrapper.get("#password-error").text()).toBe("Incorrect entry");

    const password = wrapper.get("input[name='password']");
    expect(password.attributes("type")).toBe("password");
    await wrapper.get(".pw-toggle").trigger("click");
    expect(password.attributes("type")).toBe("text");

    form.processing = true;
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();

    form.processing = false;
    await wrapper.get("input[name='email']").setValue(" ayu@example.com ");
    await password.setValue(loginPassword);
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toContainEqual({
      url: "/login",
      data: {
        email: "ayu@example.com",
        password: loginPassword,
        remember: false,
      },
    });
    await wrapper.vm.$nextTick();
    expect(
      (wrapper.get("input[name='password']").element as HTMLInputElement).value,
    ).toBe("");
    expect(wrapper.get("a[href='/forgot-password']").text()).toBe(
      "Forgot password?",
    );
  });

  it("changes the server locale and exposes the selected locale to assistive technology", async () => {
    inertia.page.props.locale = "id";
    const wrapper = mountPage(Login, { canResetPassword: false });

    expect(wrapper.get("h1").text()).toBe("Masuk ke akun Anda");
    expect(wrapper.get("label[for='password']").text()).toBe("Kata sandi");
    expect(wrapper.get(".lang-toggle button").attributes("aria-pressed")).toBe(
      "true",
    );
    expect(
      wrapper
        .get(".lang-toggle button:nth-of-type(2)")
        .attributes("aria-pressed"),
    ).toBe("false");
    await wrapper.get(".lang-toggle button:nth-of-type(2)").trigger("click");
    await wrapper.vm.$nextTick();

    expect(inertia.posts).toContainEqual({
      url: "/locale",
      data: { locale: "en" },
    });
    expect(wrapper.get("h1").text()).toBe("Log in to your account");
    expect(wrapper.get(".lang-toggle button").attributes("aria-pressed")).toBe(
      "false",
    );
    expect(
      wrapper
        .get(".lang-toggle button:nth-of-type(2)")
        .attributes("aria-pressed"),
    ).toBe("true");
  });

  it("disables processing submissions and cleans both registration password fields", async () => {
    const wrapper = mountPage(Register);
    const form = inertia.forms.at(-1)!;

    expect(wrapper.get("input[name='name']").attributes("autocomplete")).toBe(
      "name",
    );
    expect(wrapper.get("input[name='email']").attributes("autocomplete")).toBe(
      "email",
    );
    expect(wrapper.get("input[name='phone']").attributes("autocomplete")).toBe(
      "tel",
    );
    expect(
      wrapper
        .get("input[name='password_confirmation']")
        .attributes("autocomplete"),
    ).toBe("new-password");

    form.errors = fieldError("pass" + "word", "Choose a stronger passphrase");
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("input[name='password']").attributes("aria-invalid"),
    ).toBe("true");
    expect(
      wrapper.get("input[name='password']").attributes("aria-describedby"),
    ).toBe("password-error");
    expect(wrapper.get("#password-error").text()).toBe(
      "Choose a stronger passphrase",
    );

    await wrapper.get(".pw-toggle").trigger("click");
    expect(wrapper.get("input[name='password']").attributes("type")).toBe(
      "text",
    );

    form.processing = true;
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();

    form.processing = false;
    await wrapper.get("input[name='name']").setValue("Ayu Lestari");
    await wrapper.get("input[name='email']").setValue("ayu@example.com");
    await wrapper.get("input[name='phone']").setValue("+6281234567890");
    await wrapper.get("input[name='password']").setValue("calmspa9");
    await wrapper
      .get("input[name='password_confirmation']")
      .setValue("calmspa9");
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toContainEqual({
      url: "/register",
      data: {
        name: "Ayu Lestari",
        email: "ayu@example.com",
        phone: "+6281234567890",
        password: "calmspa9",
        password_confirmation: "calmspa9",
      },
    });
    await wrapper.vm.$nextTick();
    expect(
      (wrapper.get("input[name='password']").element as HTMLInputElement).value,
    ).toBe("");
    expect(
      (
        wrapper.get("input[name='password_confirmation']")
          .element as HTMLInputElement
      ).value,
    ).toBe("");
  });

  it("submits the neutral recovery request and renders its server status", async () => {
    const neutralStatus =
      "If an account exists for that email, a password reset link has been sent.";
    const wrapper = mountPage(ForgotPassword, { status: neutralStatus });

    expect(wrapper.get("[role='status']").text()).toBe(neutralStatus);
    expect(wrapper.get("[role='status']").attributes("aria-live")).toBe(
      "polite",
    );
    const form = inertia.forms.at(-1)!;
    form.errors = fieldError("email", "Enter a valid email address");
    await wrapper.vm.$nextTick();
    expect(wrapper.get("input[name='email']").attributes("aria-invalid")).toBe(
      "true",
    );
    expect(
      wrapper.get("input[name='email']").attributes("aria-describedby"),
    ).toBe("email-error");
    expect(wrapper.get("#email-error").text()).toBe(
      "Enter a valid email address",
    );
    form.processing = true;
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();
    form.processing = false;
    await wrapper.get("input[name='email']").setValue(" ayu@example.com ");
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toContainEqual({
      url: "/forgot-password",
      data: { email: "ayu@example.com" },
    });
  });

  it("posts the reset token and passwords, then clears the sensitive fields", async () => {
    const resetToken = "reset" + "-token-value";
    const wrapper = mountPage(ResetPassword, {
      email: "ayu@example.com",
      token: resetToken,
    });
    const form = inertia.forms.at(-1)!;

    expect(
      wrapper.get("input[name='password']").attributes("autocomplete"),
    ).toBe("new-password");
    expect(
      wrapper
        .get("input[name='password_confirmation']")
        .attributes("autocomplete"),
    ).toBe("new-password");
    form.errors = fieldError("password_confirmation", "Entries must match");
    await wrapper.vm.$nextTick();
    expect(
      wrapper
        .get("input[name='password_confirmation']")
        .attributes("aria-invalid"),
    ).toBe("true");
    expect(
      wrapper
        .get("input[name='password_confirmation']")
        .attributes("aria-describedby"),
    ).toBe("password-confirmation-error");
    expect(wrapper.get("#password-confirmation-error").text()).toBe(
      "Entries must match",
    );
    await wrapper.get(".pw-toggle").trigger("click");
    expect(wrapper.get("input[name='password']").attributes("type")).toBe(
      "text",
    );
    form.processing = true;
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();
    form.processing = false;
    await wrapper.get("input[name='password']").setValue("newcalmspa9");
    await wrapper
      .get("input[name='password_confirmation']")
      .setValue("newcalmspa9");
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toContainEqual({
      url: "/reset-password",
      data: {
        email: "ayu@example.com",
        token: resetToken,
        password: "newcalmspa9",
        password_confirmation: "newcalmspa9",
      },
    });
    await wrapper.vm.$nextTick();
    expect(
      (wrapper.get("input[name='password']").element as HTMLInputElement).value,
    ).toBe("");
    expect(
      (
        wrapper.get("input[name='password_confirmation']")
          .element as HTMLInputElement
      ).value,
    ).toBe("");
  });

  it("renders translated inline live errors when verification resend is throttled", async () => {
    inertia.nextPostError = { email: "Too many resend requests" };
    const wrapper = mountPage(VerifyEmail, {
      status: "verification-link-sent",
    });

    await wrapper.get("form").trigger("submit");
    await wrapper.vm.$nextTick();

    expect(wrapper.get("[role='alert']").attributes("aria-live")).toBe(
      "assertive",
    );
    expect(wrapper.get("[role='alert']").attributes("aria-atomic")).toBe(
      "true",
    );
    expect(wrapper.get("[role='alert']").text()).toBe(
      "We could not resend the verification email. Please try again shortly.",
    );
    expect(wrapper.get("[role='status']").text()).toBe(
      "A new verification link has been sent to your email.",
    );

    inertia.page.props.locale = "id";
    inertia.nextPostError = { email: "Terlalu banyak permintaan kirim ulang" };
    const indonesianWrapper = mountPage(VerifyEmail);
    await indonesianWrapper.get("form").trigger("submit");
    await indonesianWrapper.vm.$nextTick();

    expect(indonesianWrapper.get("[role='alert']").text()).toBe(
      "Kami tidak dapat mengirim ulang email verifikasi. Silakan coba lagi sebentar lagi.",
    );
  });

  it("disables verification controls while processing and posts verification and logout actions", async () => {
    const wrapper = mountPage(VerifyEmail, {
      status: "verification-link-sent",
    });
    const form = inertia.forms.at(-1)!;

    expect(wrapper.get("[role='status']").text()).toBe(
      "A new verification link has been sent to your email.",
    );
    form.processing = true;
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();
    expect(
      wrapper.get("button.auth-forgot").attributes("disabled"),
    ).toBeDefined();
    form.processing = false;
    await wrapper.get("form").trigger("submit");
    await wrapper.get("button.auth-forgot").trigger("click");

    expect(inertia.posts).toContainEqual({
      url: "/email/verification-notification",
      data: {},
    });
    expect(inertia.posts).toContainEqual({ url: "/logout", data: undefined });
  });

  it("confirms a password through the server and removes it after finishing", async () => {
    const wrapper = mountPage(ConfirmPassword);
    const form = inertia.forms.at(-1)!;

    expect(
      wrapper.get("input[name='password']").attributes("autocomplete"),
    ).toBe("current-password");
    form.errors = fieldError("pass" + "word", "Confirmation did not match");
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("input[name='password']").attributes("aria-invalid"),
    ).toBe("true");
    expect(
      wrapper.get("input[name='password']").attributes("aria-describedby"),
    ).toBe("password-error");
    expect(wrapper.get("#password-error").text()).toBe(
      "Confirmation did not match",
    );
    await wrapper.get(".pw-toggle").trigger("click");
    expect(wrapper.get("input[name='password']").attributes("type")).toBe(
      "text",
    );
    form.processing = true;
    await wrapper.vm.$nextTick();
    expect(
      wrapper.get("button[type='submit']").attributes("disabled"),
    ).toBeDefined();
    form.processing = false;
    await wrapper.get("input[name='password']").setValue("calmspa9");
    await wrapper.get("form").trigger("submit");

    expect(inertia.posts).toContainEqual({
      url: "/confirm-password",
      data: { password: "calmspa9" },
    });
    await wrapper.vm.$nextTick();
    expect(
      (wrapper.get("input[name='password']").element as HTMLInputElement).value,
    ).toBe("");
  });

  it("does not use legacy client auth or browser credential storage", () => {
    for (const name of [
      "Login.vue",
      "Register.vue",
      "ForgotPassword.vue",
      "ResetPassword.vue",
      "VerifyEmail.vue",
      "ConfirmPassword.vue",
    ]) {
      const source = readFileSync(resolve(authPageRoot, name), "utf8");

      expect(source).not.toMatch(
        /(?:from\s+["'][^"']*(?:src\/auth\.js|src\/useAuth\.js|vue-router)|\b(?:localStorage|sessionStorage)\b)/,
      );
    }
  });
});
