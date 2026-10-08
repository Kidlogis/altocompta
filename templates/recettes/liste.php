<?php use Bnc\Plugin; use Bnc\View as V;
// Renvoi d'une ligne de correction vers l'écriture qu'elle concerne, par son numéro.
$numeros = [];
foreach ($lignes as $x) { $numeros[(int) $x['id']] = (int) $x['numero_chrono']; }
$ref = static fn($id) => isset($numeros[(int) $id])
    ? str_pad((string) $numeros[(int) $id], 3, '0', STR_PAD_LEFT) : null;
$nbCorrections = (int) $nb_total - (int) $nb_actives;
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Livre des recettes</h2>
    <span class="sous"><?= (int) $nb_actives ?> encaissement<?= $nb_actives > 1 ? 's' : '' ?> · total <?= V::montant($total) ?> €
      <?php if ($nbCorrections > 0 || $epure): ?>
        · <a href="<?= $epure ? '?' : '?epure=1' ?>"><?= $epure ? 'afficher les corrections' : 'masquer les ' . $nbCorrections . ' ligne' . ($nbCorrections > 1 ? 's' : '') . ' de correction' ?></a>
      <?php endif; ?>
      <?php if (!empty($tva) && $nb_a_ventiler > 0 && empty($a_ventiler)): ?>
        · <a href="?a_ventiler=1"><b><?= (int) $nb_a_ventiler ?> à éclater</b> en HT et TVA</a>
      <?php elseif (!empty($a_ventiler)): ?>
        · à éclater seulement — <a href="/recettes">tout afficher</a>
      <?php endif; ?></span>
    <form method="get" class="droite recherche">
      <input type="search" name="q" value="<?= V::e($q) ?>" placeholder="Client, nature, facture…">
    </form>
    <?php if ($exercice['statut'] === 'ouvert'): ?>
      <a href="/recettes/registre" class="btn btn-sm">Registre PDF</a>
      <a href="/recettes/export" class="btn btn-sm">CSV</a>
      <a href="/recettes/nouvelle" class="btn btn-sm btn-p">Ajouter une recette</a>
    <?php endif; ?>
  </div>

  <?php if (!$lignes): ?>
    <div class="vide-etat">
      <?= $q !== '' ? 'Aucune recette ne correspond à cette recherche.'
                    : 'Aucune recette sur cet exercice. Les encaissements arrivent par ' . Plugin::phraseOrigine() . ', ou à la saisie.' ?>
    </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:52px">N°</th>
        <th style="width:96px">Encaissé le</th>
        <th>Client</th>
        <th>Nature</th>
        <th style="width:104px">Règlement</th>
        <th class="num" style="width:112px"><?= !empty($tva) ? 'TTC' : 'Montant' ?></th>
        <?php if (!empty($tva)): ?><th class="num" style="width:96px">HT</th><th class="num" style="width:88px">TVA</th><?php endif; ?>
        <th style="width:76px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lignes as $l):
      $statut = $l['statut'];
      $cible  = $statut === 'annulation' ? $ref($l['contrepasse_id']) : ($l['rectifie_id'] ? $ref($l['rectifie_id']) : null);
    ?>
      <tr<?= $statut !== 'active' ? ' class="' . V::e($statut) . '"' : '' ?>>
        <td class="ref"><?= str_pad((string) $l['numero_chrono'], 3, '0', STR_PAD_LEFT) ?></td>
        <td class="date"><?= V::date($l['date_encaissement']) ?></td>
        <td><?= V::e($l['client']) ?>
          <?php if ($statut === 'annulation'): ?>
            <span class="etiq etiq-ann">annulation</span>
            <div class="sous-ligne">annule la n° <?= $cible ?? '—' ?><?= $l['notes'] ? ' · ' . V::e($l['notes']) : '' ?></div>
          <?php elseif ($statut === 'annulee'): ?>
            <span class="etiq">annulée</span>
            <?php if ($l['motif_annulation']): ?><div class="sous-ligne"><?= V::e($l['motif_annulation']) ?></div><?php endif; ?>
          <?php elseif ($l['rectifie_id']): ?>
            <span class="etiq etiq-rect">rectifie la n° <?= $cible ?? '—' ?></span>
          <?php endif; ?></td>
        <td>
          <?= V::e($l['nature']) ?>
          <?php if ($l['reference_facture']): ?><span class="etiq etiq-2035"><?= V::e($l['reference_facture']) ?></span><?php endif; ?>
        </td>
        <td><span class="etiq"><?= V::e(ucfirst((string) $l['mode_reglement'])) ?></span></td>
        <td class="num <?= (float) $l['montant'] < 0 ? 'num-d' : 'num-c' ?>">
          <?php if ((int) $l['est_avoir'] === 1): ?><span class="qp">avoir</span><?php endif; ?>
          <?= V::montant($l['montant'], (float) $l['montant'] < 0) ?>
        </td>
        <?php if (!empty($tva)): ?>
          <?php if ($l['montant_ht'] !== null): ?>
            <td class="num"><?= V::montant($l['montant_ht']) ?></td><td class="num"><?= V::montant($l['montant_tva']) ?></td>
          <?php elseif ($statut === 'active' && $exercice['statut'] === 'ouvert'): ?>
            <td class="num" colspan="2"><a href="/recettes/<?= (int) $l['id'] ?>/modifier" class="puce p-att">à éclater</a></td>
          <?php else: ?>
            <td class="num vide" colspan="2">—</td>
          <?php endif; ?>
        <?php endif; ?>
        <td class="actions">
          <?php if ($exercice['statut'] === 'ouvert' && $statut === 'active'): ?>
            <a href="/recettes/<?= (int) $l['id'] ?>/modifier" class="lien-mini">Modifier</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5" class="totalr">Total des recettes encaissées — case AA (écritures actives)<?= !empty($tva) ? ' · TTC, HT retenu et TVA' : '' ?></td>
        <?php if (!empty($tva) && !empty($totaux)): ?>
          <td class="num num-c totalr"><?= V::montant($totaux['ttc']) ?></td>
          <td class="num num-c totalr"><?= V::montant($totaux['ht']) ?></td>
          <td class="num totalr"><?= V::montant($totaux['tva']) ?></td>
        <?php else: ?>
          <td class="num num-c totalr"><?= V::montant($total) ?></td>
        <?php endif; ?>
        <td class="totalr"></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../pager.php'; ?>
