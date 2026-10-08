<?php use Bnc\Auth; use Bnc\Plugin; use Bnc\View as V; use Bnc\Poste2035;
$jeton = V::e(Auth::jeton());
$libSens = ['tous'=>'tous','credit'=>'crédits','debit'=>'débits'];
?>
<?php if (!empty($nb_doublons)): ?>
  <div class="flash flash-att" style="margin-bottom:14px">
    <?= (int) $nb_doublons ?> doublon<?= $nb_doublons > 1 ? 's' : '' ?> probable<?= $nb_doublons > 1 ? 's' : '' ?>
    parmi les opérations importées. <a href="/doublons">Les examiner</a>
  </div>
<?php endif; ?>
<div class="triage">
  <div class="carte">
    <div class="carte-tete">
      <h2>Opérations à affecter</h2>
      <span class="sous"><?= count($lignes) ?> en attente</span>
      <?php if ($lignes): ?>
        <form method="post" action="/affectation/tout-ignorer" class="droite"
              onsubmit="return confirm('Écarter toutes les opérations en attente ?')">
          <input type="hidden" name="_jeton" value="<?= $jeton ?>">
          <button class="btn btn-sm">Tout ignorer</button>
        </form>
        <form method="post" action="/affectation/detecter-virements">
          <input type="hidden" name="_jeton" value="<?= $jeton ?>">
          <button class="btn btn-sm">Repérer les virements internes</button>
        </form>
        <form method="post" action="/affectation/valider-suggestions">
          <input type="hidden" name="_jeton" value="<?= $jeton ?>">
          <button class="btn btn-sm btn-p">Valider les suggestions</button>
        </form>
      <?php endif; ?>
      <a href="/affectation/exclues" class="btn btn-sm<?= $lignes ? '' : ' droite' ?>">
        Opérations exclues<?= $nb_exclues > 0 ? ' (' . (int) $nb_exclues . ')' : '' ?>
      </a>
    </div>

    <?php if (!$lignes): ?>
      <?php $connecteurs = Plugin::connecteurs(); ?>
      <div class="vide-etat">
        Rien en attente. Les opérations apparaissent ici après
        <?= $connecteurs ? 'une synchronisation ' . V::e(Plugin::enumeration(array_column($connecteurs, 'nom'))) . (count($connecteurs) > 1 ? ', ou ' : ' ou ') : '' ?>l'import d'un relevé.<br>
        <?php foreach ($connecteurs as $cx): ?>
          <a href="<?= V::e($cx['route']) ?>" class="btn btn-sm" style="margin-top:12px">Synchronisation <?= V::e($cx['nom']) ?></a>
        <?php endforeach; ?>
        <a href="/import" class="btn btn-sm" style="margin-top:12px">Importer un relevé</a>
      </div>
    <?php else: ?>
      <div class="aide-clavier">
        <kbd>↓</kbd><kbd>↑</kbd> se déplacer · <kbd>R</kbd> recette · <kbd>D</kbd> dépense · <kbd>M</kbd> immobilisation · <kbd>V</kbd> virement interne · <kbd>I</kbd> ignorer
        · le trombone joint la pièce, qui suivra l'écriture créée
      </div>

      <div id="triage-liste">
      <?php foreach ($lignes as $i => $t):
        $credit = (float) $t['montant'] >= 0;
        $posteSuggere = $t['suggestion_poste'] ?: '';
      ?>
        <div class="ligne<?= $i === 0 ? ' vise' : '' ?>" data-id="<?= (int) $t['id'] ?>"
             data-suggestion="<?= V::e((string) $t['suggestion_type']) ?>"
             data-poste="<?= V::e($posteSuggere) ?>">
          <div class="date"><?= V::date($t['date_operation']) ?></div>
          <div>
            <div class="lib-op"><?= V::e($t['libelle']) ?></div>
            <div class="src">
              <?php if ($t['compte_libelle']): ?>
                <code><?= V::e($t['compte_libelle']) ?></code> ·
              <?php elseif ($t['compte']): ?>
                <code><?= V::e($t['compte']) ?></code> ·
              <?php endif; ?>
              <?= V::e(Plugin::libelleSource((string) $t['source'])) ?>
              <?php if (!empty($t['contrepartie_id'])): ?>
                <span class="suggere suggere-vi">
                  ↔ virement interne vers <?= V::e((string) $t['contrepartie_compte']) ?>
                  le <?= V::date($t['contrepartie_date']) ?>
                </span>
              <?php elseif ($t['suggestion_type']): ?>
                <span class="suggere">
                  → <?= $t['suggestion_type'] === 'hors_bnc' ? 'hors BNC' : V::e($t['suggestion_type']) ?>
                  <?php if ($t['categorie_libelle']): ?> · <?= V::e($t['categorie_libelle']) ?>
                  <?php elseif ($posteSuggere): ?> · <?= V::e($posteSuggere) ?><?php endif; ?>
                  <?php if ($t['regle_motif']): ?> · règle <?= V::e($t['regle_motif']) ?><?php endif; ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
          <div class="montant <?= $credit ? 'num-c' : 'num-d' ?>"><?= V::montant($t['montant'], true) ?></div>
          <div class="actes">
            <form method="post" action="/affectation/<?= (int) $t['id'] ?>" class="acte-form">
              <input type="hidden" name="_jeton" value="<?= $jeton ?>">
              <input type="hidden" name="type" value="recette">
              <button class="acte acte-r" data-role="recette">Recette</button>
            </form>
            <form method="post" action="/affectation/<?= (int) $t['id'] ?>" class="acte-form form-depense">
              <input type="hidden" name="_jeton" value="<?= $jeton ?>">
              <input type="hidden" name="type" value="depense">
              <select name="categorie_id" class="mini-select select-cat" aria-label="Catégorie">
                <option value="">catégorie…</option>
                <?php foreach ($categories as $ct): ?>
                  <option value="<?= (int) $ct['id'] ?>"
                          <?= (int) ($t['suggestion_categorie'] ?? 0) === (int) $ct['id'] ? 'selected' : '' ?>>
                    <?= V::e($ct['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="acte acte-d" data-role="depense">Dépense</button>
            </form>
            <?php if (!empty($t['contrepartie_id'])): ?>
            <form method="post" action="/affectation/<?= (int) $t['id'] ?>" class="acte-form">
              <input type="hidden" name="_jeton" value="<?= $jeton ?>">
              <input type="hidden" name="type" value="virement_interne">
              <button class="acte acte-v" data-role="virement"
                      title="Écarte les deux moitiés du virement">↔ Interne</button>
            </form>
            <?php endif; ?>
            <form method="post" action="/affectation/<?= (int) $t['id'] ?>" class="acte-form">
              <input type="hidden" name="_jeton" value="<?= $jeton ?>">
              <input type="hidden" name="type" value="immobilisation">
              <button class="acte acte-i" data-role="immobilisation" title="Bien durable à amortir">Immo</button>
            </form>
            <form method="post" action="/affectation/<?= (int) $t['id'] ?>" class="acte-form">
              <input type="hidden" name="_jeton" value="<?= $jeton ?>">
              <input type="hidden" name="type" value="hors_bnc">
              <select name="motif" class="mini-select" aria-label="Motif d'exclusion">
                <option value="prive">privé</option>
                <option value="apport_retrait">apport/retrait</option>
                <option value="doublon">doublon</option>
                <option value="tva">TVA</option>
                <option value="autre">autre</option>
              </select>
              <button class="acte acte-x" data-role="ignorer">Ignorer</button>
            </form>
            <form method="post" action="/affectation/<?= (int) $t['id'] ?>/justificatif"
                  enctype="multipart/form-data" class="acte-form joindre">
              <input type="hidden" name="_jeton" value="<?= $jeton ?>">
              <input type="file" name="justificatif" id="j<?= (int) $t['id'] ?>"
                     accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                     onchange="this.form.submit()" hidden>
              <label for="j<?= (int) $t['id'] ?>" class="acte acte-j"
                     title="Joindre la facture ou le ticket">
                <?php $n = $justifs[(int) $t['id']] ?? 0; ?>
                <?= $n > 0 ? '&#128206; ' . $n : '&#128206;' ?>
              </label>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="carte">
      <div class="carte-tete"><h2>Règles d'affectation</h2></div>
      <?php if (!$regles): ?>
        <div class="vide-etat">Aucune règle. Une règle pré-catégorise les opérations dont le libellé correspond.</div>
      <?php else: ?>
        <?php foreach ($regles as $rg): ?>
          <div class="regle<?= (int) $rg['actif'] === 0 ? ' inactive' : '' ?>">
            <span class="motif"><?= V::e($rg['motif']) ?></span>
            <span class="fleche">→</span>
            <span class="cible">
              <?= $rg['cible_type'] === 'hors_bnc' ? 'Hors BNC' : ucfirst($rg['cible_type']) ?>
              <?php if ($rg['poste_2035']): ?> · <?= V::e($rg['poste_2035']) ?><?php endif; ?>
            </span>
            <div class="cpt">
              <?= V::e($rg['type_match']) ?> · <?= V::e($libSens[$rg['sens_montant']] ?? 'tous') ?>
              · appliquée <?= (int) $rg['nb_appliquee'] ?> fois
            </div>
            <div class="regle-actions">
              <form method="post" action="/regles/<?= (int) $rg['id'] ?>/basculer">
                <input type="hidden" name="_jeton" value="<?= $jeton ?>">
                <button class="lien-mini"><?= (int) $rg['actif'] === 1 ? 'Désactiver' : 'Activer' ?></button>
              </form>
              <form method="post" action="/regles/<?= (int) $rg['id'] ?>/supprimer">
                <input type="hidden" name="_jeton" value="<?= $jeton ?>">
                <button class="lien-mini lien-danger">Supprimer</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <form method="post" action="/regles/creer" class="champ" style="border-top:1px solid var(--trait)">
        <input type="hidden" name="_jeton" value="<?= $jeton ?>">
        <label for="rm">Nouvelle règle</label>
        <input id="rm" name="motif" placeholder="URSSAF" required>
        <div class="champ-duo" style="margin-top:8px">
          <select name="type_match" aria-label="Type de correspondance">
            <option value="contient">contient</option>
            <option value="commence">commence par</option>
            <option value="regex">expression régulière</option>
            <option value="montant_exact">montant exact</option>
          </select>
          <select name="sens_montant" aria-label="Sens">
            <option value="tous">tous</option>
            <option value="debit">débits</option>
            <option value="credit">crédits</option>
          </select>
        </div>
        <div class="champ-duo" style="margin-top:8px">
          <select name="cible_type" aria-label="Affecter en">
            <option value="depense">Dépense</option>
            <option value="recette">Recette</option>
            <option value="hors_bnc">Hors BNC</option>
          </select>
          <select name="poste_2035" aria-label="Poste 2035">
            <option value="">poste…</option>
            <?php foreach ($postes as $po): ?>
              <option value="<?= V::e($po['code']) ?>"><?= V::e($po['code'] . ' — ' . mb_substr($po['libelle'], 0, 28)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-sm" style="width:100%;margin-top:10px">Ajouter la règle</button>
        <div class="aide">Les règles ne s'appliquent qu'aux opérations importées ensuite.</div>
      </form>
    </div>
  </div>
</div>
