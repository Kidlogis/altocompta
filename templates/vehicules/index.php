<?php use Bnc\Auth; use Bnc\BaremeKm; use Bnc\View as V;
$j = V::e(Auth::jeton());
$option = (int) ($exercice['option_bareme_km'] ?? 0) === 1;
?>
<form method="post" action="/vehicules/option-bareme" class="carte">
  <div class="carte-tete">
    <h2>Option pour le barème kilométrique — <?= (int) $exercice['annee'] ?></h2>
  </div>
  <input type="hidden" name="_jeton" value="<?= $j ?>">
  <?php if ($option): ?>
    <div class="etat"><b>Option active</b> — indemnités <?= V::montant($indemnites['total']) ?> € en case BR</div>
  <?php endif; ?>
  <div class="champ">
    <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start">
      <input type="checkbox" name="actif" value="1" <?= $option ? 'checked' : '' ?> style="width:auto;margin-top:3px">
      <span>Appliquer le barème kilométrique pour cet exercice</span>
    </label>
    <div class="aide">L'option est annuelle et vaut pour tous les véhicules. L'indemnité couvre carburant,
      entretien, assurance, pneumatiques et dépréciation : en contrepartie, les frais réels de véhicule
      saisis en case BR et l'amortissement des véhicules rattachés cessent d'être déductibles.
      Restent déductibles à part les péages, le stationnement et les intérêts d'emprunt.</div>
    <button class="btn btn-sm" style="margin-top:10px"><?= $option ? 'Mettre à jour' : 'Activer l\'option' ?></button>
  </div>
  <?php if ($option && !$bareme_ok): ?>
    <p class="alerte" style="margin:0 16px 14px">Aucun barème saisi pour <?= (int) $exercice['annee'] ?>.
      <a href="/vehicules/bareme">Le renseigner</a> avant de déclarer.</p>
  <?php endif; ?>
</form>

<div class="carte" style="margin-top:14px">
  <div class="carte-tete">
    <h2>Véhicules</h2>
    <span class="sous"><?= count($vehicules) ?> véhicule<?= count($vehicules) > 1 ? 's' : '' ?></span>
    <a href="/vehicules/bareme" class="btn btn-sm droite">Barème</a>
  </div>

  <?php if (!$indemnites['lignes']): ?>
    <div class="vide-etat">Aucun kilomètre enregistré sur cet exercice.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Véhicule</th><th style="width:64px">CV</th>
        <th class="num" style="width:92px">Kilomètres</th>
        <th style="width:150px">Tranche appliquée</th>
        <th class="num" style="width:110px">Indemnité</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($indemnites['lignes'] as $l): $v = $l['vehicule']; ?>
      <tr>
        <td>
          <?= V::e($v['designation']) ?>
          <?php if ((int) $v['electrique'] === 1): ?><span class="etiq">électrique +20 %</span><?php endif; ?>
          <div class="sous-ligne">
            <?= V::e(BaremeKm::TYPES[$v['type_vehicule']] ?? '') ?>
            <?= $v['immatriculation'] ? '· ' . V::e($v['immatriculation']) : '' ?>
            · <?= (int) $l['nb_trajets'] ?> trajet<?= $l['nb_trajets'] > 1 ? 's' : '' ?>
          </div>
        </td>
        <td class="ref"><?= (int) $v['puissance_cv'] ?> CV</td>
        <td class="num"><?= number_format($l['kilometres'], 0, ',', ' ') ?></td>
        <td>
          <?php if ($l['calcul']): ?>
            <span class="ref"><?= V::e($l['calcul']['tranche']) ?></span>
            <div class="sous-ligne">× <?= number_format($l['calcul']['coefficient'], 4, ',', '') ?><?php
              if ($l['calcul']['constante'] > 0): ?> + <?= number_format($l['calcul']['constante'], 0, ',', ' ') ?><?php endif; ?></div>
          <?php else: ?>
            <span class="puce p-ko">barème absent</span>
          <?php endif; ?>
        </td>
        <td class="num num-d"><?= $l['calcul'] ? V::montant($l['calcul']['montant']) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4" class="totalr">Indemnités kilométriques — case BR<?= $option ? '' : ' (option non activée)' ?></td>
        <td class="num num-d totalr"><?= V::montant($indemnites['total']) ?></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

  <form method="post" action="/vehicules/enregistrer" class="champ" style="border-top:1px solid var(--trait)">
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <label>Ajouter un véhicule</label>
    <div class="champ-duo" style="margin-top:6px">
      <input name="designation" placeholder="Renault Clio" required>
      <input name="immatriculation" placeholder="AB-123-CD">
    </div>
    <div class="champ-duo" style="margin-top:8px">
      <select name="type_vehicule">
        <?php foreach (BaremeKm::TYPES as $k => $lib): ?><option value="<?= $k ?>"><?= $lib ?></option><?php endforeach; ?>
      </select>
      <div class="suffixe">
        <input name="puissance_cv" type="number" min="1" max="99" value="5">
        <span>CV</span>
      </div>
    </div>
    <div class="champ-duo" style="margin-top:8px">
      <select name="immobilisation_id">
        <option value="">Non inscrit au registre</option>
        <?php foreach ($immos as $i): ?>
          <?php if ($i['categorie'] === 'vehicule'): ?>
            <option value="<?= (int) $i['id'] ?>"><?= V::e($i['designation']) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <label style="font-weight:400;display:flex;gap:6px;align-items:center;font-size:12.5px">
        <input type="checkbox" name="electrique" value="1" style="width:auto"> Électrique
      </label>
    </div>
    <button class="btn btn-sm" style="margin-top:10px">Ajouter</button>
    <div class="aide">Rattacher au registre le véhicule s'il y est inscrit : sous option barème,
      son amortissement sera automatiquement écarté du calcul.</div>
  </form>
