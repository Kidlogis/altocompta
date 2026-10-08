<?php use Bnc\Auth; use Bnc\CompteBancaire as C; use Bnc\View as V;
$j = V::e(Auth::jeton());
$v = static fn(string $k, $d = '') => V::e((string) ($ligne[$k] ?? $d));
$edition = $ligne && !empty($ligne['id']);
?>
<div class="duo-form">
  <div>
    <div class="carte">
      <div class="carte-tete">
        <h2>Comptes bancaires</h2>
        <span class="sous"><?= count($comptes) ?> compte<?= count($comptes) > 1 ? 's' : '' ?></span>
      </div>

      <?php if (!$comptes): ?>
        <div class="vide-etat">
          Aucun compte déclaré. Déclarer chaque compte permet de rattacher les opérations
          importées et de repérer les virements entre vos propres comptes.
        </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Libellé</th><th style="width:104px">Nature</th>
            <th class="num" style="width:88px">Opérations</th>
            <th class="num" style="width:88px">En attente</th>
            <th style="width:76px"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($comptes as $cpt):
          $st = $stats[(int) $cpt['id']] ?? ['nb' => 0, 'en_attente' => 0]; ?>
          <tr<?= (int) $cpt['actif'] === 0 ? ' style="opacity:.55"' : '' ?>>
            <td>
              <a href="/comptes/<?= (int) $cpt['id'] ?>"><?= V::e($cpt['libelle']) ?></a>
              <?php if ((int) $cpt['actif'] === 0): ?><span class="etiq">clos</span><?php endif; ?>
              <div class="sous-ligne">
                <?= V::e((string) $cpt['banque']) ?>
                <?php if ($cpt['iban_fin']): ?>··<?= V::e($cpt['iban_fin']) ?><?php endif; ?>
              </div>
            </td>
            <td><span class="etiq"><?= V::e(C::NATURES[$cpt['nature']] ?? '') ?></span></td>
            <td class="num"><?= $st['nb'] ?></td>
            <td class="num<?= $st['en_attente'] > 0 ? '' : ' vide' ?>"><?= $st['en_attente'] ?></td>
            <td class="actions">
              <form method="post" action="/comptes/<?= (int) $cpt['id'] ?>/basculer">
                <input type="hidden" name="_jeton" value="<?= $j ?>">
                <button class="lien-mini"><?= (int) $cpt['actif'] === 1 ? 'Clore' : 'Rouvrir' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php if ($sans_compte > 0): ?>
      <div class="champ" style="border-top:1px solid var(--trait)">
        <span class="puce p-att"><?= (int) $sans_compte ?> opération<?= $sans_compte > 1 ? 's' : '' ?> sans compte rattaché</span>
        <div class="aide">Sans rattachement, les virements entre vos comptes ne peuvent pas être
          repérés. Renseigner les motifs de reconnaissance ci-contre, puis relancer le rattachement.</div>
        <form method="post" action="/comptes/rattacher" style="margin-top:10px">
          <input type="hidden" name="_jeton" value="<?= $j ?>">
          <button class="btn btn-sm">Rattacher et repérer les virements</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <div class="carte" style="margin-top:14px">
      <div class="carte-tete"><h2>Virements internes</h2></div>
      <div class="champ">
        <p class="txt">Un virement entre deux de vos comptes apparaît deux fois : en débit sur
          l'un, en crédit sur l'autre. Traité comme un flux ordinaire, le crédit deviendrait une
          recette et le débit une dépense — l'erreur atteindrait le double du montant viré.</p>
        <p class="txt" style="margin-top:8px">Les paires sont donc repérées automatiquement
          (même montant en sens opposé, comptes distincts, moins de
          <?= \Bnc\VirementInterne::JOURS_TOLERANCE ?> jours d'écart) et proposées à l'écran
          d'affectation. Elles sortent de la 2035 tout en restant tracées.</p>
      </div>
    </div>
  </div>

  <form method="post" action="/comptes/enregistrer" class="carte">
    <div class="carte-tete"><h2><?= $edition ? 'Modifier le compte' : 'Ajouter un compte' ?></h2></div>
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <input type="hidden" name="id" value="<?= (int) ($ligne['id'] ?? 0) ?>">

    <div class="champ">
      <label for="lb">Libellé</label>
      <input id="lb" name="libelle" required value="<?= $v('libelle') ?>" placeholder="Qonto professionnel">
    </div>
    <div class="champ">
      <label for="bq">Banque</label>
      <input id="bq" name="banque" value="<?= $v('banque') ?>">
    </div>
    <div class="champ">
      <label for="ib">Fin d'IBAN</label>
      <input id="ib" name="iban_fin" value="<?= $v('iban_fin') ?>" placeholder="0143" maxlength="8">
      <div class="aide">Les derniers caractères seulement : ils suffisent à reconnaître le compte
        sans conserver l'IBAN complet.</div>
    </div>
    <div class="champ">
      <label for="na">Nature</label>
      <select id="na" name="nature">
        <?php foreach (C::NATURES as $k => $lib): ?>
          <option value="<?= $k ?>" <?= ($ligne['nature'] ?? 'professionnel') === $k ? 'selected' : '' ?>><?= $lib ?></option>
        <?php endforeach; ?>
      </select>
      <div class="aide">Plusieurs comptes professionnels sont possibles. Un compte mixte reçoit
        aussi des flux privés, à écarter au triage.</div>
    </div>
    <div class="champ">
      <label for="mo">Motifs de reconnaissance</label>
      <input id="mo" name="motifs" value="<?= $v('motifs') ?>" placeholder="Qonto 4417|FR7630004">
      <div class="aide">Ce que les relevés écrivent pour désigner ce compte, séparé par des barres
        verticales. Sert à rattacher automatiquement les opérations importées.</div>
    </div>
    <div class="champ barre-actions">
      <button class="btn btn-p"><?= $edition ? 'Enregistrer' : 'Ajouter' ?></button>
      <?php if ($edition): ?>
        <a href="/comptes" class="btn">Annuler</a>
      <?php endif; ?>
    </div>

    <?php if ($edition): ?>
    <div class="champ" style="border-top:1px solid var(--trait)">
      <p class="txt">Supprimer le compte laisse les opérations importées en place, simplement
        sans rattachement.</p>
    </div>
    <?php endif; ?>
  </form>
</div>

<?php if ($edition): ?>
<form method="post" action="/comptes/<?= (int) $ligne['id'] ?>/supprimer" style="margin-top:14px"
      onsubmit="return confirm('Supprimer ce compte ?')">
  <input type="hidden" name="_jeton" value="<?= $j ?>">
  <button class="btn btn-danger">Supprimer le compte</button>
</form>
<?php endif; ?>
