<?php use Bnc\Auth; use Bnc\TvaPeriodes; use Bnc\View as V;
$j  = V::e(Auth::jeton());
$e  = static fn($c) => V::montant(((int) $c) / 100);
$p  = $tp['periodes'];
$t  = $tp['totaux'];
$ouvert = $exercice['statut'] === 'ouvert';
$nbEclater = $tp['a_eclater']['recettes'] + $tp['a_eclater']['depenses'];
$explications = [
    'mensuelle'     => 'une déclaration chaque mois (CA3, régime réel normal)',
    'trimestrielle' => 'une déclaration chaque trimestre (CA3, régime réel normal, sur option ou si la TVA annuelle est faible)',
    'annuelle'      => 'une déclaration par an (CA12, régime simplifié), avec deux acomptes en cours d\'année',
];
$etats = ['comptee' => ['comptée', 'p-ok'], 'a_renseigner' => ['TVA à renseigner', 'p-att'], 'exclue' => ['non comptée', 'p-att'], 'sans_virement' => ['TVA inconnue', 'p-att'],
          'incoherente' => ['incohérente', 'p-ko'], 'sans_tva' => ['sans TVA', '']];
$formPeriodicite = static function (?string $courante, string $jeton) use ($explications): string {
    ob_start(); ?>
  <form method="post" action="/tva/periodicite">
    <input type="hidden" name="_jeton" value="<?= $jeton ?>">
    <div class="champ">
      <div class="modes">
        <?php foreach (TvaPeriodes::PERIODICITES as $k => $lib): ?>
          <label><input type="radio" name="periodicite" value="<?= $k ?>" <?= $courante === $k ? 'checked' : '' ?> required>
            <span><b><?= V::e($lib) ?></b> — <?= V::e($explications[$k]) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="aide">Elle dépend de votre régime d'imposition à la TVA, que l'application ne peut pas deviner : elle figure sur votre
        espace professionnel impots.gouv.fr.</div>
    </div>
    <div class="champ barre-actions"><button class="btn btn-p">Enregistrer</button></div>
  </form>
<?php return (string) ob_get_clean(); };
?>

<?php if ($tp['periodicite'] === null): ?>
  <div class="carte" style="margin-bottom:14px">
    <div class="carte-tete"><h2>Votre périodicité de déclaration</h2></div>
    <?= $formPeriodicite(null, $j) ?>
    <div class="champ" style="border-top:1px solid var(--trait)">
      <div class="aide">En attendant, l'exercice est totalisé d'un bloc, ci-dessous.</div>
    </div>
  </div>
<?php endif; ?>

