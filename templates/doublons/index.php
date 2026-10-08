<?php use Bnc\Auth; use Bnc\View as V;
$j = V::e(Auth::jeton());

/** Une moitié de paire : ce qu'il faut pour décider laquelle garder. */
$fiche = static function (array $t, string $titre) use ($j): void {
    $etat = match ($t['statut']) {
        'affecte' => 'déjà passée en ' . ($t['affecte_type'] ?? 'écriture'),
        'ignore'  => 'ignorée',
        default   => 'en attente d\'affectation',
    };
    ?>
    <div class="doublon-moitie">
      <div class="sous-ligne"><?= V::e($titre) ?></div>
      <div class="doublon-lib"><?= V::e($t['libelle']) ?></div>
      <div class="sous-ligne">
        <?= V::date($t['date_operation']) ?>
        · <?= V::e($t['compte_nom'] ?? $t['compte'] ?? 'compte non rattaché') ?>
        · <span class="etiq"><?= V::e($t['source']) ?></span>
      </div>
      <div class="sous-ligne"><?= V::e($etat) ?></div>
    </div>
    <?php
};
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Doublons probables</h2>
    <span class="sous"><?= count($paires) ?> paire<?= count($paires) > 1 ? 's' : '' ?> à examiner</span>
    <a href="/affectation" class="btn btn-sm droite">Retour au triage</a>
  </div>

  <div class="champ" style="border-bottom:1px solid var(--trait)">
    <div class="aide">Une même opération peut arriver deux fois sous des formes différentes : une
      opération « en attente » puis sa version définitive, le même relevé reçu de deux sources, deux
      comptes qui se recoupent. Rien n'est supprimé ni décidé automatiquement. Deux opérations
      identiques d'un même compte le même jour ne sont jamais proposées : une banque produit parfois
      exactement cela, et ce sont deux opérations réelles.</div>
  </div>

  <?php if (!$paires): ?>
    <div class="vide-etat">Aucun doublon probable parmi les opérations importées.</div>
  <?php endif; ?>

  <?php foreach ($paires as $p): $a = $p['a']; $b = $p['b']; ?>
    <div class="doublon-paire">
      <div class="doublon-entete">
        <b><?= V::montant($a['montant'], true) ?> €</b>
        <span class="sous"><?= V::e($p['raison']) ?></span>
      </div>
      <div class="doublon-corps">
        <?php $fiche($a, 'Opération A'); ?>
        <?php $fiche($b, 'Opération B'); ?>
      </div>
      <div class="doublon-actions">
        <?php foreach ([[$a, $b, 'A'], [$b, $a, 'B']] as [$garde, $ecarte, $lettre]): ?>
          <form method="post" action="/doublons/resoudre"
                onsubmit="return confirm('Garder l\'opération <?= $lettre ?> et écarter l\'autre ?<?= $ecarte['statut'] === 'affecte' ? ' Son écriture sera annulée (avec une ligne d\'annulation).' : '' ?>')">
            <input type="hidden" name="_jeton" value="<?= $j ?>">
            <input type="hidden" name="garder" value="<?= (int) $garde['id'] ?>">
            <input type="hidden" name="ecarter" value="<?= (int) $ecarte['id'] ?>">
            <button class="btn btn-sm">Garder <?= $lettre ?>, écarter l'autre</button>
          </form>
        <?php endforeach; ?>
        <form method="post" action="/doublons/ecarter">
          <input type="hidden" name="_jeton" value="<?= $j ?>">
          <input type="hidden" name="a" value="<?= (int) $a['id'] ?>">
          <input type="hidden" name="b" value="<?= (int) $b['id'] ?>">
          <button class="btn btn-sm">Ce ne sont pas des doublons</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
