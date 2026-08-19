<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { language, setLanguage, tr } from '../useLanguage.js';
import initialServices from '../Data/services.js';
import initialCategories from '../Data/categories.js';
import initialGallery from '../Data/gallery.js';

const route = useRoute();
const router = useRouter();

// Active tab view: 'overview' | 'services' | 'categories' | 'gallery' | 'bookings' | 'content'
const currentView = ref(route.query.view || 'overview');
const mobileSidebarOpen = ref(false);

// Toast notification state
const toastMessage = ref('');
let toastTimer = null;
function showToast(msg) {
  toastMessage.value = msg;
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    toastMessage.value = '';
  }, 3500);
}

// ----------------------------------------------------
// DUMMY STATE: Services
// ----------------------------------------------------
const servicesList = ref(
  initialServices.map((s, idx) => ({
    id: idx + 1,
    slug: s.slug,
    category: s.category,
    nameId: s.nameId,
    nameEn: s.nameEn,
    descId: s.descId,
    descEn: s.descEn,
    longId: s.longId || s.descId,
    longEn: s.longEn || s.descEn,
    goodId: s.goodId || '',
    goodEn: s.goodEn || '',
    duration: s.duration,
    price: s.price,
    image: s.image,
    gallery: s.gallery && s.gallery.length ? s.gallery.map(g => (g.startsWith('/img/') ? g : `/img/${g}`)) : [s.image, '/img/oil.jpg', '/img/stilllife.jpg'],
  }))
);

const serviceSearch = ref('');
const serviceCategoryFilter = ref('all');
const servicePage = ref(1);
const servicesPerPage = 6;
const editingService = ref(null); // null = list mode, object = add/edit mode

const filteredServices = computed(() => {
  return servicesList.value.filter((s) => {
    const matchCat = serviceCategoryFilter.value === 'all' || s.category === serviceCategoryFilter.value;
    const query = serviceSearch.value.toLowerCase().trim();
    const matchQuery =
      !query ||
      s.nameId.toLowerCase().includes(query) ||
      s.nameEn.toLowerCase().includes(query) ||
      s.category.toLowerCase().includes(query);
    return matchCat && matchQuery;
  });
});

const paginatedServices = computed(() => {
  const start = (servicePage.value - 1) * servicesPerPage;
  return filteredServices.value.slice(start, start + servicesPerPage);
});

const totalServicePages = computed(() => Math.max(1, Math.ceil(filteredServices.value.length / servicesPerPage)));

function openAddService() {
  editingService.value = {
    id: 0,
    slug: '',
    nameId: '',
    nameEn: '',
    category: categoriesList.value[0]?.slug || 'massage',
    duration: 60,
    price: 150000,
    descId: '',
    descEn: '',
    longId: '',
    longEn: '',
    goodId: '',
    goodEn: '',
    image: '/img/massage.jpg',
    gallery: ['/img/massage.jpg', '/img/oil.jpg', '/img/stilllife.jpg'],
  };
}

function openEditService(service) {
  editingService.value = JSON.parse(JSON.stringify(service));
  if (!editingService.value.gallery || !editingService.value.gallery.length) {
    editingService.value.gallery = [service.image, '/img/oil.jpg', '/img/stilllife.jpg'];
  }
}

function handleServicePhotoUpload(slotIndex, event) {
  const file = event.target.files?.[0];
  if (file && editingService.value) {
    const reader = new FileReader();
    reader.onload = (e) => {
      const result = e.target?.result;
      if (typeof result === 'string') {
        editingService.value.gallery[slotIndex] = result;
        if (slotIndex === 0) {
          editingService.value.image = result;
        }
      }
    };
    reader.readAsDataURL(file);
  }
}

function saveService() {
  if (!editingService.value.nameId || !editingService.value.nameEn || editingService.value.duration <= 0 || editingService.value.price < 0) {
    alert(tr('ad.f.err'));
    return;
  }

  if (editingService.value.id === 0) {
    // Add new
    const newId = servicesList.value.length ? Math.max(...servicesList.value.map((s) => s.id)) + 1 : 1;
    const newSvc = {
      ...editingService.value,
      id: newId,
      slug: editingService.value.nameEn.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || `service-${newId}`,
      image: editingService.value.gallery[0] || '/img/massage.jpg',
    };
    servicesList.value.unshift(newSvc);
    showToast(tr('ad.toast.added'));
  } else {
    // Update existing
    const idx = servicesList.value.findIndex((s) => s.id === editingService.value.id);
    if (idx !== -1) {
      servicesList.value[idx] = {
        ...editingService.value,
        image: editingService.value.gallery[0] || servicesList.value[idx].image,
      };
      showToast(tr('ad.toast.updated'));
    }
  }
  editingService.value = null;
}

