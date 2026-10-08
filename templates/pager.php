<?php use Bnc\Pagination; use Bnc\View as V;
if (($nb_pages ?? 1) <= 1) return; ?>
<nav class="pager" aria-label="Pagination">
  <span class="pager-info"><?= (int) $nb ?> ligne<?= $nb > 1 ? 's' : '' ?> · page <?= (int) $page ?> sur <?= (int) $nb_pages ?></span>
  <?php if ($page > 1): ?>
    <a href="<?= V::e(Pagination::lien($page - 1)) ?>" class="pager-lien">Précédent</a>
  <?php endif; ?>
  <?php foreach (Pagination::fenetre($page, $nb_pages) as $n): ?>
    <?php if ($n === 0): ?>
      <span class="pager-coupure">…</span>
    <?php elseif ($n === $page): ?>
      <span class="pager-lien courant" aria-current="page"><?= $n ?></span>
    <?php else: ?>
      <a href="<?= V::e(Pagination::lien($n)) ?>" class="pager-lien"><?= $n ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($page < $nb_pages): ?>
    <a href="<?= V::e(Pagination::lien($page + 1)) ?>" class="pager-lien">Suivant</a>
  <?php endif; ?>
</nav>
