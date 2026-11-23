<?php
// ============================================================
// Guinée Carto — API (PHP + PostgreSQL via PDO)
// Version 3 TABLES : ponctuels / lineaires / surfaciques
//
// Points d'entrée (paramètre ?action=) :
//   list                       (public)  contributions visibles sur la carte
//   add        POST JSON       (public)  ajouter une contribution
//   pending    &token=...      (admin)   contributions en attente
//   setstatut  POST JSON+token (admin)   valider / rejeter / marquer importé
//   export     &token=...      (admin)   GeoJSON (&couche= pour une seule couche)
// ============================================================

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// CORS : l'interface publiée sur GitHub Pages appelle cette API depuis un autre domaine.
const ORIGINES_AUTORISEES = [
    'https://lamadgeo.alwaysdata.net',
    'https://mamadouba0228.github.io',
];
$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origine, ORIGINES_AUTORISEES, true)) {
    header('Access-Control-Allow-Origin: ' . $origine);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Correspondance type de géométrie → table (liste blanche : aucune
// valeur venant de l'utilisateur n'est insérée telle quelle en SQL).
const TABLES = [
    'point'    => 'ponctuels',
    'ligne'    => 'lineaires',
    'polygone' => 'surfaciques',
];
const GEOJSON_ATTENDU = [
    'point'    => 'Point',
    'ligne'    => 'LineString',
    'polygone' => 'Polygon',
];

// --- Connexion à la base ---
try {
    $pdo = new PDO(
        'pgsql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erreur' => 'Connexion à la base impossible. Vérifiez config.php.']);
    exit;
}

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true);

// Identifiant UUID (contributions, commentaires)
function estUuid($s): bool {
    return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1;
}

// Tronque une chaîne (utilise mbstring si disponible, sinon repli standard)
function limiter(string $s, int $max): string {
    $s = trim($s);
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max);
    return substr($s, 0, $max);
}

function estAdmin(): bool {
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    $ok = hash_equals(ADMIN_TOKEN, (string)$token);
    if (!$ok) usleep(500000); // freine les attaques par force brute (0,5 s par échec)
    return $ok;
}

// Quota anti-inondation : limite le nombre d'ajouts par adresse IP et par jour.
// Nécessite la table quota_ip (voir schema.sql). En son absence, n'empêche
// pas le fonctionnement (le quota est simplement ignoré).
function quotaDepasse(PDO $pdo, int $max = 300): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
    try {
        $st = $pdo->prepare(
            "INSERT INTO quota_ip (ip, jour, n) VALUES (:ip, CURRENT_DATE, 1)
             ON CONFLICT (ip, jour) DO UPDATE SET n = quota_ip.n + 1
             RETURNING n"
        );
        $st->execute([':ip' => $ip]);
        return ((int)$st->fetchColumn()) > $max;
    } catch (Exception $e) {
        return false;
    }
}

// Contrôle d'une géométrie GeoJSON Point / LineString / Polygon (coordonnées WGS84)
function geometrieValide(array $geo): bool {
    $pointOk = fn($p) => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])
        && abs((float)$p[0]) <= 180 && abs((float)$p[1]) <= 90;
    $c = $geo['coordinates'] ?? null;
    switch ($geo['type'] ?? '') {
        case 'Point':
            return $pointOk($c);
        case 'LineString':
            return is_array($c) && count($c) >= 2 && count($c) <= 5000 && count(array_filter($c, $pointOk)) === count($c);
        case 'Polygon':
            $a = $c[0] ?? null;
            return is_array($c) && count($c) === 1 && is_array($a) && count($a) >= 4 && count($a) <= 5000
                && count(array_filter($a, $pointOk)) === count($a)
                && (float)$a[0][0] === (float)end($a)[0] && (float)$a[0][1] === (float)end($a)[1];
    }
    return false;
}

// Compte contributeur : nom, prénom, sous-préfecture et village obligatoires
// (les anciens comptes « nom + prénom » restent acceptés tant qu'ils ne sont pas complétés).
function enregistrerCompte(PDO $pdo, string $dev, array $c, bool $exigerComplet = false): bool {
    $champ = fn($k, $max) => limiter(trim((string)($c[$k] ?? '')), $max);
    $nom = $champ('nom', 100); $prenom = $champ('prenom', 100);
    $pref = $champ('prefecture', 100); $sp = $champ('sous_prefecture', 100); $vil = $champ('village', 150);
    $tel = $champ('telephone', 30); $mail = $champ('email', 120);
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';
    if ($nom === '' || $prenom === '') return false;
    if ($exigerComplet && ($sp === '' || $vil === '')) return false;
    // Un ancien compte incomplet ne doit pas effacer un village déjà connu
    $st = $pdo->prepare(
        "INSERT INTO contributeurs (device_id, nom, prenom, telephone, email, prefecture, sous_prefecture, village)
         VALUES (:d, :n, :p, :t, :m, :pf, :sp, :v)
         ON CONFLICT (device_id) DO UPDATE
           SET nom = :n, prenom = :p, telephone = :t, email = :m,
               prefecture = COALESCE(NULLIF(:pf, ''), contributeurs.prefecture),
               sous_prefecture = COALESCE(NULLIF(:sp, ''), contributeurs.sous_prefecture),
               village = COALESCE(NULLIF(:v, ''), contributeurs.village)"
    );
    $st->execute([':d' => $dev, ':n' => $nom, ':p' => $prenom, ':t' => $tel, ':m' => $mail,
                  ':pf' => $pref, ':sp' => $sp, ':v' => $vil]);
    return true;
}

