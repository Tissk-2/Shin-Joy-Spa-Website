import type { PageProps } from "@inertiajs/core";
import type { LucideIcon } from "lucide-vue-next";

export type Locale = "id" | "en";

export interface ServiceCategoryData {
  slug: string;
  nameId: string;
  nameEn: string;
  image: string;
}

export interface ServiceData {
  slug: string;
  category: string;
  nameId: string;
  nameEn: string;
  descId: string;
  descEn: string;
  longId: string;
  longEn: string;
  goodId: string;
  goodEn: string;
  image: string;
  gallery: string[];
  duration: number;
  price: number;
  popularity: number;
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
  catalog: { categories: ServiceCategoryData[] };
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