</div>

<div class="carte" style="margin-top:14px">
  <div class="carte-tete">
    <h2>Registre des trajets</h2>
    <span class="sous"><?= count($trajets) ?> trajet<?= count($trajets) > 1 ? 's' : '' ?> · exercice <?= (int) $exercice['annee'] ?></span>
    <?php if ($trajets): ?><a href="/trajets/export" class="btn btn-sm droite">Exporter</a><?php endif; ?>
  </div>

  <?php if ($vehicules && $exercice['statut'] === 'ouvert'): ?>
  <form method="post" action="/trajets/creer" class="filtres">
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <select name="vehicule_id" required>
      <?php foreach ($vehicules as $v): ?>
        <option value="<?= (int) $v['id'] ?>"><?= V::e($v['designation']) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="date_trajet" type="date" value="<?= date('Y-m-d') ?>" required>
    <input name="motif" placeholder="Motif du déplacement" required style="flex:1;min-width:160px">
    <div class="suffixe" style="width:110px">
      <input name="kilometres" inputmode="decimal" placeholder="km" required>
      <span>km</span>
    </div>
    <button class="btn btn-sm btn-p">Ajouter</button>
  </form>
  <?php endif; ?>

  <?php if (!$trajets): ?>
    <div class="vide-etat">
      Aucun trajet. L'administration attend un relevé justifiant les kilomètres déclarés :
      date, destination ou motif, et distance.
    </div>
  <?php else: ?>
  <table>
    <thead>
      <tr><th style="width:96px">Date</th><th>Véhicule</th><th>Motif</th>
          <th class="num" style="width:80px">Km</th><th style="width:70px"></th></tr>
    </thead>
    <tbody>
    <?php foreach (array_slice($trajets, 0, 60) as $t): ?>
      <tr>
        <td class="date"><?= V::date($t['date_trajet']) ?></td>
        <td><?= V::e($t['vehicule']) ?></td>
        <td><?= V::e($t['motif']) ?></td>
        <td class="num"><?= number_format((float) $t['kilometres'], 0, ',', ' ') ?></td>
        <td class="actions">
          <form method="post" action="/trajets/<?= (int) $t['id'] ?>/supprimer">
            <input type="hidden" name="_jeton" value="<?= $j ?>">
            <button class="lien-mini lien-danger">Retirer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($trajets) > 60): ?>
    <div class="champ"><div class="aide">60 trajets les plus récents affichés sur <?= count($trajets) ?>.</div></div>
  <?php endif; ?>
  <?php endif; ?>
</div>
