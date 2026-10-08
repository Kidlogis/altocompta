<?php use Bnc\Auth; use Bnc\View as V;
$v = static fn(string $k, $d = '') => V::e((string) ($ligne[$k] ?? $d));
$modes = ['carte'=>'Carte','prelevement'=>'Prélèvement','virement'=>'Virement','cheque'=>'Chèque','especes'=>'Espèces','autre'=>'Autre'];
$estEdition = $ligne && !empty($ligne['id']);
?>
<div class="duo-form">
  <form method="post" action="/depenses/enregistrer" class="carte" enctype="multipart/form-data">
    <div class="carte-tete"><h2><?= $estEdition ? 'Modifier la dépense' : 'Nouvelle dépense' ?></h2></div>
    <?php foreach ($erreurs as $e): ?><p class="alerte" style="margin:12px 16px 0"><?= V::e($e) ?></p><?php endforeach; ?>
    <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
    <input type="hidden" name="id" value="<?= (int) ($ligne['id'] ?? 0) ?>">

    <div class="champ">
      <label for="d">Date de décaissement</label>
      <input id="d" name="date_decaissement" type="date" required value="<?= $v('date_decaissement', date('Y-m-d')) ?>">
      <div class="aide">La date où l'argent a quitté le compte, pas celle de la facture.</div>
    </div>
    <div class="champ">
      <label for="f">Fournisseur</label>
      <input id="f" name="fournisseur" required value="<?= $v('fournisseur') ?>">
    </div>
    <div class="champ">
      <label for="l">Libellé</label>
      <input id="l" name="libelle" required value="<?= $v('libelle') ?>">
    </div>
<?php
    // Une écriture affectée par un poste seul (une règle d'affectation) a un poste mais pas de catégorie. Sans cela, la fiche
    // l'obligerait à en choisir une, et le poste en découlerait : il pourrait changer sans qu'on le voie.
    $posteLigne = (string) ($ligne['poste_2035'] ?? '');
    $sansCat    = $posteLigne !== '' && (int) ($ligne['categorie_id'] ?? 0) === 0;
    $candidates = $sansCat ? \Bnc\CategorieMetier::ayantPoste($categories, $posteLigne) : [];
    $unique     = count($candidates) === 1 ? (int) $candidates[0]['id'] : 0;
    $gardePoste = $sansCat && $unique === 0;
