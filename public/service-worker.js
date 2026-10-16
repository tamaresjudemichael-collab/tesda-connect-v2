/* TESDA Connect PWA shell worker.
 * IMPORTANT: Do not cache authenticated HTML, Livewire responses, APIs,
 * learner documents, exports, or any other private/sensitive response.
 */
const CACHE_PREFIX = 'tesda-connect-shell-';
const CACHE_NAME = `${CACHE_PREFIX}v2`;
const BASE_URL = new URL('./', self.location.href);
const BASE_PATH = BASE_URL.pathname;
const SHELL_FILES = [
    'offline.html',
    'offline-competency-sync.js',
    'manifest.webmanifest',
    'pwa-icon-192.png',
    'pwa-icon-512.png',
    'favicon.ico',
    'favicon.svg',
    'apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE_NAME);
        const urls = SHELL_FILES.map((path) => new URL(path, BASE_URL).toString());
        // Cache only known, public shell assets. If an optional favicon is absent,
        // install should still succeed; only offline.html is mandatory.
        for (const url of urls) {
            try {
                const response = await fetch(url, { cache: 'reload' });
                if (response.ok && response.type === 'basic') {
                    await cache.put(url, response);
                }
            } catch {
                // The mandatory offline fallback is checked below.
            }
        }
        const fallback = await cache.match(new URL('offline.html', BASE_URL).toString());
        if (!fallback) throw new Error('TESDA Connect offline fallback could not be cached.');
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME).map((name) => caches.delete(name)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (!url.pathname.startsWith(BASE_PATH)) return;

    // Never intercept or cache Livewire, API, downloads, or private document paths.
    const relativePath = url.pathname.slice(BASE_PATH.length).replace(/^\/+/, '');
    if (/^(livewire|api|learner-documents|reports\/export|certificates\/)/i.test(relativePath)) return;

    if (request.mode === 'navigate') {
        // Always try the network for authenticated pages. Do NOT cache the result.
        event.respondWith(fetch(request).catch(async () => {
            const cache = await caches.open(CACHE_NAME);
            return (await cache.match(new URL('offline.html', BASE_URL).toString())) || Response.error();
        }));
        return;
    }

    // Cache-first is limited to known public app-shell assets, never HTML/API data.
    const isPublicShellAsset =
        relativePath.startsWith('build/assets/') ||
        /^(manifest\.webmanifest|offline-competency-sync\.js|pwa-icon-(192|512)\.png|favicon\.(ico|svg)|apple-touch-icon\.png)$/.test(relativePath);
    if (!isPublicShellAsset) return;

    event.respondWith((async () => {
        const cache = await caches.open(CACHE_NAME);
        const cached = await cache.match(request);
        if (cached) return cached;
        const response = await fetch(request);
        if (response.ok && response.type === 'basic') await cache.put(request, response.clone());
        return response;
    })());
});
