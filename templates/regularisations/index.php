<?php use Bnc\Auth; use Bnc\Regularisation as R; use Bnc\View as V;
$j = V::e(Auth::jeton());
$v = static fn(string $k, $d = '') => V::e((string) ($ligne[$k] ?? $d));
$edition = $ligne && !empty($ligne['id']);
$propres = array_filter($impact['lignes'], static fn($l) => $l['origine'] === 'exercice');
$contre  = array_filter($impact['lignes'], static fn($l) => $l['origine'] === 'contrepassation');
?>
<div class="duo-form">
  <div>
    <div class="carte">
      <div class="carte-tete">
        <h2>Régularisations — exercice <?= (int) $exercice['annee'] ?></h2>
        <span class="sous"><?= count($lignes) ?> écriture<?= count($lignes) > 1 ? 's' : '' ?></span>
      </div>

      <div class="champ">
        <p class="txt">La comptabilité BNC se tient en trésorerie : une charge n'est déductible
          qu'une fois décaissée. Certaines écritures dérogent à ce principe — au premier rang
          desquelles les cotisations sociales obligatoires dues au 31 décembre mais appelées
          plus tard.</p>
        <p class="txt" style="margin-top:8px">Leur admissibilité dépend du régime applicable et
          relève d'un arbitrage avec l'AGA ou l'expert-comptable. Le logiciel les enregistre et
          les trace, il ne se prononce pas.</p>
      </div>

      <?php if (!$lignes): ?>
        <div class="vide-etat">Aucune régularisation sur cet exercice.</div>
      <?php else: ?>
      <table>
        <thead>
          <tr><th>Libellé</th><th style="width:130px">Sens</th><th style="width:52px">Case</th>
              <th class="num" style="width:104px">Montant</th><th style="width:70px"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
          <tr>
            <td>
              <a href="/regularisations/<?= (int) $l['id'] ?>"><?= V::e($l['libelle']) ?></a>
              <?php if ((int) $l['contrepasser'] !== 1): ?>
                <span class="etiq">sans contre-passation</span>
              <?php endif; ?>
              <div class="sous-ligne"><?= V::e(mb_substr((string) $l['justification'], 0, 90)) ?></div>
            </td>
            <td><span class="etiq"><?= V::e(R::SENS[$l['sens']] ?? '') ?></span></td>
            <td><span class="etiq etiq-2035"><?= V::e($l['poste_2035']) ?></span></td>
            <td class="num <?= $l['sens'] === 'charge_a_payer' ? 'num-d' : 'num-c' ?>"><?= V::montant($l['montant']) ?></td>
            <td class="actions">
              <?php if ($exercice['statut'] === 'ouvert'): ?>
                <form method="post" action="/regularisations/<?= (int) $l['id'] ?>/supprimer"
                      onsubmit="return confirm('Supprimer cette régularisation ?')">
                  <input type="hidden" name="_jeton" value="<?= $j ?>">
                  <button class="lien-mini lien-danger">Retirer</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="carte" style="margin-top:14px">
      <div class="carte-tete"><h2>Effet sur la déclaration</h2></div>
      <table>
        <tbody>
          <tr>
            <td>Charges à payer de l'exercice</td>
            <td class="num num-d"><?= V::montant(array_sum(array_map(
              static fn($l) => $l['sens'] === 'charge_a_payer' ? (float) $l['montant'] : 0, $propres))) ?></td>
          </tr>
          <tr>
            <td>Produits à recevoir de l'exercice</td>
            <td class="num num-c"><?= V::montant(array_sum(array_map(
              static fn($l) => $l['sens'] === 'produit_a_recevoir' ? (float) $l['montant'] : 0, $propres))) ?></td>
          </tr>
          <?php if ($contre): ?>
          <tr>
            <td>Contre-passation de l'exercice précédent
              <div class="sous-ligne"><?= count($contre) ?> écriture(s) neutralisée(s)</div></td>
            <td class="num"><?= V::montant(-array_sum(array_map(
              static fn($l) => (float) $l['montant'], $contre)), true) ?></td>
          </tr>
          <?php endif; ?>
          <tr class="totalr">
            <td>Effet net sur les dépenses — case CE</td>
            <td class="num num-d"><?= V::montant($impact['total_depenses'], true) ?></td>
          </tr>
        </tbody>
      </table>
      <div class="champ">
        <div class="aide">Une charge déduite en N réapparaît sur le relevé de N+1 au moment du
          paiement. Sans neutralisation, elle serait déduite deux fois : la contre-passation
          s'applique donc automatiquement à l'exercice suivant, sauf à l'avoir écartée à la saisie.</div>
      </div>
    </div>
  </div>

  <form method="post" action="/regularisations/enregistrer" class="carte">
    <div class="carte-tete"><h2><?= $edition ? 'Modifier' : 'Nouvelle régularisation' ?></h2></div>
    <?php foreach ($erreurs as $e): ?><p class="alerte" style="margin:12px 16px 0"><?= V::e($e) ?></p><?php endforeach; ?>
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <input type="hidden" name="id" value="<?= (int) ($ligne['id'] ?? 0) ?>">

    <div class="champ">
      <label for="se">Sens</label>
      <select id="se" name="sens" onchange="majCat(this.value)">
        <?php foreach (R::SENS as $k => $lib): ?>
          <option value="<?= $k ?>" <?= ($ligne['sens'] ?? 'charge_a_payer') === $k ? 'selected' : '' ?>><?= $lib ?></option>
        <?php endforeach; ?>
      </select>
      <div class="aide">Une charge engagée non payée, ou une recette acquise non encaissée.</div>
    </div>
    <div class="champ">
      <label for="lb">Libellé</label>
      <input id="lb" name="libelle" required value="<?= $v('libelle') ?>"
             placeholder="Cotisations URSSAF du 4e trimestre">
    </div>
    <div class="champ">
      <label for="ct">Catégorie</label>
      <select id="ct" name="categorie_id" required>
        <option value="">— choisir —</option>
        <?php foreach ($cat_dep as $c): ?>
          <option value="<?= (int) $c['id'] ?>" data-sens="charge_a_payer"
                  <?= (int) ($ligne['categorie_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= V::e($c['libelle']) ?> — case <?= V::e($c['poste_2035']) ?>
          </option>
        <?php endforeach; ?>
        <?php foreach ($cat_rec as $c): ?>
          <option value="<?= (int) $c['id'] ?>" data-sens="produit_a_recevoir" hidden
                  <?= (int) ($ligne['categorie_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
            <?= V::e($c['libelle']) ?> — case <?= V::e($c['poste_2035']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="champ">
      <label for="mt">Montant</label>
      <input id="mt" name="montant" inputmode="decimal" required value="<?= $v('montant') ?>">
    </div>
    <div class="champ">
      <label for="ju">Justification</label>
      <textarea id="ju" name="justification" rows="3" required
                placeholder="Appel de cotisations du 15/01/2026 portant sur le 4e trimestre 2025."><?= $v('justification') ?></textarea>
      <div class="aide">Obligatoire : une régularisation déroge au principe de trésorerie et doit
        pouvoir être expliquée en cas de contrôle.</div>
    </div>
    <div class="champ">
      <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start">
        <input type="checkbox" name="contrepasser" value="1" style="width:auto;margin-top:3px"
               <?= (int) ($ligne['contrepasser'] ?? 1) === 1 ? 'checked' : '' ?>>
        <span>Contre-passer sur l'exercice suivant</span>
      </label>
      <div class="aide">À laisser coché dans le cas courant. Décocher seulement si le paiement
        ne passera pas par les registres de l'exercice suivant.</div>
    </div>
    <div class="champ barre-actions">
      <button class="btn btn-p">Enregistrer</button>
      <?php if ($edition): ?><a href="/regularisations" class="btn">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<script>
function majCat(sens) {
  var sel = document.getElementById('ct');
  Array.prototype.forEach.call(sel.options, function (o) {
    if (o.value === '') return;
    o.hidden = o.dataset.sens !== sens;
  });
  if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) { sel.value = ''; }
}
</script>
