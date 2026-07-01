/**
 * Register the precache service worker. It only caches static build assets and
 * fonts (see public/sw.js) so the app shell loads on a flaky connection; it never
 * intercepts navigations or Inertia XHR, so online behaviour is unchanged and there
 * is no risk of serving stale page props.
 */
export function registerServiceWorker(): void {
  if (!('serviceWorker' in navigator)) return
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // A failed SW registration is non-fatal — the queue still works without it.
    })
  })
}
