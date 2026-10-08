<?php use Bnc\Auth; use Bnc\Utilisateur; use Bnc\View as V;
$j = V::e(Auth::jeton());
$v = static fn(string $k) => V::e((string) ($moi[$k] ?? ''));
$siren = Utilisateur::siren(isset($moi['siret']) ? preg_replace('/\D/', '', (string) $moi['siret']) : null);

// Complétude : sur les rubriques qui servent réellement à identifier le
// déclarant. Un repère pour savoir où l'on en est, jamais une obligation.
$suivies = ['denomination', 'siret', 'code_ape', 'adresse_1', 'code_postal', 'ville',
            'telephone', 'email_pro', 'profession', 'forme_juridique', 'tva_regime', 'sie'];
$remplies = count(array_filter($suivies, static fn($k) => trim((string) ($moi[$k] ?? '')) !== ''));
$pct = (int) round(100 * $remplies / count($suivies));
?>
<div class="profil-grille">

<form method="post" action="/profil" class="profil-fiche">
  <input type="hidden" name="_jeton" value="<?= $j ?>">

  <?php foreach ($erreurs as $e): ?>
    <p class="alerte" style="margin-bottom:12px"><?= V::e($e) ?></p>
  <?php endforeach; ?>

  <!-- 1. Identité et activité -->
  <div class="carte">
    <div class="carte-tete"><h2>Identité et activité</h2></div>
    <div class="champ">
      <label for="n">Nom et prénom du déclarant</label>
      <input id="n" name="nom" required value="<?= $v('nom') ?>">
    </div>
    <div class="champ">
      <label for="d">Dénomination</label>
      <input id="d" name="denomination" value="<?= $v('denomination') ?>">
      <div class="aide">Portée en tête de la déclaration 2035 et des registres.</div>
    </div>
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="fj">Forme juridique</label>
          <input id="fj" name="forme_juridique" value="<?= $v('forme_juridique') ?>" maxlength="100"
                 placeholder="Entreprise individuelle (EI)">
        </div>
        <div class="champ">
          <label for="pr">Profession exercée</label>
          <input id="pr" name="profession" value="<?= $v('profession') ?>" placeholder="Formateur indépendant">
        </div>
      </div>
    </div>
    <div class="champ">
      <label for="dd">Début d'activité</label>
      <input id="dd" name="date_debut_activite" type="date" value="<?= $v('date_debut_activite') ?>" style="max-width:200px">
    </div>
  </div>

  <!-- 2. Identifiants officiels -->
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Identifiants officiels</h2></div>
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="s">SIRET</label>
          <input id="s" name="siret" value="<?= $v('siret') ?>" inputmode="numeric" placeholder="14 chiffres">
          <div class="aide"><?= $siren ? 'SIREN : <b>' . V::e($siren) . '</b> (neuf premiers chiffres).' : 'Quatorze chiffres, espaces tolérés.' ?></div>
        </div>
        <div class="champ">
          <label for="ape">Code APE / NAF</label>
          <input id="ape" name="code_ape" value="<?= $v('code_ape') ?>" placeholder="8559A" maxlength="8">
          <div class="aide">Quatre chiffres et une lettre, avec ou sans point.</div>
        </div>
      </div>
    </div>
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="tr">Régime de TVA</label>
          <select id="tr" name="tva_regime">
            <option value="">— non précisé —</option>
            <?php foreach (Utilisateur::REGIMES_TVA as $k => $lib): ?>
              <option value="<?= $k ?>" <?= ($moi['tva_regime'] ?? '') === $k ? 'selected' : '' ?>><?= V::e($lib) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="champ">
          <label for="tn">N° de TVA intracommunautaire</label>
          <input id="tn" name="tva_numero" value="<?= $v('tva_numero') ?>" placeholder="FR12345678901">
          <div class="aide">Inutile en franchise ou en exonération.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Formation professionnelle -->
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Organisme de formation</h2>
      <span class="sous">Si vous l'êtes</span></div>
    <div class="champ">
      <label for="nda">Numéro de déclaration d'activité (NDA)</label>
      <input id="nda" name="nda" value="<?= $v('nda') ?>" inputmode="numeric" placeholder="11 chiffres" style="max-width:260px">
      <div class="aide">Délivré par la DREETS lors de l'enregistrement de l'organisme de formation.</div>
    </div>
    <div class="champ">
      <label for="cert">Certifications et labels</label>
      <input id="cert" name="certifications" value="<?= $v('certifications') ?>"
             placeholder="Qualiopi — actions de formation, valable jusqu'au 12/2027">
    </div>
  </div>

  <!-- 4. Adresse -->
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Adresse</h2></div>
    <div class="champ">
      <label for="a1">Adresse</label>
      <input id="a1" name="adresse_1" value="<?= $v('adresse_1') ?>" autocomplete="address-line1">
    </div>
    <div class="champ">
      <label for="a2">Complément d'adresse</label>
      <input id="a2" name="adresse_2" value="<?= $v('adresse_2') ?>" autocomplete="address-line2">
    </div>
    <div class="champ">
      <div class="champ-duo champ-trio">
        <div class="champ">
          <label for="cp">Code postal</label>
          <input id="cp" name="code_postal" value="<?= $v('code_postal') ?>" inputmode="numeric" autocomplete="postal-code">
        </div>
        <div class="champ">
          <label for="vi">Ville</label>
          <input id="vi" name="ville" value="<?= $v('ville') ?>" autocomplete="address-level2">
        </div>
        <div class="champ">
          <label for="pa">Pays</label>
          <input id="pa" name="pays" value="<?= $v('pays') ?>" placeholder="France" autocomplete="country-name">
        </div>
      </div>
    </div>
  </div>

  <!-- 5. Contact -->
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Contact</h2></div>
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="tel">Téléphone</label>
          <input id="tel" name="telephone" value="<?= $v('telephone') ?>" inputmode="tel" autocomplete="tel">
        </div>
        <div class="champ">
          <label for="ep">E-mail professionnel</label>
          <input id="ep" name="email_pro" type="text" value="<?= $v('email_pro') ?>" autocomplete="email">
          <div class="aide">Contact affiché, distinct de l'identifiant de connexion.</div>
        </div>
      </div>
    </div>
    <div class="champ">
      <label for="sw">Site web</label>
      <input id="sw" name="site_web" value="<?= $v('site_web') ?>" placeholder="www.exemple.fr">
    </div>
    <div class="champ">
      <label for="em">Identifiant de connexion</label>
      <input id="em" type="text" readonly value="<?= $v('email') ?>">
      <div class="aide">Ne se modifie pas ici.</div>
    </div>
  </div>

  <!-- 6. Gestion et fiscalité -->
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Rattachement fiscal et gestion</h2></div>
    <div class="champ">
      <label for="sie">Service des impôts des entreprises (SIE)</label>
      <input id="sie" name="sie" value="<?= $v('sie') ?>" placeholder="SIE de Paris 8e">
    </div>
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="agn">Association de gestion agréée</label>
          <input id="agn" name="aga_nom" value="<?= $v('aga_nom') ?>">
        </div>
        <div class="champ">
          <label for="agu">N° d'adhésion</label>
          <input id="agu" name="aga_numero" value="<?= $v('aga_numero') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- 7. Assurance -->
  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Assurance professionnelle</h2></div>
    <div class="champ">
      <div class="champ-duo">
        <div class="champ">
          <label for="asn">Assureur (responsabilité civile)</label>
          <input id="asn" name="assurance_nom" value="<?= $v('assurance_nom') ?>">
        </div>
        <div class="champ">
          <label for="asc">N° de contrat</label>
          <input id="asc" name="assurance_contrat" value="<?= $v('assurance_contrat') ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="barre-fiche">
    <button class="btn btn-p">Enregistrer la fiche</button>
    <span class="sous">Tout est facultatif : une fiche incomplète n'empêche aucun calcul.</span>
  </div>
