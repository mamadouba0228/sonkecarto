// ============================================================
// Guinée Carto — Service worker (application installable)
//
// Stratégie volontairement simple et sûre :
//  - Fichiers de l'application : RÉSEAU d'abord (vos mises à jour
//    arrivent immédiatement), cache en secours (ouverture hors ligne).
//  - api.php : réseau uniquement, avec secours cache en lecture.
//  - Tuiles de carte et services externes : non interceptés.
//
// ⚠️ Si vous modifiez l'application, incrémentez le numéro de
// version ci-dessous (v1 → v2 → ...) pour purger l'ancien cache.
// ============================================================
const CACHE = 'guinee-carto-v1';
const COQUILLE = [
  './',
  './index.html',
  './manifest.webmanifest',
  './icone-192.png',
  './icone-512.png',
  './icone-maskable-512.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => c.addAll(COQUILLE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((cles) => Promise.all(
        cles.filter((k) => k !== CACHE).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  if (e.request.method !== 'GET') return; // envois de contributions : réseau direct
  const url = new URL(e.request.url);
  if (url.origin !== location.origin) return; // tuiles, Photon, Nominatim : réseau direct

  // Réseau d'abord, cache en secours (hors ligne)
  e.respondWith(
    fetch(e.request)
      .then((reponse) => {
        const copie = reponse.clone();
        caches.open(CACHE).then((c) => c.put(e.request, copie));
        return reponse;
      })
      .catch(() => caches.match(e.request, { ignoreSearch: url.pathname.endsWith('api.php') ? false : true }))
  );
});
