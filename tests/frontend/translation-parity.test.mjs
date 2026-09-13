import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
  new URL("../../resources/js/data/translations.ts", import.meta.url),
  "utf8",
);
const translations = new Function(
  source.replace(/^export default\s+/, "return "),
)();

function recursiveKeys(value, prefix = "") {
  return Object.entries(value)
    .flatMap(([key, child]) => {
      const path = prefix ? `${prefix}.${key}` : key;
      return child && typeof child === "object" && !Array.isArray(child)
        ? recursiveKeys(child, path)
        : [path];
    })
    .sort();
}

test("active Indonesian and English UI dictionaries have recursive key parity", () => {
  assert.deepEqual(
    recursiveKeys(translations.id),
    recursiveKeys(translations.en),
  );
});

test("dynamic public catalog surfaces keep paired functional copy", () => {
  const keys = [
    "menu.allTitle",
    "menu.allSub",
    "menu.from",
    "menu.viewDetails",
    "menu.emptyTitle",
    "menu.emptyBody",
    "menu.related",
  ];

  for (const locale of ["id", "en"]) {
    for (const key of keys) {
      assert.equal(
        typeof translations[locale][key],
        "string",
        `${locale}.${key}`,
      );
      assert.notEqual(translations[locale][key].trim(), "", `${locale}.${key}`);
    }
  }
});

test("catalog navigation, forms and lifecycle actions require paired functional copy", () => {
  const keys = [
    "ad.nav.catalog",
    ...[
      "title",
      "subtitle",
      "categories",
      "packages",
      "category",
      "package",
      "status",
      "status.draft",
      "status.published",
      "status.archived",
      "categoryCount",
      "packageCount",
      "optionCount",
      "variantCount",
      "extensionCount",
      "vip",
      "createCategory",
      "createPackage",
      "editCategory",
      "editPackage",
      "publish",
      "archive",
      "restore",
      "delete",
      "hiddenByCategory",
      "emptyCategories",
      "emptyPackages",
      "confirmPublish",
      "confirmArchive",
      "confirmRestore",
      "confirmDelete",
      "error.generic",
      "error.conflict",
      "form.categoryNameId",
      "form.categoryNameEn",
      "form.packageNameId",
      "form.packageNameEn",
      "form.packageSlug",
      "form.cover",
      "form.gallery",
      "upload.help",
      "processing",
      "form.createTitle",
      "form.editTitle",
      "form.slug",
      "form.slugHelp",
      "form.slugLocked",
      "form.displayOrder",
      "form.orderHelp",
      "form.requiredHelp",
      "form.image",
      "form.currentPreview",
      "form.selectedPreview",
      "form.replaceImage",
      "form.removeImage",
      "form.imageRemoved",
      "form.undoRemove",
      "form.save",
      "form.create",
      "form.saveChanges",
      "form.cancel",
      "form.createPackageTitle",
      "form.editPackageTitle",
      "form.categoryId",
      "form.categoryPlaceholder",
      "form.isVip",
      "form.descId",
      "form.descEn",
      "form.longId",
      "form.longEn",
      "form.benefitId",
      "form.benefitEn",
      "form.variants",
      "form.extensions",
      "form.code",
      "form.mode",
      "form.modeRegular",
      "form.modeDoubleTherapist",
      "form.durationMinutes",
      "form.priceIdr",
      "form.active",
      "form.emptyRows",
      "form.addRow",
      "form.removeRow",
      "form.saveDraft",
      "form.galleryHelp",
      "form.galleryLimit",
      "form.galleryTypeError",
      ...["category", "package"].flatMap((record) =>
        ["publish", "archive", "restore", "delete"].flatMap((action) => [
          `lifecycle.${record}.${action}.title`,
          `lifecycle.${record}.${action}.body`,
          `lifecycle.${record}.${action}.confirm`,
        ]),
      ),
      "lifecycle.publish.title",
      "lifecycle.publish.body",
      "lifecycle.publish.confirm",
      "lifecycle.archive.title",
      "lifecycle.archive.body",
      "lifecycle.archive.confirm",
      "lifecycle.restore.title",
      "lifecycle.restore.body",
      "lifecycle.restore.confirm",
      "lifecycle.delete.title",
      "lifecycle.delete.body",
      "lifecycle.delete.confirm",
    ].map((key) => `ad.catalog.${key}`),
  ];
  for (const locale of ["id", "en"]) {
    for (const key of keys) {
      assert.equal(
        typeof translations[locale][key],
        "string",
        `${locale}.${key}`,
      );
      assert.notEqual(translations[locale][key].trim(), "", `${locale}.${key}`);
      assert.doesNotMatch(translations[locale][key], /[\u2013\u2014]/);
    }
  }
});

