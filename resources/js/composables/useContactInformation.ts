import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useLanguage } from "@/composables/useLanguage";
import { resolveContactInformation } from "@/lib/contactInformation";
import type { AppPageProps } from "@/types";

export function useContactInformation() {
  const page = usePage<AppPageProps>();
  const { language } = useLanguage();

  const contactInformation = computed(() =>
    resolveContactInformation(language.value, page.props.content),
  );

  return contactInformation;
}
