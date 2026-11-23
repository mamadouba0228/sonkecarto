// ============================================================
// Guinée Carto — Service worker (fonctionnement hors ligne)
//
//  - Pages de l'application (index, stats) : RÉSEAU d'abord, pour que
//    les mises à jour arrivent tout de suite ; si le réseau ne répond pas
//    en quelques secondes, la copie en cache est servie.
//  - Leaflet (cdnjs) et icônes : copie en cache (fichiers versionnés).
//  - Liste des contributions (api.php?action=list) : réseau d'abord,
//    dernière liste reçue en secours.
//  - Fonds de carte Esri et OpenStreetMap : les morceaux de carte déjà
//    affichés sont gardés (3000 au maximum) pour être revus hors ligne.
//    Google n'est jamais gardé (conditions d'utilisation).
//  - Tout le reste (envois de contributions, admin, recherche d'adresse) :
//    réseau direct, sans cache.
//
// Changer VERSION purge les anciens caches de l'application.
// ============================================================
const VERSION = 'v3';
const PORTEE = self.registration.scope;          // démo et stable ont chacun leur cache
const CACHE_APP = `gc-app-${VERSION}-${PORTEE}`;
const CACHE_DONNEES = `gc-donnees-${PORTEE}`;
const CACHE_TUILES = `gc-tuiles-${PORTEE}`;
const MAX_TUILES = 3000;
const DELAI_PAGE = 4000;    // ms avant de servir la page en cache
const DELAI_LISTE = 8000;   // ms avant de servir la liste en cache

const CDN = 'https://cdnjs.cloudflare.com/ajax/libs/';
const LEAFLET = CDN + 'leaflet/1.9.4/';
const COQUILLE = [
  './', './index.html', './stats.html', './manifest.webmanifest',
  './icone-192.png', './icone-512.png', './icone-maskable-512.png',
  LEAFLET + 'leaflet.min.js', LEAFLET + 'leaflet.min.css',
  LEAFLET + 'images/layers.png', LEAFLET + 'images/layers-2x.png',
  CDN + 'leaflet.markercluster/1.5.3/leaflet.markercluster.js',
  CDN + 'leaflet.markercluster/1.5.3/MarkerCluster.css',
];
const HOTES_TUILES = ['tile.openstreetmap.org', 'server.arcgisonline.com'];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_APP)
      // un fichier manquant ne doit pas empêcher l'installation
      .then((c) => Promise.all(COQUILLE.map((u) => c.add(u).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((cles) => Promise.all(cles
        .filter((k) => k === "guinee-carto-v1" || (k.endsWith(PORTEE) && k.startsWith("gc-app-") && k !== CACHE_APP))
        .map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Réseau d'abord ; au-delà du délai (ou en cas d'échec) la copie en cache,
// la réponse réseau continuant de mettre le cache à jour si elle arrive.
function reseauPuisCache(requete, nomCache, cle, delai) {
  return new Promise((resoudre) => {
    let fini = false;
    const servirCache = () => caches.open(nomCache).then((c) => c.match(cle)).then((r) => {
      if (r && !fini) { fini = true; resoudre(r); }
      return r;
    });
    const minuterie = setTimeout(servirCache, delai);
    fetch(requete)
      .then((rep) => {
        if (rep.ok) {
          const copie = rep.clone();
          caches.open(nomCache).then((c) => c.put(cle, copie));
        }
        clearTimeout(minuterie);
        if (!fini) { fini = true; resoudre(rep); }
      })
      .catch(() => {
        clearTimeout(minuterie);
        servirCache().then((r) => {
          if (!fini) { fini = true; resoudre(r || Response.error()); }
        });
      });
  });
}

let ajoutsTuiles = 0;
async function tuile(requete) {
  const cache = await caches.open(CACHE_TUILES);
  const enCache = await cache.match(requete.url);
  if (enCache) return enCache;
  // Demande en mode CORS : la réponse est lisible et pèse son vrai poids
  // dans le quota (une réponse « opaque » compte pour plusieurs Mo).
  const rep = await fetch(requete.url, { mode: 'cors', credentials: 'omit' });
  if (rep.ok) {
    await cache.put(requete.url, rep.clone());
    if (++ajoutsTuiles % 100 === 0) {
      const cles = await cache.keys();       // ordre d'ajout : les plus anciennes d'abord
      await Promise.all(cles.slice(0, Math.max(0, cles.length - MAX_TUILES)).map((k) => cache.delete(k)));
    }
  }
  return rep;
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;                 // envois : réseau direct
  const url = new URL(req.url);

  // Liste publique des contributions (même domaine ou Alwaysdata depuis GitHub)
  if (url.pathname.endsWith('/api.php')) {
    if (url.searchParams.get('action') === 'list' && [...url.searchParams].length === 1) {
      e.respondWith(reseauPuisCache(req, CACHE_DONNEES, url.href, DELAI_LISTE));
    }
    return;                                          // autres actions : réseau direct
  }

  if (HOTES_TUILES.includes(url.hostname)) {
    e.respondWith(tuile(req).catch(() => Response.error()));
    return;
  }

  if (url.href.startsWith(CDN)) {
    e.respondWith(caches.match(url.href).then((r) => r || fetch(req)));
    return;
  }

  if (url.origin !== location.origin) return;       // Google, Photon, Nominatim… : réseau direct

  // Pages et fichiers de l'application (l'admin n'est pas gardé)
  const chemin = url.pathname.slice(new URL(PORTEE).pathname.length);
  const estApp = req.mode === 'navigate'
    ? ['', 'index.html', 'stats.html'].includes(chemin)
    : COQUILLE.includes('./' + chemin);
  if (!estApp) return;
  const cle = req.mode === 'navigate' && chemin === '' ? './' : './' + chemin;
  e.respondWith(reseauPuisCache(req, CACHE_APP, new URL(cle, PORTEE).href, DELAI_PAGE));
});
