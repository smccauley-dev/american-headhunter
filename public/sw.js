// American Headhunter — precache service worker.
//
// Scope is deliberately narrow: cache-first for same-origin build assets and web
// fonts only, so the member app shell keeps loading with a weak or dropped signal.
// It does NOT intercept HTML navigations or Inertia XHR — page data always comes
// from the network, so we never risk serving a member stale props, and the offline
// write queue (IndexedDB, see resources/js/offline/queue.ts) remains the single
// mechanism for offline writes.

const CACHE = 'ah-static-v1'

self.addEventListener('install', () => {
  self.skipWaiting()
})

self.addEventListener('activate', event => {
  event.waitUntil(
    caches
      .keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', event => {
  const req = event.request
  if (req.method !== 'GET') return

  const url = new URL(req.url)
  const isBuildAsset = url.origin === self.location.origin && url.pathname.startsWith('/build/')
  const isFont =
    url.origin === 'https://fonts.googleapis.com' || url.origin === 'https://fonts.gstatic.com'
  if (!isBuildAsset && !isFont) return

  event.respondWith(
    caches.open(CACHE).then(async cache => {
      const cached = await cache.match(req)
      if (cached) return cached
      const res = await fetch(req)
      if (res.ok) cache.put(req, res.clone())
      return res
    }),
  )
})
