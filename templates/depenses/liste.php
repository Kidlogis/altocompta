<?php use Bnc\Plugin; use Bnc\View as V;
// Renvoi d'une ligne de correction vers l'écriture qu'elle concerne : une dépense
// n'a pas de numéro, on la désigne par sa date et son poste.
$parId = [];
foreach ($lignes as $x) { $parId[(int) $x['id']] = $x; }
$designe = static fn($id) => isset($parId[(int) $id])
    ? V::date($parId[(int) $id]['date_decaissement']) . ' · ' . $parId[(int) $id]['poste_2035'] : null;
$nbCorrections = (int) $nb_total - (int) $nb_actives;
$aVentiler = !empty($filtres['a_ventiler']);
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Registre des dépenses</h2>
    <span class="sous"><?= (int) $nb_actives ?> décaissement<?= $nb_actives > 1 ? 's' : '' ?> · total déductible <?= V::montant($total) ?> €
      <?php if ($nbCorrections > 0 || $epure): ?>
        · <a href="<?= $epure ? '?' : '?epure=1' ?>"><?= $epure ? 'afficher les corrections' : 'masquer les ' . $nbCorrections . ' ligne' . ($nbCorrections > 1 ? 's' : '') . ' de correction' ?></a>
      <?php endif; ?>
      <?php if (!empty($tva) && $nb_a_ventiler > 0 && !$aVentiler): ?>
        · <a href="?a_ventiler=1"><b><?= (int) $nb_a_ventiler ?> à éclater</b> en HT et TVA</a>
      <?php endif; ?></span>
    <?php if ($exercice['statut'] === 'ouvert'): ?>
      <a href="/depenses/registre" class="btn btn-sm droite">Registre PDF</a>
      <a href="/depenses/export" class="btn btn-sm">CSV</a>
      <a href="/depenses/nouvelle" class="btn btn-sm btn-p">Ajouter une dépense</a>
    <?php endif; ?>
  </div>

  <form method="get" class="filtres">
    <input type="search" name="q" value="<?= V::e($filtres['q']) ?>" placeholder="Fournisseur ou libellé…">
    <select name="poste">
      <option value="">Tous les postes</option>
      <?php foreach ($postes as $po): ?>
        <option value="<?= V::e($po['code']) ?>" <?= $filtres['poste'] === $po['code'] ? 'selected' : '' ?>>
          <?= V::e($po['code'] . ' — ' . $po['libelle']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label class="case"><input type="checkbox" name="sans_justif" value="1" <?= $filtres['sans_justificatif'] ? 'checked' : '' ?>> Sans justificatif</label>
    <?php if (!empty($tva)): ?>
      <label class="case"><input type="checkbox" name="a_ventiler" value="1" <?= $aVentiler ? 'checked' : '' ?>> À éclater (HT / TVA)</label>
    <?php endif; ?>
    <button class="btn btn-sm">Filtrer</button>
    <?php if ($filtres['q'] || $filtres['poste'] || $filtres['sans_justificatif'] || $aVentiler): ?>
      <a href="/depenses" class="lien-mini">Effacer</a>
    <?php endif; ?>
  </form>

  <?php if (!$lignes): ?>
    <div class="vide-etat">Aucune dépense ne correspond. Les décaissements arrivent par <?= V::e(Plugin::phraseOrigine()) ?>, ou à la saisie.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:96px">Payé le</th>
        <th>Fournisseur</th>
        <th>Poste 2035</th>
        <th style="width:96px">Justificatif</th>
        <th class="num" style="width:112px"><?= !empty($tva) ? 'TTC' : 'Montant' ?></th>
        <?php if (!empty($tva)): ?><th class="num" style="width:96px">HT</th><th class="num" style="width:88px">TVA</th><?php endif; ?>
        <th class="num" style="width:104px">Déductible</th>
        <th style="width:76px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lignes as $l):
      $statut = $l['statut'];
      $deductible = \Bnc\Tva::retenu($l, true, !empty($tva)) * (int) $l['quote_part_pro'] / 100;
      $cible = $statut === 'annulation' ? $designe($l['contrepasse_id']) : ($l['rectifie_id'] ? $designe($l['rectifie_id']) : null);
    ?>
      <tr<?= $statut !== 'active' ? ' class="' . V::e($statut) . '"' : '' ?>>
        <td class="date"><?= V::date($l['date_decaissement']) ?></td>
        <td>
          <?= V::e($l['fournisseur']) ?>
          <?php if ($statut === 'annulation'): ?>
            <span class="etiq etiq-ann">annulation</span>
          <?php elseif ($statut === 'annulee'): ?>
            <span class="etiq">annulée</span>
          <?php elseif ($l['rectifie_id']): ?>
            <span class="etiq etiq-rect">rectifie la ligne <?= $cible ? 'du ' . V::e($cible) : 'annulée' ?></span>
          <?php endif; ?>
          <div class="sous-ligne"><?= V::e($l['libelle']) ?>
            <?php if ($statut === 'annulation'): ?> · annule la ligne <?= $cible ? 'du ' . V::e($cible) : 'd\'origine' ?><?php endif; ?>
            <?php if ($statut === 'annulee' && $l['motif_annulation']): ?> · <?= V::e($l['motif_annulation']) ?><?php endif; ?></div>
        </td>
        <td>
          <span class="etiq etiq-2035"><?= V::e($l['poste_2035']) ?></span>
          <?= V::e(\Bnc\Poste2035::libelle($l['poste_2035'])) ?>
        </td>
        <td>
          <?php if ($statut === 'annulation'): ?>
            <span class="vide">—</span>
          <?php elseif ((int) $l['nb_justif'] > 0): ?>
            <span class="puce p-ok">Joint</span>
          <?php elseif ($statut === 'annulee'): ?>
            <span class="vide">—</span>
          <?php else: ?>
            <span class="puce p-att">Manquant</span>
          <?php endif; ?>
        </td>
        <td class="num <?= (float) $l['montant'] < 0 ? 'num-c' : 'num-d' ?>">
          <?php if ((int) $l['est_remboursement'] === 1): ?><span class="qp">remb.</span><?php endif; ?>
          <?= V::montant($l['montant'], (float) $l['montant'] < 0) ?>
        </td>
        <?php if (!empty($tva)): ?>
          <?php if ($l['montant_ht'] !== null): ?>
            <td class="num"><?= V::montant($l['montant_ht']) ?></td>
            <td class="num"><?= V::montant($l['montant_tva']) ?><?= $l['tva_non_recuperable'] !== null ? '<div class="sous-ligne">dont ' . V::montant($l['tva_non_recuperable']) . ' non récup.</div>' : '' ?></td>
          <?php elseif ($statut === 'active' && $exercice['statut'] === 'ouvert'): ?>
            <td class="num" colspan="2"><a href="/depenses/<?= (int) $l['id'] ?>/modifier" class="puce p-att">à éclater</a></td>
          <?php else: ?>
            <td class="num vide" colspan="2">—</td>
          <?php endif; ?>
        <?php endif; ?>
        <td class="num">
          <?php if ((int) $l['quote_part_pro'] < 100): ?>
            <span class="qp"><?= (int) $l['quote_part_pro'] ?> %</span>
          <?php endif; ?>
          <?= V::montant($deductible) ?>
        </td>
        <td class="actions">
          <?php if ($exercice['statut'] === 'ouvert' && $statut === 'active'): ?>
            <a href="/depenses/<?= (int) $l['id'] ?>/modifier" class="lien-mini">Modifier</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4" class="totalr">Total des dépenses déductibles — case CE (écritures actives)<?= !empty($tva) ? ', hors taxes' : '' ?></td>
        <td class="num totalr"><?= !empty($totaux) ? V::montant($totaux['ttc']) : '' ?></td>
        <?php if (!empty($tva)): ?><td class="totalr"></td><td class="num totalr"><?= V::montant($totaux['tva'] ?? 0) ?></td><?php endif; ?>
        <td class="num num-d totalr"><?= V::montant($total) ?></td>
        <td class="totalr"></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../pager.php'; ?>
