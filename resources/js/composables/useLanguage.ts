import { router, usePage } from "@inertiajs/vue3";
import { computed, type ComputedRef } from "vue";

import translations from "@/data/translations";
import type { AppPageProps, Locale } from "@/types";

type Replacements = Readonly<Record<string, string | number>>;
type TranslationDictionary = Record<Locale, Record<string, string>>;

export function translate(
  locale: Locale,
  key: string,
  replacements: Replacements = {},
  dictionary: TranslationDictionary = translations,
): string {
  let value = dictionary[locale]?.[key] ?? dictionary.en?.[key] ?? key;

  for (const [name, replacement] of Object.entries(replacements)) {
    value = value.replaceAll(`{${name}}`, String(replacement));
  }

  return value;
}

export interface LanguageControls {
  language: ComputedRef<Locale>;
  tr: (key: string, replacements?: Replacements) => string;
  setLanguage: (locale: Locale) => void;
}

export function useLanguage(): LanguageControls {
  const page = usePage<AppPageProps>();
  const language = computed(() => page.props.locale);
  const tr = (key: string, replacements: Replacements = {}): string =>
    translate(language.value, key, replacements);
  const setLanguage = (locale: Locale): void => {
    router.post(
      "/locale",
      { locale },
      {
        preserveScroll: true,
        preserveState: true,
        replace: true,
      },
    );
  };

  return { language, tr, setLanguage };
}
