<?php use Bnc\Auth; use Bnc\View as V;
$ouvert = $exercice['statut'] === 'ouvert';
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Déclaration 2035 — exercice <?= (int) $exercice['annee'] ?></h2>
    <span class="sous">Annexe 2035-A · calculée sur les registres<?= !empty($calc['hors_taxes']) ? ' · montants hors taxes (assujetti à la TVA)' : '' ?></span>
    <a href="/declaration/pdf" class="btn btn-sm btn-p droite">Exporter en PDF</a>
  </div>

  <div class="f2035">
    <?php foreach ($lignes as $l): ?>
      <?php if ($l['type'] === 'bandeau'): ?>
        <div class="bandeau"><?= V::e($l['libelle']) ?></div>
      <?php elseif ($l['type'] === 'resultat'): ?>
        <div class="lg resultat">
          <span class="code"><?= V::e($l['code']) ?></span>
          <span><?= V::e($l['libelle']) ?></span>
          <span class="num"><?= V::montant($l['montant']) ?></span>
        </div>
      <?php else: ?>
        <div class="lg <?= $l['type'] === 'total' ? 'total' : '' ?>">
          <span class="code"><?= V::e($l['code']) ?></span>
          <span><?= V::e($l['libelle']) ?></span>
          <span class="num<?= $l['montant'] === null ? ' vide' : '' ?>">
            <?= $l['montant'] === null ? '—' : V::montant($l['montant']) ?>
          </span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php
// Annexes apportées par les extensions actives — l'annexe 2035-B vient
// du plugin Pro quand il est installé.
foreach ($calc['annexes'] ?? [] as $annexe) {
    if (is_file($annexe['gabarit'])) {
        extract($annexe['donnees'], EXTR_SKIP);
        require $annexe['gabarit'];
    }
}
?>

<div class="duo" style="margin-top:14px">
  <div class="carte">
    <div class="carte-tete"><h2>Avant de déclarer</h2></div>
    <?php foreach ($calc['controles'] as $ct): ?>
      <div class="champ">
        <span class="puce <?= $ct['niveau'] === 'ok' ? 'p-ok' : ($ct['niveau'] === 'erreur' ? 'p-ko' : 'p-att') ?>">
          <?= $ct['nb'] !== null ? (int) $ct['nb'] . ' ' : '' ?><?= V::e($ct['texte']) ?>
        </span>
        <div class="aide">
          <?= V::e($ct['detail']) ?>
          <?php if ($ct['lien']): ?> <a href="<?= V::e($ct['lien']) ?>">Voir</a>.<?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div>
    <div class="carte">
      <div class="carte-tete"><h2>Exports</h2></div>
      <div class="champ">
        <a href="/declaration/pdf" class="btn btn-sm">Formulaire 2035 (PDF)</a>
        <a href="/declaration/2035e" class="btn btn-sm">Annexe 2035-E</a>
        <div class="aide">Document de travail reprenant les cases renseignées et les contrôles ci-contre.</div>
      </div>
      <div class="champ">
        <a href="/recettes/registre" class="btn btn-sm">Livre des recettes (PDF)</a>
        <a href="/depenses/registre" class="btn btn-sm">Registre des dépenses (PDF)</a>
        <div class="aide">Documents paginés et datés, à présenter en cas de contrôle.</div>
      </div>
      <div class="champ">
        <a href="/recettes/export" class="btn btn-sm">Livre des recettes (CSV)</a>
        <a href="/depenses/export" class="btn btn-sm">Registre des dépenses (CSV)</a>
        <?php if (\Bnc\Plugin::estActif('immobilisations')): ?>
          <a href="/immobilisations/export" class="btn btn-sm">Immobilisations (CSV)</a>
        <?php endif; ?>
        <?php if (!empty($calc['option_bareme'])): ?>
          <a href="/trajets/export" class="btn btn-sm">Registre des trajets (CSV)</a>
        <?php endif; ?>
        <div class="aide">Séparateur point-virgule et BOM : s'ouvrent directement dans Excel en français.
          C'est le format à transmettre à un expert-comptable ou une AGA.</div>
      </div>
    </div>

    <div class="carte" style="margin-top:14px">
      <div class="carte-tete"><h2>Clôture de l'exercice</h2></div>
      <div class="champ">
        <?php if ($ouvert): ?>
          <p class="txt">Clore l'exercice <?= (int) $exercice['annee'] ?> le passe en lecture seule :
            plus aucune écriture ne pourra y être ajoutée ni modifiée. Réversible.</p>
          <form method="post" action="/exercice/clore" style="margin-top:12px"
                onsubmit="return confirm('Clore l\'exercice <?= (int) $exercice['annee'] ?> ?')">
            <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
            <button class="btn">Clore l'exercice</button>
          </form>
        <?php else: ?>
          <p class="txt">L'exercice <?= (int) $exercice['annee'] ?> est clos depuis le
            <?= V::date($exercice['clos_le']) ?>. Aucune écriture ne peut y être portée.</p>
          <form method="post" action="/exercice/rouvrir" style="margin-top:12px">
            <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">
            <button class="btn">Rouvrir l'exercice</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="carte" style="margin-top:14px">
      <div class="carte-tete"><h2>Télétransmission</h2></div>
      <div class="champ">
        <p class="txt">La 2035 se télédéclare en EDI-TDFC, par un partenaire agréé par la DGFiP
          (association de gestion agréée, expert-comptable, ou éditeur habilité). Aucun logiciel
          ne peut transmettre directement à l'administration sans cet agrément.</p>
        <p class="txt" style="margin-top:8px">Le PDF et les CSV ci-contre couvrent ce que demande
          un partenaire pour établir la déclaration.</p>
      </div>
    </div>
  </div>
</div>
