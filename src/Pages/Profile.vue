<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import MainLayout from '../Layouts/MainLayout.vue';
import { language, tr } from '../useLanguage.js';
import { currentUser, initializeAuth } from '../useAuth.js';
import { createBookingStore } from '../bookingHistory.js';
import { partitionBookings } from '../bookingHistory.js';

initializeAuth();

const bookingStore = createBookingStore();
const account = computed(() => currentUser.value);
const bookingResult = computed(() => (
  currentUser.value
    ? bookingStore.listForUser(currentUser.value.id)
    : { ok: true, bookings: [] }
));
const historyUnavailable = computed(() => !bookingResult.value.ok);
const history = computed(() => partitionBookings(bookingResult.value.bookings, new Date()));
const dateFormatter = computed(() => new Intl.DateTimeFormat(
  language.value === 'id' ? 'id-ID' : 'en-GB',
  { dateStyle: 'long' },
));

function formatDate(date) {
  return dateFormatter.value.format(new Date(`${date}T00:00:00`));
}

function serviceName(booking) {
  return language.value === 'id' ? booking.service.nameId : booking.service.nameEn;
}
</script>

<template>
  <Teleport to="head">
    <title>{{ `${tr('profile.title')} · Shin Joy Spa Malang` }}</title>
  </Teleport>

  <MainLayout>
    <section class="page-hero">
      <div class="container">
        <nav class="breadcrumb" :aria-label="tr('nav.breadcrumb')">
          <RouterLink class="ulink" to="/">{{ tr('nav.home') }}</RouterLink>
          <span aria-hidden="true">›</span>
          <span>{{ tr('profile.title') }}</span>
        </nav>
        <h1>{{ tr('profile.title') }}</h1>
        <p class="lead">{{ tr('profile.sub') }}</p>
      </div>
    </section>

    <section class="section" style="padding-top:clamp(2.5rem,5vw,3.5rem)">
      <div class="container profile-layout">
        <aside class="profile-summary" :aria-labelledby="'profile-summary-title'">
          <p class="eyebrow">{{ tr('profile.summary') }}</p>
          <h2 id="profile-summary-title" class="h3">{{ account?.name }}</h2>
          <dl>
            <div>
              <dt>{{ tr('profile.email') }}</dt>
              <dd>{{ account?.email }}</dd>
            </div>
            <div>
              <dt>{{ tr('profile.phone') }}</dt>
              <dd>{{ account?.phone }}</dd>
            </div>
          </dl>
        </aside>

        <div
          v-if="historyUnavailable"
          class="history-storage-error auth-notice"
          role="status"
          aria-live="polite"
        >
          {{ tr('profile.historyUnavailable') }}
        </div>

        <div v-else>
          <section class="history-section" aria-labelledby="upcoming-history-title">
            <div class="history-section__header">
              <div>
                <p class="eyebrow">{{ tr('profile.statusUpcoming') }}</p>
                <h2 id="upcoming-history-title">{{ tr('profile.upcoming') }}</h2>
              </div>
            </div>

            <div v-if="history.upcoming.length" class="history-list">
              <article v-for="booking in history.upcoming" :key="booking.id" class="history-card">
                <div class="history-card__heading">
                  <div>
                    <p class="history-status history-status--upcoming">{{ tr('profile.statusUpcoming') }}</p>
                    <h3>{{ serviceName(booking) }}</h3>
                  </div>
                  <strong>Rp {{ booking.service.price.toLocaleString('id-ID') }}</strong>
                </div>
                <dl class="history-meta">
                  <div>
                    <dt>{{ tr('profile.date') }}</dt>
                    <dd><time :datetime="booking.date">{{ formatDate(booking.date) }}</time></dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.time') }}</dt>
                    <dd>{{ booking.time }} WIB</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.duration') }}</dt>
                    <dd>{{ booking.service.duration }} {{ tr('d.min') }}</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.guests') }}</dt>
                    <dd>{{ tr(`b.guest${booking.guests}`) }}</dd>
                  </div>
                </dl>
                <dl class="history-meta history-meta--contact">
                  <div>
                    <dt>{{ tr('profile.contact') }}</dt>
                    <dd>{{ booking.name }}</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.email') }}</dt>
                    <dd>{{ booking.email || tr('profile.notProvided') }}</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.phone') }}</dt>
                    <dd>{{ booking.phone }}</dd>
                  </div>
                </dl>
              </article>
            </div>
            <p v-else class="history-empty">
              {{ tr('profile.emptyUpcoming') }}
              <RouterLink class="ulink" to="/booking">{{ tr('profile.emptyAction') }}</RouterLink>
            </p>
          </section>

          <section class="history-section" aria-labelledby="past-history-title">
            <div class="history-section__header">
              <div>
                <p class="eyebrow">{{ tr('profile.statusPast') }}</p>
                <h2 id="past-history-title">{{ tr('profile.past') }}</h2>
              </div>
            </div>

            <div v-if="history.past.length" class="history-list">
              <article v-for="booking in history.past" :key="booking.id" class="history-card">
                <div class="history-card__heading">
                  <div>
                    <p class="history-status history-status--past">{{ tr('profile.statusPast') }}</p>
                    <h3>{{ serviceName(booking) }}</h3>
                  </div>
                  <strong>Rp {{ booking.service.price.toLocaleString('id-ID') }}</strong>
                </div>
                <dl class="history-meta">
                  <div>
                    <dt>{{ tr('profile.date') }}</dt>
                    <dd><time :datetime="booking.date">{{ formatDate(booking.date) }}</time></dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.time') }}</dt>
                    <dd>{{ booking.time }} WIB</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.duration') }}</dt>
                    <dd>{{ booking.service.duration }} {{ tr('d.min') }}</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.guests') }}</dt>
                    <dd>{{ tr(`b.guest${booking.guests}`) }}</dd>
                  </div>
                </dl>
                <dl class="history-meta history-meta--contact">
                  <div>
                    <dt>{{ tr('profile.contact') }}</dt>
                    <dd>{{ booking.name }}</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.email') }}</dt>
                    <dd>{{ booking.email || tr('profile.notProvided') }}</dd>
                  </div>
                  <div>
                    <dt>{{ tr('profile.phone') }}</dt>
                    <dd>{{ booking.phone }}</dd>
                  </div>
                </dl>
              </article>
            </div>
            <p v-else class="history-empty">
              {{ tr('profile.emptyPast') }}
              <RouterLink class="ulink" to="/booking">{{ tr('profile.emptyAction') }}</RouterLink>
            </p>
          </section>
        </div>
      </div>
    </section>
  </MainLayout>
</template>
