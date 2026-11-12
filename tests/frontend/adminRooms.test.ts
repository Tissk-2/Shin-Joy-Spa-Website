import { mount } from "@vue/test-utils";
import { reactive, nextTick } from "vue";
import { beforeEach, describe, expect, it, vi } from "vitest";
import Index from "@/pages/admin/rooms/Index.vue";
import type { AdminRoomInventory, Locale } from "@/types";

const state = vi.hoisted(() => ({
  page: { props: { locale: "en" as Locale } },
}));
const usePoll = vi.hoisted(() => vi.fn());
vi.mock("@inertiajs/vue3", () => ({
  usePage: () => state.page,
  usePoll,
  router: { post: vi.fn() },
}));
vi.mock("@/layouts/admin/AdminLayout.vue", () => ({
  default: {
    props: ["title", "subtitle"],
    template: "<main><h1>{{ title }}</h1><slot /></main>",
  },
}));

const inventory = (): AdminRoomInventory => ({
  state: "ready",
  generatedAt: "2026-10-24T03:00:00Z",
  blocksUntil: "2026-11-23T03:00:00Z",
  schedulingEnabled: false,
  rooms: [
    {
      id: 1,
      version: 1,
      code: "AAA",
      name: "Real <script>room</script>",
      type: null,
      capacity: 2,
      cleaningMinutes: 15,
      isActive: true,
      currentBlockCount: 1,
      upcomingBlockCount: 11,
      occupancy: null,
      compatibleVariants: [
        {
          id: 1,
          code: "V1",
          serviceNameId: "Pijat",
          serviceNameEn: "Massage",
          treatmentMode: "double_therapist",
          durationMinutes: 90,
          variantStatus: "draft",
          serviceStatus: "published",
          categoryStatus: "archived",
        },
      ],
      blocks: [
        {
          id: 1,
          startsAt: "2026-10-24T02:00:00Z",
          endsAt: "2026-10-24T04:00:00Z",
          reason: "<img src=x onerror=alert(1)>",
        },
      ],
    },
    {
      id: 2,
      version: 1,
      code: "BBB",
      name: "Future room",
      type: "all",
      capacity: 3,
      cleaningMinutes: 0,
      isActive: true,
      currentBlockCount: 0,
      upcomingBlockCount: 1,
      occupancy: null,
      compatibleVariants: [],
      blocks: [
        {
          id: 2,
          startsAt: "2026-10-25T02:00:00Z",
          endsAt: "2026-10-25T04:00:00Z",
          reason: "Future repair",
        },
      ],
    },
    {
      id: 3,
      version: 1,
      code: "CCC",
      name: "Disabled blocked",
      type: "unset",
      capacity: 7,
      cleaningMinutes: 5,
      isActive: false,
      currentBlockCount: 1,
      upcomingBlockCount: 0,
      occupancy: null,
      compatibleVariants: [],
      blocks: [],
    },
  ],
});
const render = (data = inventory()) =>
  mount(Index, { props: { inventory: data } });
const codes = (wrapper: ReturnType<typeof render>) =>
  wrapper
    .findAll("tbody tr[data-room-id]")
    .map((row) => row.attributes("data-room-id"));

