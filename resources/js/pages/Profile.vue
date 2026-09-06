<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import { useLanguage } from "@/composables/useLanguage";
import MainLayout from "@/layouts/MainLayout.vue";
import type { AppPageProps } from "@/types";

const page = usePage<AppPageProps>();
const { tr } = useLanguage();
const account = computed(() => page.props.auth.user);
</script>

<template>
  <Head :title="`${tr('profile.title')} · Shin Joy Spa Malang`" />

  <MainLayout>
    <section class="page-hero">
      <div class="container">
        <nav class="breadcrumb" :aria-label="tr('nav.breadcrumb')">
          <Link class="ulink" href="/">{{ tr("nav.home") }}</Link>
          <span aria-hidden="true">›</span>
          <span>{{ tr("profile.title") }}</span>
        </nav>
        <h1>{{ tr("profile.title") }}</h1>
        <p class="lead">{{ tr("profile.sub") }}</p>
      </div>
    </section>

    <section class="section" style="padding-top: clamp(2.5rem, 5vw, 3.5rem)">
      <div class="container profile-layout">
        <aside
          class="profile-summary"
          :aria-labelledby="'profile-summary-title'"
        >
          <p class="eyebrow">{{ tr("profile.summary") }}</p>
          <h2 id="profile-summary-title" class="h3">{{ account?.name }}</h2>
          <dl>
            <div>
              <dt>{{ tr("profile.email") }}</dt>
              <dd>{{ account?.email }}</dd>
            </div>
            <div>
              <dt>{{ tr("profile.phone") }}</dt>
              <dd>{{ account?.phone }}</dd>
            </div>
          </dl>
        </aside>

        <section class="history-section" aria-labelledby="booking-history-link">
          <div class="history-section__header">
            <h2 id="booking-history-link">{{ tr("profile.bookingsTitle") }}</h2>
          </div>
          <p class="history-empty history-empty--left">
            <span>{{ tr("profile.bookingsBody") }}</span>
            <Link class="ulink" href="/bookings">{{
              tr("profile.bookingsAction")
            }}</Link>
          </p>
        </section>
      </div>
    </section>
  </MainLayout>
</template>
