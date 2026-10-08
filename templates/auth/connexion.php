<?php use Bnc\Auth; use Bnc\View as V; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion — AltoCompta</title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= V::asset('/assets/app.css') ?>">
</head>
<body class="ecran-connexion">
  <form method="post" action="/connexion" class="carte boite-connexion">
    <div class="marque-connexion">
      <img src="<?= V::asset('/assets/img/logo-440.png') ?>" alt="AltoCompta" class="marque-logo">
      <span>Comptabilité BNC — déclaration contrôlée</span>
    </div>

    <?php if (!empty($erreur)): ?>
      <p class="alerte"><?= V::e($erreur) ?></p>
    <?php endif; ?>

    <input type="hidden" name="_jeton" value="<?= V::e(Auth::jeton()) ?>">

    <div class="champ">
      <label for="email">Adresse e-mail</label>
      <input id="email" name="email" type="email" autocomplete="username" required autofocus>
    </div>
    <div class="champ">
      <label for="mdp">Mot de passe</label>
      <input id="mdp" name="mot_de_passe" type="password" autocomplete="current-password" required>
    </div>
    <div class="champ">
      <button type="submit" class="btn btn-p" style="width:100%">Se connecter</button>
    </div>
  </form>
</body>
</html>