function repondre(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// SELECT commun sur les 3 tables, avec le type en constante par table.
// $where est écrit en dur dans le code (jamais issu de l'utilisateur).
function unionTables(string $colonnes, string $where): string {
    $parts = [];
    foreach (TABLES as $type => $table) {
        $parts[] = "SELECT '$type' AS geom_type, $colonnes FROM $table WHERE $where";
    }
    return implode(' UNION ALL ', $parts);
}

// ============================================================
switch ($action) {

// ------------------------------------------------------------
// PUBLIC : contributions affichées sur la carte (3 couches réunies)
// ------------------------------------------------------------
case 'list':
    $parts = [];
    foreach (TABLES as $type => $table) {
        $parts[] = "SELECT '$type' AS geom_type, t.id, t.geom_geojson, t.categorie, t.nom,
                           t.notes, t.attributs, t.photo, t.statut, t.created_at,
                           co.prenom AS c_prenom, co.nom AS c_nom
                    FROM $table t
                    LEFT JOIN contributeurs co ON co.device_id = t.device_id
                    WHERE t.statut IN ('en_attente', 'valide', 'importe_osm')";
    }
    $sql = implode(' UNION ALL ', $parts) . ' ORDER BY created_at DESC LIMIT 5000';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['geom_geojson'] = json_decode($r['geom_geojson'], true);
        $r['attributs'] = json_decode($r['attributs'] ?? '{}', true);
        // Signature publique : Prénom + initiale du nom (jamais de coordonnées)
        $prenom = trim((string)($r['c_prenom'] ?? ''));
        $nomC   = trim((string)($r['c_nom'] ?? ''));
        if ($prenom !== '') {
            $ini = $nomC !== ''
                ? (function_exists('mb_substr') ? mb_substr($nomC, 0, 1) : substr($nomC, 0, 1)) . '.'
                : '';
            $r['signature'] = trim($prenom . ' ' . $ini);
        } else {
            $r['signature'] = '';
        }
        unset($r['c_prenom'], $r['c_nom']);
    }
    unset($r);
    // Nombre de commentaires par contribution (affiché sur la carte et dans la liste)
    try {
        $nb = $pdo->query("SELECT contribution_id::text, COUNT(*) FROM commentaires GROUP BY 1")
                  ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as &$r) $r['nb_commentaires'] = (int)($nb[$r['id']] ?? 0);
        unset($r);
    } catch (Exception $e) { /* table absente : pas de compteur */ }
    repondre(['ok' => true, 'contributions' => $rows]);

// ------------------------------------------------------------
// PUBLIC : discussion d'une contribution (commentaires et réponses)
// GET ?action=commentaires&id=<contribution>&dev=<appareil, facultatif>
// ------------------------------------------------------------
case 'commentaires':
    $cid = (string)($_GET['id'] ?? '');
    if (!estUuid($cid)) repondre(['ok' => false, 'erreur' => 'Identifiant invalide.'], 400);
    $dev = limiter((string)($_GET['dev'] ?? ''), 64);
    // Appareil de l'auteur de la contribution (pour le badge « auteur »)
    $devContrib = '';
    foreach (TABLES as $table) {
        $st = $pdo->prepare("SELECT device_id FROM $table WHERE id = :id");
        $st->execute([':id' => $cid]);
        $v = $st->fetchColumn();
        if ($v !== false) { $devContrib = (string)$v; break; }
    }
    $st = $pdo->prepare(
        "SELECT c.id, c.auteur, c.texte, c.created_at, c.reponse_a, c.device_id, co.village
         FROM commentaires c
         LEFT JOIN contributeurs co ON co.device_id = c.device_id
         WHERE c.contribution_id = :id ORDER BY c.created_at"
    );
    $st->execute([':id' => $cid]);
    $liste = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $d = (string)$c['device_id'];
        $liste[] = [
            'id' => $c['id'], 'auteur' => $c['auteur'], 'texte' => $c['texte'],
            'created_at' => $c['created_at'],
            'date' => date('d/m/Y H:i', strtotime((string)$c['created_at'])),
            'reponse_a' => $c['reponse_a'],
            'village' => (string)($c['village'] ?? ''),
            'mien' => $dev !== '' && $d !== '' && hash_equals($d, $dev),
            'auteur_contribution' => $devContrib !== '' && $d === $devContrib,
        ];
    }
    repondre(['ok' => true, 'commentaires' => $liste]);