<div class="carte">
  <div class="carte-tete">
    <h2>TVA — exercice <?= (int) $exercice['annee'] ?></h2>
    <span class="sous">
      <?= $tp['periodicite'] !== null ? V::e(TvaPeriodes::PERIODICITES[$tp['periodicite']]) . ' · <a href="#periodicite">changer</a>' : 'périodicité non choisie' ?>
    </span>
    <a href="/tva/export" class="btn btn-sm droite">CSV</a>
  </div>

  <?php foreach ($tp['avertissements'] as $av): ?>
    <p class="alerte" style="margin:0;border-radius:0;border-width:0 0 1px"><?= V::e($av) ?></p>
  <?php endforeach; ?>

  <?php if ($nbEclater > 0): ?>
    <p class="avis" style="margin:0;border-radius:0;border-width:0 0 1px">
      <b><?= (int) $nbEclater ?> écriture<?= $nbEclater > 1 ? 's' : '' ?> non éclatée<?= $nbEclater > 1 ? 's' : '' ?> en HT et TVA</b>
      (<?= (int) $tp['a_eclater']['recettes'] ?> recette<?= $tp['a_eclater']['recettes'] > 1 ? 's' : '' ?>,
      <?= (int) $tp['a_eclater']['depenses'] ?> dépense<?= $tp['a_eclater']['depenses'] > 1 ? 's' : '' ?>) :
      leur TVA est inconnue et <b>n'est pas comptée</b>. Les chiffres ci-dessous sont donc incomplets tant qu'elles ne sont pas éclatées —
      <?php if ($tp['a_eclater']['recettes'] > 0): ?><a href="/recettes?a_ventiler=1">recettes</a><?php endif; ?>
      <?= $tp['a_eclater']['recettes'] > 0 && $tp['a_eclater']['depenses'] > 0 ? ' · ' : '' ?>
      <?php if ($tp['a_eclater']['depenses'] > 0): ?><a href="/depenses?a_ventiler=1">dépenses</a><?php endif; ?>.
    </p>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Période</th>
        <th class="num">TVA collectée</th>
        <th class="num">Déductible<br><span class="sous">dépenses</span></th>
        <th class="num">Déductible<br><span class="sous">acquisitions</span></th>
        <th class="num">TVA nette</th>
        <th class="num">Crédit reporté</th>
        <th class="num">À payer</th>
        <th class="num">Crédit à reporter</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($p as $l): ?>
      <tr>
        <td>
          <b><?= V::e($l['libelle']) ?></b>
          <?php if ($l['nb_a_eclater'] > 0): ?><span class="puce p-att" title="Écritures de cette période sans TVA connue"><?= (int) $l['nb_a_eclater'] ?> à éclater</span><?php endif; ?>
          <div class="sous-ligne">du <?= V::date($l['debut']) ?> au <?= V::date($l['fin']) ?><?= $l['partielle'] ? ' · période partielle' : '' ?> · <?= (int) $l['nb_ecritures'] ?> écriture<?= $l['nb_ecritures'] > 1 ? 's' : '' ?></div>
        </td>
        <td class="num"><?= $e($l['collectee_c']) ?></td>
        <td class="num"><?= $e($l['deductible_c']) ?></td>
        <td class="num"><?= $e($l['acquisitions_c']) ?></td>
        <td class="num"><?= $e($l['net_c']) ?></td>
        <td class="num <?= $l['credit_entrant_c'] == 0 ? 'vide' : '' ?>"><?= $l['credit_entrant_c'] == 0 ? '—' : $e($l['credit_entrant_c']) ?></td>
        <td class="num <?= $l['a_payer_c'] > 0 ? 'num-d' : 'vide' ?>"><b><?= $l['a_payer_c'] > 0 ? $e($l['a_payer_c']) : '—' ?></b></td>
        <td class="num <?= $l['credit_sortant_c'] > 0 ? 'num-c' : 'vide' ?>"><?= $l['credit_sortant_c'] > 0 ? $e($l['credit_sortant_c']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="totalr">Total de l'exercice</td>
        <td class="num totalr"><?= $e($t['collectee_c']) ?></td>
        <td class="num totalr"><?= $e($t['deductible_c']) ?></td>
        <td class="num totalr"><?= $e($t['acquisitions_c']) ?></td>
        <td class="num totalr"><?= $e($t['net_c']) ?></td>
        <td class="num totalr"><?= $tp['credit_initial_c'] > 0 ? $e($tp['credit_initial_c']) . ' <span class="sous">à l\'ouverture</span>' : '—' ?></td>
        <td class="num num-d totalr"><?= $e($t['a_payer_c']) ?></td>
        <td class="num num-c totalr"><?= $tp['credit_final_c'] > 0 ? $e($tp['credit_final_c']) : '—' ?></td>
      </tr>
    </tfoot>
  </table>
</div>

<div class="profil-grille" style="margin-top:14px">
  <div>
    <div class="carte">
      <div class="carte-tete"><h2>Crédit de TVA reporté à l'ouverture</h2></div>
      <form method="post" action="/tva/credit">
        <input type="hidden" name="_jeton" value="<?= $j ?>">
        <div class="champ">
          <label for="cr">Crédit venant de l'exercice précédent <span class="sous">en €</span></label>
          <input id="cr" name="credit" inputmode="decimal" style="max-width:180px" <?= $ouvert ? '' : 'readonly' ?>
                 value="<?= V::e(number_format($tp['credit_initial_c'] / 100, 2, ',', '')) ?>">
          <div class="aide">Un crédit de TVA se reporte de déclaration en déclaration jusqu'à son remboursement. À renseigner pour le premier
            exercice suivi ici, ou si un crédit existait avant AltoCompta.</div>
        </div>
        <?php if ($ouvert): ?><div class="champ barre-actions"><button class="btn btn-sm btn-p">Enregistrer</button></div><?php endif; ?>
      </form>
      <?php if ($ouvert && $precedent !== null && $precedent['credit_c'] > 0 && $tp['credit_initial_c'] != $precedent['credit_c']): ?>
        <form method="post" action="/tva/credit/reprendre" class="champ" style="border-top:1px solid var(--trait)">
          <input type="hidden" name="_jeton" value="<?= $j ?>">
          <p class="avis" style="margin:0 0 8px;border:0">L'exercice <?= (int) $precedent['annee'] ?> s'est achevé sur un crédit de
            <b><?= $e($precedent['credit_c']) ?> €</b>. Le reprendre ici ?</p>
          <button class="btn btn-sm">Reprendre <?= $e($precedent['credit_c']) ?> €</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($tp['periodicite'] !== null): ?>
    <div class="carte" id="periodicite" style="margin-top:14px">
      <div class="carte-tete"><h2>Périodicité</h2></div>
      <?= $formPeriodicite($tp['periodicite'], $j) ?>
    </div>
    <?php endif; ?>
  </div>

  <aside class="profil-cote">
    <div class="carte">
      <div class="carte-tete"><h2>Ce que ces chiffres supposent</h2></div>
      <div class="champ">
        <ul style="margin:0 0 0 18px;padding:0">
          <li class="aide" style="margin-bottom:6px"><b>La TVA se compte à la date de l'écriture</b> : à l'encaissement pour les recettes, au paiement
            pour les dépenses. C'est la règle des prestations de services. Une vente de <b>biens</b> (exigible à la livraison) et
            l'<b>option pour les débits</b> ne sont pas gérées.</li>
          <li class="aide" style="margin-bottom:6px">La TVA déductible d'une dépense est celle de la facture, moins la part non récupérable, au prorata professionnel.</li>
          <li class="aide" style="margin-bottom:6px">Ce n'est <b>pas</b> la déclaration CA3 ou CA12 : ce sont les montants à y reporter, à contrôler avec votre expert-comptable.</li>
        </ul>
      </div>
    </div>
  </aside>
</div>

<?php if ($tp['acquisitions']): ?>
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>TVA des acquisitions</h2><span class="sous">biens immobilisés, déclarés par l'extension Immobilisations</span></div>
    <table>
      <thead><tr><th style="width:96px">Acquis le</th><th>Bien</th><th>État</th><th class="num">TVA</th></tr></thead>
      <tbody>
      <?php foreach ($tp['acquisitions'] as $a): [$lib, $cl] = $etats[$a['etat']] ?? [$a['etat'], '']; ?>
        <tr>
          <td class="date"><?= V::date($a['date']) ?></td>
          <td><?= V::e($a['libelle']) ?><div class="sous-ligne"><?= V::e($a['detail']) ?></div></td>
          <td><span class="puce <?= $cl ?>"><?= V::e($lib) ?></span></td>
          <td class="num <?= $a['etat'] === 'comptee' ? '' : 'vide' ?>"><?= $a['etat'] === 'comptee' ? V::montant($a['tva']) : ($a['tva'] > 0 ? '(' . V::montant($a['tva']) . ')' : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="champ"><div class="aide">La TVA d'un bien est déduite du virement qui l'a payé (TTC − HT) : c'est une déduction, pas une saisie. Seules les lignes « comptée »
      entrent dans le tableau.</div></div>
  </div>
<?php endif; ?>

<?php if ($tp['paiements']['lignes']): ?>
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete">
      <h2>Paiements de TVA repérés</h2>
      <span class="sous">versé <?= $e($tp['paiements']['verse_c']) ?> € · remboursé <?= $e($tp['paiements']['rembourse_c']) ?> €</span>
    </div>
    <table>
      <thead><tr><th style="width:96px">Date</th><th>Opération bancaire</th><th class="num">Montant</th></tr></thead>
      <tbody>
      <?php foreach ($tp['paiements']['lignes'] as $l): ?>
        <tr><td class="date"><?= V::date($l['d']) ?></td><td><?= V::e($l['libelle']) ?></td>
          <td class="num <?= (float) $l['montant'] < 0 ? 'num-d' : 'num-c' ?>"><?= V::montant($l['montant']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="champ"><div class="aide">Ce sont les opérations écartées du résultat avec le motif « TVA ». Elles ne sont pas rapprochées automatiquement des périodes :
      la TVA d'une période se paie le mois suivant, à cheval sur deux exercices au changement d'année.</div></div>
  </div>
<?php endif; ?>
