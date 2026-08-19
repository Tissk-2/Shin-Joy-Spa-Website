import { createRouter, createWebHistory } from 'vue-router';
import routes from './routes.js';
import { initializeAuth, isAuthenticated } from './useAuth.js';

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to) {
    if (to.hash) return { el: to.hash, behavior: 'smooth' };
    return { top: 0 };
  },
});

router.beforeEach((to) => {
  initializeAuth();

  if (to.meta.requiresAuth && !isAuthenticated.value) {
    return { name: 'login', query: { redirect: to.fullPath } };
  }

  if (isAuthenticated.value && (to.name === 'login' || to.name === 'register')) {
    return { name: 'profile' };
  }

  return true;
});

export default router;
