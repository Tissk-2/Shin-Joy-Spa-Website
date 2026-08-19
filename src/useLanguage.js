import { ref } from 'vue';
import translations from './Data/translations.js';

const supported = ['id', 'en'];
export const language = ref('id');

export function tr(key, replacements = {}) {
  let value = translations[language.value]?.[key]
    ?? translations.en?.[key]
    ?? key;

  for (const [name, replacement] of Object.entries(replacements)) {
    value = value.replaceAll(`{${name}}`, String(replacement));
  }

  return value;
}

export function setLanguage(next) {
  if (!supported.includes(next)) return;

  language.value = next;
  if (typeof document !== 'undefined') document.documentElement.lang = next;
  if (typeof localStorage !== 'undefined') localStorage.setItem('shinjoy-language', next);
}

export function initializeLanguage() {
  if (typeof localStorage === 'undefined') return;
  setLanguage(localStorage.getItem('shinjoy-language') || 'id');
}
