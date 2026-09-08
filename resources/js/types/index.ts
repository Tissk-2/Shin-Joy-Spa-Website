import type { PageProps } from "@inertiajs/core";
import type { LucideIcon } from "lucide-vue-next";

export type Locale = "id" | "en";

export type TreatmentMode = "regular" | "double_therapist";

export type BookingStatus = "pending" | "confirmed" | "completed" | "cancelled";

export interface PackageVariantData {
  code: string;
  mode: TreatmentMode;
  duration: number;
  price: number;
}

export interface PackageExtensionData {
  code: string;
  mode: TreatmentMode;
  duration: number;
  price: number;
}

export interface PackageLinkData {
  slug: string;
  nameId: string;
  nameEn: string;
  isVip: boolean;
}

export interface PackageData extends PackageLinkData {
  descId: string;
  descEn: string;
  longId: string;
  longEn: string;
  benefitId: string | null;
  benefitEn: string | null;
  image: string;
  gallery: string[];
  variants: PackageVariantData[];
  extensions: PackageExtensionData[];
}

export interface BookingAppointmentData {
  date: string;
  time: string;
  timezone: string;
}

export interface BookingSummaryData {
  reference: string;
  status: BookingStatus;
  package: PackageLinkData;
  treatmentMode: TreatmentMode;
  appointment: BookingAppointmentData;
  guests: number;
  totalDurationMinutes: number;
  totalPriceIdr: number;
}

export interface BookingCustomerData {
  name: string;
  email: string;
  phone: string;
}

export interface BookingChoiceData {
  choiceCode: string;
  durationMinutes: number;
  priceIdr: number;
}

export interface BookingDetailData extends BookingSummaryData {
  customer: BookingCustomerData;
  base: BookingChoiceData;
  extension: BookingChoiceData | null;
  notes: string | null;
}

export interface AdminBookingSummaryData {
  readonly reference: string;
  readonly status: BookingStatus;
  readonly package: Readonly<
    Pick<PackageLinkData, "nameId" | "nameEn" | "isVip">
  >;
  readonly treatmentMode: TreatmentMode;
  readonly appointment: Readonly<BookingAppointmentData>;
  readonly customer: Readonly<BookingCustomerData>;
  readonly guests: number;
  readonly totalDurationMinutes: number;
  readonly totalPriceIdr: number;
  readonly allowedTransitions: readonly BookingStatus[];
}

export interface AdminBookingStatusHistoryData {
  readonly actor: Readonly<{ name: string; email: string }>;
  readonly fromStatus: BookingStatus;
  readonly toStatus: BookingStatus;
  readonly reason: string | null;
  readonly createdAt: string;
}

export interface AdminBookingDetailData extends AdminBookingSummaryData {
  readonly base: Readonly<BookingChoiceData>;
  readonly extension: Readonly<BookingChoiceData> | null;
  readonly notes: string | null;
  readonly history: readonly AdminBookingStatusHistoryData[];
}

export interface AdminBookingFilters {
  readonly scope: "upcoming" | "past" | "all";
  readonly status: BookingStatus | null;
  readonly q: string;
  readonly dateFrom: string | null;
  readonly dateTo: string | null;
}

export interface AdminBookingCounts {
  readonly today: number;
  readonly pending: number;
  readonly confirmed: number;
  readonly completed: number;
}

export interface AdminBookingPaginationLink {
  readonly url: string | null;
  readonly label: string;
  readonly active: boolean;
}

export interface AdminBookingPagination {
  readonly data: readonly AdminBookingSummaryData[];
  readonly links: readonly AdminBookingPaginationLink[];
  readonly currentPage: number;
  readonly lastPage: number;
  readonly total: number;
}

export interface PublicUser {
  id: number;
  name: string;
  email: string;
  phone: string;
  email_verified_at: string | null;
}

export interface AppPageProps extends PageProps {
  auth: { user: PublicUser | null; canAccessAdmin: boolean };
  locale: Locale;
  flash: { status?: string };
  catalog: { packages: PackageLinkData[] };
}

export interface BreadcrumbItem {
  title: string;
  href: string;
}

export interface NavItem {
  title: string;
  href: string;
  icon?: LucideIcon;
  isActive?: boolean;
}

export interface SharedData extends AppPageProps {
  name: string;
  quote: { message: string; author: string };
  ziggy: {
    location: string;
    url: string;
    port: null | number;
    defaults: Record<string, unknown>;
    routes: Record<string, string>;
  };
}

export type User = PublicUser;

export type BreadcrumbItemType = BreadcrumbItem;
