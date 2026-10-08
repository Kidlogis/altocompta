<?php use Bnc\Auth; use Bnc\Plugin; use Bnc\View as V;
$u = Auth::utilisateur();

// Une tuile : pictogramme Iconoir sur fond de couleur, à la Windows 10.
// La couleur est un repère visuel par destination, pas une donnée a retenir.
$tuile = static function (string $url, string $icone, string $couleur, string $libelle,
                          string $actifKey, ?int $badge = null) use (&$actif) {
    // Sur un fond clair (un jaune, par exemple), le blanc ne se lit plus : on
    // bascule le texte et le pictogramme en sombre. Luminosité perçue, 0 à 255.
    $clair = false;
    if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $couleur, $m)) {
        $clair = (299 * hexdec($m[1]) + 587 * hexdec($m[2]) + 114 * hexdec($m[3])) / 1000 > 160;
    }
    printf(
        '<a href="%s" class="tuile-nav%s" style="background:%s' . ($clair ? ';color:#1B1B1B' : '') . '">'
        . '<span class="tuile-nav-icone">%s</span>'
        . '<span class="tuile-nav-texte">%s</span>%s</a>' . "\n",
        V::e($url),
        ($actif ?? '') === $actifKey ? ' on' : '',
        V::e($couleur),
        V::icone($icone),
        V::e($libelle),
        $badge ? '<span class="tuile-nav-badge">' . (int) $badge . '</span>' : ''
    );
};

