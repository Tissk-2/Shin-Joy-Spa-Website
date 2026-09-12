import type { PageProps } from "@inertiajs/core";
import type { LucideIcon } from "lucide-vue-next";

export type Locale = "id" | "en";

export type TreatmentMode = "regular" | "double_therapist";

export type BookingStatus = "pending" | "confirmed" | "completed" | "cancelled";

export type CatalogStatus = "draft" | "published" | "archived";

export interface AdminCatalogAbilities {
  readonly update: boolean;
  readonly publish: boolean;
  readonly archive: boolean;
  readonly restore: boolean;
  readonly delete: boolean;
}

export interface AdminCatalogCategoryData {
  readonly slug: string;
  readonly version: number;
  readonly nameId: string;
  readonly nameEn: string;
  readonly image: string | null;
  readonly status: CatalogStatus;
  readonly packageCount: number;
  readonly displayOrder: number;
  readonly can: Readonly<AdminCatalogAbilities>;
}

export interface AdminCatalogPackageData {
  readonly version: number;
  readonly slug: string;
  readonly categorySlug: string;
  readonly nameId: string;
  readonly nameEn: string;
  readonly image: string | null;
  readonly gallery: readonly Readonly<{ retentionId: string; url: string }>[];
  readonly isVip: boolean;
  readonly status: CatalogStatus;
  readonly variantCount: number;
  readonly extensionCount: number;
  readonly optionCount: number;
  readonly displayOrder: number;
  readonly can: Readonly<AdminCatalogAbilities>;
}

export interface AdminCatalogCategoryOption {
  readonly id: number;
  readonly slug: string;
  readonly nameId: string;
  readonly nameEn: string;
  readonly status: CatalogStatus;
}

export interface AdminCatalogGalleryItem {
  readonly retentionId: string;
  readonly url: string;
}

export interface AdminCatalogChoiceData {
  readonly id: number;
  readonly code: string;
  readonly mode: TreatmentMode;
  readonly durationMinutes: number;
  readonly priceIdr: number;
  readonly isActive: boolean;
  readonly displayOrder: number;
  readonly isPublished: boolean;
}

export interface CatalogChoiceInput {
  readonly clientKey: string;
  readonly id?: number;
  readonly code: string;
  readonly mode: TreatmentMode;
  readonly durationMinutes: string;
  readonly priceIdr: string;
  readonly isActive: boolean;
  readonly displayOrder: string;
  readonly isPublished: boolean;
}

export interface AdminCatalogPackageFormData {
  readonly version: number | null;
  readonly categoryId: number | null;
  readonly slug: string;
  readonly nameId: string;
  readonly nameEn: string;
  readonly descId: string | null;
  readonly descEn: string | null;
  readonly longId: string | null;
  readonly longEn: string | null;
  readonly benefitId: string | null;
  readonly benefitEn: string | null;
  readonly isVip: boolean;
  readonly displayOrder: number;
  readonly cover: string | null;
  readonly gallery: readonly AdminCatalogGalleryItem[];
  readonly variants: readonly AdminCatalogChoiceData[];
  readonly extensions: readonly AdminCatalogChoiceData[];
  readonly status: CatalogStatus;
  readonly isPublished: boolean;
  readonly can: Readonly<AdminCatalogAbilities>;
}

export interface AdminCatalogPackageFormProps {
  readonly categories: readonly AdminCatalogCategoryOption[];
  readonly package: AdminCatalogPackageFormData;
}

export interface AdminCatalogIndexProps {
  readonly categories: readonly AdminCatalogCategoryData[];
  readonly packages: readonly AdminCatalogPackageData[];
  readonly can: Readonly<{
    createCategory: boolean;
    createPackage: boolean;
  }>;
}

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
