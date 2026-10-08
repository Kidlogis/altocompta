<?php use Bnc\Affectation; use Bnc\Auth; use Bnc\View as V;
$j = V::e(Auth::jeton());
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Opérations exclues</h2>
    <span class="sous"><?= count($lignes) ?> opération<?= count($lignes) > 1 ? 's' : '' ?> écartée(s) de la 2035</span>
    <a href="/affectation" class="btn btn-sm droite">Retour au triage</a>
  </div>

  <div class="champ" style="border-bottom:1px solid var(--trait)">
    <div class="aide">Une opération écartée n'entre pas dans le calcul de la 2035, mais reste
      consultable ici avec son motif — utile en cas de contrôle, ou pour corriger un « Ignorer »
      fait par erreur.</div>
  </div>

  <?php if (!$lignes): ?>
    <div class="vide-etat">Aucune opération exclue sur cet exercice.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:96px">Date</th>
        <th>Opération</th>
        <th style="width:140px">Motif</th>
        <th class="num" style="width:104px">Montant</th>
        <th style="width:90px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lignes as $l): ?>
      <tr>
        <td class="date"><?= V::date($l['date_operation']) ?></td>
        <td>
          <?= V::e($l['libelle']) ?>
          <?php if ($l['compte_libelle']): ?>
            <div class="sous-ligne"><code><?= V::e($l['compte_libelle']) ?></code></div>
          <?php endif; ?>
        </td>
        <td><span class="etiq"><?= V::e(Affectation::MOTIFS_EXCLUSION[$l['motif_exclusion']] ?? 'Autre') ?></span></td>
        <td class="num"><?= V::montant($l['montant']) ?></td>
        <td class="actions">
          <form method="post" action="/affectation/<?= (int) $l['id'] ?>/rouvrir">
            <input type="hidden" name="_jeton" value="<?= $j ?>">
            <button class="lien-mini">Rouvrir</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
