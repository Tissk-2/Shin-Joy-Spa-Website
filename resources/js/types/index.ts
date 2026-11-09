import type { PageProps } from "@inertiajs/core";
import type { LucideIcon } from "lucide-vue-next";

export type Locale = "id" | "en";

export type TreatmentMode = "regular" | "double_therapist";

export type BookingStatus =
  | "pending"
  | "confirmed"
  | "completed"
  | "cancelled"
  | "awaiting_payment"
  | "no_show";

export type CatalogStatus = "draft" | "published" | "archived";

export type PricingBasis = "per_person" | "per_booking";

export interface AdminCatalogAbilities {
  readonly update: boolean;
  readonly publish: boolean;
  readonly archive: boolean;
  readonly restore: boolean;
  readonly delete: boolean;
}

export interface AdminCatalogFormAbilities extends AdminCatalogAbilities {
  readonly attestPricing: boolean;
}

export interface AdminCatalogPricingVerifier {
  readonly id: number;
  readonly name: string;
  readonly email: string;
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
  readonly pricingBasis: PricingBasis | null;
  readonly minGuests: number | null;
  readonly maxGuests: number | null;
  readonly pricingVerified: boolean;
  readonly pricingVerifiedBy: AdminCatalogPricingVerifier | null;
  readonly cover: string | null;
  readonly gallery: readonly AdminCatalogGalleryItem[];
  readonly variants: readonly AdminCatalogChoiceData[];
  readonly extensions: readonly AdminCatalogChoiceData[];
  readonly status: CatalogStatus;
  readonly isPublished: boolean;
  readonly can: Readonly<AdminCatalogFormAbilities>;
}

export interface AdminCatalogPackageFormProps {
  readonly categories: readonly AdminCatalogCategoryOption[];
  readonly package: AdminCatalogPackageFormData;
}

export interface AdminCatalogIndexProps {
  readonly packages: readonly AdminCatalogPackageData[];
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
  pricingBasis?: PricingBasis | null;
  minGuests?: number | null;
  maxGuests?: number | null;
  pricingVerified?: boolean;
}

export interface BookingAuthContext {
  readonly slug: string;
  readonly nameId: string;
  readonly nameEn: string;
  readonly startingPriceIdr: number;
}

export interface PublicCatalogCategoryData {
  readonly slug: string;
  readonly nameId: string;
  readonly nameEn: string;
  readonly image: string | null;
  readonly packages: readonly PackageData[];
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
  paymentPreference?: "at_spa" | "online" | null;
  pricingVerified?: boolean;
  room?: string | null;
}

export interface AdminBookingSummaryData {
  readonly reference: string;
  readonly status: BookingStatus;
  readonly package: Readonly<
    Pick<PackageLinkData, "nameId" | "nameEn" | "isVip">
  >;
  readonly treatmentMode: TreatmentMode;
  readonly appointment: Readonly<BookingAppointmentData>;
  readonly customer: Readonly<
    Omit<BookingCustomerData, "email"> & { email: string | null }
  >;
  readonly guests: number;
  readonly totalDurationMinutes: number;
  readonly totalPriceIdr: number;
  readonly allowedTransitions: readonly BookingStatus[];
}

export interface AdminBookingStatusHistoryData {
  readonly operationalConfirmedAt: string | null;
  readonly actor: Readonly<{
    name: string | null;
    email: string | null;
  }>;
  readonly fromStatus: BookingStatus;
  readonly toStatus: BookingStatus;
  readonly reason: string | null;
  readonly createdAt: string;
}

export interface AdminBookingOrigin {
  readonly source: "website" | "walk_in" | "phone" | "whatsapp" | null;
  readonly staffName: string | null;
}