test("customer booking pages keep paired keys and avoid out-of-scope promises", () => {
  for (const locale of ["id", "en"]) {
    for (const key of [
      "nav.bookings",
      "profile.bookingsAction",
      "bookings.title",
      "bookings.status.pending",
      "bookings.status.confirmed",
      "bookings.status.completed",
      "bookings.status.cancelled",
      "bookings.noExtension",
      "bookings.noNotes",
    ]) {
      assert.equal(typeof translations[locale][key], "string");
      assert.notEqual(translations[locale][key].trim(), "");
    }

    const bookingCopy = Object.entries(translations[locale])
      .filter(([key]) => key.startsWith("bookings."))
      .map(([, value]) => value)
      .join("\n")
      .toLowerCase();

    assert.doesNotMatch(
      bookingCopy,
      /admin|payment|deposit|gateway|cancel request|request cancellation|reschedule|notification|pembayaran|deposit|ajukan pembatalan|minta pembatalan|ubah jadwal|notifikasi/,
    );
  }
});

test("admin booking operations keep paired copy within the approved scope", () => {
  const requiredKeys = [
    "ad.panel",
    "ad.skip",
    "ad.logout",
    "ad.viewsite",
    "ad.nav.bookings",
    "ad.booking.title",
    "ad.booking.subtitle",
    "ad.booking.reference",
    "ad.booking.details",
    "ad.count.today",
    "ad.count.pending",
    "ad.count.confirmed",
    "ad.count.completed",
    "ad.filter.search",
    "ad.filter.status",
    "ad.filter.dateFrom",
    "ad.filter.dateTo",
    "ad.filter.apply",
    "ad.filter.clear",
    "ad.scope.upcoming",
    "ad.scope.past",
    "ad.scope.all",
    "ad.status.pending",
    "ad.status.confirmed",
    "ad.status.completed",
    "ad.status.cancelled",
    "ad.table.customer",
    "ad.table.treatment",
    "ad.table.schedule",
    "ad.table.guests",
    "ad.table.status",
    "ad.table.action",
    "ad.table.open",
    "ad.empty.all",
    "ad.empty.filtered",
    "ad.pagination.previous",
    "ad.pagination.next",
    "ad.detail.appointment",
    "ad.detail.customer",
    "ad.detail.treatment",
    "ad.detail.notes",
    "ad.detail.noNotes",
    "ad.detail.noExtension",
    "ad.detail.back",
    "ad.history.title",
    "ad.history.empty",
    "ad.history.reason",
    "ad.history.noReason",
    "ad.confirm.title",
    "ad.confirm.body",
    "ad.confirm.reason",
    "ad.confirm.submit",
    "ad.confirm.cancel",
    "ad.confirm.processing",
    "ad.confirm.error",
    "ad.flash.statusUpdated",
  ];
  const approvedPrefixes = [
    "ad.booking.",
    "ad.count.",
    "ad.filter.",
    "ad.scope.",
    "ad.status.",
    "ad.table.",
    "ad.empty.",
    "ad.pagination.",
    "ad.detail.",
    "ad.history.",
    "ad.confirm.",
    "ad.flash.",
  ];

  for (const locale of ["id", "en"]) {
    for (const key of requiredKeys) {
      assert.equal(
        typeof translations[locale][key],
        "string",
        `${locale}.${key}`,
      );
      assert.notEqual(translations[locale][key].trim(), "", `${locale}.${key}`);
    }

    const operationalCopy = Object.entries(translations[locale])
      .filter(([key]) =>
        approvedPrefixes.some((prefix) => key.startsWith(prefix)),
      )
      .map(([, value]) => value)
      .join("\n")
      .toLowerCase();

    assert.doesNotMatch(
      operationalCopy,
      /delete|payment|refund|reschedule|notification|hapus (?:reservasi|layanan|kategori|foto)|pembayaran|pengembalian dana|ubah jadwal|notifikasi/,
    );

    for (const unsupportedKey of [
      "ad.bk.statusDone",
      "ad.bk.delete",
      "ad.ov.revenue",
      "ad.sv.title",
      "ad.cat.title",
      "ad.gal.title",
      "ad.ct.title",
      "ad.f.addTitle",
      "ad.nav.services",
      "auth.admin.badge",
      "auth.admin.title",
      "auth.admin.sub",
      "auth.admin.submit",
    ]) {
      assert.equal(translations[locale][unsupportedKey], undefined);
    }
  }
});
