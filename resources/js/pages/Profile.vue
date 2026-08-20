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

        <div>
          <section
            class="history-section"
            aria-labelledby="upcoming-history-title"
          >
            <div class="history-section__header">
              <div>
                <p class="eyebrow">{{ tr("profile.statusUpcoming") }}</p>
                <h2 id="upcoming-history-title">
                  {{ tr("profile.upcoming") }}
                </h2>
              </div>
            </div>
            <p class="history-empty">
              {{ tr("profile.emptyUpcoming") }}
              <Link class="ulink" href="/booking">{{
                tr("profile.emptyAction")
              }}</Link>
            </p>
          </section>

          <section class="history-section" aria-labelledby="past-history-title">
            <div class="history-section__header">
              <div>
                <p class="eyebrow">{{ tr("profile.statusPast") }}</p>
                <h2 id="past-history-title">{{ tr("profile.past") }}</h2>
              </div>
            </div>
            <p class="history-empty">
              {{ tr("profile.emptyPast") }}
              <Link class="ulink" href="/booking">{{
                tr("profile.emptyAction")
              }}</Link>
            </p>
          </section>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
