<?php use Bnc\Auth; use Bnc\Boutique; use Bnc\View as V;
$j = V::e(Auth::jeton());
$libEtat = ['absente' => ['Non installée', 'p-att'], 'inactive' => ['Installée, inactive', 'p-att'], 'active' => ['Installée et active', 'p-ok']];
?>
<?php if ($defaut_manquant): ?>
  <p class="alerte" style="margin-bottom:14px">Le catalogue intégré (<code>boutique/kidlogis.json</code>) est absent ou invalide.</p>
<?php endif; ?>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
  <a class="btn btn-p" href="/extensions#importer">Importer une extension (.zip)</a>
  <a class="btn" href="#ajouter">Ajouter une boutique</a>
</div>

<?php foreach ($boutiques as $b): $bt = $b['boutique']; ?>
  <div class="carte" style="margin-bottom:18px">
    <div class="carte-tete">
      <h2><?= V::e($bt['nom']) ?></h2>
      <?php if ($b['integree']): ?><span class="puce p-ok">Boutique officielle</span>
      <?php else: ?><span class="puce p-att">Boutique tierce, non vérifiée</span><?php endif; ?>
      <?php if (!$b['integree']): ?>
        <span class="droite" style="display:flex;gap:6px">
          <?php if (!empty($b['source'])): ?>
            <form method="post" action="/boutique/<?= V::e($b['cle']) ?>/actualiser"><input type="hidden" name="_jeton" value="<?= $j ?>"><button class="btn btn-sm">Actualiser</button></form>
          <?php endif; ?>
          <form method="post" action="/boutique/<?= V::e($b['cle']) ?>/supprimer" onsubmit="return confirm('Retirer cette boutique ? Les extensions déjà installées ne sont pas touchées.')">
            <input type="hidden" name="_jeton" value="<?= $j ?>"><button class="btn btn-sm btn-danger">Retirer</button></form>
        </span>
      <?php endif; ?>
    </div>
    <?php if (!empty($bt['description']) || !empty($bt['url']) || !$b['integree']): ?>
      <div class="champ" style="border-bottom:1px solid var(--trait)">
        <?php if (!empty($bt['description'])): ?><div class="aide"><?= V::e($bt['description']) ?></div><?php endif; ?>
        <?php if (!$b['integree']): ?>
          <div class="aide">Contenu fourni par un tiers : AltoCompta le présente tel quel et n'en garantit ni l'exactitude ni les prix.
            <?= !empty($b['source']) ? 'Importée depuis ' . V::e((string) parse_url($b['source'], PHP_URL_HOST)) . '.' : 'Importée depuis un fichier.' ?></div>
        <?php endif; ?>
        <?php if (!empty($bt['url'])): ?><div class="aide"><a href="<?= V::e($bt['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Site de la boutique</a></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$b['produits']): ?>
      <div class="vide-etat">Cette boutique ne propose aucun produit.</div>
    <?php else: ?>
    <div class="boutique-grille">
      <?php foreach ($b['produits'] as $p):
        $e = Boutique::etat($p, $plugins, $etats, $core);
        [$lib, $cl] = $libEtat[$e['statut']];
        $prix = Boutique::prixAffiche($p['prix']);
        $gratuit = $p['prix']['montant'] !== null && (float) $p['prix']['montant'] === 0.0;
      ?>
        <article class="produit">
          <?php if (!empty($p['image'])): ?>
            <img src="<?= V::e(Boutique::urlImage((string) $p['image'])) ?>" alt="<?= V::e((string) $p['titre']) ?>" loading="lazy" referrerpolicy="no-referrer">
          <?php endif; ?>
          <div class="produit-corps">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <span class="puce <?= $cl ?>"><?= V::e($lib) ?><?= $e['installee'] ? ' · v' . V::e($e['installee']) : '' ?></span>
              <?php if ($e['maj']): ?><span class="puce p-att">Mise à jour : v<?= V::e((string) $p['version']) ?></span><?php endif; ?>
              <?php if (!$e['compatible']): ?><span class="puce p-ko">Demande AltoCompta <?= V::e((string) $p['core_min']) ?></span><?php endif; ?>
            </div>
            <h3><?= V::e((string) $p['titre']) ?></h3>
            <?php if (!empty($p['resume'])): ?><p class="aide" style="margin:0"><?= V::e((string) $p['resume']) ?></p><?php endif; ?>
            <?php if ($prix !== ''): ?><div class="produit-prix"><?= V::e($prix) ?></div><?php endif; ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if (!empty($p['url_achat'])): ?>
                <a class="btn btn-p btn-sm" href="<?= V::e($p['url_achat']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= $gratuit ? 'Obtenir' : 'Acheter' ?></a>
              <?php endif; ?>
              <?php if ($e['statut'] === 'absente' || $e['maj']): ?>
                <a class="btn btn-sm" href="/extensions#importer">Importer le .zip</a>
              <?php endif; ?>
            </div>
            <details>
              <summary>Détails</summary>
              <div class="produit-detail">
                <?php if (!empty($p['description'])): ?><p><?= V::e((string) $p['description']) ?></p><?php endif; ?>
                <?php if ($p['points_forts']): ?><ul><?php foreach ($p['points_forts'] as $pf): ?><li><?= V::e($pf) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if (!empty($p['prerequis'])): ?><p><b>À savoir.</b> <?= V::e((string) $p['prerequis']) ?></p><?php endif; ?>
                <p class="sous-ligne"><?= !empty($p['editeur']) ? V::e((string) $p['editeur']) . ' · ' : '' ?><?= !empty($p['version']) ? 'v' . V::e((string) $p['version']) : '' ?><?= !empty($p['core_min']) ? ' · AltoCompta ' . V::e((string) $p['core_min']) . ' ou plus' : '' ?></p>
              </div>
            </details>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="carte" id="ajouter">
  <div class="carte-tete"><h2>Ajouter une boutique</h2></div>
  <form method="post" action="/boutique/importer" enctype="multipart/form-data">
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="ad">Adresse du catalogue (https)</label>
          <input id="ad" name="adresse" type="url" placeholder="https://exemple.fr/boutique.json" autocomplete="off">
        </div>
        <div class="champ">
          <label for="fi">…ou un fichier .json</label>
          <input id="fi" type="file" name="fichier" accept=".json,application/json">
        </div>
      </div>
    </div>
    <div class="champ">
      <div class="aide">Une boutique ne fait que présenter des extensions et renvoyer vers l'achat : elle ne peut rien installer. Son contenu est
        vérifié champ par champ avant d'être conservé ; une adresse qui pointe vers un réseau interne est refusée, et une redirection n'est pas suivie.</div>
      <details style="margin-top:8px"><summary class="aide" style="cursor:pointer">Format attendu (schéma 1)</summary>
<pre class="empreinte" style="white-space:pre-wrap;margin-top:6px">{
  "schema": 1,
  "boutique": { "id": "ma-boutique", "nom": "Ma boutique", "url": "https://…", "description": "…" },
  "produits": [{
    "id": "mon-module", "extension": "mon-module", "titre": "Mon module",
    "resume": "…", "description": "…", "points_forts": ["…"], "prerequis": "…",
    "version": "1.0.0", "core_min": "2026.10.11",
    "prix": { "montant": 29.9, "devise": "EUR", "periodicite": "unique" },
    "url_achat": "https://…", "image": "https://…/couverture.webp",
    "editeur": "…", "sha256": "empreinte du zip (facultatif)"
  }]
}</pre></details>
    </div>
    <div class="champ barre-actions"><button class="btn btn-p">Importer la boutique</button></div>
  </form>
</div>
