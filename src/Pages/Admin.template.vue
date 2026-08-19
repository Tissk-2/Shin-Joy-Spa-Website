<template>
  <div class="admin">
    <aside class="admin-sidebar">
      <div class="admin-brand">
        <svg class="mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(-40 16 17)" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(-20 16 17)" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(20 16 17)" />
          <path class="mark-fill" d="M16 17C13.2 10 13.2 6 16 3.5 18.8 6 18.8 10 16 17Z" transform="rotate(40 16 17)" />
        </svg>
        <span class="wordmark"><b>Shin Joy</b><span>{{ tr('ad.panel') }}</span></span>
      </div>
      <nav class="admin-nav">
        <a href="#" :class="{ active: currentView === 'overview' }" @click.prevent="setView('overview')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
          <span>{{ tr('ad.nav.overview') }}</span>
        </a>
        <a href="#" :class="{ active: currentView === 'services' }" @click.prevent="setView('services')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3c2 3.2 3.4 5.6 3.4 8.2A3.4 3.4 0 0112 14.6a3.4 3.4 0 01-3.4-3.4C8.6 8.6 10 6.2 12 3Z" stroke-linejoin="round"/><path d="M5 20c2-1 4-1.5 7-1.5s5 .5 7 1.5" stroke-linecap="round"/></svg>
          <span>{{ tr('ad.nav.services') }}</span>
        </a>
        <a href="#" :class="{ active: currentView === 'categories' }" @click.prevent="setView('categories')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20.6 13.4 10.6 3.4A2 2 0 0 0 9.2 2.8H4a2 2 0 0 0-2 2v5.2a2 2 0 0 0 .6 1.4l10 10a2 2 0 0 0 2.8 0l5.2-5.2a2 2 0 0 0 0-2.8Z" stroke-linejoin="round"/><circle cx="7" cy="7" r="1.4"/></svg>
          <span>{{ tr('ad.nav.categories') }}</span>
        </a>
        <a href="#" :class="{ active: currentView === 'gallery' }" @click.prevent="setView('gallery')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>{{ tr('ad.nav.gallery') }}</span>
        </a>
        <a href="#" :class="{ active: currentView === 'bookings' }" @click.prevent="setView('bookings')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4" stroke-linecap="round"/></svg>
          <span>{{ tr('ad.nav.bookings') }}</span>
        </a>
        <a href="#" :class="{ active: currentView === 'content' }" @click.prevent="setView('content')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 4h9l5 5v10a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1Z" stroke-linejoin="round"/><path d="M14 4v5h5M8 13h8M8 17h5" stroke-linecap="round"/></svg>
          <span>{{ tr('ad.nav.content') }}</span>
        </a>
      </nav>
      <div class="admin-side-footer">
        <div class="lang-toggle" role="group" aria-label="Language">
          <button type="button" :aria-pressed="language === 'id'" @click="setLanguage('id')">ID</button>
          <button type="button" :aria-pressed="language === 'en'" @click="setLanguage('en')">EN</button>
        </div>
        <button type="button" class="admin-logout" @click="handleLogout">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M15 12H5M9 8l-4 4 4 4M14 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>{{ tr('ad.logout') }}</span>
        </button>
      </div>
    </aside>

    <div class="admin-main">
      <header class="admin-topbar">
        <div>
          <template v-if="currentView === 'overview'">
            <h1>{{ tr('ad.ov.title') }}</h1>
            <p class="sub">{{ tr('ad.ov.sub') }}</p>
          </template>
          <template v-if="currentView === 'services'">
            <h1>{{ tr('ad.sv.title') }}</h1>
            <p class="sub">{{ tr('ad.sv.sub') }}</p>
          </template>
          <template v-if="currentView === 'categories'">
            <h1>{{ tr('ad.cat.title') }}</h1>
            <p class="sub">{{ tr('ad.cat.sub') }}</p>
          </template>
          <template v-if="currentView === 'gallery'">
            <h1>{{ tr('ad.gal.title') }}</h1>
            <p class="sub">{{ tr('ad.gal.sub') }}</p>
          </template>
          <template v-if="currentView === 'bookings'">
            <h1>{{ tr('ad.bk.title') }}</h1>
            <p class="sub">{{ tr('ad.bk.sub') }}</p>
          </template>
          <template v-if="currentView === 'content'">
            <h1>{{ tr('ad.ct.title') }}</h1>
            <p class="sub">{{ tr('ad.ct.sub') }}</p>
          </template>
        </div>
        <div class="admin-topbar-actions">
          <RouterLink class="link-arrow" to="/" target="_blank" rel="noopener"><span>{{ tr('ad.viewsite') }}</span></RouterLink>
          <span class="admin-user"><span class="avatar">A</span><span>Admin</span></span>
        </div>
      </header>

      <div class="admin-content">
        <div v-if="toastMessage" class="toast show" style="position:static;transform:none;margin-bottom:1.4rem;display:inline-flex">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>{{ toastMessage }}</span>
        </div>

        <!-- OVERVIEW -->
        <template v-if="currentView === 'overview'">
          <div class="admin-greeting">
            <h2>{{ tr('ad.greeting') }} Admin</h2>
            <p>{{ todayDateFormatted }}</p>
          </div>
          <div class="stat-grid">
            <div class="stat-card rose"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3c2 3.2 3.4 5.6 3.4 8.2A3.4 3.4 0 0112 14.6a3.4 3.4 0 01-3.4-3.4C8.6 8.6 10 6.2 12 3Z" stroke-linejoin="round"/></svg></span><div class="stat-val">{{ servicesList.length }}</div><div class="stat-label">{{ tr('ad.ov.services') }}</div><div class="stat-sub">...</div></div>
            <div class="stat-card gold"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4" stroke-linecap="round"/></svg></span><div class="stat-val">{{ bookingsList.length }}</div><div class="stat-label">{{ tr('ad.ov.bookings') }}</div><div class="stat-sub">...</div></div>
            <div class="stat-card sage"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div class="stat-val">{{ todayBookingsCount }}</div><div class="stat-label">{{ tr('ad.ov.today') }}</div><div class="stat-sub">...</div></div>
            <div class="stat-card plum"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9v6M18 9v6" stroke-linecap="round"/></svg></span><div class="stat-val">{{ formatRupiah(totalRevenue) }}</div><div class="stat-label">{{ tr('ad.ov.revenue') }}</div><div class="stat-sub">...</div></div>
          </div>
          <div class="chart-row">
            <div class="chart-card">
              <div class="chart-card-head"><h2>{{ tr('ad.ov.chartBookings') }}</h2><span class="sub">{{ tr('ad.ov.chartBookingsSub') }}</span></div>
              <div class="chart-empty">{{ tr('ad.ov.chartEmpty') }}</div>
            </div>
            <div class="chart-card">
              <div class="chart-card-head"><h2>{{ tr('ad.ov.chartCats') }}</h2></div>
              <div class="donut-wrap">
                <!-- SVG placeholder for charts -->
                <svg viewBox="0 0 100 100" class="donut-svg"><circle cx="50" cy="50" r="40" fill="none" stroke="#eee" stroke-width="10" /></svg>
                <div class="donut-legend">
                   <!-- categories -->
                </div>
              </div>
            </div>
          </div>
          <div class="panel">
            <div class="panel-head"><h2>{{ tr('ad.ov.recent') }}</h2></div>
            <div class="adm-table-wrap" v-if="recentBookings.length">
              <table class="adm-table">
                <thead><tr><th>{{ tr('ad.bk.colCustomer') }}</th><th>{{ tr('ad.bk.colService') }}</th><th>{{ tr('ad.bk.colSchedule') }}</th><th>{{ tr('ad.bk.colStatus') }}</th></tr></thead>
                <tbody>
                  <tr v-for="b in recentBookings" :key="b.id">
                    <td><div class="cust"><span class="cust-avatar">{{ getInitials(b.customerName) }}</span><div class="cust-meta"><b>{{ b.customerName }}</b><small>{{ b.phone }}</small></div></div></td>
                    <td>{{ b.serviceNameEn }}</td>
                    <td>{{ b.bookingDate }} &middot; {{ b.bookingTime }}</td>
                    <td><span :class="['status-badge', 'status-' + b.status]">{{ tr('ad.bk.status' + b.status.charAt(0).toUpperCase() + b.status.slice(1)) }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="adm-empty">{{ tr('ad.ov.empty') }}</div>
          </div>
        </template>

        <!-- SERVICES -->
        <template v-if="currentView === 'services'">
          <template v-if="editingService">
            <div class="panel">
              <div class="panel-head"><div><h2>{{ editingService.id === 0 ? tr('ad.f.addTitle') : tr('ad.f.editTitle') }}</h2></div></div>
              <form class="content-form" @submit.prevent="saveService">
                <div class="content-grid">
                  <div class="field full"><label>{{ tr('ad.f.photos') }}</label>
                    <div class="svc-slots">
                      <div class="svc-slot" v-for="n in 3" :key="n">
                        <span class="svc-slot__tag">{{ tr('ad.f.photoN').replace('{n}', n) }}{{ n === 1 ? ' · ' + tr('ad.f.cover') : '' }}</span>
                        <img class="svc-slot__img" :src="editingService.gallery[n-1] || '/img/massage.jpg'" alt="">
                        <input type="file" accept="image/jpeg,image/png,image/webp" @change="(e) => handleServiceImageUpload(e, n-1)">
                      </div>
                    </div>
                    <p class="form-hint">{{ tr('ad.f.photosHint') }}</p>
                  </div>
                  <div class="field"><label>{{ tr('ad.f.nameId') }} <span class="req">*</span></label><input type="text" v-model="editingService.nameId" required></div>
                  <div class="field"><label>{{ tr('ad.f.nameEn') }} <span class="req">*</span></label><input type="text" v-model="editingService.nameEn" required></div>
                  <div class="field full"><label>{{ tr('ad.f.cat') }}</label>
                    <select v-model="editingService.category">
                      <option v-for="c in categoriesList" :key="c.slug" :value="c.slug">{{ c.nameEn }}</option>
                    </select>
                  </div>
                  <div class="field"><label>{{ tr('ad.f.dur') }} <span class="req">*</span></label><input type="number" v-model="editingService.duration" required></div>
                  <div class="field"><label>{{ tr('ad.f.price') }} <span class="req">*</span></label><input type="number" v-model="editingService.price" required></div>
                  <div class="field full"><label>{{ tr('ad.f.descId') }}</label><textarea rows="2" v-model="editingService.descId"></textarea></div>
                  <div class="field full"><label>{{ tr('ad.f.descEn') }}</label><textarea rows="2" v-model="editingService.descEn"></textarea></div>
                </div>
                <div class="content-actions">
                  <button type="button" class="btn btn--ghost" @click="editingService = null">{{ tr('ad.f.cancel') }}</button>
                  <button type="submit" class="btn btn--primary">{{ tr('ad.f.save') }}</button>
                </div>
              </form>
            </div>
          </template>
          <template v-else>
            <div class="panel">
              <div class="panel-head">
                <span class="sub">{{ servicesList.length }} services</span>
                <div class="panel-head-actions"><button type="button" class="btn btn--primary" @click="openAddService"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg><span>{{ tr('ad.sv.add') }}</span></button></div>
              </div>
              <div class="adm-toolbar">
                <div class="adm-search">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4" stroke-linecap="round"/></svg>
                  <input type="search" placeholder="Search..." v-model="servicesSearchQuery">
                </div>
              </div>
              <div class="adm-table-wrap">
                <table class="adm-table">
                  <thead><tr><th>{{ tr('ad.sv.colService') }}</th><th>{{ tr('ad.sv.colCat') }}</th><th>{{ tr('ad.sv.colDur') }}</th><th>{{ tr('ad.sv.colPrice') }}</th><th class="right">{{ tr('ad.sv.colActions') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="s in servicesList" :key="s.id">
                      <td><div class="adm-cell-service"><img :src="s.image" alt="" loading="lazy"><div><b>{{ s.nameEn }}</b><small>{{ s.nameId }}</small></div></div></td>
                      <td><span class="cat-chip">{{ s.category }}</span></td>
                      <td>{{ s.duration }} min</td>
                      <td class="price-cell">{{ formatRupiah(s.price) }}</td>
                      <td><div class="row-actions">
                        <button type="button" class="icon-btn" @click="openEditService(s)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 20h4L18 10l-4-4L4 16v4Z" stroke-linejoin="round"/><path d="M13 6l4 4"/></svg></button>
                        <button type="button" class="icon-btn danger" @click="deleteService(s.id)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      </div></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </template>
        </template>

        <!-- CATEGORIES -->
        <template v-if="currentView === 'categories'">
          <template v-if="editingCategory">
            <div class="panel">
              <div class="panel-head"><div><h2>{{ editingCategory.id === 0 ? tr('ad.cat.addTitle') : tr('ad.cat.editTitle') }}</h2></div></div>
              <form class="content-form" @submit.prevent="saveCategory">
                <div class="content-grid">
                  <div class="field"><label>{{ tr('ad.f.nameId') }} <span class="req">*</span></label><input type="text" v-model="editingCategory.nameId" required></div>
                  <div class="field"><label>{{ tr('ad.f.nameEn') }} <span class="req">*</span></label><input type="text" v-model="editingCategory.nameEn" required></div>
                </div>
                <div class="content-actions">
                  <button type="button" class="btn btn--ghost" @click="editingCategory = null">{{ tr('ad.f.cancel') }}</button>
                  <button type="submit" class="btn btn--primary">{{ tr('ad.f.save') }}</button>
                </div>
              </form>
            </div>
          </template>
          <template v-else>
            <div class="panel">
              <div class="panel-head">
                <span class="sub">{{ categoriesList.length }} {{ tr('ad.nav.categories') }}</span>
                <div class="panel-head-actions"><button type="button" class="btn btn--primary" @click="openAddCategory"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg><span>{{ tr('ad.cat.add') }}</span></button></div>
              </div>
              <div class="adm-table-wrap">
                <table class="adm-table">
                  <thead><tr><th>{{ tr('ad.cat.colName') }}</th><th>{{ tr('ad.cat.colCount') }}</th><th class="right">{{ tr('ad.cat.colActions') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="c in categoriesList" :key="c.id">
                      <td><div class="adm-cell-service"><img :src="c.image || '/img/massage.jpg'" alt="" loading="lazy"><div><b><span class="cat-swatch" style="background:#555"></span>{{ c.nameEn }}</b><small>{{ c.nameId }}</small></div></div></td>
                      <td>0</td>
                      <td><div class="row-actions">
                        <button type="button" class="icon-btn" @click="openEditCategory(c)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 20h4L18 10l-4-4L4 16v4Z" stroke-linejoin="round"/><path d="M13 6l4 4"/></svg></button>
                        <button type="button" class="icon-btn danger" @click="deleteCategory(c)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                      </div></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </template>
        </template>

        <!-- GALLERY -->
        <template v-if="currentView === 'gallery'">
          <div class="panel">
            <div class="panel-head"><span class="sub">{{ galleryList.length }} {{ tr('ad.nav.gallery') }}</span></div>
            <form class="content-form">
              <div class="gal-add">
                <input type="file" accept="image/jpeg,image/png,image/webp">
                <button type="button" class="btn btn--primary" @click="showToast(tr('ad.toast.added'))"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg><span>{{ tr('ad.gal.add') }}</span></button>
              </div>
            </form>
            <div class="gal-grid">
              <div class="gal-item" v-for="(g, i) in galleryList" :key="i">
                <img :src="g" alt="" loading="lazy">
                <div class="gal-item__bar">
                  <button class="icon-btn" @click="moveGalleryImage(i, -1)" :disabled="i === 0">&uarr;</button>
                  <button class="icon-btn" @click="moveGalleryImage(i, 1)" :disabled="i === galleryList.length - 1">&darr;</button>
                  <button class="icon-btn danger" @click="deleteGalleryImage(i)">&times;</button>
                </div>
              </div>
            </div>
          </div>
        </template>

        <!-- BOOKINGS -->
        <template v-if="currentView === 'bookings'">
          <div class="panel">
            <div class="panel-head"><span class="sub">{{ bookingsList.length }} {{ tr('ad.nav.bookings') }}</span></div>
            <div class="adm-toolbar">
               <div class="adm-search">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4" stroke-linecap="round"/></svg>
                  <input type="search" placeholder="Search...">
               </div>
            </div>
            <div class="adm-table-wrap">
              <table class="adm-table">
                <thead><tr><th>{{ tr('ad.bk.colCustomer') }}</th><th>{{ tr('ad.bk.colService') }}</th><th>{{ tr('ad.bk.colSchedule') }}</th><th>{{ tr('ad.bk.colGuests') }}</th><th>{{ tr('ad.bk.colStatus') }}</th><th class="right">{{ tr('ad.bk.colActions') }}</th></tr></thead>
                <tbody>
                  <tr v-for="b in bookingsList" :key="b.id">
                    <td><div class="cust"><span class="cust-avatar">{{ getInitials(b.customerName) }}</span><div class="cust-meta"><b>{{ b.customerName }}</b><small>{{ b.phone }}</small></div></div></td>
                    <td>{{ b.serviceNameEn }}</td>
                    <td>{{ b.bookingDate }} &middot; {{ b.bookingTime }}</td>
                    <td>{{ b.guests }}</td>
                    <td>
                      <select :class="['status-select', 'status-' + b.status]" v-model="b.status" @change="updateBookingStatus(b.id, b.status)">
                        <option value="pending">{{ tr('ad.bk.statusPending') }}</option>
                        <option value="confirmed">{{ tr('ad.bk.statusConfirmed') }}</option>
                        <option value="done">{{ tr('ad.bk.statusDone') }}</option>
                      </select>
                    </td>
                    <td><div class="row-actions"><button type="button" class="icon-btn danger" @click="deleteBooking(b.id)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>

        <!-- CONTENT -->
        <template v-if="currentView === 'content'">
          <form @submit.prevent="saveAllContent">
            <div id="content-sections">
              <div class="panel">
                <div class="panel-head"><div><h2>{{ tr('ad.ct.secHero') }}</h2></div></div>
                <div class="content-section-body"><div class="content-grid">
                  <div class="field"><label>{{ tr('ad.ct.heading') }} (ID)</label><input type="text" v-model="contentFields['hero.title'].id"></div>
                  <div class="field"><label>{{ tr('ad.ct.heading') }} (EN)</label><input type="text" v-model="contentFields['hero.title'].en"></div>
                  <div class="field full"><label>{{ tr('ad.ct.subt') }} (ID)</label><textarea rows="2" v-model="contentFields['hero.sub'].id"></textarea></div>
                  <div class="field full"><label>{{ tr('ad.ct.subt') }} (EN)</label><textarea rows="2" v-model="contentFields['hero.sub'].en"></textarea></div>
                </div></div>
              </div>
            </div>
            <div class="content-actions">
              <button type="button" class="btn btn--ghost" @click="resetAllContent">{{ tr('ad.ct.resetAll') }}</button>
              <button type="submit" class="btn btn--primary">{{ tr('ad.ct.saveAll') }}</button>
            </div>
          </form>
        </template>

      </div>
    </div>
  </div>
</template>
