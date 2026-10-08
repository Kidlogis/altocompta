<?php use Bnc\Auth; use Bnc\View as V;
$j = V::e(Auth::jeton());
$m = $apercu['manifeste'];
$verbe = match ($apercu['action']) {
    'mise_a_jour' => 'Mise à jour de la v' . ($apercu['installee'] ?? '?') . ' vers la v' . $m['version'],
    'identique'   => 'Réinstallation de la v' . $m['version'] . ' (même version)',
    default       => 'Nouvelle installation',
};
$ko = $apercu['catalogue'] !== null && !$apercu['catalogue']['conforme'];
?>
<div class="carte">
  <div class="carte-tete"><h2>Importer une extension</h2><span class="sous">Rien n'est installé tant que vous n'avez pas confirmé</span></div>

  <div class="champ">
    <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
      <strong style="font-size:16px"><?= V::e($m['nom']) ?></strong>
      <span class="ref">v<?= V::e($m['version']) ?></span>
      <?php if ($m['licence'] !== ''): ?><span class="etiq"><?= V::e($m['licence']) ?></span><?php endif; ?>
      <span class="puce p-att"><?= V::e($verbe) ?></span>
    </div>
    <?php if ($m['description'] !== ''): ?><div class="aide" style="margin-top:6px"><?= V::e($m['description']) ?></div><?php endif; ?>
    <div class="sous-ligne" style="margin-top:6px">
      Identifiant <code><?= V::e($apercu['id']) ?></code>
      <?= $m['auteur'] !== '' ? ' · ' . V::e($m['auteur']) : '' ?>
      · <?= (int) $apercu['fichiers'] ?> fichier<?= $apercu['fichiers'] > 1 ? 's' : '' ?>, <?= V::e(number_format($apercu['octets'] / 1024, 0, ',', ' ')) ?> Ko
      <?= $m['core_min'] !== '' ? ' · demande AltoCompta ' . V::e($m['core_min']) . ' ou plus' : '' ?>
    </div>
  </div>

  <div class="champ" style="border-top:1px solid var(--trait)">
    <label>Empreinte SHA-256 du fichier</label>
    <div class="empreinte"><?= V::e((string) $apercu['sha256']) ?></div>
    <?php if ($apercu['catalogue'] === null): ?>
      <div class="aide" style="margin-top:6px">Aucune boutique connue ne publie d'empreinte pour cette version : rien ne permet de
        confirmer que ce fichier est celui du vendeur. Comparez avec l'empreinte affichée sur sa page, si elle en donne une.</div>
    <?php elseif ($apercu['catalogue']['conforme']): ?>
      <div style="margin-top:6px"><span class="puce p-ok">Conforme</span>
        <span class="aide">Cette empreinte est celle que publie « <?= V::e($apercu['catalogue']['boutique']) ?> » pour cette version.</span></div>
    <?php else: ?>
      <p class="alerte" style="margin-top:8px"><b>Ce fichier ne correspond pas à l'empreinte publiée</b> par « <?= V::e($apercu['catalogue']['boutique']) ?> »
        pour cette version. Il a pu être modifié, ou ne pas être l'original : ne l'installez pas sans savoir pourquoi.</p>
    <?php endif; ?>
  </div>

  <form method="post" action="/extensions/importer/confirmer">
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <div class="champ" style="border-top:1px solid var(--trait)">
      <p class="avis" style="margin:0 0 10px;border:0">Une extension est du <b>code PHP exécuté avec les droits de l'application</b> :
        elle peut lire et modifier toutes vos données. Cette analyse vérifie la forme du fichier, pas ses intentions.</p>
      <label style="display:flex;gap:8px;align-items:flex-start;font-weight:500">
        <input type="checkbox" name="confiance" value="1" style="width:auto;margin-top:3px">
        <span>Je fais confiance à la source de ce fichier<?= $ko ? ', malgré l\'empreinte qui ne correspond pas' : '' ?>.</span>
      </label>
    </div>
    <div class="champ barre-actions">
      <button class="btn btn-p">Installer l'extension</button>
      <button class="btn" formaction="/extensions/importer/annuler">Annuler</button>
    </div>
  </form>
  <div class="champ" style="border-top:1px solid var(--trait)"><div class="aide">
    <?= $apercu['action'] === 'installation'
        ? 'Elle sera copiée dans plugins/, inactive : il restera à l\'activer, ce qui applique ses migrations.'
        : 'L\'ancienne version est sauvegardée dans storage/sauvegardes/ avant d\'être remplacée. Les données de l\'extension sont conservées.' ?>
  </div></div>
</div>
