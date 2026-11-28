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

  it("stops at the last bookable day, 365 days ahead", async () => {
    const wrapper = render({ date: "2027-11-01" });
    expect(wrapper.get("#f-date").attributes("max")).toBe("2027-11-12");
    expect(wrapper.text()).toContain("November 2027");
    expect(day(wrapper, "2027-11-12").attributes("disabled")).toBeUndefined();
    expect(day(wrapper, "2027-11-13").attributes("disabled")).toBeDefined();
    expect(
      wrapper.get("[data-picker-next]").attributes("disabled"),
    ).toBeDefined();
    await day(wrapper, "2027-11-13").trigger("click");
    expect(wrapper.emitted("update:date")).toBeUndefined();
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
  // Staff review 2026-11-26: a nearly full time looked like any other, and full times vanished.
  it("labels the last room and greys out a full time", async () => {
    const wrapper = render({
      times: ["15:00", "15:30", "16:00"],
      timeStates: { "15:00": "full", "15:30": "last" },
    });
    const label = (time: string) =>
      wrapper.get(`input[value="${time}"]`).element.closest("label")!;
    expect(
      wrapper.get('input[value="15:00"]').attributes("disabled"),
    ).toBeDefined();
    expect(label("15:00").textContent).toContain("Full");
    expect(label("15:30").textContent).toContain("1 left");
    expect(
      wrapper.get('input[value="15:30"]').attributes("disabled"),
    ).toBeUndefined();
    expect(label("16:00").textContent!.trim()).toBe("16:00");
    await wrapper.get('input[value="15:00"]').trigger("change");
    expect(wrapper.emitted("update:time")).toBeUndefined();

    state.page.props.locale = "id";
    await nextTick();
    expect(label("15:00").textContent).toContain("Penuh");
    expect(label("15:30").textContent).toContain("Sisa 1");
  });

  it("draws plain times without states", () => {
    const wrapper = render();
    expect(wrapper.findAll("#f-time input[disabled]")).toHaveLength(0);
    expect(wrapper.find(".dtp__state").exists()).toBe(false);
  });

  it("marks full in-month days with a legend, keeps them clickable, and emits month changes", async () => {
    const wrapper = render({
      fullDates: ["2026-11-11", "2026-11-20", "2026-12-01"],
    });
    // Past day (Nov 11) and out-of-month day (Dec 1) are not marked full in November's view.
    expect(day(wrapper, "2026-11-11").classes()).not.toContain(
      "dtp__day--full",
    );
    expect(day(wrapper, "2026-12-01").classes()).not.toContain(
      "dtp__day--full",
    );

    const fullDay = day(wrapper, "2026-11-20");
    expect(fullDay.classes()).toContain("dtp__day--full");
    expect(fullDay.attributes("disabled")).toBeUndefined();
    expect(fullDay.attributes("aria-label")).toBe(
      "Friday, 20 November 2026, Full",
    );
    expect(wrapper.get("[data-picker-full-legend]").text()).toContain("Full");

    // Still clickable so guests can inspect the full times or refresh.
    await fullDay.trigger("click");
    expect(wrapper.emitted("update:date")?.at(-1)).toEqual(["2026-11-20"]);

    state.page.props.locale = "id";
    await nextTick();
    expect(fullDay.attributes("aria-label")).toBe(
      "Jumat, 20 November 2026, Penuh",
    );
    expect(wrapper.get("[data-picker-full-legend]").text()).toContain("Penuh");

    // Switching month emits update:month and hides the legend if no full day is visible there.
    await wrapper.get("[data-picker-next]").trigger("click");
    expect(wrapper.emitted("update:month")?.at(-1)).toEqual(["2026-12"]);
    expect(day(wrapper, "2026-12-01").classes()).toContain("dtp__day--full");
    await wrapper.setProps({ fullDates: [] });
    expect(wrapper.find("[data-picker-full-legend]").exists()).toBe(false);
    wrapper.unmount();
  });
});
