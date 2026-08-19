import { computed, readonly, ref } from 'vue';
import { createAuthStore } from './auth.js';

const authStore = createAuthStore();
const currentUserRef = ref(null);
let initialized = false;

export const currentUser = readonly(currentUserRef);
export const isAuthenticated = computed(() => Boolean(currentUser.value));

export function initializeAuth() {
  if (!initialized) {
    currentUserRef.value = copyPublicUser(authStore.getCurrentUser());
    initialized = true;
  }

  return currentUserRef.value;
}

export async function registerUser(input) {
  const result = await authStore.register(input);
  if (result.ok) currentUserRef.value = copyPublicUser(result.user);
  return result;
}

export async function loginUser(input) {
  const result = await authStore.login(input);
  if (result.ok) currentUserRef.value = copyPublicUser(result.user);
  return result;
}

export function logoutUser() {
  const result = authStore.logout();
  if (result.ok) currentUserRef.value = null;
  return result;
}

function copyPublicUser(user) {
  return user ? { ...user } : null;
}