</form>

<aside class="profil-cote">
  <div class="carte">
    <div class="carte-tete"><h2>Fiche renseignée</h2><span class="sous"><?= $pct ?> %</span></div>
    <div class="champ">
      <div class="jauge-fiche"><div style="width:<?= $pct ?>%"></div></div>
      <div class="aide"><?= $remplies ?> rubrique<?= $remplies > 1 ? 's' : '' ?> sur <?= count($suivies) ?>
        parmi celles qui identifient le déclarant.</div>
    </div>
  </div>

  <div class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>À quoi ça sert</h2></div>
    <div class="champ">
      <p class="txt">Ces informations sont reprises en en-tête de la <b>déclaration 2035</b>, du
        <b>livre des recettes</b> et du <b>registre des dépenses</b>, et figées dans chaque
        <b>archive d'exercice</b> : une archive garde l'identité du déclarant telle qu'elle était à la
        clôture.</p>
      <p class="txt" style="margin-top:8px">Aucun IBAN complet n'est conservé, volontairement.</p>
    </div>
  </div>

  <form method="post" action="/profil/mot-de-passe" class="carte" style="margin-top:14px">
    <div class="carte-tete"><h2>Mot de passe</h2></div>
    <input type="hidden" name="_jeton" value="<?= $j ?>">
    <div class="champ">
      <label for="a">Mot de passe actuel</label>
      <input id="a" name="actuel" type="password" required autocomplete="current-password">
    </div>
    <div class="champ">
      <label for="nv">Nouveau mot de passe</label>
      <input id="nv" name="nouveau" type="password" required autocomplete="new-password">
      <div class="aide"><?= Utilisateur::LONGUEUR_MIN ?> caractères au minimum.</div>
    </div>
    <div class="champ">
      <label for="cf">Confirmation</label>
      <input id="cf" name="confirmation" type="password" required autocomplete="new-password">
    </div>
    <div class="champ barre-actions"><button class="btn">Changer le mot de passe</button></div>
  </form>
</aside>

</div>
