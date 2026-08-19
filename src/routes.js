const routes = [
  { path: '/', name: 'home', component: () => import('./Pages/Home.vue') },
  { path: '/services', name: 'services', component: () => import('./Pages/Services.vue') },
  {
    path: '/services/:slug',
    name: 'service-detail',
    component: () => import('./Pages/ServiceDetail.vue'),
    props: true,
  },
  { path: '/booking', name: 'booking', component: () => import('./Pages/Booking.vue') },
  { path: '/about', name: 'about', component: () => import('./Pages/About.vue') },
  { path: '/login', name: 'login', component: () => import('./Pages/Login.vue') },
  { path: '/register', name: 'register', component: () => import('./Pages/Register.vue') },
  {
    path: '/profile',
    name: 'profile',
    component: () => import('./Pages/Profile.vue'),
    meta: { requiresAuth: true },
  },
  { path: '/admin-login', name: 'admin-login', component: () => import('./Pages/AdminLogin.vue') },
  { path: '/admin', name: 'admin', component: () => import('./Pages/Admin.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

export default routes;
