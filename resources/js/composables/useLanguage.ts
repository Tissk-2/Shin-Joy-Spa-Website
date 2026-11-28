import { router, usePage } from "@inertiajs/vue3";
import { computed, type ComputedRef } from "vue";

import translations from "@/data/translations";
import type { AppPageProps, Locale } from "@/types";

type Replacements = Readonly<Record<string, string | number>>;
type TranslationDictionary = Record<Locale, Record<string, string>>;

function pickPluralForm(template: string, replacements: Replacements): string {
  const divider = template.indexOf("|");
  if (divider === -1) return template;

  const singular = template.slice(0, divider);
  const plural = template.slice(divider + 1);
  let count: number | undefined;
  if (typeof replacements.count === "number") count = replacements.count;
  else if (typeof replacements.n === "number") count = replacements.n;
  else {
    const numbers = Object.values(replacements).filter(
      (entry): entry is number => typeof entry === "number",
    );
    if (numbers.length === 1) count = numbers[0];
  }

  return count === 1 ? singular : plural;
}

export function translate(
  locale: Locale,
  key: string,
  replacements: Replacements = {},
  dictionary: TranslationDictionary = translations,
): string {
  const raw = dictionary[locale]?.[key] ?? dictionary.en?.[key] ?? key;
  let value = pickPluralForm(raw, replacements);

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
