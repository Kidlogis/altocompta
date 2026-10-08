<?php use Bnc\Auth; use Bnc\Plugin; use Bnc\View as V;
$j = V::e(Auth::jeton());
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Extensions</h2>
    <span class="sous"><?= count($plugins) ?> détectée<?= count($plugins) > 1 ? 's' : '' ?> dans plugins/</span>
    <a class="btn btn-sm btn-p droite" href="#importer">Importer un .zip</a>
  </div>

  <?php foreach ($erreurs_maj ?? [] as $idErr => $msgErr): ?>
    <div class="champ">
      <span class="puce p-ko">Mise à niveau de « <?= V::e($idErr) ?> » en échec</span>
      <div class="aide"><?= V::e($msgErr) ?> L'extension peut dysfonctionner tant que sa base n'est
        pas à jour. Une désactivation puis réactivation relance ses migrations sans perdre de données.</div>
    </div>
  <?php endforeach; ?>

  <?php if (!$plugins): ?>
    <div class="vide-etat">
      Aucune extension installée. Importez le fichier <b>.zip</b> d'une extension avec le formulaire
      ci-dessous (ou choisissez-en une dans la <a href="/boutique">boutique</a>).
    </div>
  <?php else: foreach ($plugins as $id => $m):
    $etat   = $etats[$id] ?? [];
    $actif  = (int) ($etat['actif'] ?? 0) === 1;
  ?>
    <div class="champ">
      <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
        <strong style="font-size:14px"><?= V::e($m['nom']) ?></strong>
        <span class="ref">v<?= V::e((string) ($m['version'] ?? '?')) ?></span>
        <span class="etiq"><?= V::e((string) ($m['licence'] ?? 'inconnue')) ?></span>
        <span class="puce <?= $actif ? 'p-ok' : 'p-att' ?>"><?= $actif ? 'Active' : 'Inactive' ?></span>
      </div>
      <div class="aide" style="margin-top:6px"><?= V::e((string) ($m['description'] ?? '')) ?></div>
      <?php if (!empty($m['auteur'])): ?>
        <div class="sous-ligne"><?= V::e($m['auteur']) ?></div>
      <?php endif; ?>

      <div class="barre-actions" style="margin-top:10px">
        <?php if ($actif): ?>
          <form method="post" action="/extensions/<?= V::e($id) ?>/desactiver">
            <input type="hidden" name="_jeton" value="<?= $j ?>">
            <button class="btn btn-sm">Désactiver</button>
          </form>
        <?php else: ?>
          <form method="post" action="/extensions/<?= V::e($id) ?>/activer">
            <input type="hidden" name="_jeton" value="<?= $j ?>">
            <button class="btn btn-sm btn-p">Activer</button>
          </form>
        <?php endif; ?>

      </div>

    </div>
  <?php endforeach; endif; ?>
</div>

<div class="carte" id="importer" style="margin-top:14px">
  <div class="carte-tete">
    <h2>Importer une extension</h2>
    <a class="btn btn-sm droite" href="/boutique">Voir la boutique</a>
  </div>
  <form method="post" action="/extensions/importer" enctype="multipart/form-data">
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <div class="champ">
      <label for="arc">Fichier .zip de l'extension</label>
      <input id="arc" type="file" name="archive" accept=".zip,application/zip" required>
      <div class="aide">L'achat d'une extension donne un fichier .zip, sans clé de licence : il suffit de l'importer ici.
        Un aperçu s'affiche avant toute installation, et l'ancienne version est sauvegardée en cas de mise à jour.</div>
    </div>
    <div class="champ">
      <p class="avis" style="margin:0;border:0">Une extension est du <b>code PHP exécuté avec les droits de l'application</b>,
        qui accède à toutes vos données. N'importez que des fichiers dont vous connaissez la provenance.</p>
    </div>
    <div class="champ barre-actions"><button class="btn btn-p">Analyser le fichier</button></div>
  </form>
  <div class="champ" style="border-top:1px solid var(--trait)">
    <p class="txt">On peut aussi déposer le dossier de l'extension dans <code>plugins/</code>. Une désactivation ne
      supprime rien : les données de l'extension restent en base et sont retrouvées si elle est réactivée.</p>
  </div>
</div>
