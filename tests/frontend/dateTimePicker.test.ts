import { mount } from "@vue/test-utils";
import { nextTick, reactive } from "vue";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import DateTimePicker from "@/components/booking/DateTimePicker.vue";
import type { Locale } from "@/types";

const state = vi.hoisted(() => ({
  page: { props: { locale: "en" as Locale } },
}));
vi.mock("@inertiajs/vue3", () => ({
  usePage: () => state.page,
  router: { post: vi.fn() },
}));

// Today in Jakarta is Thursday 12 November 2026.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-11-12T03:00:00Z"));
  state.page = reactive({ props: { locale: "en" as Locale } });
});
afterEach(() => vi.useRealTimers());

const render = (props: Record<string, unknown> = {}) =>
  mount(DateTimePicker, {
    attachTo: document.body,
    props: {
      date: "",
      time: "",
      times: ["09:00", "09:30", "10:00"],
      dateId: "f-date",
      timeId: "f-time",
      dateLabel: "Date",
      timeLabel: "Time",
      ...props,
    },
  });
const day = (wrapper: ReturnType<typeof render>, iso: string) =>
  wrapper.get(`[data-date="${iso}"]`);

describe("date and time picker", () => {
  it("shows today's month Monday first, marks today and closes the past", () => {
    const wrapper = render();
    expect(wrapper.text()).toContain("November 2026");
    expect(wrapper.findAll("th").map((th) => th.text())).toEqual([
      "Mon",
      "Tue",
      "Wed",
      "Thu",
      "Fri",
      "Sat",
      "Sun",
    ]);
    // Six weeks starting Monday 26 October keep the height steady between months.
    expect(wrapper.findAll("[data-date]")).toHaveLength(42);
    expect(wrapper.find("[data-date]").attributes("data-date")).toBe(
      "2026-10-26",
    );
    expect(day(wrapper, "2026-11-12").attributes("aria-current")).toBe("date");
    expect(day(wrapper, "2026-11-11").attributes("disabled")).toBeDefined();
    expect(day(wrapper, "2026-11-12").attributes("disabled")).toBeUndefined();
    expect(
      wrapper.get("[data-picker-previous]").attributes("disabled"),
    ).toBeDefined();
    expect(day(wrapper, "2026-11-12").attributes("aria-label")).toBe(
      "Thursday, 12 November 2026",
    );
    wrapper.unmount();
  });

  it("picks a day by click, by the Today button and by typing, and follows the chosen month", async () => {
    const wrapper = render();
    await day(wrapper, "2026-11-20").trigger("click");
    expect(wrapper.emitted("update:date")?.at(-1)).toEqual(["2026-11-20"]);
    await wrapper.get("[data-picker-next]").trigger("click");
    expect(wrapper.text()).toContain("December 2026");
    await wrapper.get("[data-picker-today]").trigger("click");
    expect(wrapper.emitted("update:date")?.at(-1)).toEqual(["2026-11-12"]);
    await wrapper.setProps({ date: "2027-02-03" });
    expect(wrapper.text()).toContain("February 2027");
    expect(
      day(wrapper, "2027-02-03")
        .element.closest("td")!
        .getAttribute("aria-selected"),
    ).toBe("true");
    await wrapper.get("#f-date").setValue("2027-03-15");
    expect(wrapper.emitted("update:date")?.at(-1)).toEqual(["2027-03-15"]);
    wrapper.unmount();
  });

  it("moves through days with the keyboard without passing today", async () => {
    const wrapper = render({ date: "2026-11-30" });
    const focused = () => document.activeElement?.getAttribute("data-date");
    expect(day(wrapper, "2026-11-30").attributes("tabindex")).toBe("0");
    expect(day(wrapper, "2026-11-29").attributes("tabindex")).toBe("-1");
    (day(wrapper, "2026-11-30").element as HTMLElement).focus();
    const press = async (key: string) => {
      await wrapper.get("table").trigger("keydown", { key });
      await nextTick();
    };
    await press("ArrowRight");
    expect(focused()).toBe("2026-12-01");
    expect(wrapper.text()).toContain("December 2026");
    await press("ArrowUp");
    expect(focused()).toBe("2026-11-24");
    await press("Home");
    expect(focused()).toBe("2026-11-23");
    await press("End");
    expect(focused()).toBe("2026-11-29");
    await press("PageDown");
    expect(focused()).toBe("2026-12-29");
    await press("PageUp");
    await press("PageUp");
    // Going back past today stops on today.
    expect(focused()).toBe("2026-11-12");
    await press("ArrowLeft");
    expect(focused()).toBe("2026-11-12");
    // Moving focus does not choose a day; Enter on the button does (native click).
    expect(wrapper.emitted("update:date")).toBeUndefined();
    wrapper.unmount();
  });

  it("lists times as radio buttons and says why the list is empty", async () => {
    const wrapper = render({ date: "2026-11-20", time: "09:30" });
    const group = wrapper.get("#f-time");
    expect(group.attributes("role")).toBe("radiogroup");
    expect(group.attributes("tabindex")).toBe("-1");
    const radios = group.findAll<HTMLInputElement>('input[type="radio"]');
    expect(radios.map((radio) => radio.element.value)).toEqual([
      "09:00",
      "09:30",
      "10:00",
    ]);
    expect(radios[1].element.checked).toBe(true);
    await radios[2].setValue(true);
    expect(wrapper.emitted("update:time")?.at(-1)).toEqual(["10:00"]);
    expect(wrapper.text()).toContain("Friday, 20 November 2026, 09:30");
    await wrapper.setProps({ times: [] });
    expect(wrapper.text()).toContain("No times are free on this day.");
    await wrapper.setProps({ date: "" });
    expect(wrapper.text()).toContain("Choose a day to see its times.");
    wrapper.unmount();
  });

  it("carries the page's ids, errors and descriptions on the real controls", () => {
    const wrapper = render({
      dateInvalid: true,
      timeInvalid: true,
      dateDescribedBy: "f-date-error",
      timeDescribedBy: "f-time-error room-note",
    });
    const input = wrapper.get("#f-date");
    expect(input.attributes("type")).toBe("date");
    expect(input.attributes("min")).toBe("2026-11-12");
    expect(input.attributes("aria-invalid")).toBe("true");
    expect(input.attributes("aria-describedby")).toBe("f-date-error");
    expect(wrapper.get(`label[for="f-date"]`).text()).toBe("Date");
    const group = wrapper.get("#f-time");
    expect(group.attributes("aria-invalid")).toBe("true");
    expect(group.attributes("aria-describedby")).toBe("f-time-error room-note");
    expect(wrapper.get(`#${group.attributes("aria-labelledby")}`).text()).toBe(
      "Time",
    );
    wrapper.unmount();
  });

  it("lists times before a day is picked and keeps an old draft's past day, with the page's own reason first", async () => {
    const wrapper = render();
    expect(wrapper.findAll('input[type="radio"]')).toHaveLength(3);
    await wrapper.setProps({ date: "2026-11-01", time: "09:00" });
    expect(wrapper.text()).toContain("November 2026");
    expect(wrapper.get<HTMLInputElement>("input:checked").element.value).toBe(
      "09:00",
    );
    expect(day(wrapper, "2026-11-01").attributes("disabled")).toBeDefined();
    await wrapper.setProps({
      times: [],
      emptyText: "Choose a treatment first.",
    });
    expect(wrapper.text()).toContain("Choose a treatment first.");
    expect(wrapper.text()).not.toContain("No times are free");
    wrapper.unmount();
  });

  it("speaks Indonesian", () => {
    state.page.props.locale = "id";
    const wrapper = render({ date: "2026-11-20", time: "09:00" });
    expect(wrapper.text()).toContain("November 2026");
    expect(wrapper.findAll("th")[0].text()).toBe("Sen");
    expect(wrapper.get("[data-picker-today]").text()).toBe("Hari ini");
    expect(day(wrapper, "2026-11-20").attributes("aria-label")).toBe(
      "Jumat, 20 November 2026",
    );
    wrapper.unmount();
  });
});
