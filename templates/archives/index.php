<?php use Bnc\View as V; ?>
<div class="carte">
  <div class="carte-tete">
    <h2>Archives — exercice <?= (int) $exercice['annee'] ?></h2>
    <span class="sous"><?= count($archives) ?> archive<?= count($archives) > 1 ? 's' : '' ?></span>
  </div>

  <div class="champ">
    <p class="txt">Chaque clôture fige un instantané complet de l'exercice — écritures actives
      comme annulées — et en calcule l'empreinte. Des années plus tard, recalculer l'empreinte du
      contenu conservé et la comparer à celle enregistrée rapporte la preuve que ce qui a été
      déclaré n'a pas bougé.</p>
    <p class="txt" style="margin-top:8px">Rouvrir puis reclore un exercice produit une archive
      supplémentaire sans effacer la précédente : la suite des états successifs est elle-même une
      information.</p>
  </div>

  <?php if (!$archives): ?>
    <div class="vide-etat">
      Aucune archive sur cet exercice. Elle sera créée automatiquement à la clôture, depuis
      <a href="/declaration">le formulaire 2035</a>.
    </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:130px">Figée le</th>
        <th class="num" style="width:80px">Écritures</th>
        <th class="num" style="width:110px">Recettes</th>
        <th class="num" style="width:110px">Dépenses</th>
        <th class="num" style="width:110px">Résultat</th>
        <th style="width:120px">Intégrité</th>
        <th style="width:90px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($archives as $a):
      $ctl = $controles[(int) $a['id']] ?? ['valide' => false];
    ?>
      <tr>
        <td class="date">
          <?= V::date(substr((string) $a['cree_le'], 0, 10)) ?>
          <div class="sous-ligne"><?= V::e(substr((string) $a['cree_le'], 11, 5)) ?>
            · jusqu'au n° <?= (int) $a['sequence_fin'] ?> du journal</div>
        </td>
        <td class="num"><?= (int) $a['nb_recettes'] + (int) $a['nb_depenses'] ?></td>
        <td class="num num-c"><?= V::montant((float) $a['total_recettes']) ?></td>
        <td class="num num-d"><?= V::montant((float) $a['total_depenses']) ?></td>
        <td class="num"><?= V::montant((float) $a['resultat'], true) ?></td>
        <td>
          <span class="puce <?= $ctl['valide'] ? 'p-ok' : 'p-ko' ?>">
            <?= $ctl['valide'] ? 'Conforme' : 'Altérée' ?>
          </span>
          <div class="sous-ligne" style="font-family:var(--chiffre)"
               title="<?= V::e((string) $a['empreinte']) ?>">
            <?= V::e(substr((string) $a['empreinte'], 0, 12)) ?>…
          </div>
        </td>
        <td class="actions">
          <a href="/archives/<?= (int) $a['id'] ?>/telecharger" class="lien-mini">Télécharger</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="champ" style="border-top:1px solid var(--trait)">
    <div class="aide">Le fichier téléchargé est servi exactement tel qu'enregistré : c'est ce
      contenu, octet pour octet, dont l'empreinte a été calculée. Le conserver hors de
      l'application permet de recalculer l'empreinte indépendamment, avec n'importe quel outil
      capable de produire un SHA-256.</div>
  </div>
  <?php endif; ?>
</div>