// Les entrées apportées par les extensions actives, regroupées par section.
// Icône et couleur par défaut si une future extension n'en déclare pas.
$iconesPlugins   = ['immobilisations' => 'immobilisations', 'altoviz' => 'altoviz'];
$couleursPlugins = ['immobilisations' => '#008575', 'altoviz' => '#0078D4'];
$parSection = [];
foreach (Plugin::navigation() as $e) {
    $parSection[$e['section'] ?? 'Extensions'][] = $e;
}
$rendreSection = static function (string $section) use ($parSection, $tuile, $iconesPlugins, $couleursPlugins) {
    foreach ($parSection[$section] ?? [] as $e) {
        $cle = $e['actif'] ?? '';
        // L'entrée porte sa propre icône et sa couleur ; les tables ci-dessus ne
        // servent plus qu'aux extensions qui n'en déclarent pas.
        $tuile($e['url'], $e['icone'] ?? $iconesPlugins[$cle] ?? 'extensions',
               $e['couleur'] ?? $couleursPlugins[$cle] ?? '#5C2D91', $e['libelle'], $cle);
    }
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= V::e($titre_page ?? 'AltoCompta') ?> — AltoCompta</title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
<link rel="icon" type="image/png" sizes="64x64" href="/assets/img/favicon-64.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= V::asset('/assets/app.css') ?>">
</head>
<body>
<div class="app">
  <aside class="rail">
    <div class="marque">
      <img src="<?= V::asset('/assets/img/logo-880.png') ?>" alt="AltoCompta" class="marque-logo">
      <span><?= V::e($u['denomination'] ?: $u['nom']) ?></span>
    </div>
    <nav class="nav">
      <div class="tuiles-titre">Registres</div>
      <div class="tuiles-nav">
        <?php
        $tuile('/', 'dashboard', '#0F6FC6', 'Tableau de bord', 'bord');
        $tuile('/recettes', 'recettes', '#107C10', 'Livre des recettes', 'recettes');
        $tuile('/depenses', 'depenses', '#D83B01', 'Registre des dépenses', 'depenses');
        $tuile('/vehicules', 'vehicules', '#5C2D91', 'Véhicules et trajets', 'vehicules');
        $rendreSection('Registres');
        ?>
      </div>

      <div class="tuiles-titre">Banque</div>
      <div class="tuiles-nav">
        <?php
        $tuile('/affectation', 'triage', '#CA5010', 'À affecter', 'triage', $a_affecter ?? null);
        $tuile('/comptes', 'comptes', '#004E8C', 'Comptes bancaires', 'comptes');
        $tuile('/categories', 'categories', '#8764B8', 'Catégories', 'categories');
        $tuile('/import', 'import', '#038387', 'Importer un relevé', 'import');
        $tuile('/doublons', 'doublons', '#8A6D00', 'Doublons', 'doublons');
        $tuile('/regularisations', 'regularisations', '#B4009E', 'Régularisations', 'regularisations');
        $rendreSection('Banque');
        ?>
      </div>

      <div class="tuiles-titre">Déclaration</div>
      <div class="tuiles-nav">
        <?php
        $tuile('/declaration', 'f2035', '#498205', 'Formulaire 2035', '2035');
        $tuile('/declaration/2035e', 'f2035e', '#005B70', 'Annexe 2035-E', '2035e');
        $tuile('/provisions', 'provisions', '#69797E', 'Provisions', 'provisions');
        if (!empty($tva)) { $tuile('/tva', 'tva', '#4F6BED', 'TVA', 'tva'); }
        $tuile('/journal', 'journal', '#4A154B', 'Journal', 'journal');
        $tuile('/archives', 'archives', '#2D5016', 'Archives', 'archives');
        ?>
      </div>

      <div class="tuiles-titre">Réglages</div>
      <div class="tuiles-nav">
        <?php
        $tuile('/profil', 'profil', '#767676', 'Mon compte', 'profil');
        if (\Bnc\Utilisateur::estAdmin($u)) {
            $tuile('/utilisateurs', 'utilisateurs', '#603CBA', 'Comptes', 'utilisateurs');
            $tuile('/boutique', 'boutique', '#6B4FBB', 'Boutique', 'boutique');
            $tuile('/extensions', 'extensions', '#E3008C', 'Extensions', 'extensions');
            $tuile('/mise-a-jour', 'maj', '#B7472A', 'Mise à jour', 'mise-a-jour');
        }
        $rendreSection('Réglages');
        ?>
      </div>
    </nav>
    <div class="rail-pied">
      <b><?= V::e($u['nom']) ?></b>
      <form method="post" action="/deconnexion">
        <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
        <button type="submit" class="lien-pied">Se déconnecter</button>
      </form>
    </div>
  </aside>

  <div>
    <header class="tete">
      <h1><?= V::e($titre_page ?? '') ?></h1>
      <?php if (!empty($exercices)): ?>
      <form method="post" action="/exercice" class="exercice">
        <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
        <input type="hidden" name="retour" value="<?= V::e($_SERVER['REQUEST_URI'] ?? '/') ?>">
        <label for="ex">Exercice</label>
        <select id="ex" name="exercice_id" onchange="this.form.submit()">
          <?php foreach ($exercices as $x): ?>
            <option value="<?= (int) $x['id'] ?>" <?= $x['id'] == $exercice['id'] ? 'selected' : '' ?>>
              <?= (int) $x['annee'] ?><?= $x['statut'] === 'clos' ? ' (clos)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <details class="exercice-neuf">
        <summary class="btn btn-sm" title="Créer un nouvel exercice">+ Nouvel exercice</summary>
        <form method="post" action="/exercice/creer" class="exercice-neuf-corps">
          <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
          <input type="hidden" name="retour" value="<?= V::e(Auth::cheminLocal($_SERVER['REQUEST_URI'] ?? '/')) ?>">
          <label for="ex-annee">Année du nouvel exercice</label>
          <input id="ex-annee" name="annee" type="number" required
                 min="<?= \Bnc\Exercice::ANNEE_MIN ?>" max="<?= (int) date('Y') + 1 ?>"
                 value="<?= \Bnc\Exercice::anneeProposee($exercices, (int) date('Y')) ?>">
          <button class="btn btn-p btn-sm">Créer l'exercice</button>
          <div class="aide">Du 1er janvier au 31 décembre. L'exercice créé devient l'exercice affiché ;
            les autres ne sont pas modifiés, et clore l'exercice précédent reste à faire.</div>
        </form>
      </details>
      <?php endif; ?>
      <?php if (isset($resultat)): ?>
      <div class="jauge">
        <span>Résultat</span>
        <strong class="<?= $resultat >= 0 ? 'num-c' : 'num-d' ?>"><?= V::montant($resultat, true) ?> €</strong>
      </div>
      <?php endif; ?>
    </header>

    <main>
      <?php if (!empty($flash)): ?>
        <div class="flash flash-<?= V::e($flash['type']) ?>"><?= V::e($flash['message']) ?></div>
      <?php endif; ?>
      <?= $contenu ?>
    </main>
  </div>
</div>
<script src="<?= V::asset('/assets/app.js') ?>" defer></script>
</body>
</html>