// ------------------------------------------------------------
// PUBLIC : nouveau commentaire, ou réponse à un commentaire
// POST JSON : { contribution_id, contribution_type, device_id, auteur, texte, reponse_a? }
// ------------------------------------------------------------
case 'add_commentaire':
    if (!is_array($input)) repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    $cid   = (string)($input['contribution_id'] ?? '');
    $type  = (string)($input['contribution_type'] ?? '');
    $dev   = limiter((string)($input['device_id'] ?? ''), 64);
    $aut   = limiter((string)($input['auteur'] ?? ''), 60);
    $texte = limiter((string)($input['texte'] ?? ''), 500);
    $rep   = (string)($input['reponse_a'] ?? '');
    if (!estUuid($cid) || !isset(TABLES[$type]) || $dev === '' || $aut === '') {
        repondre(['ok' => false, 'erreur' => 'Données invalides.'], 400);
    }
    if ($texte === '') repondre(['ok' => false, 'erreur' => 'Le commentaire est vide.'], 400);
    if (quotaDepasse($pdo)) repondre(['ok' => false, 'erreur' => 'Trop d’envois aujourd’hui. Réessayez demain.'], 429);
    $table = TABLES[$type];
    $st = $pdo->prepare("SELECT 1 FROM $table WHERE id = :id AND statut <> 'rejete'");
    $st->execute([':id' => $cid]);
    if (!$st->fetchColumn()) repondre(['ok' => false, 'erreur' => 'Contribution introuvable.'], 404);
    if ($rep !== '') {
        // Une réponse vise toujours un commentaire de la même contribution
        $st = $pdo->prepare('SELECT 1 FROM commentaires WHERE id = :r AND contribution_id = :c');
        $st->execute([':r' => $rep, ':c' => $cid]);
        if (!estUuid($rep) || !$st->fetchColumn()) $rep = '';
    }
    // Double envoi (double appui) : même texte du même appareil dans la minute
    $st = $pdo->prepare("SELECT 1 FROM commentaires WHERE device_id = :d AND contribution_id = :c
                         AND texte = :t AND created_at > now() - interval '1 minute'");
    $st->execute([':d' => $dev, ':c' => $cid, ':t' => $texte]);
    if ($st->fetchColumn()) repondre(['ok' => true, 'doublon' => true]);
    $st = $pdo->prepare(
        "INSERT INTO commentaires (id, contribution_id, contribution_type, device_id, auteur, texte, reponse_a)
         VALUES (gen_random_uuid(), :c, :ty, :d, :a, :t, NULLIF(:r, '')::uuid)"
    );
    $st->execute([':c' => $cid, ':ty' => $type, ':d' => $dev, ':a' => $aut, ':t' => $texte, ':r' => $rep]);
    repondre(['ok' => true]);

// ------------------------------------------------------------
// PUBLIC : le contributeur supprime SON commentaire
// POST JSON : { id, device_id }
// ------------------------------------------------------------
case 'supprimer_commentaire':
    if (!is_array($input)) repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    $id  = (string)($input['id'] ?? '');
    $dev = limiter((string)($input['device_id'] ?? ''), 64);
    if (!estUuid($id) || $dev === '') repondre(['ok' => false, 'erreur' => 'Données invalides.'], 400);
    $st = $pdo->prepare("DELETE FROM commentaires WHERE id = :id AND device_id = :d AND device_id <> ''");
    $st->execute([':id' => $id, ':d' => $dev]);
    if ($st->rowCount() === 0) repondre(['ok' => false, 'erreur' => 'Suppression impossible.'], 403);
    // Les réponses restent, rattachées directement à la discussion
    $pdo->prepare('UPDATE commentaires SET reponse_a = NULL WHERE reponse_a = :id')->execute([':id' => $id]);
    repondre(['ok' => true]);

// ------------------------------------------------------------
// ADMIN : suppression d'un commentaire (modération)
// ------------------------------------------------------------
case 'delete_commentaire':
    if (!estAdmin()) repondre(['ok' => false, 'erreur' => 'Mot de passe admin incorrect.'], 403);
    $id = (string)(($input['id'] ?? '') ?: '');
    if (!estUuid($id)) repondre(['ok' => false, 'erreur' => 'Identifiant invalide.'], 400);
    $pdo->prepare('DELETE FROM commentaires WHERE id = :id')->execute([':id' => $id]);
    $pdo->prepare('UPDATE commentaires SET reponse_a = NULL WHERE reponse_a = :id')->execute([':id' => $id]);
    repondre(['ok' => true]);

// ------------------------------------------------------------
// PUBLIC : nouveaux messages pour un contributeur depuis sa dernière visite :
//  - sur ses contributions ; - en réponse à ses commentaires ;
//  - dans les discussions auxquelles il a participé.
// GET ?action=mes_notifications&ids=<id,id…>&depuis=<ms>&dev=<appareil>
// ------------------------------------------------------------
case 'mes_notifications':
    $dev = limiter((string)($_GET['dev'] ?? ''), 64);
    $ids = array_values(array_filter(array_slice(explode(',', (string)($_GET['ids'] ?? '')), 0, 500), 'estUuid'));
    $depuis = max(0, (int)($_GET['depuis'] ?? 0)) / 1000;
    if ($dev === '') repondre(['ok' => true, 'commentaires' => []]);
    $noms = [];
    foreach (TABLES as $table) $noms[] = "SELECT id, nom FROM $table";
    $place = $ids ? implode(',', array_fill(0, count($ids), '?')) : "NULL";
    $sql = "SELECT c.contribution_id, c.contribution_type, c.auteur, c.created_at, x.nom AS nom_contrib,
                   CASE WHEN c.contribution_id::text IN ($place) THEN 'ma_contribution'
                        WHEN p.device_id = ? THEN 'reponse'
                        ELSE 'discussion' END AS motif
            FROM commentaires c
            LEFT JOIN commentaires p ON p.id = c.reponse_a
            LEFT JOIN (" . implode(' UNION ALL ', $noms) . ") x ON x.id = c.contribution_id
            WHERE c.created_at > to_timestamp(?) AND c.device_id <> ?
              AND (c.contribution_id::text IN ($place)
                   OR p.device_id = ?
                   OR c.contribution_id IN (SELECT contribution_id FROM commentaires WHERE device_id = ?))
            ORDER BY c.created_at LIMIT 100";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($ids, [$dev, $depuis, $dev], $ids, [$dev, $dev]));
    repondre(['ok' => true, 'commentaires' => $st->fetchAll(PDO::FETCH_ASSOC)]);

// ------------------------------------------------------------
// PUBLIC : ajout d'une contribution → routée vers la bonne table
// ------------------------------------------------------------
case 'add':
    if (!is_array($input)) {
        repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    }

    $id   = (string)($input['id'] ?? '');
    $type = (string)($input['geom_type'] ?? '');
    $geo  = $input['geom_geojson'] ?? null;
    $cat  = limiter((string)($input['categorie'] ?? ''), 50);
    $nom  = limiter((string)($input['nom'] ?? ''), 200);
    $note = limiter((string)($input['notes'] ?? ''), 500);
    $qui  = limiter((string)($input['contributeur'] ?? ''), 100);
    $dev  = limiter((string)($input['device_id'] ?? ''), 64);
    $attrs = $input['attributs'] ?? [];
    if (!is_array($attrs)) $attrs = [];
    $attrsJson = json_encode($attrs, JSON_UNESCAPED_UNICODE);
    if ($attrsJson === false || strlen($attrsJson) > 2000) $attrsJson = '{}';
    if ($attrsJson === '[]') $attrsJson = '{}';

    // Validations
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        repondre(['ok' => false, 'erreur' => 'Identifiant invalide.'], 400);
    }
    if (!isset(TABLES[$type])) {
        repondre(['ok' => false, 'erreur' => 'Type de géométrie invalide.'], 400);
    }
    if (!is_array($geo) || empty($geo['type']) || !isset($geo['coordinates'])) {
        repondre(['ok' => false, 'erreur' => 'Géométrie GeoJSON invalide.'], 400);
    }
    if ($geo['type'] !== GEOJSON_ATTENDU[$type]) {
        repondre(['ok' => false, 'erreur' => 'Géométrie incohérente avec le type.'], 400);
    }
    if ($cat === '') {
        repondre(['ok' => false, 'erreur' => 'Catégorie manquante.'], 400);
    }
    if (quotaDepasse($pdo)) {
        repondre(['ok' => false, 'erreur' => 'Quota journalier atteint. Réessayez demain.'], 429);
    }

    // ----- Photo facultative (base64 JPEG/PNG, ré-encodée côté serveur) -----
    $photo = '';
    $photoB64 = (string)($input['photo'] ?? '');
    if ($photoB64 !== '') {
        if (strlen($photoB64) > 900000) {
            repondre(['ok' => false, 'erreur' => 'Photo trop volumineuse.'], 400);
        }
        $pos = strpos($photoB64, 'base64,');
        if ($pos !== false) $photoB64 = substr($photoB64, $pos + 7);
        $bin = base64_decode($photoB64, true);
        if ($bin === false || strlen($bin) < 100) {
            repondre(['ok' => false, 'erreur' => 'Photo invalide.'], 400);
        }
        $dir = __DIR__ . '/photos';
        if (!is_dir($dir)) @mkdir($dir, 0755);
        $chemin = $dir . '/' . $id . '.jpg';
        if (function_exists('imagecreatefromstring')) {
            // Ré-encodage complet : neutralise tout contenu non-image
            $img = @imagecreatefromstring($bin);
            if ($img === false) repondre(['ok' => false, 'erreur' => 'Photo invalide.'], 400);
            imagejpeg($img, $chemin, 82);
            imagedestroy($img);
        } else {
            // Sans GD : contrôle des octets magiques JPEG/PNG
            $estJpeg = substr($bin, 0, 3) === "\xFF\xD8\xFF";
            $estPng  = substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n";
            if (!$estJpeg && !$estPng) repondre(['ok' => false, 'erreur' => 'Photo invalide.'], 400);
            file_put_contents($chemin, $bin);
        }
        $photo = 'photos/' . $id . '.jpg';
    }

    $table = TABLES[$type]; // valeur interne, sûre
    $st = $pdo->prepare(
        "INSERT INTO $table (id, geom_geojson, categorie, nom, notes, contributeur, attributs, device_id, photo)
         VALUES (:id, :geo, :cat, :nom, :note, :qui, :attrs, :dev, :photo)
         ON CONFLICT (id) DO NOTHING"
    );
    $st->execute([
        ':id' => $id,
        ':geo' => json_encode($geo),
        ':cat' => $cat, ':nom' => $nom, ':note' => $note, ':qui' => $qui,
        ':attrs' => $attrsJson, ':dev' => $dev, ':photo' => $photo,
    ]);
    // Créer/mettre à jour le compte contributeur lié à cet appareil
    if ($dev !== '' && isset($input['compte']) && is_array($input['compte'])) {
        enregistrerCompte($pdo, $dev, $input['compte']);
    }
    repondre(['ok' => true, 'id' => $id, 'table' => $table]);

