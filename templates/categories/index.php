<?php use Bnc\Auth; use Bnc\View as V;
$j = V::e(Auth::jeton());

$bloc = static function (array $cats, string $titre) use ($j) {
    $V = '\Bnc\View';
    echo '<div class="carte" style="margin-top:14px"><div class="carte-tete"><h2>'
       . $V::e($titre) . '</h2><span class="sous">' . count($cats) . ' catégories</span></div>';
    echo '<table><thead><tr><th>Catégorie</th><th style="width:56px">Case</th>'
       . '<th>Reconnue par</th><th style="width:80px"></th></tr></thead><tbody>';
    foreach ($cats as $c) {
        $propre = $c['user_id'] !== null;
        echo '<tr' . ((int) $c['actif'] === 0 ? ' style="opacity:.5"' : '') . '>';
        echo '<td>' . $V::e($c['libelle']);
        if ($propre) echo ' <span class="etiq">à vous</span>';
        if (!empty($c['aide'])) echo '<div class="sous-ligne">' . $V::e($c['aide']) . '</div>';
        echo '</td>';
        echo '<td><span class="etiq etiq-2035" title="' . $V::e($c['poste_libelle'] ?? '') . '">'
           . $V::e($c['poste_2035']) . '</span></td>';
        echo '<td class="sous-ligne" style="font-family:var(--chiffre)">'
           . $V::e(str_replace('|', ' · ', (string) $c['mots_cles'])) . '</td>';
        echo '<td class="actions">';
        if ($propre) {
            echo '<form method="post" action="/categories/' . (int) $c['id'] . '/supprimer">'
               . '<input type="hidden" name="_jeton" value="' . $j . '">'
               . '<button class="lien-mini lien-danger">Retirer</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
};
?>
<div class="carte">
  <div class="carte-tete"><h2>Nomenclature métier</h2></div>
  <div class="champ">
    <p class="txt">La 2035-A raisonne en codes — BP, BW, BS — que rien ne rend évidents.
      Ces catégories donnent des libellés parlants, chacun adossé à sa case officielle. Choisir
      « Assurance responsabilité civile professionnelle » suffit : le poste BP en découle.</p>
    <p class="txt" style="margin-top:8px">Les mots-clés servent aussi de filet à l'import : sans
      avoir créé la moindre règle, un prélèvement URSSAF part en charges sociales personnelles.
      Vos propres règles restent prioritaires.</p>
  </div>
</div>

<?php $bloc($depenses, 'Dépenses'); ?>
<?php $bloc($recettes, 'Recettes'); ?>

<form method="post" action="/categories/creer" class="carte" style="margin-top:14px;max-width:560px">
  <div class="carte-tete"><h2>Ajouter une catégorie</h2></div>
  <input type="hidden" name="_jeton" value="<?= $j ?>">
  <div class="champ">
    <label for="lb">Libellé</label>
    <input id="lb" name="libelle" required placeholder="Location de studio d'enregistrement">
  </div>
  <div class="champ-duo" style="padding:0 16px">
    <div class="champ">
      <label for="se">Sens</label>
      <select id="se" name="sens" onchange="majPostes(this.value)">
        <option value="depense">Dépense</option>
        <option value="recette">Recette</option>
      </select>
    </div>
    <div class="champ">
      <label for="po">Case 2035</label>
      <select id="po" name="poste_2035" required>
        <?php foreach ($postes_d as $p): ?>
          <option value="<?= V::e($p['code']) ?>" data-sens="depense"><?= V::e($p['code'] . ' — ' . $p['libelle']) ?></option>
        <?php endforeach; ?>
        <?php foreach ($postes_r as $p): ?>
          <option value="<?= V::e($p['code']) ?>" data-sens="recette" hidden><?= V::e($p['code'] . ' — ' . $p['libelle']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="champ">
    <label for="mc">Mots-clés de reconnaissance</label>
    <input id="mc" name="mots_cles" placeholder="STUDIO|ENREGISTREMENT">
    <div class="aide">Séparés par des barres verticales, comparés au libellé bancaire sans tenir
      compte de la casse ni des accents. Facultatif.</div>
  </div>
  <div class="champ barre-actions"><button class="btn btn-p">Ajouter</button></div>
</form>

<script>
function majPostes(sens) {
  var sel = document.getElementById('po');
  Array.prototype.forEach.call(sel.options, function (o) {
    o.hidden = o.dataset.sens !== sens;
  });
  var premier = Array.prototype.find.call(sel.options, function (o) { return !o.hidden; });
  if (premier) { sel.value = premier.value; }
}
</script>
