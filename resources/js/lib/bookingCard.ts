import { translate } from "@/composables/useLanguage";
import type { ContactInformation } from "@/lib/contactInformation";
import type { BookingSummaryData, Locale } from "@/types";

const width = 1080;
const height = 1350;
const sans = '"Plus Jakarta Sans", Arial, sans-serif';
const display = '"Playfair Display", Georgia, serif';
const colors = {
  background: "#faf7f3",
  paper: "#ffffff",
  ink: "#302827",
  muted: "#776d69",
  rose: "#8c3b43",
  gold: "#b49768",
  line: "#e8ded4",
  inset: "#f8f3ed",
} as const;

function label(
  ctx: CanvasRenderingContext2D,
  text: string,
  x: number,
  y: number,
): void {
  ctx.fillStyle = colors.muted;
  ctx.font = `700 21px ${sans}`;
  ctx.fillText(text, x, y);
}

function value(
  ctx: CanvasRenderingContext2D,
  text: string,
  x: number,
  y: number,
  maxWidth: number,
  size: number,
  family = sans,
): void {
  ctx.fillStyle = colors.ink;
  ctx.font = `700 ${size}px ${family}`;
  ctx.fillText(text, x, y, maxWidth);
}

function roundedPanel(
  ctx: CanvasRenderingContext2D,
  x: number,
  y: number,
  panelWidth: number,
  panelHeight: number,
  radius: number,
  color: string,
): void {
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.roundRect(x, y, panelWidth, panelHeight, radius);
  ctx.fill();
}

function dateLabel(date: string, locale: Locale): string {
  const [year, month, day] = date.split("-").map(Number);

  return new Intl.DateTimeFormat(locale === "id" ? "id-ID" : "en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date(year, month - 1, day));
}

function statusColors(status: BookingSummaryData["status"]): {
  background: string;
  foreground: string;
} {
  switch (status) {
    case "pending":
      return { background: "#f7ead0", foreground: "#704718" };
    case "confirmed":
      return { background: "#e7f1e7", foreground: "#315e3d" };
    case "completed":
      return { background: "#e9edef", foreground: "#3d5860" };
    case "cancelled":
      return { background: "#f5e8e8", foreground: "#823943" };
  }
}

function drawBookingCard(
  ctx: CanvasRenderingContext2D,
  booking: BookingSummaryData,
  locale: Locale,
  contact: ContactInformation,
): void {
  const tr = (key: string, replacements?: Record<string, string | number>) =>
    translate(locale, key, replacements);
  const packageName =
    locale === "id" ? booking.package.nameId : booking.package.nameEn;
  const status =
    booking.status === "pending"
      ? tr("bookings.cardPending")
      : tr(`bookings.status.${booking.status}`);
  const statusColor = statusColors(booking.status);
  const timezone =
    booking.appointment.timezone === "Asia/Jakarta"
      ? "WIB"
      : booking.appointment.timezone;

  ctx.fillStyle = colors.background;
  ctx.fillRect(0, 0, width, height);
  roundedPanel(ctx, 42, 42, 996, 1266, 34, colors.paper);
  ctx.fillStyle = colors.rose;
  ctx.fillRect(108, 192, 864, 3);

  ctx.fillStyle = colors.ink;
  ctx.font = `400 58px ${display}`;
  ctx.fillText("Shin Joy", 108, 131);
  ctx.fillStyle = colors.gold;
  ctx.font = `700 20px ${sans}`;
  ctx.fillText("SPA · MALANG", 111, 163);

  ctx.fillStyle = colors.rose;
  ctx.font = `800 23px ${sans}`;
  ctx.fillText(tr("bookings.cardHeading"), 108, 247);
  label(ctx, tr("bookings.reference"), 108, 294);
  value(ctx, booking.reference, 108, 343, 864, 39);

  ctx.font = `700 28px ${sans}`;
  roundedPanel(
    ctx,
    108,
    370,
    Math.min(ctx.measureText(status).width + 64, 864),
    65,
    32,
    statusColor.background,
  );
  ctx.fillStyle = statusColor.foreground;
  ctx.fillText(status, 140, 412, 800);
  ctx.fillStyle = colors.muted;
  ctx.font = `500 19px ${sans}`;
  ctx.fillText(tr("bookings.cardStatusAtDownload"), 108, 471);
  ctx.fillStyle = colors.line;
  ctx.fillRect(108, 500, 864, 2);

  label(ctx, tr("bookings.date"), 108, 552);
  value(
    ctx,
    dateLabel(booking.appointment.date, locale),
    108,
    609,
    465,
    46,
    display,
  );
  label(ctx, tr("bookings.time"), 614, 552);
  value(ctx, `${booking.appointment.time} ${timezone}`, 614, 609, 358, 39);

  roundedPanel(ctx, 108, 653, 864, 327, 24, colors.inset);
  label(ctx, tr("bookings.treatmentBreakdown"), 141, 705);
  value(ctx, packageName, 141, 766, 798, 40, display);
  label(ctx, tr("bookings.treatmentType"), 141, 820);
  value(
    ctx,
    tr(`bookings.treatmentMode.${booking.treatmentMode}`),
    141,
    865,
    798,
    30,
  );
  label(ctx, tr("bookings.guests"), 141, 918);
  label(ctx, tr("bookings.totalDuration"), 567, 918);
  value(
    ctx,
    tr(booking.guests === 1 ? "bookings.guestOne" : "bookings.guestMany", {
      count: booking.guests,
    }),
    141,
    955,
    370,
    28,
  );
  value(
    ctx,
    `${booking.totalDurationMinutes} ${tr("bookings.minutes")}`,
    567,
    955,
    370,
    28,
  );

  label(ctx, tr("bookings.totalPrice"), 108, 1045);
  value(
    ctx,
    `Rp ${booking.totalPriceIdr.toLocaleString("id-ID")}`,
    108,
    1115,
    864,
    58,
    display,
  );
  ctx.fillStyle = colors.line;
  ctx.fillRect(108, 1153, 864, 2);

  label(ctx, tr("loc.addresst"), 108, 1200);
  value(ctx, contact.address, 108, 1236, 864, 24);
  ctx.fillStyle = colors.muted;
  ctx.font = `500 18px ${sans}`;
  ctx.fillText(tr("bookings.cardStatusNote"), 108, 1275, 864);
}

export async function downloadBookingCard(
  booking: BookingSummaryData,
  locale: Locale,
  contact: ContactInformation,
): Promise<void> {
  if (document.fonts) {
    await Promise.all([
      document.fonts.load(`400 58px ${display}`),
      document.fonts.load(`700 28px ${sans}`),
    ]);
  }

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext("2d");

  if (!ctx) {
    throw new Error("Canvas is unavailable.");
  }

  drawBookingCard(ctx, booking, locale, contact);

  const blob = await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob((result) => {
      if (result) resolve(result);
      else reject(new Error("PNG export failed."));
    }, "image/png");
  });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = `shin-joy-booking-${booking.reference}.png`;
  anchor.hidden = true;
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
