const CACHE_NAME = 'bookkam-v4';
const OFFLINE_URL = '/offline.html';

const ASSETS = [
  '/',
  '/index.php',
  '/css/style.css?v=4',
  '/css/material-icons-outlined.css',
  '/fonts/material-icons-outlined.woff2',
  '/js/app.js?v=4',
  '/js/auth.js?v=4',
  '/js/customer.js?v=4',
  '/js/driver.js?v=4',
  '/js/admin.js?v=4',
  '/js/maps.js?v=4',
  '/js/utils.js?v=4',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/offline.html',
];

// ── Install: cache all assets ─────────────────────────────────────────────────
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME)
      .then(c => c.addAll(ASSETS))
      .catch(() => {})
  );
  self.skipWaiting();
});

// ── Activate: clear old caches ────────────────────────────────────────────────
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// ── Fetch: network first, cache fallback, offline page as last resort ─────────
self.addEventListener('fetch', e => {
  // Always go network for API calls — never serve stale API responses
  if (e.request.url.includes('/api/')) return;

  // For everything else: try network, fall back to cache, then offline page
  e.respondWith(
    fetch(e.request)
      .then(response => {
        // Cache fresh successful responses
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
        }
        return response;
      })
      .catch(() =>
        caches.match(e.request).then(cached => {
          if (cached) return cached;
          // If navigating to a page with no cache, show offline page
          if (e.request.mode === 'navigate') {
            return caches.match(OFFLINE_URL);
          }
        })
      )
  );
});

// ── Background sync: notify clients when back online ─────────────────────────
self.addEventListener('message', e => {
  if (e.data && e.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
