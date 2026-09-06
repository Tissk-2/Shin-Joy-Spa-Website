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

export interface PublicUser {
  id: number;
  name: string;
  email: string;
  phone: string;
  email_verified_at: string | null;
}

export interface AppPageProps extends PageProps {
  auth: { user: PublicUser | null };
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
