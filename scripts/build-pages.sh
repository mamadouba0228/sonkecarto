#!/usr/bin/env bash
# build-pages.sh — génère la version statique pour GitHub Pages
# Usage : scripts/build-pages.sh <stable|demo> <dossier_sortie>
# GitHub Pages n'exécute pas PHP : les appels API sont redirigés vers le backend Alwaysdata.

set -euo pipefail

CHANNEL="${1:?canal requis : stable ou demo}"
OUT="${2:?dossier de sortie requis}"
BACKEND="${BACKEND_URL:-https://lamadgeo.alwaysdata.net/sonkecarto}"

case "$CHANNEL" in stable|demo) ;; *) echo "Canal inconnu : $CHANNEL" >&2; exit 1 ;; esac

rm -rf "$OUT"
mkdir -p "$OUT"

# ── Pages : l'adresse relative de l'API devient l'adresse Alwaysdata ──
sed -e "s|const e=\"api.php\"|const e=\"$BACKEND/api.php\"|" index.html > "$OUT/index.html"
sed -e "s|const API = 'api.php';|const API = '$BACKEND/api.php';|" admin.html > "$OUT/admin.html"
sed -e "s|fetch('api.php?|fetch('$BACKEND/api.php?|" stats.html > "$OUT/stats.html"

cp manifest.webmanifest icone-192.png icone-512.png icone-maskable-512.png "$OUT/"

# ── Spécificités de la démo ──
if [ "$CHANNEL" = "demo" ]; then
  sed -i \
    -e 's|"name": "\([^"]*\)"|"name": "\1 (Démo)"|' \
    -e 's|"short_name": "GuinéeCarto"|"short_name": "GuinéeCarto Démo"|' \
    "$OUT/manifest.webmanifest"
  for f in index.html admin.html stats.html; do
    sed -i \
      -e 's|<title>\([^<]*\)</title>|<title>[DÉMO] \1</title>|' \
      -e 's|</body>|<div style="position:fixed;top:0;left:50%;transform:translateX(-50%);z-index:99999;background:#FF9500;color:#fff;font:600 11px/1 sans-serif;padding:4px 10px;border-radius:0 0 6px 6px;pointer-events:none">VERSION DÉMO</div>\n</body>|' \
      "$OUT/$f"
  done
fi

touch "$OUT/.nojekyll"

# Vérifie qu'aucun appel relatif à l'API ne subsiste (PHP indisponible sur GitHub Pages)
if grep -nE "[\"'(]api\.php" "$OUT/index.html" "$OUT/admin.html" "$OUT/stats.html"; then
  echo "Erreur : appels relatifs à api.php restants" >&2
  exit 1
fi
echo "Build $CHANNEL -> $OUT"
