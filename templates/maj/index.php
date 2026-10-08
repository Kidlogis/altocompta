<?php use Bnc\Auth; use Bnc\View as V;
$j = V::e(Auth::jeton());
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Mise à jour</h2>
    <span class="sous">Version installée : <?= $version ? V::e($version) : 'non renseignée' ?></span>
  </div>

  <?php if ($en_attente): ?>
    <div class="champ" style="background:#FDF6E8">
      <span class="puce p-att"><?= count($en_attente) ?> migration<?= count($en_attente) > 1 ? 's' : '' ?> en attente</span>
      <div class="aide" style="margin-top:8px">Le code de l'application est plus récent que la base de données.
        Tant que ce n'est pas rattrapé, certaines pages peuvent afficher une erreur. Aucune donnée n'est perdue :
        les migrations ajoutent des colonnes et des tables, elles n'en retirent pas.</div>
      <div class="aide" style="margin-top:6px;font-family:var(--chiffre)"><?= V::e(implode(' · ', $en_attente)) ?></div>
      <form method="post" action="/mise-a-jour/migrer" style="margin-top:10px"
            onsubmit="return confirm('Appliquer les migrations en attente ? Pensez à avoir une sauvegarde récente.')">
        <input type="hidden" name="_jeton" value="<?= $j ?>">
        <button class="btn btn-p">Appliquer les migrations</button>
      </form>
    </div>
  <?php else: ?>
    <div class="champ"><span class="puce p-ok">Base de données à jour</span></div>
  <?php endif; ?>

  <?php if (!$extension): ?>
    <div class="champ">
      <span class="puce p-ko">Extension PHP zip indisponible</span>
      <div class="aide">Ce serveur ne dispose pas de l'extension <code>zip</code> : le module de
        mise à jour ne peut pas fonctionner. À activer depuis le panneau d'hébergement, ou à
        mettre à jour manuellement en déposant les fichiers par FTP.</div>
    </div>
  <?php elseif ($apercu): ?>
    <div class="champ">
      <span class="puce p-att">Archive en attente de confirmation</span>
      <div class="aide" style="margin-top:8px">
        <?= (int) $apercu['nb_fichiers'] ?> fichier(s) au total —
        <?= (int) $apercu['nb_public'] ?> dans l'interface publique,
        <?= (int) $apercu['nb_autres'] ?> ailleurs.
        <?php if ($apercu['version']): ?>
          Version annoncée : <b><?= V::e($apercu['version']) ?></b>.
        <?php endif; ?>
      </div>
      <p class="txt" style="margin-top:10px">Une sauvegarde complète de l'installation actuelle sera
        créée avant toute écriture. <code>config/config.php</code> et tout le contenu de
        <code>storage/</code> — vos justificatifs compris — ne sont jamais écrasés, même si
        l'archive en contenait.</p>
      <div class="barre-actions" style="margin-top:12px">
        <form method="post" action="/mise-a-jour/appliquer"
              onsubmit="return confirm('Appliquer cette mise à jour maintenant ?')">
          <input type="hidden" name="_jeton" value="<?= $j ?>">
          <button class="btn btn-p">Confirmer et appliquer</button>
        </form>
        <form method="post" action="/mise-a-jour/annuler">
          <input type="hidden" name="_jeton" value="<?= $j ?>">
          <button class="btn">Annuler</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <form method="post" action="/mise-a-jour/analyser" enctype="multipart/form-data" class="champ">
      <input type="hidden" name="_jeton" value="<?= $j ?>">
      <label for="archive">Archive de mise à jour (.zip)</label>
      <input id="archive" name="archive" type="file" accept=".zip" required>
      <div class="aide">Le fichier fourni avec chaque livraison. Remplace les fichiers de
        l'application ; n'importe jamais <code>config/config.php</code> ni <code>storage/</code>.
        Une confirmation sera demandée avant toute écriture.</div>
      <button class="btn btn-p" style="margin-top:10px">Analyser l'archive</button>
    </form>
  <?php endif; ?>
</div>

<div class="carte" style="margin-top:14px">
  <div class="carte-tete">
    <h2>Sauvegardes</h2>
    <span class="sous"><?= count($sauvegardes) ?> conservée(s) — les 5 plus récentes</span>
    <form method="post" action="/mise-a-jour/sauvegarder" class="droite">
      <input type="hidden" name="_jeton" value="<?= $j ?>">
      <button class="btn btn-sm">Sauvegarder maintenant</button>
    </form>
  </div>

  <?php if (!$sauvegardes): ?>
    <div class="vide-etat">Aucune sauvegarde. La première mise à jour en créera une automatiquement.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Fichier</th><th style="width:110px">Taille</th><th style="width:90px"></th></tr></thead>
    <tbody>
    <?php foreach ($sauvegardes as $s): ?>
      <tr>
        <td><?= V::e($s['nom']) ?><div class="sous-ligne"><?= V::date(date('Y-m-d', $s['date'])) ?></div></td>
        <td class="num"><?= number_format($s['taille'] / 1048576, 1, ',', ' ') ?> Mo</td>
        <td class="actions">
          <a href="/mise-a-jour/sauvegardes/<?= rawurlencode($s['nom']) ?>/telecharger" class="lien-mini">Télécharger</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="champ" style="border-top:1px solid var(--trait)">
    <div class="aide">Une sauvegarde est une archive complète — config comprise — à restaurer
      manuellement (dépôt FTP) en cas de besoin : il n'y a volontairement pas de restauration en
      un clic, pour ne pas ajouter un second mécanisme à haut risque à côté de la mise à jour.</div>
  </div>
</div>
