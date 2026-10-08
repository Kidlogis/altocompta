<?php use Bnc\Auth; use Bnc\BaremeKm; use Bnc\View as V;
$j = V::e(Auth::jeton());
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Barème kilométrique <?= (int) $annee ?></h2>
    <span class="sous"><?= count($lignes) ?> ligne<?= count($lignes) > 1 ? 's' : '' ?></span>
    <form method="get" class="droite exercice">
      <label for="an">Année</label>
      <select id="an" name="annee" onchange="this.form.submit()">
        <?php foreach ($annees as $a): ?>
          <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
        <?php endforeach; ?>
        <?php if (!in_array($annee, $annees, true)): ?>
          <option value="<?= (int) $annee ?>" selected><?= (int) $annee ?> (vide)</option>
        <?php endif; ?>
      </select>
    </form>
  </div>

  <div class="champ">
    <div class="aide">Les coefficients sont fixés par arrêté chaque année. Les valeurs livrées avec
      l'application datent de 2024 et servent de point de départ : les vérifier contre l'arrêté
      publié pour l'exercice déclaré. L'indemnité vaut kilomètres × coefficient, plus la constante
      s'il y en a une, majorée de 20 % pour un véhicule électrique.</div>
  </div>

  <?php if (!$lignes): ?>
    <div class="vide-etat">Aucun barème pour <?= (int) $annee ?>. Le reporter depuis une autre année ci-dessous.</div>
  <?php else: ?>
  <form method="post" action="/vehicules/bareme/enregistrer">
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <input type="hidden" name="annee" value="<?= (int) $annee ?>">
    <table>
      <thead>
        <tr>
          <th style="width:90px">Type</th><th style="width:110px">Puissance</th>
          <th style="width:160px">Tranche</th>
          <th style="width:110px">Coefficient</th><th style="width:110px">Constante</th>
        </tr>
      </thead>
      <tbody>
      <?php $typePrec = null; foreach ($lignes as $l): ?>
        <tr>
          <td><?php if ($l['type_vehicule'] !== $typePrec): ?>
                <span class="etiq"><?= V::e(BaremeKm::TYPES[$l['type_vehicule']] ?? '') ?></span>
                <?php $typePrec = $l['type_vehicule']; endif; ?></td>
          <td class="ref">
            <?= (int) $l['puissance_min'] ?><?php
              if ((int) $l['puissance_max'] >= 99): ?> CV et plus<?php
              elseif ((int) $l['puissance_max'] > (int) $l['puissance_min']): ?> à <?= (int) $l['puissance_max'] ?> CV<?php
              else: ?> CV<?php endif; ?>
          </td>
          <td class="ref">
            <?= number_format((int) $l['tranche_min'], 0, ',', ' ') ?>
            <?= (int) $l['tranche_max'] >= 999999 ? 'km et +' : 'à ' . number_format((int) $l['tranche_max'], 0, ',', ' ') . ' km' ?>
          </td>
          <td><input name="coefficient[<?= (int) $l['id'] ?>]" class="mini-champ"
                     value="<?= number_format((float) $l['coefficient'], 4, ',', '') ?>" inputmode="decimal"></td>
          <td><input name="constante[<?= (int) $l['id'] ?>]" class="mini-champ"
                     value="<?= number_format((float) $l['constante'], 2, ',', '') ?>" inputmode="decimal"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="champ barre-actions" style="border-top:1px solid var(--trait)">
      <button class="btn btn-p">Enregistrer le barème</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<form method="post" action="/vehicules/bareme/dupliquer" class="carte" style="margin-top:14px;max-width:420px">
  <div class="carte-tete"><h2>Reporter sur une nouvelle année</h2></div>
  <input type="hidden" name="_jeton" value="<?= $j ?>">
  <div class="champ-duo" style="padding:14px 16px 0">
    <div class="champ">
      <label for="src">Depuis</label>
      <select id="src" name="source">
        <?php foreach ($annees as $a): ?><option value="<?= $a ?>"><?= $a ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="champ">
      <label for="cib">Vers</label>
      <input id="cib" name="cible" type="number" min="2000" max="2100" value="<?= (int) date('Y') ?>">
    </div>
  </div>
  <div class="champ">
    <button class="btn">Reporter</button>
    <div class="aide">Copie les lignes à l'identique. Les coefficients sont ensuite à corriger
      un par un d'après l'arrêté de l'année.</div>
  </div>
</form>
