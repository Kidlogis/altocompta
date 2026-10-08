<?php use Bnc\Plugin; use Bnc\View as V; ?>
<div class="tuiles">
  <div class="tuile">
    <div class="lib">Recettes encaissées</div>
    <div class="val num-c"><?= V::montant($recettes) ?></div>
    <div class="case">case AA · <?= (int) $nb_recettes ?> opération<?= $nb_recettes > 1 ? 's' : '' ?></div>
  </div>
  <div class="tuile">
    <div class="lib">Dépenses payées</div>
    <div class="val num-d"><?= V::montant($depenses) ?></div>
    <div class="case">case CE · <?= (int) $nb_depenses ?> opération<?= $nb_depenses > 1 ? 's' : '' ?></div>
  </div>
  <div class="tuile forte">
    <div class="lib">Résultat BNC</div>
    <div class="val"><?= V::montant($resultat, true) ?></div>
    <div class="case">case <?= $resultat >= 0 ? 'CP · excédent' : 'CR · insuffisance' ?></div>
  </div>
  <div class="tuile">
    <div class="lib">À affecter</div>
    <div class="val" style="color:var(--attente)"><?= (int) $a_affecter ?></div>
    <div class="case">opération<?= $a_affecter > 1 ? 's' : '' ?> en attente</div>
  </div>
</div>

<div class="duo">
  <div class="carte">
    <div class="carte-tete">
      <h2>Dernières recettes</h2>
      <a href="/recettes" class="btn btn-sm droite">Tout voir</a>
    </div>
    <?php if (!$dernieres): ?>
      <div class="vide-etat">
        Aucune recette sur cet exercice. Les encaissements arrivent par <?= V::e(Plugin::phraseOrigine()) ?>,
        ou <a href="/recettes/nouvelle">à la saisie</a>.
      </div>
    <?php else: ?>
      <table>
        <tbody>
        <?php foreach ($dernieres as $l): ?>
          <tr>
            <td class="date"><?= V::date($l['date_encaissement'], 'd/m') ?></td>
            <td><?= V::e($l['client']) ?> — <?= V::e($l['nature']) ?></td>
            <td class="num num-c"><?= V::montant($l['montant']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="carte">
    <div class="carte-tete">
      <h2>Dépenses par poste</h2>
      <span class="sous droite">Exercice <?= (int) $exercice['annee'] ?></span>
    </div>
    <?php if (!$postes): ?>
      <div class="vide-etat">Aucune dépense enregistrée sur cet exercice.</div>
    <?php else: ?>
      <table>
        <tbody>
        <?php foreach ($postes as $p): ?>
          <tr>
            <td><?= V::e($p['libelle']) ?></td>
            <td style="width:44px"><span class="etiq etiq-2035"><?= V::e($p['poste_2035']) ?></span></td>
            <td class="num num-d"><?= V::montant($p['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
