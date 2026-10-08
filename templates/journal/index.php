<?php use Bnc\Journal; use Bnc\View as V; ?>
<div class="carte">
  <div class="carte-tete">
    <h2>Contrôle d'intégrité</h2>
    <span class="sous"><?= (int) $nb_total ?> événement<?= $nb_total > 1 ? 's' : '' ?> au journal</span>
  </div>
  <div class="champ">
    <?php if ($controle['intacte']): ?>
      <span class="puce p-ok">Chaîne intacte</span>
      <div class="aide">Les <?= (int) $controle['nb'] ?> entrées du journal se rattachent correctement
        les unes aux autres, et le contenu de chacune correspond à son empreinte. Aucune écriture n'a
        été modifiée ni retirée depuis son enregistrement.</div>
      <?php if ($controle['derniere_empreinte']): ?>
        <div class="sous-ligne" style="margin-top:8px;font-family:var(--chiffre);word-break:break-all">
          Empreinte courante : <?= V::e($controle['derniere_empreinte']) ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <span class="puce p-ko">Chaîne rompue</span>
      <div class="aide">
        Rupture détectée à l'entrée n° <?= (int) $controle['rupture']['sequence'] ?> :
        <b><?= V::e($controle['rupture']['motif']) ?></b>.
        <?= V::e($controle['rupture']['detail']) ?>
        Les entrées antérieures restent vérifiables ; celles qui suivent ne peuvent plus être garanties.
      </div>
    <?php endif; ?>
  </div>
  <div class="champ" style="border-top:1px solid var(--trait)">
    <div class="aide">Chaque écriture, rectification ou annulation dépose ici une entrée dont
      l'empreinte couvre son contenu et celle de l'entrée précédente. Retoucher ou retirer une ligne
      a posteriori rompt le chaînage à partir de ce point, ce que ce contrôle détecte.</div>
  </div>
</div>

<div class="carte" style="margin-top:14px">
  <div class="carte-tete">
    <h2>Journal</h2>
    <span class="sous"><?= count($lignes) ?> entrée<?= count($lignes) > 1 ? 's' : '' ?>
      <?= $tous ? '· tous exercices' : '· exercice ' . (int) $exercice['annee'] ?></span>
    <a href="<?= $tous ? '/journal' : '/journal?tous' ?>" class="btn btn-sm droite">
      <?= $tous ? 'Cet exercice' : 'Tous les exercices' ?>
    </a>
  </div>

  <?php if (!$lignes): ?>
    <div class="vide-etat">Aucun événement sur cette période.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:52px">N°</th>
        <th style="width:132px">Horodatage</th>
        <th style="width:112px">Acte</th>
        <th>Détail</th>
        <th style="width:88px">Empreinte</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lignes as $l):
      $d = json_decode((string) $l['donnees'], true) ?: [];
      $classe = match ($l['type']) {
          'creation'      => 'p-ok',
          'annulation'    => 'p-ko',
          'rectification' => 'p-att',
          default         => '',
      };
    ?>
      <tr>
        <td class="ref"><?= (int) $l['sequence'] ?></td>
        <td class="date"><?= V::date(substr((string) $l['horodatage'], 0, 10)) ?>
          <span class="sous-ligne"><?= V::e(substr((string) $l['horodatage'], 11, 8)) ?></span></td>
        <td>
          <span class="puce <?= $classe ?>"><?= V::e(Journal::TYPES[$l['type']] ?? $l['type']) ?></span>
          <div class="sous-ligne"><?= V::e($l['objet']) ?><?= $l['objet_id'] ? ' #' . (int) $l['objet_id'] : '' ?></div>
        </td>
        <td>
          <?php
          $avant = $d['avant'] ?? null;
          $apres = $d['apres'] ?? null;
          $ref = $apres ?? $avant;
          ?>
          <?php if ($ref): ?>
            <?= V::e((string) ($ref['client'] ?? $ref['fournisseur'] ?? $ref['libelle']
                               ?? $ref['designation'] ?? '—')) ?>
            <?php $m = $ref['montant'] ?? $ref['montant_ht'] ?? null; ?>
            <?php if ($m !== null): ?>
              · <span class="ref"><?= V::montant((float) $m) ?></span>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($d['motif'])): ?>
            <div class="sous-ligne">Motif : <?= V::e($d['motif']) ?></div>
          <?php endif; ?>
          <div class="sous-ligne"><?= V::e($l['acteur']) ?></div>
        </td>
        <td class="sous-ligne" style="font-family:var(--chiffre)" title="<?= V::e($l['empreinte']) ?>">
          <?= V::e(substr((string) $l['empreinte'], 0, 10)) ?>…
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