function deleteService(id) {
  if (confirm(tr('ad.sv.deleteConfirm'))) {
    servicesList.value = servicesList.value.filter((s) => s.id !== id);
    showToast(tr('ad.toast.deleted'));
  }
}

// ----------------------------------------------------
// DUMMY STATE: Categories
// ----------------------------------------------------
const categoryColors = {
  massage: '#b87c7c',
  facial: '#d4af37',
  body: '#87a987',
  package: '#7c5369',
};

const categoriesList = ref(
  initialCategories.map((c, idx) => ({
    id: idx + 1,
    slug: c.slug,
    nameId: c.nameId,
    nameEn: c.nameEn,
    color: categoryColors[c.slug] || '#b87c7c',
    image: c.image || '/img/massage.jpg',
  }))
);

const editingCategory = ref(null);

function openAddCategory() {
  editingCategory.value = {
    id: 0,
    slug: '',
    nameId: '',
    nameEn: '',
    color: '#b87c7c',
    image: '/img/massage.jpg',
  };
}

function openEditCategory(cat) {
  editingCategory.value = JSON.parse(JSON.stringify(cat));
}

function getServiceCountByCategory(slug) {
  return servicesList.value.filter((s) => s.category === slug).length;
}

function saveCategory() {
  if (!editingCategory.value.nameId || !editingCategory.value.nameEn) {
    alert(tr('ad.cat.err'));
    return;
  }

  if (editingCategory.value.id === 0) {
    const newId = categoriesList.value.length ? Math.max(...categoriesList.value.map((c) => c.id)) + 1 : 1;
    const slug = editingCategory.value.nameEn.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || `category-${newId}`;
    const newCat = {
      ...editingCategory.value,
      id: newId,
      slug,
      color: editingCategory.value.color || '#b87c7c',
      image: '/img/massage.jpg',
    };
    categoriesList.value.push(newCat);
    showToast(tr('ad.cat.toastAdded'));
  } else {
    const idx = categoriesList.value.findIndex((c) => c.id === editingCategory.value.id);
    if (idx !== -1) {
      categoriesList.value[idx] = { ...editingCategory.value };
      showToast(tr('ad.cat.toastUpdated'));
    }
  }
  editingCategory.value = null;
}

function deleteCategory(cat) {
  const inUse = getServiceCountByCategory(cat.slug);
  if (inUse > 0) {
    alert(tr('ad.cat.inUse'));
    return;
  }
  if (confirm(tr('ad.cat.deleteConfirm'))) {
    categoriesList.value = categoriesList.value.filter((c) => c.id !== cat.id);
    showToast(tr('ad.cat.toastDeleted'));
  }
}

// ----------------------------------------------------
// DUMMY STATE: Gallery
// ----------------------------------------------------
const galleryList = ref([...initialGallery]);

function handleAddGalleryImage(event) {
  const file = event.target.files?.[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = (e) => {
      const result = e.target?.result;
      if (typeof result === 'string') {
        galleryList.value.push(result);
        showToast(tr('ad.gal.toastAdded'));
      }
    };
    reader.readAsDataURL(file);
    event.target.value = '';
  }
}

function moveGalleryImage(index, direction) {
  const newIndex = direction === 'up' ? index - 1 : index + 1;
  if (newIndex < 0 || newIndex >= galleryList.value.length) return;
  const item = galleryList.value.splice(index, 1)[0];
  galleryList.value.splice(newIndex, 0, item);
}

function deleteGalleryImage(index) {
  if (confirm(tr('ad.gal.deleteConfirm'))) {
    galleryList.value.splice(index, 1);
    showToast(tr('ad.gal.toastDeleted'));
  }
}

