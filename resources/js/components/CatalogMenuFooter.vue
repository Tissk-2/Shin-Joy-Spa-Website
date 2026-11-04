<script setup lang="ts">
import { useContactInformation } from "@/composables/useContactInformation";
import { useLanguage } from "@/composables/useLanguage";

const { tr } = useLanguage();
const contactInformation = useContactInformation();
</script>

<template>
  <footer class="catalog-menu-footer">
    <address>
      <strong>{{ tr("loc.addresst") }}</strong>
      <span>{{ contactInformation.address }}</span>
    </address>
    <p class="menu-policy" role="note">{{ tr("menu.hotWaterPolicy") }}</p>
  </footer>
</template>
