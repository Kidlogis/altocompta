<?php use Bnc\Auth; use Bnc\Utilisateur; use Bnc\View as V;
$j = V::e(Auth::jeton());
?>
<div class="carte">
  <div class="carte-tete">
    <h2>Comptes</h2>
    <span class="sous"><?= count($comptes) ?> compte<?= count($comptes) > 1 ? 's' : '' ?></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Nom</th><th>Adresse</th><th style="width:78px">Rôle</th>
        <th style="width:78px">État</th><th style="width:110px">Dernière visite</th>
        <th style="width:190px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($comptes as $u): ?>
      <tr>
        <td>
          <?= V::e($u['nom']) ?>
          <?php if ($u['id'] == $moi['id']): ?><span class="etiq">vous</span><?php endif; ?>
          <?php if ($u['denomination']): ?><div class="sous-ligne"><?= V::e($u['denomination']) ?></div><?php endif; ?>
        </td>
        <td class="ref"><?= V::e($u['email']) ?></td>
        <td><span class="etiq"><?= $u['role'] === 'admin' ? 'Admin' : 'Utilisateur' ?></span></td>
        <td>
          <span class="puce <?= (int) $u['actif'] === 1 ? 'p-ok' : 'p-att' ?>">
            <?= (int) $u['actif'] === 1 ? 'Actif' : 'Inactif' ?>
          </span>
        </td>
        <td class="date"><?= $u['derniere_connexion'] ? V::date($u['derniere_connexion']) : '—' ?></td>
        <td class="actions">
          <?php if ($u['id'] != $moi['id']): ?>
            <form method="post" action="/utilisateurs/<?= (int) $u['id'] ?>/basculer" style="display:inline">
              <input type="hidden" name="_jeton" value="<?= $j ?>">
              <button class="lien-mini"><?= (int) $u['actif'] === 1 ? 'Désactiver' : 'Activer' ?></button>
            </form>
            <form method="post" action="/utilisateurs/<?= (int) $u['id'] ?>/mot-de-passe"
                  style="display:inline-flex;gap:4px;margin-left:8px"
                  onsubmit="return confirm('Réinitialiser le mot de passe de <?= V::e($u['nom']) ?> ?')">
              <input type="hidden" name="_jeton" value="<?= $j ?>">
              <input type="password" name="nouveau" placeholder="nouveau mdp" required
                     class="mini-champ" autocomplete="new-password">
              <button class="lien-mini">Définir</button>
            </form>
          <?php else: ?>
            <a href="/profil" class="lien-mini">Mon compte</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<form method="post" action="/utilisateurs/creer" class="carte" style="margin-top:14px;max-width:520px">
  <div class="carte-tete"><h2>Nouveau compte</h2></div>
  <?php foreach ($erreurs as $e): ?><p class="alerte" style="margin:12px 16px 0"><?= V::e($e) ?></p><?php endforeach; ?>
  <input type="hidden" name="_jeton" value="<?= $j ?>">
  <div class="champ-duo" style="padding:14px 16px 0">
    <div class="champ">
      <label for="cn">Nom</label>
      <input id="cn" name="nom" required>
    </div>
    <div class="champ">
      <label for="ce">Adresse e-mail</label>
      <input id="ce" name="email" type="email" required>
    </div>
  </div>
  <div class="champ">
    <label for="cd">Dénomination</label>
    <input id="cd" name="denomination">
  </div>
  <div class="champ-duo" style="padding:0 16px">
    <div class="champ">
      <label for="cm">Mot de passe</label>
      <input id="cm" name="mot_de_passe" type="password" required autocomplete="new-password">
    </div>
    <div class="champ">
      <label for="cc">Confirmation</label>
      <input id="cc" name="confirmation" type="password" required autocomplete="new-password">
    </div>
  </div>
  <div class="champ">
    <label for="cr">Rôle</label>
    <select id="cr" name="role">
      <option value="user">Utilisateur</option>
      <option value="admin">Administrateur</option>
    </select>
    <div class="aide">Chaque compte a sa propre comptabilité, cloisonnée des autres.
      Un exercice <?= date('Y') ?> est ouvert automatiquement.</div>
  </div>
  <div class="champ barre-actions"><button class="btn btn-p">Créer le compte</button></div>
</form>