// ----------------------------------------------------
// DUMMY STATE: Bookings
// ----------------------------------------------------
const initialDummyBookings = [
  {
    id: 101,
    customerName: 'Siti Rahmawati',
    phone: '+62 812 3456 7891',
    serviceNameId: 'Pijat Tradisional Jawa',
    serviceNameEn: 'Traditional Javanese Massage',
    category: 'massage',
    bookingDate: '2026-08-17',
    bookingTime: '10:00',
    guests: 2,
    price: 370000,
    status: 'confirmed',
  },
  {
    id: 102,
    customerName: 'Budi Santoso',
    phone: '+62 813 9876 5432',
    serviceNameId: 'Pijat Batu Panas',
    serviceNameEn: 'Hot Stone Massage',
    category: 'massage',
    bookingDate: '2026-08-17',
    bookingTime: '14:00',
    guests: 1,
    price: 220000,
    status: 'pending',
  },
  {
    id: 103,
    customerName: 'Clara Wijaya',
    phone: '+62 819 8765 4321',
    serviceNameId: 'Facial Pencerah',
    serviceNameEn: 'Brightening Facial',
    category: 'facial',
    bookingDate: '2026-08-18',
    bookingTime: '11:30',
    guests: 1,
    price: 175000,
    status: 'confirmed',
  },
  {
    id: 104,
    customerName: 'Dewi Lestari',
    phone: '+62 821 5555 1234',
    serviceNameId: 'Ritual Rempah Jawa',
    serviceNameEn: 'Javanese Herbal Ritual',
    category: 'package',
    bookingDate: '2026-08-19',
    bookingTime: '15:00',
    guests: 2,
    price: 650000,
    status: 'pending',
  },
  {
    id: 105,
    customerName: 'Ahmad Fauzi',
    phone: '+62 812 9988 7766',
    serviceNameId: 'Pijat Aromaterapi',
    serviceNameEn: 'Aromatherapy Massage',
    category: 'massage',
    bookingDate: '2026-08-20',
    bookingTime: '13:00',
    guests: 1,
    price: 150000,
    status: 'confirmed',
  },
  {
    id: 106,
    customerName: 'Maya Indah',
    phone: '+62 878 1122 3344',
    serviceNameId: 'Lulur Tradisional Jawa',
    serviceNameEn: 'Traditional Javanese Scrub',
    category: 'body',
    bookingDate: '2026-08-16',
    bookingTime: '16:00',
    guests: 1,
    price: 165000,
    status: 'done',
  },
  {
    id: 107,
    customerName: 'Rina Kusuma',
    phone: '+62 856 7788 9900',
    serviceNameId: 'Paket Relaksasi Total',
    serviceNameEn: 'Total Relaxation Package',
    category: 'package',
    bookingDate: '2026-08-15',
    bookingTime: '09:30',
    guests: 2,
    price: 720000,
    status: 'done',
  },
];

const bookingsList = ref([...initialDummyBookings]);
const bookingSearch = ref('');
const bookingStatusFilter = ref('all');
const bookingPage = ref(1);
const bookingsPerPage = 6;

const filteredBookings = computed(() => {
  return bookingsList.value.filter((b) => {
    const matchStatus = bookingStatusFilter.value === 'all' || b.status === bookingStatusFilter.value;
    const query = bookingSearch.value.toLowerCase().trim();
    const matchQuery =
      !query ||
      b.customerName.toLowerCase().includes(query) ||
      b.phone.toLowerCase().includes(query) ||
      b.serviceNameId.toLowerCase().includes(query) ||
      b.serviceNameEn.toLowerCase().includes(query);
    return matchStatus && matchQuery;
  });
});

const paginatedBookings = computed(() => {
  const start = (bookingPage.value - 1) * bookingsPerPage;
  return filteredBookings.value.slice(start, start + bookingsPerPage);
});

const totalBookingPages = computed(() => Math.max(1, Math.ceil(filteredBookings.value.length / bookingsPerPage)));

function updateBookingStatus(booking, newStatus) {
  booking.status = newStatus;
  showToast(tr('ad.toast.status'));
}

function deleteBooking(id) {
  if (confirm(tr('ad.bk.deleteConfirm'))) {
    bookingsList.value = bookingsList.value.filter((b) => b.id !== id);
    showToast(tr('ad.bk.delete'));
  }
}

