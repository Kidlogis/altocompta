<?php use Bnc\Declaration2035E as E; use Bnc\View as V; ?>
<?php if (!$va['exigee']): ?>
<div class="carte">
  <div class="carte-tete"><h2>Annexe 2035-E — valeur ajoutée produite</h2></div>
  <div class="champ">
    <span class="puce p-ok">Annexe non exigée</span>
    <div class="aide">Cette annexe sert au calcul de la CVAE et n'est à servir qu'au-delà de
      <?= V::montant(E::SEUIL_RECETTES) ?> € de recettes. Les vôtres s'élèvent à
      <?= V::montant($va['recettes']) ?> €. Le tableau ci-dessous est donné à titre indicatif.</div>
  </div>
</div>
<?php endif; ?>

<div class="carte"<?= $va['exigee'] ? '' : ' style="margin-top:14px;opacity:.75"' ?>>
  <div class="carte-tete">
    <h2>Détermination de la valeur ajoutée — exercice <?= (int) $exercice['annee'] ?></h2>
    <span class="sous">Annexe 2035-E</span>
  </div>

  <div class="f2035">
    <div class="bloc">
      <div class="bandeau">Produits</div>
      <div class="lg total">
        <span class="code">AA</span><span>Montant net des recettes</span>
        <span class="num"><?= V::montant($va['recettes']) ?></span>
      </div>
    </div>

    <?php foreach ($va['groupes'] as $titre => $g): ?>
      <div class="bloc">
        <div class="bandeau"><?= V::e($titre) ?></div>
        <?php if (!$g['lignes']): ?>
          <div class="lg"><span class="code"></span><span class="vide">Aucun montant</span><span class="num vide">—</span></div>
        <?php else: foreach ($g['lignes'] as $l): ?>
          <div class="lg">
            <span class="code"><?= V::e($l['code']) ?></span>
            <span><?= V::e(\Bnc\Poste2035::libelle($l['code'])) ?></span>
            <span class="num"><?= V::montant($l['montant']) ?></span>
          </div>
        <?php endforeach; endif; ?>
        <div class="lg total">
          <span class="code"></span><span>Sous-total</span>
          <span class="num"><?= V::montant($g['total']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="bloc">
      <div class="lg total">
        <span class="code"></span><span>Total des consommations en provenance de tiers</span>
        <span class="num"><?= V::montant($va['total_consommations']) ?></span>
      </div>
      <div class="lg resultat">
        <span class="code">VA</span><span>Valeur ajoutée produite</span>
        <span class="num"><?= V::montant($va['valeur_ajoutee']) ?></span>
      </div>
    </div>
  </div>
</div>

<div class="duo" style="margin-top:14px">
  <div class="carte">
    <div class="carte-tete"><h2>Postes exclus du calcul</h2></div>
    <?php if (!$va['exclus']): ?>
      <div class="vide-etat">Aucun poste exclu sur cet exercice.</div>
    <?php else: ?>
      <table>
        <tbody>
        <?php foreach ($va['exclus'] as $x): ?>
          <tr>
            <td style="width:44px"><span class="etiq etiq-2035"><?= V::e($x['code']) ?></span></td>
            <td><?= V::e($x['raison']) ?></td>
            <td class="num"><?= V::montant($x['montant']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div class="champ">
      <div class="aide">Rémunérations, charges sociales, amortissements et charges financières ne
        sont pas des consommations auprès de tiers : ils restent dans la valeur ajoutée.</div>
    </div>
  </div>

  <div class="carte">
    <div class="carte-tete"><h2>À vérifier</h2></div>
    <?php if ($non_classes): ?>
    <div class="champ">
      <span class="puce p-ko"><?= count($non_classes) ?> poste(s) non classé(s)</span>
      <div class="aide">Les cases <?= V::e(implode(', ', $non_classes)) ?> ne figurent ni dans les
        consommations ni dans les exclusions : elles ne sont donc pas prises en compte.
        C'est une anomalie à signaler.</div>
    </div>
    <?php endif; ?>
    <div class="champ">
      <span class="puce p-att">Ventilation par défaut</span>
      <div class="aide">Le regroupement des postes 2035-A en consommations de tiers est celui
        retenu par défaut. Les règles d'inclusion évoluent et comportent des cas particuliers :
        la vérifier avec l'AGA ou l'expert-comptable avant de déclarer.</div>
    </div>
    <div class="champ">
      <span class="puce <?= $va['exigee'] ? 'p-att' : 'p-ok' ?>">
        Seuil de <?= V::montant(E::SEUIL_RECETTES) ?> €
      </span>
      <div class="aide"><?= $va['exigee']
        ? 'Vos recettes dépassent ce seuil : l\'annexe est à servir.'
        : 'Vos recettes restent en deçà : l\'annexe n\'a pas à être servie.' ?></div>
    </div>
  </div>
</div>