?>
    <div class="champ">
      <label for="cat">Catégorie</label>
      <?php if ($sansCat): ?><input type="hidden" name="poste_2035" value="<?= V::e($posteLigne) ?>"><?php endif; ?>
      <select id="cat" name="categorie_id" <?= $gardePoste ? '' : 'required' ?>>
        <option value=""><?= $gardePoste ? '— garder le poste actuel —' : '— choisir —' ?></option>
        <?php foreach ($categories as $ct): ?>
          <option value="<?= (int) $ct['id'] ?>"
                  <?= (int) ($ligne['categorie_id'] ?? 0) === (int) $ct['id'] || $unique === (int) $ct['id'] ? 'selected' : '' ?>>
            <?= V::e($ct['libelle']) ?> — case <?= V::e($ct['poste_2035']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($gardePoste): ?>
        <div class="aide"><b>Poste actuel : <?= V::e($posteLigne) ?></b> — <?= V::e(\Bnc\Poste2035::libelle($posteLigne)) ?>, sans catégorie. Il est conservé tel quel ;
          choisir une catégorie le remplace par la case de cette catégorie.</div>
      <?php elseif ($unique): ?>
        <div class="aide">Catégorie présélectionnée : c'est la seule qui corresponde au poste <?= V::e($posteLigne) ?>, sa case est donc inchangée.
          <a href="/categories">Voir la correspondance</a>.</div>
      <?php else: ?>
        <div class="aide">La case de la 2035 en découle. <a href="/categories">Voir la correspondance</a>.</div>
      <?php endif; ?>
    </div>
    <div class="champ">
     <div class="champ-duo">
      <div class="champ">
        <label for="m">Montant payé<?= !empty($tva) ? ' (TTC)' : '' ?></label>
        <input id="m" name="montant" inputmode="decimal" required value="<?= $v('montant') ?>">
      </div>
      <div class="champ">
        <label for="q">Quote-part professionnelle</label>
        <div class="suffixe">
          <input id="q" name="quote_part_pro" type="number" min="1" max="100" value="<?= $v('quote_part_pro', '100') ?>">
          <span>%</span>
        </div>
        <div class="aide">Pour un usage mixte : seule cette part entre dans la 2035.</div>
      </div>
     </div>
    </div>
<?php if (!empty($tva)): $depenseFiche = true; ?>
    <div class="champ tva-bloc" data-tva style="border:1px solid var(--trait);border-radius:var(--r-sm);margin:0 16px 12px;padding:12px 14px;background:#FAFBFD">
      <label style="display:block;margin-bottom:2px">Éclatement HT / TVA</label>
      <div class="aide" style="margin:0 0 10px">Le montant ci-dessus est le <b>TTC</b>, celui du virement : il ne change pas. Saisissez le HT et la TVA tels
        qu'imprimés sur la facture — ou le taux seul, pour les calculer. Tant que ce n'est pas fait, l'écriture compte pour son TTC.</div>
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
    <?php endif; ?>
    <div class="champ">
      <label for="r">Règlement</label>
      <select id="r" name="mode_reglement">
        <?php foreach ($modes as $k => $lib): ?>
          <option value="<?= $k ?>" <?= ($ligne['mode_reglement'] ?? 'carte') === $k ? 'selected' : '' ?>><?= $lib ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!$estEdition): ?>
    <div class="champ">
      <label for="j">Justificatif</label>
      <input id="j" name="justificatif" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic">
      <div class="aide">PDF ou photo, 10 Mo au maximum. Ajoutable plus tard.</div>
    </div>
    <?php endif; ?>
    <div class="champ">
      <label for="no">Notes</label>
      <textarea id="no" name="notes" rows="2"><?= $v('notes') ?></textarea>
    </div>
    <div class="champ barre-actions">
      <button class="btn btn-p">Enregistrer</button>
      <a href="/depenses" class="btn">Annuler</a>
    </div>
  </form>

  <?php if ($estEdition): ?>
  <div>
    <div class="carte">
      <div class="carte-tete"><h2>Justificatifs</h2></div>
      <?php if (!$justificatifs): ?>
        <div class="vide-etat">Aucun justificatif. Il n'est pas transmis avec la déclaration, mais doit pouvoir être produit en cas de contrôle.</div>
      <?php else: ?>
        <?php foreach ($justificatifs as $j): ?>
          <div class="fichier">
            <a href="/justificatif/<?= (int) $j['id'] ?>" target="_blank" rel="noopener"><?= V::e($j['nom_origine']) ?></a>
            <span class="sous-ligne"><?= number_format((int) $j['taille'] / 1024, 0, ',', ' ') ?> Ko · <?= V::date($j['ajoute_le']) ?></span>
            <form method="post" action="/justificatif/<?= (int) $j['id'] ?>/supprimer" class="droite"
                  onsubmit="return confirm('Supprimer ce justificatif ?')">
              <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
              <button class="lien-mini lien-danger">Supprimer</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <form method="post" action="/depenses/<?= (int) $ligne['id'] ?>/justificatif"
            enctype="multipart/form-data" class="champ" style="border-top:1px solid var(--trait)">
        <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
        <label for="ja">Ajouter un justificatif</label>
        <input id="ja" name="justificatif" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic">
        <button class="btn btn-sm" style="margin-top:8px">Téléverser</button>
      </form>
    </div>

    <div class="carte" style="margin-top:14px">
      <div class="carte-tete"><h2>Annuler</h2></div>
      <div class="champ">
        <p class="txt">La dépense sortira des totaux et de la déclaration, mais restera au registre,
          marquée annulée. Ses justificatifs sont conservés : ils documentent une écriture qui a existé.</p>
        <p class="txt" style="margin-top:8px">L'annulation est consignée au journal, avec son motif.</p>
        <form method="post" action="/depenses/<?= (int) $ligne['id'] ?>/annuler"
              onsubmit="return confirm('Annuler cette dépense ?')" style="margin-top:12px">
          <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
          <div class="champ" style="padding:0">
            <label for="motif-annul">Motif</label>
            <input id="motif-annul" name="motif" placeholder="Doublon, erreur de saisie…">
          </div>
          <button class="btn btn-danger" style="margin-top:10px">Annuler la dépense</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