// ----------------------------------------------------
// DUMMY STATE: Content Overrides
// ----------------------------------------------------
const contentFields = reactive({
  'hero.eyebrow': { id: 'Shin Joy Spa · Malang', en: 'Shin Joy Spa · Malang' },
  'hero.title': { id: 'Ketenangan yang dirawat dengan sepenuh hati', en: 'Calm, tended with care' },
  'hero.sub': { id: 'Pijat tradisional, perawatan wajah, dan ritual spa dalam ruang yang hangat dan tenang.', en: 'Traditional massage, facials, and spa rituals in a warm, quiet space.' },
  'about.eyebrow': { id: 'Tentang Shin Joy', en: 'About Shin Joy' },
  'about.title': { id: 'Sebuah jeda kecil dari hiruk kota', en: 'A small pause from the busy city' },
  'about.body': { id: 'Shin Joy Spa lahir dari satu keyakinan sederhana: setiap orang berhak atas waktu untuk bernapas.', en: 'Shin Joy Spa began with one simple belief: everyone deserves a moment to breathe.' },
  'loc.address': { id: 'Jl. Ijen No. 12, Klojen, Kota Malang, Jawa Timur 65119', en: 'Jl. Ijen No. 12, Klojen, Malang City, East Java 65119' },
  'loc.hours': { id: 'Setiap hari · 09.00 – 21.00 WIB', en: 'Every day · 9:00 AM – 9:00 PM WIB' },
  'loc.phone': { id: '+62 812 3456 7890', en: '+62 812 3456 7890' },
  'social.instagram': { id: 'https://instagram.com/shinjoyspa', en: 'https://instagram.com/shinjoyspa' },
  'social.whatsapp': { id: 'https://wa.me/6281234567890', en: 'https://wa.me/6281234567890' },
});

function saveAllContent() {
  showToast(tr('ad.ct.toastSaved'));
}

function resetAllContent() {
  if (confirm(tr('ad.ct.resetAllConfirm'))) {
    showToast(tr('ad.ct.toastReset'));
  }
}

// ----------------------------------------------------
// OVERVIEW STATS & CHARTS COMPUTATION
// ----------------------------------------------------
const totalRevenue = computed(() => {
  return bookingsList.value.reduce((sum, b) => sum + (b.price || 0), 0);
});

const activeCategoriesCount = computed(() => {
  return new Set(servicesList.value.map(s => s.category)).size;
});

const pendingBookingsCount = computed(() => {
  return bookingsList.value.filter(b => b.status === 'pending').length;
});

const within7DaysCount = computed(() => {
  const today = new Date();
  const nextWeek = new Date();
  nextWeek.setDate(nextWeek.getDate() + 7);
  const todayStr = today.toISOString().split('T')[0];
  const nextWeekStr = nextWeek.toISOString().split('T')[0];
  return bookingsList.value.filter(b => b.bookingDate >= todayStr && b.bookingDate < nextWeekStr).length;
});