// ------------------------------------------------------------
// PUBLIC : enregistrement / mise à jour du compte contributeur
// (sans attendre une contribution). POST JSON : { device_id, compte }
// ------------------------------------------------------------
case 'compte':
    if (!is_array($input) || !is_array($input['compte'] ?? null)) {
        repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    }
    $dev = limiter((string)($input['device_id'] ?? ''), 64);
    if ($dev === '') repondre(['ok' => false, 'erreur' => 'Identifiant appareil manquant.'], 400);
    if (!enregistrerCompte($pdo, $dev, $input['compte'], true)) {
        repondre(['ok' => false, 'erreur' => 'Nom, prénom, sous-préfecture et village sont obligatoires.'], 400);
    }
    repondre(['ok' => true]);

// ------------------------------------------------------------
// PUBLIC : le contributeur MODIFIE sa propre contribution,
// uniquement tant qu'elle n'a pas été traitée par l'admin.
// L'identité est prouvée par le device_id enregistré à la création.
// ------------------------------------------------------------
case 'update':
    if (!is_array($input)) repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    $id  = (string)($input['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        repondre(['ok' => false, 'erreur' => 'Identifiant invalide.'], 400);
    }
    $dev = limiter((string)($input['device_id'] ?? ''), 64);
    $cat = limiter((string)($input['categorie'] ?? ''), 50);
    $nom = limiter((string)($input['nom'] ?? ''), 200);
    $note = limiter((string)($input['notes'] ?? ''), 500);
    $attrs = $input['attributs'] ?? [];
    if (!is_array($attrs)) $attrs = [];
    $attrsJson = json_encode($attrs, JSON_UNESCAPED_UNICODE) ?: '{}';
    if ($dev === '' || $cat === '' || strlen($attrsJson) > 2000) {
        repondre(['ok' => false, 'erreur' => 'Données invalides.'], 400);
    }
    $type = (string)($input['geom_type'] ?? '');
    // Nouvelle forme / position (facultative) : même contrôle que pour l'admin
    $geo = $input['geom_geojson'] ?? null;
    $geoJson = null;
    if ($geo !== null) {
        if (!isset(TABLES[$type]) || !is_array($geo) || ($geo['type'] ?? '') !== GEOJSON_ATTENDU[$type]
            || !geometrieValide($geo)) {
            repondre(['ok' => false, 'erreur' => 'Géométrie invalide.'], 400);
        }
        $geoJson = json_encode($geo);
    }
    $tables = isset(TABLES[$type]) ? [TABLES[$type]] : array_values(TABLES);
    foreach ($tables as $table) {
        $st = $pdo->prepare(
            "UPDATE $table SET categorie = :cat, nom = :nom, notes = :note, attributs = :attrs,
                    geom_geojson = COALESCE(CAST(:geo AS jsonb), geom_geojson)
             WHERE id = :id AND device_id = :dev AND device_id <> '' AND statut = 'en_attente'"
        );
        $st->execute([':cat' => $cat, ':nom' => $nom, ':note' => $note,
                      ':attrs' => $attrsJson, ':geo' => $geoJson, ':id' => $id, ':dev' => $dev]);
        if ($st->rowCount() > 0) repondre(['ok' => true]);
    }
    repondre(['ok' => false, 'erreur' => 'Modification impossible : contribution déjà traitée ou non trouvée.'], 403);

// ------------------------------------------------------------
// PUBLIC : le contributeur SUPPRIME sa propre contribution,
// uniquement tant qu'elle n'a pas été traitée par l'admin.
// ------------------------------------------------------------
case 'delete':
    if (!is_array($input)) repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    $id  = (string)($input['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        repondre(['ok' => false, 'erreur' => 'Identifiant invalide.'], 400);
    }
    $dev = limiter((string)($input['device_id'] ?? ''), 64);
    if ($dev === '') repondre(['ok' => false, 'erreur' => 'Identifiant appareil manquant.'], 400);
    $type = (string)($input['geom_type'] ?? '');
    $tables = isset(TABLES[$type]) ? [TABLES[$type]] : array_values(TABLES);
    foreach ($tables as $table) {
        $st = $pdo->prepare(
            "DELETE FROM $table
             WHERE id = :id AND device_id = :dev AND device_id <> '' AND statut = 'en_attente'"
        );
        $st->execute([':id' => $id, ':dev' => $dev]);
        if ($st->rowCount() > 0) {
            @unlink(__DIR__ . '/photos/' . $id . '.jpg'); // photo associée, s'il y en a une
            repondre(['ok' => true]);
        }
    }
    repondre(['ok' => false, 'erreur' => 'Suppression impossible : contribution déjà traitée ou non trouvée.'], 403);

// ------------------------------------------------------------
// ADMIN : contributions en attente (3 couches réunies)
// ------------------------------------------------------------
// ------------------------------------------------------------
// PUBLIC : récupération du compte sur un autre téléphone.
// Le code de récupération est l'identifiant secret de l'appareil
// d'origine (122 bits aléatoires) : il prouve la propriété.
// POST JSON : { code }  →  { ok, compte: {nom, prenom}, ids: [...] }
// ------------------------------------------------------------
case 'recuperer':
    if (!is_array($input)) repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string)($input['code'] ?? '')));
    if (strlen($hex) !== 32) {
        repondre(['ok' => false, 'erreur' => 'Code incomplet : il doit compter 32 caractères.'], 400);
    }
    $dev = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
         . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    $st = $pdo->prepare('SELECT nom, prenom, telephone, email, prefecture, sous_prefecture, village FROM contributeurs WHERE device_id = :dev');
    $st->execute([':dev' => $dev]);
    $compte = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $ids = [];
    foreach (TABLES as $table) {
        $st = $pdo->prepare("SELECT id FROM $table WHERE device_id = :dev ORDER BY created_at");
        $st->execute([':dev' => $dev]);
        $ids = array_merge($ids, $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$compte && !$ids) {
        usleep(800000); // freine les essais au hasard
        repondre(['ok' => false, 'erreur' => 'Code inconnu. Vérifiez-le (lettres a à f et chiffres uniquement).'], 404);
    }
    repondre(['ok' => true, 'device_id' => $dev, 'compte' => $compte, 'ids' => $ids]);

case 'pending':
    if (!estAdmin()) repondre(['ok' => false, 'erreur' => 'Mot de passe admin incorrect.'], 403);
    // Filtre de statut : en_attente (défaut), valide, rejete, importe_osm ou tous.
    // Valeur passée par liste blanche uniquement (jamais interpolée depuis l'utilisateur).
    $filtre = $_GET['statut'] ?? 'en_attente';
    if (!in_array($filtre, ['en_attente', 'valide', 'rejete', 'importe_osm', 'tous'], true)) {
        $filtre = 'en_attente';
    }
    $cond = ($filtre === 'tous') ? '1=1' : "t.statut = '$filtre'";
    $parts = [];
    foreach (TABLES as $type => $table) {
        $parts[] = "SELECT '$type' AS geom_type, t.id, t.geom_geojson, t.categorie, t.nom,
                           t.notes, t.contributeur, t.attributs, t.photo, t.statut, t.created_at,
                           co.prenom AS c_prenom, co.nom AS c_nom,
                           co.telephone AS c_tel, co.email AS c_email,
                           co.village AS c_village, co.sous_prefecture AS c_sp, co.prefecture AS c_pref
                    FROM $table t
                    LEFT JOIN contributeurs co ON co.device_id = t.device_id
                    WHERE $cond";
    }
    $sql = implode(' UNION ALL ', $parts) . ' ORDER BY created_at ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['geom_geojson'] = json_decode($r['geom_geojson'], true);
        $r['attributs'] = json_decode($r['attributs'] ?? '{}', true);
    }
    repondre(['ok' => true, 'contributions' => $rows]);

