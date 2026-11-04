import { mount } from "@vue/test-utils";
import { reactive, nextTick } from "vue";
import { beforeEach, describe, expect, it, vi } from "vitest";
import Index from "@/pages/admin/rooms/Index.vue";
import type { AdminRoomInventory, Locale } from "@/types";

const state = vi.hoisted(() => ({
  page: { props: { locale: "en" as Locale } },
}));
vi.mock("@inertiajs/vue3", () => ({
  usePage: () => state.page,
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
    expect(
      wrapper.findAll('a[href="/admin/room-blocks?roomId=1"]'),
    ).toHaveLength(2);
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
