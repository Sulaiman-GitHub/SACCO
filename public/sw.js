/*
 * Service worker. It exists for two things only: fast static assets and an offline notice.
 *
 *  - Static files (CSS, JS, fonts, icons, images served from this site) use stale-while-revalidate.
 *  - Page navigations are NETWORK-ONLY. No HTML page is ever stored, because pages hold member data
 *    and phones/computers get shared. If a navigation fails, the stored offline page is shown instead.
 *  - Anything that is not a GET is never touched, so no write is ever intercepted, queued or replayed.
 */
const VERSION = 'v1';
const PRECACHE = 'sacco-precache-' + VERSION; // offline page + icons, installed up front
const STATIC = 'sacco-static-' + VERSION;     // runtime static files, cleared on sign-out
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [OFFLINE_URL, '/icons/icon-192.png', '/icons/icon-512.png'];

// Only these paths are static assets. Everything else (including /storage) is left to the network.
const STATIC_PREFIXES = ['/vendor/', '/icons/', '/images/', '/js/', '/build/'];

self.addEventListener('install', function (event) {
    event.waitUntil(caches.open(PRECACHE).then(function (cache) { return cache.addAll(PRECACHE_URLS); }));
    // No skipWaiting() here: a new version waits until the person accepts the refresh prompt.
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys
                    .filter(function (key) { return key.indexOf('sacco-') === 0 && key !== PRECACHE && key !== STATIC; })
                    .map(function (key) { return caches.delete(key); }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('message', function (event) {
    var type = event.data && event.data.type;

    if (type === 'SKIP_WAITING') {
        self.skipWaiting();
    } else if (type === 'CLEAR_RUNTIME_CACHES') {
        event.waitUntil(caches.delete(STATIC));
    }
});

function isStaticAsset(url) {
    return url.pathname === '/favicon.ico' || STATIC_PREFIXES.some(function (prefix) { return url.pathname.indexOf(prefix) === 0; });
}

function isCacheable(response) {
    if (!response || response.status !== 200 || response.type !== 'basic') {
        return false;
    }

    var cacheControl = (response.headers.get('Cache-Control') || '').toLowerCase();

    return cacheControl.indexOf('no-store') === -1 && cacheControl.indexOf('private') === -1;
}

function staleWhileRevalidate(request) {
    return caches.open(STATIC).then(function (cache) {
        return cache.match(request).then(function (cached) {
            var refresh = fetch(request).then(function (response) {
                if (isCacheable(response)) {
                    cache.put(request, response.clone());
                }

                return response;
            });

            if (cached) {
                refresh.catch(function () {}); // background refresh may fail offline; the cached copy is already served
                return cached;
            }

            return refresh;
        });
    });
}

self.addEventListener('fetch', function (event) {
    var request = event.request;

    // Never intercept writes (or anything that is not a plain GET).
    if (request.method !== 'GET') {
        return;
    }

    var url = new URL(request.url);

    // Same-origin only; scripts, API and scoring endpoints are never handled here.
    if (url.origin !== self.location.origin || url.pathname.indexOf('/api') === 0) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () { return caches.match(OFFLINE_URL, { cacheName: PRECACHE }); })
        );
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
    }
});
