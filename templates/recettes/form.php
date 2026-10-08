<?php use Bnc\Auth; use Bnc\View as V;
$v = static fn(string $k, $d = '') => V::e((string) ($ligne[$k] ?? $d));
// Une recette affectée par un poste seul (une règle d'affectation) a un poste mais pas de catégorie. Sans le poste en champ caché,
// la fiche le ramènerait silencieusement au poste par défaut (AA) à l'enregistrement.
$posteLigne = (string) ($ligne['poste_2035'] ?? '');
$sansCat    = $posteLigne !== '' && (int) ($ligne['categorie_id'] ?? 0) === 0;
$candidates = $sansCat ? \Bnc\CategorieMetier::ayantPoste($categories, $posteLigne) : [];
$unique     = count($candidates) === 1 ? (int) $candidates[0]['id'] : 0;
$gardePoste = $sansCat && $unique === 0;
$modes = ['virement'=>'Virement','prelevement'=>'Prélèvement','carte'=>'Carte','cheque'=>'Chèque','especes'=>'Espèces','autre'=>'Autre'];
?>
<div class="duo-form">
  <form method="post" action="/recettes/enregistrer" class="carte">
    <div class="carte-tete"><h2><?= $ligne && !empty($ligne['id']) ? 'Modifier la recette' : 'Nouvelle recette' ?></h2></div>
    <?php foreach ($erreurs as $e): ?><p class="alerte" style="margin:12px 16px 0"><?= V::e($e) ?></p><?php endforeach; ?>
    <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
    <input type="hidden" name="id" value="<?= (int) ($ligne['id'] ?? 0) ?>">

    <div class="champ">
      <label for="d">Date d'encaissement</label>
      <input id="d" name="date_encaissement" type="date" required value="<?= $v('date_encaissement', date('Y-m-d')) ?>">
      <div class="aide">La date où l'argent est arrivé sur le compte, pas celle de la facture.</div>
    </div>
    <div class="champ">
      <label for="c">Client</label>
      <input id="c" name="client" required value="<?= $v('client') ?>">
    </div>
    <div class="champ">
      <label for="n">Nature de la prestation</label>
      <input id="n" name="nature" required value="<?= $v('nature') ?>">
    </div>
    <div class="champ">
     <div class="champ-duo">
      <div class="champ">
        <label for="m">Montant encaissé<?= !empty($tva) ? ' (TTC)' : '' ?></label>
        <input id="m" name="montant" inputmode="decimal" required value="<?= $v('montant') ?>">
      </div>
      <div class="champ">
        <label for="r">Règlement</label>
        <select id="r" name="mode_reglement">
          <?php foreach ($modes as $k => $lib): ?>
            <option value="<?= $k ?>" <?= ($ligne['mode_reglement'] ?? 'virement') === $k ? 'selected' : '' ?>><?= $lib ?></option>
          <?php endforeach; ?>
        </select>
      </div>
     </div>
    </div>
<?php if (!empty($tva)): $depenseFiche = false; ?>
    <div class="champ tva-bloc" data-tva style="border:1px solid var(--trait);border-radius:var(--r-sm);margin:0 16px 12px;padding:12px 14px;background:#FAFBFD">
      <label style="display:block;margin-bottom:2px">Éclatement HT / TVA</label>
      <div class="aide" style="margin:0 0 10px">Le montant ci-dessus est le <b>TTC</b>, celui du virement : il ne change pas. Saisissez le HT et la TVA tels
        qu'imprimés sur la facture — ou le taux seul, pour les calculer. Tant que ce n'est pas fait, l'écriture compte pour son TTC.</div>
      <div class="champ">
     <div class="champ-duo">
        <div class="champ" style="padding:0">
          <label for="tva-taux">Taux de TVA</label>
          <div class="suffixe"><input id="tva-taux" name="taux_tva" inputmode="decimal" list="tva-taux-liste" autocomplete="off"
                 value="<?= $v('taux_tva') !== '' ? V::e(rtrim(rtrim(number_format((float) ($ligne['taux_tva'] ?? 0), 2, ',', ''), '0'), ',')) : '' ?>" placeholder="20"><span>%</span></div>
          <datalist id="tva-taux-liste"><?php foreach (\Bnc\Tva::TAUX as $t): ?><option value="<?= V::e(rtrim(rtrim(number_format($t, 2, ',', ''), '0'), ',')) ?>"><?php endforeach; ?></datalist>
        </div>
        <div class="champ" style="padding:0;align-self:end"><button type="button" class="btn btn-sm" id="tva-calculer">Calculer HT et TVA depuis le taux</button></div>
      </div>
      <div class="champ-duo" style="margin-top:10px">
        <div class="champ" style="padding:0"><label for="tva-ht">Montant HT</label>
          <input id="tva-ht" name="montant_ht" inputmode="decimal" autocomplete="off" value="<?= $v('montant_ht') ?>"></div>
        <div class="champ" style="padding:0"><label for="tva-tva">TVA</label>
          <input id="tva-tva" name="montant_tva" inputmode="decimal" autocomplete="off" value="<?= $v('montant_tva') ?>"></div>
      </div>
