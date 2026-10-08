<?php use Bnc\View as V; ?>
<div class="carte" style="padding:28px;max-width:520px">
  <h2 style="font-size:16px;margin-bottom:8px"><?= V::e($titre ?? 'Erreur') ?></h2>
  <p style="color:var(--sourdine);font-size:13.5px"><?= V::e($message ?? '') ?></p>
  <p style="margin-top:16px"><a href="/">Retour au tableau de bord</a></p>
</div>
