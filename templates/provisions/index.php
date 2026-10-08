<?php use Bnc\Auth; use Bnc\Provisions; use Bnc\Urssaf; use Bnc\View as V;
$j  = V::e(Auth::jeton());
$eu = static fn($v) => V::montant((float) $v) . ' €';
$s  = static fn(string $k, $defaut) => $saisie[$k] ?? $defaut;   // ce qui vient d'être saisi, sinon ce qui est enregistré
$anneeCourante = (int) date('Y');
$modeCourant = (string) $s('mode', $params['mode']);
?>
<div class="profil-grille">

<div>
  <?php foreach ($erreurs as $e): ?>
    <p class="alerte" style="margin-bottom:12px"><?= V::e($e) ?></p>
  <?php endforeach; ?>

  <?php if (!$urssaf_actif): ?>
    <div class="carte"><div class="carte-tete"><h2>Provisions</h2></div>
      <div class="vide-etat">Les estimations par l'Urssaf sont désactivées sur cette installation
        (<code>urssaf.actif</code> dans <code>config.php</code>).</div></div>

  <?php elseif ($estimation === null): ?>
    <div class="carte">
      <div class="carte-tete"><h2>Provisions</h2><span class="sous">Exercice <?= (int) $exercice['annee'] ?></span></div>
      <div class="vide-etat">
        Aucune estimation pour cet exercice. Elle indique combien de cotisations sociales et d'impôt sur le
        revenu votre résultat devrait entraîner, donc combien mettre de côté.<br>
        Renseignez votre foyer à droite puis lancez le calcul.
      </div>
    </div>

  <?php else: $e = $estimation['entree']; $v = $estimation['resultat']['valeurs']; ?>
    <div class="carte">
      <div class="carte-tete">
        <h2>Provisions — exercice <?= (int) $exercice['annee'] ?></h2>
        <span class="sous">Estimation de l'Urssaf du <?= V::date(substr((string) $estimation['cree_le'], 0, 10)) ?>
          à <?= V::e(substr((string) $estimation['cree_le'], 11, 5)) ?></span>
      </div>

      <?php if ($perimee): ?>
        <p class="avis"><b>À actualiser.</b> Les écritures ont changé depuis cette estimation
          (recettes à ce jour : <?= $eu($base['brut_recettes']) ?>).</p>
      <?php endif; ?>
      <?php if (empty($e['debut_activite'])): ?>
        <p class="avis"><b>Date de début d'activité non renseignée.</b> Le simulateur suppose alors une activité récente, ce
          qui change les cotisations des premières années — en général à la hausse. Renseignez-la dans
          <a href="/profil">Mon compte</a>, puis actualisez l'estimation.</p>
      <?php endif; ?>
      <?php if (!empty($e['note'])): ?><p class="avis"><?= V::e($e['note']) ?></p><?php endif; ?>
      <?php if ((int) $exercice['annee'] !== $anneeCourante): ?>
        <p class="avis">Les règles utilisées sont celles en vigueur aujourd'hui, pas celles de <?= (int) $exercice['annee'] ?> :
          pour un exercice passé, le montant peut différer de ce qui a été réellement appelé.</p>
      <?php endif; ?>
      <?php foreach ($estimation['resultat']['avertissements'] ?? [] as $av): ?>
        <p class="avis"><?= V::e($av) ?></p>
      <?php endforeach; ?>

      <div class="provision-chiffres">
        <div class="provision-chiffre"><span>Cotisations</span><strong><?= $eu($chiffres['cotisations']) ?></strong>
          <small><?= $chiffres['minimales'] ? 'cotisations minimales (déficit)' : 'sociales, CSG-CRDS comprise' ?></small></div>
        <div class="provision-chiffre"><span>Impôt sur le revenu</span><strong><?= $eu($chiffres['impot']) ?></strong>
          <small>du foyer, autres revenus compris</small></div>
        <div class="provision-chiffre provision-total"><span>À prévoir</span><strong><?= $eu($chiffres['a_prevoir']) ?></strong>
          <small><?= $chiffres['taux'] !== null ? number_format($chiffres['taux'] * 100, 1, ',', ' ') . ' % du résultat avant cotisations' : 'pas de bénéfice' ?>
            <?= $chiffres['mensuel'] !== null ? ' · ' . $eu($chiffres['mensuel']) . ' par mois' : '' ?></small></div>
      </div>

      <table class="provision-detail">
        <tbody>
          <tr><td>Recettes retenues</td><td class="num"><?= $eu($e['recettes']) ?></td></tr>
          <tr><td>Charges retenues <span class="sous-ligne">hors cotisations personnelles (poste BW)</span></td><td class="num"><?= $eu($e['charges']) ?></td></tr>
          <tr class="fort"><td>Résultat avant cotisations</td><td class="num"><?= $eu($chiffres['avant_cotisations']) ?></td></tr>
          <tr class="fort"><td>Cotisations et contributions</td><td class="num"><?= $eu($chiffres['cotisations']) ?></td></tr>
          <?php $det = Provisions::detailCotisations($v); foreach ($det['lignes'] as $lib => $montant): ?>
            <tr><td class="sous"><?= V::e($lib) ?></td><td class="num"><?= $eu($montant) ?></td></tr>
          <?php endforeach; ?>
          <?php if ($det['autres'] != 0.0): ?>
            <tr><td class="sous">Autres cotisations et contributions</td><td class="num"><?= $eu($det['autres']) ?></td></tr>
          <?php endif; ?>
          <tr><td>Résultat fiscal après cotisations <span class="sous-ligne">base de l'impôt</span></td><td class="num"><?= $eu($chiffres['resultat_fiscal']) ?></td></tr>
          <tr><td>Impôt sur le revenu du foyer</td><td class="num"><?= $eu($chiffres['impot']) ?></td></tr>
          <tr class="fort"><td>Total à prévoir</td><td class="num"><?= $eu($chiffres['a_prevoir']) ?></td></tr>
          <tr><td>Cotisations déjà versées <span class="sous-ligne">poste BW de la 2035</span></td><td class="num"><?= $eu($chiffres['deja_verse']) ?></td></tr>
          <tr class="fort"><td>Reste à mettre de côté <span class="sous-ligne">cotisations restantes + impôt</span></td><td class="num"><?= $eu($chiffres['reste_a_mettre']) ?></td></tr>
        </tbody>
      </table>

      <div class="champ" style="border-top:1px solid var(--trait)">
        <div class="aide">Source : API publique de l'Urssaf (mon-entreprise.urssaf.fr), qui applique les barèmes en vigueur.
          C'est une <b>estimation</b> : les cotisations réellement appelées procèdent d'acomptes provisoires puis d'une
          régularisation, et l'impôt dépend de la déclaration du foyer entier.</div>
        <?php if (!empty($e['debut_activite'])): ?>
          <div class="aide" style="margin-top:6px">Début d'activité retenu : <?= V::e(V::date((string) $e['debut_activite'])) ?>.</div>
        <?php endif; ?>
        <?php $manq = $estimation['resultat']['manquantes'] ?? []; if ($manq): ?>
          <details style="margin-top:8px"><summary class="aide" style="cursor:pointer"><?= count($manq) ?> donnée<?= count($manq) > 1 ? 's' : '' ?>
            non fournie<?= count($manq) > 1 ? 's' : '' ?> : valeur par défaut du simulateur</summary>
            <div class="aide" style="font-family:var(--chiffre);margin-top:6px"><?= V::e(implode(' · ', $manq)) ?></div>
          </details>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($derniere && $derniere['statut'] === 'erreur' && (!$estimation || $derniere['id'] > $estimation['id'])): ?>
    <div class="carte" style="margin-top:14px">
      <div class="carte-tete"><h2>Dernière tentative en échec</h2>
        <span class="sous"><?= V::date(substr((string) $derniere['cree_le'], 0, 10)) ?> à <?= V::e(substr((string) $derniere['cree_le'], 11, 5)) ?></span></div>
      <div class="champ"><span class="puce p-ko">Échec</span>
        <div class="aide" style="margin-top:6px"><?= V::e((string) $derniere['message']) ?></div>
        <?php if ($estimation): ?><div class="aide">L'estimation ci-dessus est la dernière qui ait abouti.</div><?php endif; ?></div>
    </div>
  <?php endif; ?>

  <?php if (count($historique) > 1): ?>
    <div class="carte" style="margin-top:14px">
      <div class="carte-tete"><h2>Estimations précédentes</h2></div>
      <table>
        <thead><tr><th>Le</th><th>Base</th><th class="num">Cotisations</th><th class="num">Impôt</th><th class="num">À prévoir</th></tr></thead>
        <tbody>
        <?php foreach ($historique as $h): $hv = $h['resultat']['valeurs']; ?>
          <tr>
            <td class="date"><?= V::date(substr((string) $h['cree_le'], 0, 10)) ?></td>
            <td><span class="etiq"><?= V::e(Provisions::MODES[$h['mode']] ?? $h['mode']) ?></span></td>
            <td class="num"><?= $eu($hv['cotisations']) ?></td>
            <td class="num"><?= $eu($hv['impot']) ?></td>
            <td class="num"><?= $eu((float) $hv['cotisations'] + (float) $hv['impot']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<aside class="profil-cote">
  <?php if ($urssaf_actif): ?>
  <form method="post" action="/provisions/estimer" class="carte">
    <div class="carte-tete"><h2>Paramètres</h2></div>
    <input type="hidden" name="_jeton" value="<?= $j ?>">

    <div class="champ">
      <label>Base de calcul</label>
      <div class="modes">
        <label><input type="radio" name="mode" value="ce_jour" <?= $modeCourant === 'ce_jour' ? 'checked' : '' ?>>
          <span>À ce jour<br><span class="sous-ligne">les écritures telles qu'elles sont : recettes <?= $eu($base['brut_recettes']) ?>, charges <?= $eu($base['brut_charges']) ?></span></span></label>
        <label><input type="radio" name="mode" value="annualise" <?= $modeCourant === 'annualise' ? 'checked' : '' ?>>
          <span>Projeté sur l'année<br><span class="sous-ligne">au prorata des jours écoulés</span></span></label>
        <label><input type="radio" name="mode" value="libre" <?= $modeCourant === 'libre' ? 'checked' : '' ?>>
          <span>Mes propres montants<br><span class="sous-ligne">pour simuler un autre niveau d'activité</span></span></label>
      </div>
      <?php if (!empty($base['note']) && $modeCourant !== 'libre'): ?><div class="aide"><?= V::e($base['note']) ?></div><?php endif; ?>
    </div>

    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="rl">Recettes</label>
          <input id="rl" name="recettes_libre" inputmode="decimal" placeholder="60 000"
                 value="<?= V::e((string) $s('recettes_libre', $params['recettes_libre'] !== null ? number_format($params['recettes_libre'], 2, ',', '') : '')) ?>">
        </div>
        <div class="champ">
          <label for="cl">Charges</label>
          <input id="cl" name="charges_libre" inputmode="decimal" placeholder="20 000"
                 value="<?= V::e((string) $s('charges_libre', $params['charges_libre'] !== null ? number_format($params['charges_libre'], 2, ',', '') : '')) ?>">
        </div>
      </div>
      <div class="aide">Utilisés seulement avec « Mes propres montants ». Charges hors cotisations sociales personnelles.</div>
    </div>

    <div class="champ">
      <label for="fa">Situation familiale</label>
      <select id="fa" name="famille">
        <?php foreach (Provisions::FAMILLES as $k => $lib): ?>
          <option value="<?= V::e($k) ?>" <?= $s('famille', $params['situation_famille']) === $k ? 'selected' : '' ?>><?= V::e($lib) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="en">Enfants à charge</label>
          <input id="en" name="enfants" type="number" min="0" max="10" value="<?= (int) $s('enfants', $params['enfants']) ?>">
        </div>
        <div class="champ">
          <label>&nbsp;</label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:500">
            <input type="checkbox" name="parent_isole" value="1" style="width:auto"
                   <?= ($saisie ? !empty($saisie['parent_isole']) : $params['parent_isole']) ? 'checked' : '' ?>> Parent isolé
          </label>
        </div>
      </div>
    </div>
    <div class="champ">
      <label for="ar">Autres revenus imposables du foyer <span class="sous">par an</span></label>
      <input id="ar" name="autres_revenus" inputmode="decimal"
             value="<?= V::e((string) $s('autres_revenus', $params['autres_revenus'] > 0 ? number_format($params['autres_revenus'], 2, ',', '') : '0')) ?>">
      <div class="aide">Salaires du conjoint, revenus fonciers… ils font monter la tranche d'imposition.</div>
    </div>

    <?php if (!$debut_activite): ?>
      <div class="champ"><div class="aide"><b>Début d'activité non renseigné</b> dans <a href="/profil">Mon compte</a> :
        sans lui, le simulateur suppose une activité récente et surestime en général les cotisations.</div></div>
    <?php endif; ?>
    <div class="champ barre-actions"><button class="btn btn-p" style="width:100%">Calculer l'estimation</button></div>
    <div class="champ" style="border-top:1px solid var(--trait)">
      <div class="aide">Un clic envoie à l'API publique de l'Urssaf les recettes et charges ci-dessus, votre date de début
        d'activité et le profil de votre foyer fiscal. <b>Ni nom, ni SIRET, ni adresse.</b> Rien n'est envoyé autrement.</div>
    </div>
  </form>
  <?php endif; ?>

  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Limites</h2></div>
    <div class="champ">
      <p class="txt">Prévu pour un <b>indépendant en BNC</b>, entreprise individuelle à l'impôt sur le revenu, en
        déclaration contrôlée, profession libérale <b>non réglementée</b> (régime général des indépendants). Une
        profession réglementée (Cipav…) relève d'une autre caisse : l'estimation ne correspondrait pas à ses cotisations réelles.</p>
      <p class="txt" style="margin-top:8px">Hors champ : exonérations, cotisations facultatives, activité à l'étranger,
        outre-mer, conjoint collaborateur.</p>
    </div>
  </div>
</aside>

</div>