describe("read-only staff room inventory", () => {
  it("only displays write links for backend-authorized owners with complete schema", () => {
    const owner = mount(Index, {
      props: {
        inventory: inventory(),
        management: { isOwner: true, ready: true },
      },
    });
    expect(owner.find('a[href="/admin/rooms/create"]').exists()).toBe(true);
    expect(owner.find('a[href="/admin/rooms/1/edit"]').exists()).toBe(true);
    const unavailable = mount(Index, {
      props: {
        inventory: inventory(),
        management: { isOwner: true, ready: false },
      },
    });
    expect(unavailable.find('a[href="/admin/rooms/create"]').exists()).toBe(
      false,
    );
    expect(unavailable.text()).toContain("Room editing is unavailable");
  });
  beforeEach(() => {
    state.page = reactive({ props: { locale: "en" as Locale } });
  });

  it("renders real immutable props, independent block badges and Jakarta observation without fake controls", () => {
    const data = inventory();
    const original = JSON.stringify(data);
    const wrapper = render(data);
    expect(codes(wrapper)).toEqual(["1", "2", "3"]);
    expect(wrapper.text()).toContain("Real <script>room</script>");
    expect(wrapper.find("tbody tr").text()).toContain("Enabled");
    expect(wrapper.find("tbody tr").text()).toContain("Currently blocked");
    expect(wrapper.text()).toContain("10:00");
    expect(wrapper.text()).toContain("WIB");
    expect(wrapper.text()).toContain("Massage");
    expect(wrapper.text()).toContain("Double therapist");
    expect(wrapper.text()).toContain("90 mins");
    expect(wrapper.text()).toContain("Inactive catalog");
    expect(wrapper.text()).toContain("<img src=x onerror=alert(1)>");
    expect(wrapper.find('script, img, [role="dialog"]').exists()).toBe(false);
    expect(wrapper.text()).not.toMatch(
      /Cempaka|Nezuko|Demo|Add Room|Edit Room|Save Room/,
    );
    expect(wrapper.findAll("button")).toHaveLength(0);
    expect(JSON.stringify(data)).toBe(original);
  });

  it("filters status independently of blocks and keeps full inventory statistics", async () => {
    const wrapper = render();
    const stats = wrapper.find("dl").text();
    await wrapper.get("#room-status").setValue("enabled");
    expect(codes(wrapper)).toEqual(["1", "2"]);
    await wrapper.get("#room-status").setValue("blocked");
    expect(codes(wrapper)).toEqual(["1", "3"]);
    await wrapper.get("#room-status").setValue("disabled");
    expect(codes(wrapper)).toEqual(["3"]);
    expect(wrapper.find("dl").text()).toBe(stats);
    expect(stats).toContain("5"); // enabled capacity excludes disabled room's seven spaces
  });

  it("distinguishes unset types from actual sentinel-like strings and searches code/name", async () => {
    const wrapper = render();
    const options = wrapper.get("#room-type").findAll("option");
    expect(options.map((o) => o.text())).toEqual([
      "All types",
      "Unset type",
      "all",
      "unset",
    ]);
    await wrapper.get("#room-type").setValue(options[1].attributes("value"));
    expect(codes(wrapper)).toEqual(["1"]);
    await wrapper.get("#room-type").setValue(options[2].attributes("value"));
    expect(codes(wrapper)).toEqual(["2"]);
    await wrapper.get("#room-type").setValue("all");
    await wrapper.get("#room-search").setValue("cCc");
    expect(codes(wrapper)).toEqual(["3"]);
    await wrapper.get("#room-search").setValue("future ROOM");
    expect(codes(wrapper)).toEqual(["2"]);
    await wrapper.get("#room-search").setValue("missing");
    expect(wrapper.text()).toContain("No rooms match");
  });

  it.each(["en", "id"] as const)(
    "shows distinct empty/unavailable states and localized details in %s",
    async (locale) => {
      state.page.props.locale = locale;
      const wrapper = render();
      expect(wrapper.text()).toContain(locale === "id" ? "Pijat" : "Massage");
      expect(wrapper.text()).toContain(
        locale === "id" ? "Dua terapis" : "Double therapist",
      );
      expect(wrapper.text()).toContain(
        locale === "id" ? "Katalog tidak aktif" : "Inactive catalog",
      );
      expect(wrapper.text()).toContain(
        locale === "id"
          ? "Tidak ada perawatan kompatibel"
          : "No compatible treatments",
      );
      const empty = render({ ...inventory(), rooms: [] });
      expect(empty.text()).toContain(
        locale === "id"
          ? "Belum ada ruangan yang ditambahkan"
          : "No rooms have been added",
      );
      const unavailable = render({
        ...inventory(),
        state: "unavailable",
        rooms: [],
      });
      expect(unavailable.text()).toContain(
        locale === "id"
          ? "Catatan ruangan belum tersedia"
          : "Room records are not available yet",
      );
      expect(unavailable.find("dl").exists()).toBe(false);
      await nextTick();
    },
  );

  it("separates block capability from owner inventory editing on desktop and mobile", () => {
    const wrapper = mount(Index, {
      props: {
        inventory: inventory(),
        management: { isOwner: false, ready: false },
        blockManagement: { canManageBlocks: true, ready: true },
      },
    });
    expect(wrapper.find('a[href="/admin/room-blocks/create"]').exists()).toBe(
      true,
    );
    expect(wrapper.find('a[href="/admin/rooms/create"]').exists()).toBe(false);
    // The table row, the mobile card and the room map tile.
    expect(
      wrapper.findAll('a[href="/admin/room-blocks?roomId=1"]'),
    ).toHaveLength(3);
  });

  it("discloses full counts, omitted blocks, observation-based current/upcoming and horizon", () => {
    const wrapper = render();
    expect(wrapper.text()).toContain("1 current");
    expect(wrapper.text()).toContain("11 upcoming");
    expect(wrapper.text()).toContain("11 more saved blocks");
    expect(wrapper.text()).toContain("30 days");
    expect(wrapper.text()).toContain("Upcoming");
    expect(wrapper.find('tbody tr[data-room-id="3"]').text()).not.toContain(
      "No blocks",
    );
    const noBlocks = render({
      ...inventory(),
      rooms: [{ ...inventory().rooms[1], upcomingBlockCount: 0, blocks: [] }],
    });
    expect(noBlocks.text()).toContain("No blocks in this window");
  });
});

