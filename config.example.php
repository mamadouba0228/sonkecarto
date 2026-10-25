<?php
// ============================================================
// Guinée Carto — Configuration (modèle)
// Copiez ce fichier en config.php et renseignez VOS informations
// alwaysdata (Bases de données → PostgreSQL).
// config.php n'est jamais publié sur GitHub (voir .gitignore).
// ============================================================

// Hôte PostgreSQL alwaysdata : postgresql-VOTRECOMPTE.alwaysdata.net
define('DB_HOST', 'postgresql-VOTRECOMPTE.alwaysdata.net');

// Nom de la base (souvent : votrecompte_guineecarto)
define('DB_NAME', 'votrecompte_guineecarto');

// Utilisateur PostgreSQL (souvent : votrecompte)
define('DB_USER', 'votrecompte');

// Mot de passe défini lors de la création de la base
define('DB_PASS', 'MOT_DE_PASSE_BASE');

// Mot de passe de la page d'administration (validation des données).
// Doit être DIFFÉRENT de DB_PASS.
define('ADMIN_TOKEN', 'MOT_DE_PASSE_ADMIN');
