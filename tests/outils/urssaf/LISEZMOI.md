# Serveur d'essai Urssaf

Exécute les règles publiées de l'Urssaf (`modele-social`) derrière la même interface que son API
(`POST /api/v1/evaluate`). Sert aux tests d'intégration des provisions, qui ne peuvent pas compter sur
un service public distant.

    npm install
    PORT=3999 node serveur.mjs

Puis, dans `config.php` : `'urssaf' => ['base_url' => 'http://127.0.0.1:3999']`.

Les versions de `modele-social` et `publicodes` sont figées : le jour où l'Urssaf renomme une règle,
mettre à jour ce paquet montre aussitôt quelles expressions de `src/Urssaf.php` sont à revoir.