// ------------------------------------------------------------
// ADMIN : changer le statut d'une contribution
// (geom_type fourni = table directe ; sinon on cherche dans les 3)
// ------------------------------------------------------------
case 'setstatut':
    if (!estAdmin()) repondre(['ok' => false, 'erreur' => 'Mot de passe admin incorrect.'], 403);
    $id     = (string)($input['id'] ?? '');
    $statut = (string)($input['statut'] ?? '');
    $type   = (string)($input['geom_type'] ?? '');
    if (!in_array($statut, ['en_attente', 'valide', 'rejete', 'importe_osm'], true)) {
        repondre(['ok' => false, 'erreur' => 'Statut invalide.'], 400);
    }
    $tables = isset(TABLES[$type]) ? [TABLES[$type]] : array_values(TABLES);
    foreach ($tables as $table) {
        $st = $pdo->prepare("UPDATE $table SET statut = :s WHERE id = :id");
        $st->execute([':s' => $statut, ':id' => $id]);
        if ($st->rowCount() > 0) repondre(['ok' => true, 'table' => $table]);
    }
    repondre(['ok' => false, 'erreur' => 'Contribution introuvable.'], 404);

// ------------------------------------------------------------
// ADMIN : remplace la géométrie d'une contribution retracée par
// le modérateur sur l'imagerie Esri (seule autorisée pour OSM).
// POST JSON : { id, geom_type, geom_geojson }
// ------------------------------------------------------------
case 'set_geom':
    if (!estAdmin()) repondre(['ok' => false, 'erreur' => 'Mot de passe admin incorrect.'], 403);
    if (!is_array($input)) repondre(['ok' => false, 'erreur' => 'Corps JSON manquant.'], 400);
    $id   = (string)($input['id'] ?? '');
    $type = (string)($input['geom_type'] ?? '');
    $geo  = $input['geom_geojson'] ?? null;
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        repondre(['ok' => false, 'erreur' => 'Identifiant invalide.'], 400);
    }
    if (!isset(TABLES[$type]) || !is_array($geo) || ($geo['type'] ?? '') !== GEOJSON_ATTENDU[$type]
        || !geometrieValide($geo)) {
        repondre(['ok' => false, 'erreur' => 'Géométrie invalide.'], 400);
    }
    $table = TABLES[$type];
    $st = $pdo->prepare(
        "UPDATE $table SET geom_geojson = :geo,
                attributs = COALESCE(attributs, '{}'::jsonb) || '{\"fond\": \"esri\"}'::jsonb
         WHERE id = :id"
    );
    $st->execute([':geo' => json_encode($geo), ':id' => $id]);
    if ($st->rowCount() > 0) repondre(['ok' => true]);
    repondre(['ok' => false, 'erreur' => 'Contribution introuvable.'], 404);

