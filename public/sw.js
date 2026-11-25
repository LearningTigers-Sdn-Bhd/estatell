/*
 * Native POS offline support. Built assets are content-hashed, so they are cached first and never go stale. The walk-in
 * offline page is network first, with the last copy kept for when the register has no connection. Sales are not kept here:
 * they live in IndexedDB (resources/js/lib/pos-offline-store.ts). See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
const ASSET_CACHE = 'estatell-pos-assets-v1';
const PAGE_CACHE = 'estatell-pos-pages-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (request.mode === 'navigate' && url.pathname.endsWith('/pos/offline')) {
        event.respondWith(networkFirst(request));
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        cache.put(request, response.clone());
    }

    return response;
}

async function networkFirst(request) {
    const cache = await caches.open(PAGE_CACHE);

    try {
        const response = await fetch(request);

        if (response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch {
        const cached = await cache.match(request);

        return cached ?? Response.error();
    }
}
