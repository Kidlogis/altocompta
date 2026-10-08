<?php use Bnc\Auth; use Bnc\View as V; ?>
<form method="post" action="/import/releve" class="carte" enctype="multipart/form-data" style="max-width:520px">
  <div class="carte-tete"><h2>Importer un relevé bancaire</h2></div>
  <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
  <div class="champ">
    <label for="rv">Fichier CSV ou OFX</label>
    <input id="rv" name="releve" type="file" accept=".csv,.txt,.ofx,.qfx" required>
    <div class="aide">Le format est reconnu au contenu. En CSV, les colonnes sont identifiées par leur
      intitulé : une date, un libellé et un montant — ou deux colonnes crédit et débit ; séparateur,
      encodage et format de date sont détectés. L'OFX et le QFX sont lus directement.</div>
  </div>
  <div class="champ">
    <label for="cp">Compte bancaire</label>
    <select id="cp" name="compte_id">
      <option value="0">Reconnaître d'après le relevé</option>
      <?php foreach ($comptes as $cpt): ?>
        <option value="<?= (int) $cpt['id'] ?>"><?= V::e($cpt['libelle']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="aide">
      <?php if (!$comptes): ?>
        Aucun compte déclaré. <a href="/comptes">En ajouter un</a> permet de rattacher les
        opérations et de repérer les virements entre vos comptes.
      <?php else: ?>
        Imposer le compte quand le relevé ne le mentionne pas — souvent le cas d'un export CSV
        téléchargé compte par compte.
      <?php endif; ?>
    </div>
  </div>
  <div class="champ">
    <button class="btn btn-p">Importer</button>
    <div class="aide">Un relevé déjà importé ne crée pas de doublon : chaque opération porte une
      empreinte unique. Les opérations arrivent ensuite dans l'écran d'affectation.</div>
  </div>
</form>