export interface AdminBookingDetailData extends AdminBookingSummaryData {
  readonly roomSchedulingEnabled: boolean;
  readonly assignedRoom: Readonly<{
    name: string;
    code: string;
    type: string | null;
    capacity: number;
    startsAt: string;
    endsAt: string;
    releasedAt: string | null;
  }> | null;
  readonly base: Readonly<BookingChoiceData>;
  readonly extension: Readonly<BookingChoiceData> | null;
  readonly notes: string | null;
  readonly origin?: AdminBookingOrigin;
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

export type AdminStaffTelegram =
  | { state: "none" }
  | { state: "waiting"; enrollmentId: number; expiresAt: string }
  | {
      state: "claimed";
      enrollmentId: number;
      identity: string;
      claimedAt: string;
    }
  | { state: "linked"; identity: string; linkedAt: string };

export interface AdminTelegramLinkChange {
  action: "activated" | "revoked";
  targetName: string;
  targetDeleted: boolean;
  identity: string;
  actorName: string;
  at: string;
}

export interface AdminFreshTelegramLink {
  staffId: number;
  command: string;
  url: string | null;
  expiresAt: string;
}

export interface AdminStaffMember {
  id: number;
  name: string;
  email: string;
  role: "owner" | "admin";
  verified: boolean;
  canManageBookings: boolean;
  telegram?: AdminStaffTelegram;
}

export interface AdminStaffPage {
  data: AdminStaffMember[];
  current_page: number;
  last_page: number;
  total: number;
  prev_page_url: string | null;
  next_page_url: string | null;
}

export interface AppPageProps extends PageProps {
  auth: { user: PublicUser | null; canAccessAdmin: boolean; isOwner?: boolean };
  locale: Locale;
  flash: { status?: string };
  catalog: { packages: PackageLinkData[] };
  content: Record<string, { id?: string; en?: string }>;
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

export type RoomStatus = "all" | "enabled" | "blocked" | "disabled";

export interface AdminRoomVariant {
  readonly id: number;
  readonly code: string;
  readonly serviceNameId: string;
  readonly serviceNameEn: string;
  readonly treatmentMode: "regular" | "double_therapist";
  readonly durationMinutes: number;
  readonly variantStatus: "draft" | "published" | "archived";
  readonly serviceStatus: "draft" | "published" | "archived";
  readonly categoryStatus: "draft" | "published" | "archived";
}

export interface AdminRoomBlock {
  readonly id: number;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly reason: string;
}

export interface AdminRoomInventory {
  readonly state: "ready" | "unavailable";
  readonly generatedAt: string;
  readonly blocksUntil: string;
  readonly schedulingEnabled: boolean;
  readonly rooms: readonly AdminRoom[];
}

export interface AdminRoom {
  readonly id: number;
  readonly version: number;
  readonly code: string;
  readonly name: string;
  readonly type: string | null;
  readonly capacity: number;
  readonly cleaningMinutes: number;
  readonly isActive: boolean;
  readonly compatibleVariants: readonly AdminRoomVariant[];
  readonly currentBlockCount: number;
  readonly upcomingBlockCount: number;
  readonly blocks: readonly AdminRoomBlock[];
}

export interface RoomManagementAccess {
  readonly isOwner: boolean;
  readonly ready: boolean;
}

export interface BookingReviewInterval {
  readonly weekday: number;
  readonly start: string;
  readonly end: string;
}

export interface BookingSettingsData {
  readonly version: number;
  readonly booking_horizon_days: number;
  readonly minimum_lead_minutes: number;
  readonly review_target_minutes: number;
  readonly minimum_checkout_minutes: number;
  readonly review_intervals: readonly BookingReviewInterval[];
}

export interface RoomEditData {
  readonly id: number;
  readonly version: number;
  readonly code: string;
  readonly name: string;
  readonly type: string | null;
  readonly capacity: number;
  readonly cleaningMinutes: number;
  readonly isActive: boolean;
  readonly compatibleVariantIds: readonly number[];
}

export interface RoomBlockAccess {
  readonly canManageBlocks: boolean;
  readonly ready: boolean;
  readonly canTerminateBlocks?: boolean;
  readonly terminationReady?: boolean;
}
export interface RoomBlockRoom {
  readonly id: number;
  readonly code: string;
  readonly name: string;
  readonly isActive: boolean;
}
export interface RoomBlockData {
  readonly id: number;
  readonly roomId: number;
  readonly roomCode?: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly startsAtInput: string;
  readonly endsAtInput: string;
  readonly reason: string;
  readonly version: number;
  readonly state: "future" | "active" | "ended" | "cancelled" | "ended_early";
  readonly effectiveEndsAt?: string;
  readonly termination?: {
    readonly kind: "cancel_future" | "end_now";
    readonly occurredAt: string;
    readonly reason: string;
    readonly actorName: string;
    readonly actorRole: string;
    readonly previousVersion?: number;
    readonly resultingVersion?: number;
  } | null;
}
export interface RoomBlockSchedule {
  readonly state: "ready" | "unavailable";
  readonly generatedAt: string;
  readonly view?: "active" | "history";
  readonly roomId: number | null;
  readonly blocks: readonly RoomBlockData[];
  readonly rooms: readonly RoomBlockRoom[];
  readonly blockManagement: RoomBlockAccess;
  readonly pagination: {
    readonly page: number;
    readonly lastPage: number;
    readonly total: number;
    readonly previous: string | null;
    readonly next: string | null;
  };
}
export interface AdminScheduleBookingItem {
  readonly kind: "booking";
  readonly reference: string;
  readonly status: BookingStatus;
  readonly customerName: string;
  readonly guests: number;
  readonly packageNameId: string;
  readonly packageNameEn: string;
  readonly startsAt: string;
  readonly treatmentEndsAt: string;
  readonly endsAt: string;
}
export interface AdminScheduleBlockItem {
  readonly kind: "block";
  readonly id: number;
  readonly reason: string;
  readonly startsAt: string;
  readonly endsAt: string;
}
export interface AdminScheduleRoom {
  readonly id: number;
  readonly code: string;
  readonly name: string;
  readonly capacity: number;
  readonly isActive: boolean;
  readonly items: readonly (
    AdminScheduleBookingItem | AdminScheduleBlockItem
  )[];
}
export interface AdminScheduleUnassigned {
  readonly reference: string;
  readonly status: BookingStatus;
  readonly customerName: string;
  readonly guests: number;
  readonly packageNameId: string;
  readonly packageNameEn: string;
  readonly time: string;
  readonly durationMinutes: number;
}
export interface AdminScheduleData {
  readonly date: string;
  readonly previousDate: string;
  readonly nextDate: string;
  readonly today: string;
  readonly schedulingEnabled: boolean;
  readonly rooms: readonly AdminScheduleRoom[];
  readonly unassigned: readonly AdminScheduleUnassigned[];
}