<?php if ($depenseFiche): ?>
      <div class="champ" style="padding:10px 0 0">
        <label for="tva-nr">TVA non récupérable <span class="sous">facultatif</span></label>
        <input id="tva-nr" name="tva_non_recuperable" inputmode="decimal" autocomplete="off" style="max-width:160px" value="<?= $v('tva_non_recuperable') ?>">
        <div class="aide">La part de la TVA que vous ne pouvez pas déduire (véhicule de tourisme, cadeaux…) : elle reste une charge.
          Laissez vide si toute la TVA est récupérable.</div>
      </div>
<?php endif; ?>
     </div>
    </div>
    <?php endif; ?>
    <div class="champ">
     <div class="champ-duo">
      <div class="champ">
        <label for="cat">Catégorie</label>
        <?php if ($sansCat): ?><input type="hidden" name="poste_2035" value="<?= V::e($posteLigne) ?>"><?php endif; ?>
        <select id="cat" name="categorie_id">
          <option value=""><?= $gardePoste ? '— garder le poste actuel —' : '— par défaut : honoraires (AA) —' ?></option>
          <?php foreach ($categories as $ct): ?>
            <option value="<?= (int) $ct['id'] ?>"
                    <?= (int) ($ligne['categorie_id'] ?? 0) === (int) $ct['id'] || $unique === (int) $ct['id'] ? 'selected' : '' ?>>
              <?= V::e($ct['libelle']) ?> — case <?= V::e($ct['poste_2035']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($gardePoste): ?>
          <div class="aide"><b>Poste actuel : <?= V::e($posteLigne) ?></b>, sans catégorie : il est conservé. Choisir une catégorie le remplace.</div>
        <?php elseif ($unique): ?>
          <div class="aide">Seule catégorie du poste <?= V::e($posteLigne) ?> : présélectionnée, la case est inchangée.</div>
        <?php endif; ?>
      </div>
      <div class="champ">
        <label for="f">Référence de facture</label>
        <input id="f" name="reference_facture" value="<?= $v('reference_facture') ?>" placeholder="FA-2026-0341">
        <div class="aide">Sert au rapprochement automatique des virements.</div>
      </div>
     </div>
    </div>
    <div class="champ">
      <label for="no">Notes</label>
      <textarea id="no" name="notes" rows="2"><?= $v('notes') ?></textarea>
    </div>
    <div class="champ barre-actions">
      <button class="btn btn-p">Enregistrer</button>
      <a href="/recettes" class="btn">Annuler</a>
    </div>
  </form>

  <?php if ($ligne && !empty($ligne['id'])): ?>
  <div class="carte">
    <div class="carte-tete"><h2>Annuler</h2></div>
    <div class="champ">
      <p class="txt">La recette n° <?= (int) $ligne['numero_chrono'] ?> sortira des totaux et de la
        déclaration, mais restera inscrite au livre, marquée annulée, avec son numéro.</p>
      <p class="txt" style="margin-top:8px">Une écriture enregistrée ne peut pas être effacée : un trou
        dans la numérotation serait précisément le signe d'une suppression, ce que la continuité du
        livre des recettes interdit. L'annulation est consignée au journal.</p>
      <form method="post" action="/recettes/<?= (int) $ligne['id'] ?>/annuler"
            onsubmit="return confirm('Annuler cette recette ?')" style="margin-top:12px">
        <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
        <div class="champ" style="padding:0">
          <label for="motif-annul">Motif</label>
          <input id="motif-annul" name="motif" placeholder="Doublon, erreur de saisie…">
        </div>
        <button class="btn btn-danger" style="margin-top:10px">Annuler la recette</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
