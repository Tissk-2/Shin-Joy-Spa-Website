import './css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import router from './router.js';

document.documentElement.classList.add('js');

function initializeReveals() {
  const elements = document.querySelectorAll('.reveal:not(.in)');
  if (!elements.length) return;

  if (
    !('IntersectionObserver' in window)
    || window.matchMedia('(prefers-reduced-motion: reduce)').matches
  ) {
    elements.forEach((element) => element.classList.add('in'));
    return;
  }

  const observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      entry.target.classList.add('in');
      observer.unobserve(entry.target);
    }
  }, { threshold: 0.12 });

  elements.forEach((element) => observer.observe(element));
}

router.afterEach(() => requestAnimationFrame(initializeReveals));

createApp(App).use(router).mount('#app');
requestAnimationFrame(initializeReveals);