describe("room map", () => {
  beforeEach(() => {
    state.page = reactive({ props: { locale: "en" as Locale } });
    usePoll.mockClear();
  });
  const [blocked, free, off] = inventory().rooms;
  const live = (): AdminRoomInventory => ({
    ...inventory(),
    schedulingEnabled: true,
    rooms: [
      {
        ...free,
        id: 1,
        code: "R1",
        occupancy: {
          state: "guest",
          customerName: "Sari <b>W</b>",
          guests: 2,
          packageNameId: "Pijat",
          packageNameEn: "Massage",
          treatmentEndsAt: "2026-10-24T08:30:00Z",
          endsAt: "2026-10-24T08:45:00Z",
        },
      },
      {
        ...free,
        id: 2,
        code: "R2",
        occupancy: {
          state: "cleaning",
          customerName: "Budi",
          guests: 1,
          packageNameId: "Pijat",
          packageNameEn: "Massage",
          treatmentEndsAt: "2026-10-24T03:00:00Z",
          endsAt: "2026-10-24T03:15:00Z",
        },
      },
      { ...off, id: 3, code: "R3" },
      { ...free, id: 4, code: "R4" },
      { ...blocked, id: 5, code: "R5" },
    ],
  });
  // Chips keep each number with its unit (non-breaking space); compare with plain spaces.
  const tiles = (wrapper: ReturnType<typeof render>) =>
    wrapper.findAll("[data-room-map-id]").map((tile) =>
      Object.assign(tile, {
        text: () => tile.element.textContent!.replace(/ /g, " ").trim(),
      }),
    );

  it("draws every room in code order, coloured by what is happening now, with a corridor after the first row", async () => {
    const wrapper = render(live());
    expect(tiles(wrapper).map((t) => t.attributes("data-room-map-id"))).toEqual(
      ["1", "2", "3", "4", "5"],
    );
    expect(tiles(wrapper).map((t) => t.attributes("data-room-state"))).toEqual([
      "guest",
      "cleaning",
      "off",
      "free",
      "blocked",
    ]);
    const [guest, cleaning, disabled, open, closed] = tiles(wrapper);
    expect(guest.text()).toContain("Guest inside");
    expect(guest.text()).toContain("Sari <b>W</b>");
    // Observed at 03:00Z; the treatment ends 08:30Z and the room has three places.
    expect(guest.text()).toContain("2/3 guests");
    expect(guest.text()).toContain("Massage");
    expect(guest.text()).toContain("5 h 30 min left");
    expect(cleaning.text()).toContain("Being cleaned");
    expect(cleaning.text()).toContain("Ready in 15 min");
    expect(cleaning.text()).not.toContain("Budi");
    expect(disabled.text()).toContain("Disabled");
    expect(open.text()).toContain("Free");
    expect(closed.text()).toContain("Currently blocked");
    // Phones turn the real plan a quarter clockwise (never mirror it): with reception at
    // the bottom, rooms 1-4 run down the right column and 5-8 down the left.
    expect(tiles(wrapper)[0].attributes("style")).toContain(
      "--turned-row: 1; --turned-column: 3",
    );
    expect(tiles(wrapper)[3].attributes("style")).toContain(
      "--turned-row: 4; --turned-column: 3",
    );
    expect(tiles(wrapper)[4].attributes("style")).toContain(
      "--turned-row: 1; --turned-column: 1",
    );
    const floor = wrapper.get("[data-room-map]").element.children;
    expect(floor[4].hasAttribute("data-room-map-corridor")).toBe(true);
    expect(wrapper.find("b").exists()).toBe(false);
    await wrapper.get("#room-status").setValue("disabled");
    expect(tiles(wrapper)).toHaveLength(5);
  });

  it("draws each room's beds with whoever is lying in them, and counts every state in the key", () => {
    const [guest, cleaning, disabled, open] = tiles(render(live()));
    expect(guest.findAll(".room-map__bed")).toHaveLength(2);
    expect(guest.findAll(".room-map__guest")).toHaveLength(2);
    expect(cleaning.findAll(".room-map__bed")).toHaveLength(2);
    expect(cleaning.findAll(".room-map__pillow")).toHaveLength(0);
    expect(disabled.findAll(".room-map__bed")).toHaveLength(0);
    expect(open.findAll(".room-map__pillow")).toHaveLength(2);
    expect(open.findAll(".room-map__guest")).toHaveLength(0);
    const single = render({
      ...live(),
      rooms: [
        {
          ...live().rooms[0],
          capacity: 1,
          occupancy: { ...live().rooms[0].occupancy!, guests: 1 },
        },
      ],
    });
    expect(tiles(single)[0].findAll(".room-map__bed")).toHaveLength(1);
    expect(
      render()
        .findAll("[data-room-map-key] .room-map__count")
        .map((count) => count.text()),
    ).toEqual(["1", "1", "1"]);
    // Scheduling off records no guests, so the key leaves those counts out.
    expect(
      render()
        .findAll("[data-room-map-key]")
        .map((key) => key.attributes("data-room-map-key")),
    ).toEqual(["free", "blocked", "off"]);
    expect(
      render(live())
        .findAll("[data-room-map-key] .room-map__count")
        .map((count) => count.text()),
    ).toEqual(["1", "1", "1", "1", "1"]);
  });

  it("opens the edit page for owners and the room's blocks for other staff", () => {
    const owner = mount(Index, {
      props: { inventory: live(), management: { isOwner: true, ready: true } },
    });
    expect(tiles(owner)[0].attributes("href")).toBe("/admin/rooms/1/edit");
    const staff = render(live());
    expect(tiles(staff)[0].attributes("href")).toBe(
      "/admin/room-blocks?roomId=1",
    );
  });

  it("calls rooms open, not free, and says why when scheduling is off", () => {
    const wrapper = render();
    expect(tiles(wrapper)[1].text()).toContain("Open");
    expect(tiles(wrapper)[1].text()).not.toContain("Free");
    expect(wrapper.text()).toContain(
      "Guests show on the map only while room scheduling is on.",
    );
    expect(render(live()).text()).not.toContain("Guests show on the map");
  });

  it("labels the map in Indonesian", () => {
    state.page.props.locale = "id";
    const [guest, cleaning] = tiles(render(live()));
    expect(guest.text()).toContain("Ada tamu");
    expect(guest.text()).toContain("5 jam 30 menit lagi");
    expect(guest.text()).toContain("2/3 tamu");
    expect(cleaning.text()).toContain("Sedang dibersihkan");
    expect(cleaning.text()).toContain("Siap dalam 15 menit");
  });

  it("counts time left from the observation in whole minutes, rounding up and never below one", () => {
    const at = (treatmentEndsAt: string, endsAt: string) => {
      const [guest, cleaning] = live().rooms;
      return tiles(
        render({
          ...live(),
          rooms: [
            {
              ...guest,
              occupancy: { ...guest.occupancy!, treatmentEndsAt },
            },
            { ...cleaning, occupancy: { ...cleaning.occupancy!, endsAt } },
          ],
        }),
      ).map((tile) => tile.text());
    };
    const [minutes, cleaningSoon] = at(
      "2026-10-24T03:24:10Z",
      "2026-10-24T03:00:20Z",
    );
    expect(minutes).toContain("25 min left");
    expect(cleaningSoon).toContain("Ready in 1 min");
    expect(at("2026-10-24T05:00:00Z", "2026-10-24T03:15:00Z")[0]).toContain(
      "2 h left",
    );
  });

  it("refreshes only the room inventory every minute", () => {
    render();
    expect(usePoll).toHaveBeenCalledWith(60_000, { only: ["inventory"] });
  });
});
