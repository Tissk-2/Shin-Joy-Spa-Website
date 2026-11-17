// A Jakarta calendar date this many days from now, as YYYY-MM-DD: inside the
// 365-day booking window, and never today, so every listed time is still ahead.
export function futureDate(days: number): string {
  const jakarta = new Date(Date.now() + 7 * 60 * 60 * 1000);
  jakarta.setUTCDate(jakarta.getUTCDate() + days);

  return jakarta.toISOString().slice(0, 10);
}