// ------------------------------------------------------------
// ADMIN : export GeoJSON pour JOSM / QGIS
//   &statut=valide|en_attente|importe_osm   (défaut : valide)
//   &couche=ponctuels|lineaires|surfaciques (défaut : les 3)
// ------------------------------------------------------------
case 'export':
    if (!estAdmin()) repondre(['ok' => false, 'erreur' => 'Mot de passe admin incorrect.'], 403);

    $statutVoulu = $_GET['statut'] ?? 'valide';
    if (!in_array($statutVoulu, ['en_attente', 'valide', 'importe_osm'], true)) $statutVoulu = 'valide';

    $coucheVoulue = $_GET['couche'] ?? '';
    $tables = in_array($coucheVoulue, array_values(TABLES), true)
        ? [$coucheVoulue] : array_values(TABLES);

    $features = [];
    foreach ($tables as $table) {
        $st = $pdo->prepare(
            "SELECT id, geom_geojson, categorie, nom, notes, contributeur, attributs, photo, created_at
             FROM $table WHERE statut = :s ORDER BY created_at"
        );
        $st->execute([':s' => $statutVoulu]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $attrs = json_decode($r['attributs'] ?? '{}', true);
            $features[] = [
                'type' => 'Feature',
                'geometry' => json_decode($r['geom_geojson'], true),
                'properties' => array_merge([
                    'id' => $r['id'],
                    'couche' => $table,
                    'categorie' => $r['categorie'],
                    'name' => $r['nom'],
                    'notes' => $r['notes'],
                    'contributeur' => $r['contributeur'],
                    'photo' => $r['photo'],
                    'date' => $r['created_at'],
                ], is_array($attrs) ? $attrs : []),
            ];
        }
    }

    $nomFichier = 'guinee_' . (count($tables) === 1 ? $tables[0] : 'toutes_couches') . '_' . $statutVoulu;
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '.geojson"');
    echo json_encode(['type' => 'FeatureCollection', 'features' => $features],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;

// ------------------------------------------------------------
// PUBLIC : classement des meilleurs contributeurs
// (prénom + initiale du nom uniquement — aucune coordonnée exposée)
// ------------------------------------------------------------
case 'classement':
    $union = [];
    foreach (TABLES as $type => $table) {
        $union[] = "SELECT device_id FROM $table WHERE statut IN ('valide', 'importe_osm') AND device_id <> ''";
    }
    $u = implode(' UNION ALL ', $union);
    $rows = $pdo->query(
        "SELECT co.prenom, co.nom, COUNT(*) AS n
         FROM ($u) t
         JOIN contributeurs co ON co.device_id = t.device_id
         GROUP BY co.device_id, co.prenom, co.nom
         ORDER BY n DESC, co.prenom ASC
         LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
    $classement = [];
    foreach ($rows as $r) {
        $initiale = $r['nom'] !== '' ? (function_exists('mb_substr')
            ? mb_substr($r['nom'], 0, 1) : substr($r['nom'], 0, 1)) . '.' : '';
        $classement[] = ['contributeur' => trim($r['prenom'] . ' ' . $initiale), 'n' => (int)$r['n']];
    }
    repondre(['ok' => true, 'classement' => $classement]);

// ------------------------------------------------------------
// PUBLIC : statistiques d'avancement de la cartographie
// (uniquement des totaux agrégés — aucune donnée personnelle)
// ------------------------------------------------------------
case 'stats_public':
    $couches = [];
    $total = 0;
    foreach (TABLES as $type => $table) {
        $n = (int)$pdo->query(
            "SELECT COUNT(*) FROM $table WHERE statut IN ('valide', 'importe_osm')"
        )->fetchColumn();
        $couches[$table] = $n;
        $total += $n;
    }
    $nbContributeurs = (int)$pdo->query("SELECT COUNT(*) FROM contributeurs")->fetchColumn();

    $union = [];
    foreach (TABLES as $type => $table) {
        $union[] = "SELECT attributs FROM $table WHERE statut IN ('valide', 'importe_osm')";
    }
    $u = implode(' UNION ALL ', $union);
    $prefectures = $pdo->query(
        "SELECT attributs->>'prefecture' AS prefecture, COUNT(*) AS n
         FROM ($u) t
         WHERE COALESCE(attributs->>'prefecture', '') <> ''
         GROUP BY 1 ORDER BY n DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    repondre(['ok' => true, 'total' => $total, 'couches' => $couches,
              'contributeurs' => $nbContributeurs, 'prefectures' => $prefectures]);

// ------------------------------------------------------------
// ADMIN : statistiques pour le tableau de bord
// (contributions par catégorie / sous-préfecture / statut, et états)
// ------------------------------------------------------------
case 'stats':
    if (!estAdmin()) repondre(['ok' => false, 'erreur' => 'Mot de passe admin incorrect.'], 403);

    $union = [];
    foreach (TABLES as $type => $table) {
        $union[] = "SELECT categorie, attributs, statut FROM $table";
    }
    $u = implode(' UNION ALL ', $union);

    // Répartition par catégorie × sous-préfecture × statut
    $lignes = $pdo->query(
        "SELECT categorie,
                COALESCE(NULLIF(attributs->>'sous_prefecture', ''), '(non précisée)') AS sous_prefecture,
                statut, COUNT(*) AS n
         FROM ($u) t
         GROUP BY 1, 2, 3
         ORDER BY 2, 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Répartition des états renseignés (bon / menacé / dégradé)
    $etats = $pdo->query(
        "SELECT attributs->>'etat' AS etat, COUNT(*) AS n
         FROM ($u) t
         WHERE attributs->>'etat' IN ('bon', 'menace', 'degrade')
           AND statut <> 'rejete'
         GROUP BY 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    repondre(['ok' => true, 'lignes' => $lignes, 'etats' => $etats]);

// ------------------------------------------------------------
default:
    repondre(['ok' => false, 'erreur' => 'Action inconnue.'], 404);
}