const todayDateFormatted = computed(() => {
  return new Intl.DateTimeFormat('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(new Date());
});

const todayBookingsCount = computed(() => {
  return bookingsList.value.filter((b) => b.bookingDate === '2026-08-17').length;
});

const next7DaysBookingsCount = computed(() => {
  return bookingsList.value.length;
});

const recentBookings = computed(() => {
  return bookingsList.value.slice(0, 5);
});

// 14-day dummy schedule distribution for SVG bar chart
const chartDays = computed(() => {
  const days = [];
  const baseDate = new Date(2026, 7, 17);
  for (let i = 0; i < 14; i++) {
    const d = new Date(baseDate);
    d.setDate(d.getDate() + i);
    const dayNum = d.getDate();
    // Count matches in bookings or provide realistic mock distribution
    const count = i === 0 ? 2 : i === 1 ? 1 : i === 2 ? 2 : i === 3 ? 1 : i === 5 ? 2 : i === 7 ? 1 : (i % 3 === 0 ? 1 : 0);
    days.push({ dayNum, count, dateStr: d.toISOString().split('T')[0] });
  }
  return days;
});

const maxChartCount = computed(() => Math.max(3, ...chartDays.value.map((d) => d.count)));

// Category distribution for SVG Donut Chart
const categoryDistribution = computed(() => {
  const total = servicesList.value.length || 1;
  return categoriesList.value.map((c) => {
    const count = getServiceCountByCategory(c.slug);
    const pct = Math.round((count / total) * 100);
    return {
      nameId: c.nameId,
      nameEn: c.nameEn,
      color: c.color,
      count,
      pct,
    };
  });
});

// SVG Donut calculation
const donutSegments = computed(() => {
  let accumulated = 0;
  const total = servicesList.value.length || 1;
  return categoryDistribution.value.map((item) => {
    const pct = item.count / total;
    const dash = pct * 251.2;
    const off = 251.2 - dash;
    const rot = (accumulated / total) * 360 - 90;
    accumulated += item.count;
    return { ...item, dash, off, rot };
  });
});

function formatRupiah(num) {
  return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
}

function getInitials(name) {
  if (!name) return 'A';
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

function setView(view) {
  currentView.value = view;
  editingService.value = null;
  editingCategory.value = null;
  mobileSidebarOpen.value = false;
  router.replace({ query: { ...route.query, view } });
}

function handleLogout() {
  if (typeof localStorage !== 'undefined') {
    localStorage.removeItem('shinjoy-admin-session');
  }
  router.push('/admin-login');
}

watch(
  () => route.query.view,
  (newVal) => {
    if (newVal && ['overview', 'services', 'categories', 'gallery', 'bookings', 'content'].includes(newVal)) {
      currentView.value = newVal;
    }
  }
);

onMounted(() => {
  document.body.classList.add('admin-body');
});

onUnmounted(() => {
  document.body.classList.remove('admin-body');
});
</script>

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
            <div class="stat-card rose"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3c2 3.2 3.4 5.6 3.4 8.2A3.4 3.4 0 0112 14.6a3.4 3.4 0 01-3.4-3.4C8.6 8.6 10 6.2 12 3Z" stroke-linejoin="round"/></svg></span><div class="stat-val">{{ servicesList.length }}</div><div class="stat-label">{{ tr('ad.ov.services') }}</div><div class="stat-sub"><span class="dot rose-strong"></span>{{ tr('ad.ov.subServices', { n: activeCategoriesCount }) }}</div></div>
            <div class="stat-card gold"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4" stroke-linecap="round"/></svg></span><div class="stat-val">{{ bookingsList.length }}</div><div class="stat-label">{{ tr('ad.ov.bookings') }}</div><div class="stat-sub"><span class="dot gold-deep"></span>{{ tr('ad.ov.subPending', { n: pendingBookingsCount }) }}</div></div>
            <div class="stat-card sage"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div class="stat-val">{{ todayBookingsCount }}</div><div class="stat-label">{{ tr('ad.ov.today') }}</div><div class="stat-sub"><span class="dot sage-deep"></span>{{ tr('ad.ov.subWeek', { n: within7DaysCount }) }}</div></div>
            <div class="stat-card plum"><span class="stat-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9v6M18 9v6" stroke-linecap="round"/></svg></span><div class="stat-val">{{ formatRupiah(totalRevenue) }}</div><div class="stat-label">{{ tr('ad.ov.revenue') }}</div><div class="stat-sub"><span class="dot plum-muted"></span>{{ tr('ad.ov.subFrom', { n: bookingsList.length }) }}</div></div>
          </div>
          <div class="chart-row">
            <div class="chart-card">
              <div class="chart-card-head"><h2>{{ tr('ad.ov.chartBookings') }}</h2><span class="sub">{{ tr('ad.ov.chartBookingsSub') }}</span></div>
              <div class="chart-empty">{{ tr('ad.ov.chartEmpty') }}</div>
            </div>
            <div class="chart-card">
              <div class="chart-card-head"><h2>{{ tr('ad.ov.chartCats') }}</h2></div>
              <div class="donut-wrap">
                <svg viewBox="0 0 100 100" class="donut">
                  <circle class="donut-track" cx="50" cy="50" r="40" fill="none" stroke-width="10" />
                  <circle v-for="s in donutSegments" :key="s.nameId" cx="50" cy="50" r="40" fill="none" :stroke="s.color" stroke-width="12" :stroke-dasharray="`${s.dash} ${s.off}`" :transform="`rotate(${s.rot} 50 50)`" />
                  <circle cx="50" cy="50" r="30" fill="#fff" />
                  <text class="donut-total" x="50" y="52" text-anchor="middle" font-size="18">{{ servicesList.length }}</text>
                  <text class="donut-total-lbl" x="50" y="62" text-anchor="middle" font-size="6">{{ tr('ad.nav.services') }}</text>
                </svg>
                <div class="donut-legend">
                  <div class="legend-item" v-for="s in donutSegments" :key="s.nameId">
                    <span class="legend-dot" :style="{ background: s.color }"></span>
                    <span class="legend-name">{{ trLocale === 'en' ? s.nameEn : s.nameId }}</span>
                    <span class="legend-val">{{ s.count }}</span>
                  </div>
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
                        <input type="file" accept="image/jpeg,image/png,image/webp" @change="(e) => handleServicePhotoUpload(n-1, e)">
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
                  <input type="search" placeholder="Search..." v-model="serviceSearch">
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


