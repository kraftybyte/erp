const CACHE_NAME = 'erp-krafty-v1';
const OFFLINE_URL = '/admin/offline';

const STATIC_ASSETS = [
    '/',
    '/admin',
    '/manifest.json',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[SW] Caching static assets');
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') return;

    // Skip API requests
    if (event.request.url.includes('/api/')) return;

    // Skip cross-origin requests
    if (!event.request.url.startsWith(self.location.origin)) return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Clone and cache successful responses
                if (response.ok) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                // Return from cache or offline page
                return caches.match(event.request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }

                    // For HTML pages, return offline page
                    if (event.request.headers.get('accept')?.includes('text/html')) {
                        return caches.match(OFFLINE_URL);
                    }

                    // For other requests, return a simple error response
                    return new Response('Offline', { status: 503 });
                });
            })
    );
});

// Push notification event
self.addEventListener('push', (event) => {
    if (!event.data) return;

    let data;
    try {
        data = event.data.json();
    } catch (e) {
        data = {
            title: 'ERP Notification',
            body: event.data.text(),
        };
    }

    const options = {
        body: data.body || 'You have a new notification',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/icon-72x72.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'erp-notification',
        renotify: true,
        data: {
            url: data.url || '/admin',
            id: data.id || '',
        },
        actions: data.actions || [
            { action: 'open', title: 'Open' },
            { action: 'dismiss', title: 'Dismiss' }
        ],
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'ERP Notification', options)
    );
});

// Notification click event
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'dismiss') return;

    const url = event.notification.data?.url || '/admin';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Focus existing window if available
            for (const client of clientList) {
                if (client.url.includes('/admin') && 'focus' in client) {
                    return client.focus();
                }
            }
            // Open new window
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});

// Message event - for communication with main app
self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') {
        self.skipWaiting();
    }
});