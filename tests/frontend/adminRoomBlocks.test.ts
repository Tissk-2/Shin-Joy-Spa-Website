import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import Blocks from "@/pages/admin/rooms/Blocks.vue";
import BlockForm from "@/pages/admin/rooms/BlockForm.vue";
import { router } from "@inertiajs/vue3";
const state = vi.hoisted(() => ({
  page: { props: { locale: "en", flash: {} } },
}));
vi.mock("@inertiajs/vue3", () => ({
  usePage: () => state.page,
  router: { post: vi.fn(), patch: vi.fn() },
}));
vi.mock("@/layouts/admin/AdminLayout.vue", () => ({
  default: {
    props: ["title"],
    template: "<main><h1>{{ title }}</h1><slot /></main>",
  },
}));
const access = {
  canManageBlocks: true,
  ready: true,
  canTerminateBlocks: true,
  terminationReady: true,
};
const rooms = [{ id: 4, code: "R1", name: "Room one", isActive: false }];
const saved = {
  id: 7,
  roomId: 4,
  roomCode: "R1",
  startsAt: "2030-01-01T03:00:00Z",
  endsAt: "2030-01-01T04:00:00Z",
  startsAtInput: "2030-01-01T10:00",
  endsAtInput: "2030-01-01T11:00",
  reason: "Repair",
  version: 2,
  state: "future" as const,
};
describe("room maintenance", () => {
  beforeEach(() => vi.clearAllMocks());
  it("labels immutable periods as details in the history link and form title", () => {
    const terminal = { ...saved, state: "cancelled" as const };
    const form = mount(BlockForm, {
      props: { blockManagement: access, block: terminal, rooms },
    });
    expect(form.get("h1").text()).toBe("Maintenance period details");
    const list = mount(Blocks, {
      props: {
        schedule: {
          state: "ready",
          generatedAt: saved.startsAt,
          view: "history",
          roomId: 4,
          rooms,
          blockManagement: access,
          blocks: [terminal, { ...saved, id: 8, state: "ended" }],
          pagination: {
            page: 1,
            lastPage: 1,
            total: 2,
            previous: null,
            next: null,
          },
        },
      },
    });
    expect(list.get('a[href="/admin/room-blocks/7/edit"]').text()).toBe(
      "View details",
    );
    expect(list.get('a[href="/admin/room-blocks/8/edit"]').text()).toBe(
      "View details",
    );
  });
  it("lists far-future blocks in fixed WIB with filter-preserving pagination and escaped reasons", () => {
    const w = mount(Blocks, {
      props: {
        schedule: {
          state: "ready",
          generatedAt: "2026-11-01T00:00:00Z",
          roomId: 4,
          rooms,
          blockManagement: access,
          blocks: [{ ...saved, reason: "<script>unsafe</script>" }],
          pagination: {
            page: 1,
            lastPage: 2,
            total: 21,
            previous: null,
            next: "/admin/room-blocks?roomId=4&page=2",
          },
        },
      },
    });
    expect(w.text()).toContain("10:00");
    expect(w.text()).toContain("WIB");
    expect(w.find("script").exists()).toBe(false);
    expect(
      w.find('a[href="/admin/room-blocks?roomId=4&page=2"]').exists(),
    ).toBe(true);
    expect(w.find('a[href="/admin/room-blocks/7/edit"]').exists()).toBe(true);
  });
  it("explains all-existing-room saving only when creating that scope", async () => {
    const w = mount(BlockForm, {
      props: { blockManagement: access, block: null, rooms },
    });
    expect(w.text()).not.toContain("Rooms created later");
    await w.find("#scope").setValue("all");
    expect(w.text()).toContain("if one room conflicts, none are saved");
    const edit = mount(BlockForm, {
      props: { blockManagement: access, block: saved, rooms },
    });
    expect(edit.text()).not.toContain("Rooms created later");
  });

  it("keeps drafts on conflict, prevents duplicate submits and synchronizes created id/version", async () => {
    const w = mount(BlockForm, {
      props: { blockManagement: access, block: null, rooms },
    });
    expect(w.find("#roomId").element).toHaveProperty("value", "");
    await w.find("#roomId").setValue("4");
    await w.find("#startsAt").setValue("2030-01-01T10:00");
    await w.find("#endsAt").setValue("2030-01-01T11:00");
    await w.find("#reason").setValue("My draft");
    await w.find("form").trigger("submit");
    await w.find("form").trigger("submit");
    expect(router.post).toHaveBeenCalledTimes(1);
    const options = vi.mocked(router.post).mock.calls[0][2]!;
    options.onError!({ room: "Reservation conflict" });
    options.onFinish!({} as never);
    await w.vm.$nextTick();
    expect(w.find("#reason").element).toHaveProperty("value", "My draft");
    expect(w.get('[role="alert"]').text()).toContain("Reservation conflict");
    await w.find("form").trigger("submit");
    options.onSuccess!({ props: { block: saved } } as never);
    options.onFinish!({} as never);
    await w.vm.$nextTick();
    await w.find("form").trigger("submit");
    expect(router.patch).toHaveBeenCalledWith(
      "/admin/room-blocks/7",
      expect.objectContaining({ version: 2 }),
      expect.anything(),
    );
  });
  it("enforces active/ended and unavailable UI restrictions without clearing stale drafts", async () => {
    const w = mount(BlockForm, {
      props: {
        blockManagement: access,
        block: { ...saved, state: "active" },
        rooms,
      },
    });
    expect(w.find("#roomId").attributes("disabled")).toBeDefined();
    expect(w.find("#startsAt").attributes("disabled")).toBeDefined();
    await w.find("#reason").setValue("Stale draft");
    await w.find("form").trigger("submit");
    vi.mocked(router.patch).mock.calls[0][2]!.onError!({
      version: "Changed. Reload.",
    });
    await w.vm.$nextTick();
    expect(w.text()).toContain("Reload");
    expect(w.find("#reason").element).toHaveProperty("value", "Stale draft");
    await w.setProps({ block: { ...saved, state: "ended" } });
    expect(w.find('button[type="submit"]').exists()).toBe(false);
    await w.setProps({
      block: null,
      blockManagement: { canManageBlocks: true, ready: false },
    });
    expect(w.find("form").exists()).toBe(false);
  });
  it("confirms explicit termination with a separate reason and preserves failed drafts without duplicate writes", async () => {
    const w = mount(BlockForm, {
      props: { blockManagement: access, block: saved, rooms },
    });
    await w.get('[data-terminate="cancel_future"]').trigger("click");
    expect(w.get('[role="region"]').text()).toContain("Room one");
    expect(w.get('[role="region"]').text()).toContain("10:00");
    await w.get("#termination-reason").setValue("Separate terminal reason");
    await w.get("[data-terminal-form]").trigger("submit");
    await w.get("[data-terminal-form]").trigger("submit");
    expect(router.post).toHaveBeenCalledTimes(1);
    expect(router.post).toHaveBeenCalledWith(
      "/admin/room-blocks/7/terminate",
      {
        intent: "cancel_future",
        version: 2,
        reason: "Separate terminal reason",
      },
      expect.anything(),
    );
    expect(w.get("form fieldset").attributes("disabled")).toBeDefined();
    const options = vi.mocked(router.post).mock.calls[0][2]!;
    options.onError!({
      intent: "The period has started. Reload and choose end now explicitly.",
    });
    options.onFinish!({} as never);
    await w.vm.$nextTick();
    expect(w.get("#termination-reason").element).toHaveProperty(
      "value",
      "Separate terminal reason",
    );
    expect(w.get("#reason").element).toHaveProperty("value", "Repair");
    expect(w.get('[role="alert"]').text()).toContain(
      "choose end now explicitly",
    );
    options.onSuccess!({
      props: {
        block: {
          ...saved,
          version: 3,
          state: "cancelled",
          effectiveEndsAt: saved.startsAt,
          termination: {
            kind: "cancel_future",
            occurredAt: "2029-12-31T03:00:00.123456Z",
            reason: "Separate terminal reason",
            actorName: "<script>Actor</script>",
            actorRole: "admin",
          },
        },
      },
    } as never);
    await w.vm.$nextTick();
    expect(w.find("#reason").exists()).toBe(false);
    expect(w.text()).toContain("Separate terminal reason");
    expect(w.find("script").exists()).toBe(false);
    expect(w.find("[data-terminate]").exists()).toBe(false);
  });
  it("separates old editing readiness from terminal controls and selects end now for active records", async () => {
    const w = mount(BlockForm, {
      props: {
        blockManagement: { ...access, terminationReady: false },
        block: saved,
        rooms,
      },
    });
    expect(w.find("#reason").exists()).toBe(true);
    expect(w.find("[data-terminate]").exists()).toBe(false);
    await w.setProps({
      blockManagement: access,
      block: { ...saved, state: "active" },
    });
    expect(w.find('[data-terminate="end_now"]').exists()).toBe(true);
    await w.setProps({
      blockManagement: { ...access, canTerminateBlocks: false },
    });
    expect(w.find("[data-terminate]").exists()).toBe(false);
  });
  it("keeps history accessible to read-only staff with original and effective details and preserved filters", () => {
    const w = mount(Blocks, {
      props: {
        schedule: {
          state: "ready",
          generatedAt: saved.startsAt,
          view: "history",
          roomId: 4,
          rooms,
          blockManagement: {
            ...access,
            canManageBlocks: false,
            canTerminateBlocks: false,
          },
          blocks: [
            {
              ...saved,
              state: "ended_early",
              effectiveEndsAt: "2030-01-01T03:30:00.500000Z",
              termination: {
                kind: "end_now",
                occurredAt: "2030-01-01T03:30:00.500000Z",
                reason: "<b>Repair complete</b>",
                actorName: "<script>Actor</script>",
                actorRole: "admin",
                previousVersion: 1,
                resultingVersion: 2,
              },
            },
          ],
          pagination: {
            page: 1,
            lastPage: 2,
            total: 21,
            previous: null,
            next: "/admin/room-blocks?roomId=4&view=history&page=2",
          },
        },
      },
    });
    expect(w.text()).toContain("<script>Actor</script>");
    expect(w.text()).toContain("10:30");
    expect(w.find("script").exists()).toBe(false);
    expect(w.find('input[name="view"]').attributes("value")).toBe("history");
    expect(
      w.find('a[href="/admin/room-blocks?roomId=4&view=active"]').exists(),
    ).toBe(true);
    expect(
      w
        .find('a[href="/admin/room-blocks?roomId=4&view=history&page=2"]')
        .exists(),
    ).toBe(true);
  });
});
