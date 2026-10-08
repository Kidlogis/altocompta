# AltoCompta

Comptabilité de trésorerie pour BNC au réel (déclaration contrôlée) :
livre des recettes, registre des dépenses, import bancaire, affectation
assistée et formulaire 2035. Se connecte à Altoviz pour récupérer les
opérations bancaires.

PHP 8.1+ · MySQL 8 / MariaDB 10.6+ · sans framework, sans Composer.
Fonctionne sur mutualisé comme sur VPS.

Le cœur est sous licence **GNU AGPL v3** (voir `LICENSE`). Les extensions sous
`plugins/` sont **sous licence propriétaire, tous droits réservés** (voir le
`LICENSE` de chacune). Voir « Licences » plus bas.

## Avancement

| Lot | Contenu | État |
|-----|---------|------|
| — | Installateur web guidé | fait |
| A | Socle : schéma, config, PDO, chiffrement | fait |
| B | Auth, sessions, CSRF, exercices, tableau de bord | fait |
| C | Recettes, dépenses, justificatifs | fait |
| D | Connecteur Altoviz, import CSV, idempotence | fait |
| E | Staging, règles, écran d'affectation | fait |
| F | Import OFX / QFX | fait |
| G | Calcul et export PDF de la 2035, exports CSV, clôture | fait |
| H | Montants signés, justificatif au triage, pagination, comptes utilisateurs | fait |
| I | Immobilisations et amortissements (2035-B) | fait |
| J | Barème kilométrique, véhicules, registre des trajets | fait |
| K | Extensions, licences (AGPL v3 pour le cœur, propriétaire pour les extensions), séparation cœur / extensions | fait |
| L | Comptes bancaires multiples, virements internes | fait |
| M | Nomenclature métier formateur | fait |
| N | Régularisations de fin d'exercice | fait |
| O | Annexe 2035-E, registres imprimables | fait |

## Cœur et extensions

Le cœur couvre à lui seul une comptabilité BNC complète : livre des recettes,
registre des dépenses, import de relevés CSV et OFX, affectation assistée,
véhicules et barème kilométrique, formulaire 2035, exports et clôture.

Les extensions vivent sous `plugins/`, une par dossier, s'importent depuis un `.zip`
(Extensions › Importer) et s'activent depuis l'interface. Le cœur ne connaît aucune extension par son nom : il les découvre,
et leur offre des points d'extension — routes, navigation, migrations, postes
de dépense et annexes de la déclaration.

Quatre extensions propriétaires se greffent sur ce cœur, indépendantes les unes des autres :

- **Immobilisations** : registre des immobilisations, plans d'amortissement (linéaire,
  dégressif), dotation en case CH, annexe 2035-B, plus et moins-values de cession.
  Concerne tout BNC au réel qui possède du matériel, quel que soit son service bancaire.
- **Connecteur Altoviz** : synchronisation bancaire planifiée ou immédiate (webhook).
- **Connecteur Evoliz** : synchronisation des lignes bancaires d'un compte Evoliz.
- **Local à domicile** : quote-part professionnelle d'un logement, appliquée aux dépenses concernées (voir plus bas).

Anciennement réunies dans une seule extension, « AltoCompta Pro » (identifiant `pro`) :
un BNC qui n'utilisait qu'Evoliz aurait dû installer le connecteur Altoviz pour amortir
son matériel. Une installation existante est migrée seule, au premier chargement : l'état
de `pro` (actif ou non, licence, date d'installation) est reporté sur les deux extensions
qui la remplacent, ses tables ne bougent pas, son dossier est supprimé.

Chaque connecteur se déclare au cœur par la clé `connecteur` de son manifeste ; les messages
de l'application — file à affecter, listes vides — nomment ceux qui sont installés.

**Sans l'extension Immobilisations**, la 2035 n'a ni case CH ni annexe 2035-B. Le cœur
avertit donc, dans les contrôles avant déclaration, quand une dépense de 500 € ou plus
figure sur un poste où un bien durable peut se cacher (achats, petit outillage, véhicules,
fournitures) : un loyer ou une prime d'assurance ne déclenchent rien.

> Sans cette extension, la déclaration ne comporte ni case CH ni annexe
> 2035-B. Un BNC au réel disposant de biens amortissables doit les
> immobiliser : sa 2035 serait alors incomplète et son résultat surévalué.

Une désactivation ne supprime rien : les données restent en base et sont
retrouvées intactes à la réactivation.

## Où l'héberger

Un mutualisé suffit. L'application n'a aucune dépendance externe : pas de
Composer, pas de `vendor/`, le générateur PDF est écrit à la main. Il faut :

| | |
|---|---|
| PHP 8.1 ou plus | avec `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` |
| Une base MySQL | MySQL 8 ou MariaDB 10.6+ |
| Une tâche planifiée | pour la synchronisation Altoviz — sinon bouton manuel |

`sodium` et `curl` sont préférés mais optionnels : le chiffrement se replie
sur AES-256-GCM et les appels HTTP sur les flux PHP. L'installateur vérifie
tout cela et le dit avant de commencer.

Deux points demandent attention en mutualisé :

**Le docroot.** Idéalement il pointe sur `public/` ; le reste du code est
alors hors d'atteinte par construction. Si l'hébergeur ne le permet pas,
déposer l'application à la racine du site : le `.htaccess` fourni à la racine
réécrit tout vers `public/` et refuse `config/`, `src/`, `db/`, `bin/`,
`storage/`, `templates/` et `tests/`. Cette protection a été vérifiée contre
Apache 2.4, y compris sur des tentatives de contournement par `..`, double
slash et variation de casse.

**La tâche planifiée.** La plupart des mutualisés en proposent. À défaut,
déclarer le webhook côté Altoviz, ou lancer la synchronisation depuis le
bouton de l'interface.

Un VPS n'apporte que du confort : accès SSH pour les migrations, cron à la
minute, et maîtrise des versions de PHP.

## Installation

Créer le site et la base chez l'hébergeur, puis déposer les fichiers et
pointer le docroot sur `public/`.

```bash
cd ~/web/compta.exemple.fr
git clone <depot> .
chmod 750 config storage storage/justificatifs
```

Ouvrir ensuite `https://compta.exemple.fr/install.php` : l'installateur
vérifie les prérequis, teste la connexion à la base, applique le schéma,
crée le compte et écrit `config/config.php` avec une clé de chiffrement
neuve. Il se verrouille dès que la configuration existe.

Une fois l'installation faite :

```bash
rm public/install.php
```

### Hostinger

Hostinger impose `public_html` comme racine du site, mais l'arborescence du
projet s'y prête sans modification : déposer le **contenu** de `public/` dans
`public_html`, et tous les autres dossiers à côté, un cran au-dessus.

```
~/domains/altocompta.fr/
├── public_html/     ← contenu de public/ (index.php, install.php, assets/…)
├── config/
├── src/
├── db/
├── bin/
├── storage/
├── templates/
└── tests/
```

Les chemins relatifs du code tombent juste tels quels, et le `.htaccess`
racine devient inutile : `config/`, `src/` et `storage/` sont hors du docroot,
donc inatteignables par construction plutôt que par règle de réécriture.
Ce montage a été vérifié sous Apache 2.4 — installation, parcours complet,
dépôt et lecture d'un justificatif.

Avant d'ouvrir l'installateur, rendre `config/` et `storage/justificatifs/`
inscriptibles par le serveur web ; l'installateur refuse de commencer sinon,
en le disant.

Points propres à Hostinger :

- **Tâches planifiées.** Le plan Single est limité à deux tâches cron ; les
  plans supérieurs en autorisent davantage. L'édition directe du crontab et
  les horaires très personnalisés sont réservés aux VPS. Une seule tâche
  suffit ici : `php ~/domains/altocompta.fr/bin/synchroniser.php --silencieux`.
- **Version de PHP.** À régler sur 8.1 minimum dans hPanel.
- **Repli.** Si la synchronisation planifiée n'est pas possible, déclarer le
  webhook côté Altoviz ou utiliser le bouton de l'interface.

### Autre mutualisé sans docroot configurable

Déposer l'ensemble à la racine du site et garder le `.htaccess` racine.
Ouvrir ensuite `https://votre-domaine/install.php`. Les migrations se lancent
depuis le gestionnaire de tâches planifiées de l'hébergeur, ou par SSH s'il
en propose un.

### nginx

`public/nginx.conf.exemple` donne la configuration attendue. Le point
important : les fichiers réels sont servis directement et tout le reste
passe par `index.php`, sans quoi `install.php` devient inatteignable.
Sous Apache, le `.htaccess` fourni suffit.

### Tâche planifiée

```
*/30 * * * * /usr/bin/php /home/UTILISATEUR/web/DOMAINE/bin/synchroniser.php --silencieux
```

Le script respecte la cadence choisie par connexion et pose un verrou : deux
passages ne peuvent pas se chevaucher. Options utiles en diagnostic :

```bash
php bin/synchroniser.php --force            # ignore la cadence
php bin/synchroniser.php --source=altoviz --connexion=1   # une seule connexion
```

### Notification immédiate

`public/webhook.php` déclenche une synchronisation dès qu'Altoviz signale
une opération, sans attendre le passage planifié. L'URL à déclarer côté
Altoviz s'affiche dans les réglages de la connexion ; elle porte un secret
propre à chaque connexion, comparé en temps constant. Les rafales sont
absorbées : une notification reçue moins d'une minute après la précédente
renvoie 202 sans relancer d'appel.

### Développement local

```bash
php -S 127.0.0.1:8000 -t public public/routeur-dev.php
```

## Mise à jour

Après un `git pull` :

```bash
php db/migrate.php --etat    # ce qui reste à appliquer
php db/migrate.php           # applique
```

`db/schema.sql` est la référence unique du schéma. Les évolutions ultérieures
prennent un fichier numéroté dans `db/migrations/`, appliqué une seule fois et
tracé en base ; chaque changement doit être porté **à la fois** dans la
migration et dans `db/schema.sql`, pour qu'installation neuve et mise à jour
convergent.

`db/historique/` conserve les migrations de la phase de développement, hors
chaîne d'exécution : elles ont été repliées dans `schema.sql` avant toute mise
en production et ne doivent pas être rejouées.

## Extensions PHP

`pdo_mysql`, `mbstring`, `openssl`, `fileinfo`. `sodium` est optionnel mais
préféré pour le chiffrement des clés API ; `curl` l'est aussi, avec repli
sur les flux PHP.

## Points d'architecture

**Comptabilité de trésorerie.** La date qui fait foi est celle de
l'encaissement ou du décaissement, jamais celle de la facture.

**Montants signés.** Une recette peut être négative — c'est un avoir consenti
au client — et une dépense aussi — c'est un remboursement reçu du fournisseur.
Prendre la valeur absolue à l'affectation enregistrerait ces écritures à
l'envers et fausserait le résultat du double du montant. Le sens de l'opération
bancaire est donc conservé, et l'écriture porte un indicateur `est_avoir` ou
`est_remboursement`.

**Justificatifs au triage.** Une pièce se dépose sur l'opération bancaire
avant même son affectation : en pratique la facture arrive souvent avant que
le prélèvement apparaisse, ou l'on vide un dossier de PDF en fin de mois. Au
moment de l'affectation, la pièce est rattachée à l'écriture créée, tout en
gardant son lien vers l'opération d'origine pour la traçabilité.

**Idempotence des imports.** Chaque opération porte une empreinte SHA-256
calculée sur `user_id + date + montant + libellé normalisé + compte`, sous
index unique. Une synchronisation peut être rejouée sans créer de doublon,
ce qui autorise un recouvrement de sept jours à chaque passage pour
rattraper les opérations rétro-datées par la banque.

**Lecture des relevés.** Les colonnes sont reconnues par leur intitulé et
non par leur position, avec synonymes français et anglais. Séparateur,
encodage (UTF-8, BOM, Windows-1252) et format de date sont détectés. Une
colonne montant unique ou une paire crédit/débit fonctionnent également.
Le même parseur sert à l'export Altoviz et aux CSV téléchargés d'une banque.

**Formats de relevé.** Le format est déduit du contenu, pas de l'extension :
un `.qfx` reste de l'OFX et certaines banques servent du CSV sous une autre
extension. L'OFX 1.x est du SGML aux balises non fermées, donc illisible par
un parseur XML ; la lecture se fait sur les blocs de transaction, ce qui
couvre aussi l'OFX 2.x qui, lui, est du XML.

**Génération du PDF.** Écrite sans dépendance : pas de Composer à installer
sur le serveur. Le module produit un PDF 1.4 avec table de références
croisées et polices de base, texte converti en WinAnsi — sans quoi les
accents ressortent faux.

**Case AF.** Le montant net des recettes vaut AA − AC − AD : les débours
payés pour le compte des clients et les honoraires rétrocédés ne sont pas
des recettes propres. Les produits financiers et gains divers s'ajoutent
ensuite au net.

**Télétransmission.** La 2035 se télédéclare en EDI-TDFC par un partenaire
agréé DGFiP. Aucun logiciel ne peut transmettre directement sans agrément :
l'application produit le PDF et les CSV qu'attend un tel partenaire.

**Étanchéité du cœur.** Le cœur ne référence aucune table d'extension, pas
même par une jointure de confort : un véhicule rattaché à une immobilisation
ne porte qu'un identifiant, résolu par l'extension quand elle est là. Chaque
page a été vérifiée dans les deux états, extension active et absente.

**Annexe 2035-E.** La valeur ajoutée vaut les recettes diminuées des
consommations en provenance de tiers ; rémunérations, charges sociales,
amortissements et charges financières en sont exclus. L'annexe n'est exigée
qu'au-delà de 152 500 € de recettes, ce que l'application signale plutôt que
de produire un tableau inutile. Un contrôle vérifie qu'aucun poste de dépense
n'échappe au classement — un poste ni consommé ni exclu disparaîtrait
silencieusement du calcul. La ventilation retenue est un défaut à vérifier
avec l'AGA.

**Registres imprimables.** Le livre des recettes et le registre des dépenses
s'éditent en PDF paginé, portant l'identité du déclarant, les bornes de
l'exercice, les totaux et un numéro de page — ce qu'un CSV ne permet pas de
présenter en contrôle. Les colonnes sont tronquées d'après la largeur réellement
mesurée du texte, jamais d'après un compte de caractères, et les colonnes
chiffrées ne sont jamais tronquées.

**Régularisations.** La comptabilité BNC se tient en trésorerie : une charge
n'est déductible qu'une fois décaissée. Certaines écritures dérogent à ce
principe, au premier rang desquelles les cotisations sociales dues au
31 décembre mais appelées plus tard. Elles sont saisies à part des registres,
chacune avec une justification obligatoire. Leur admissibilité dépend du
régime applicable et relève d'un arbitrage avec l'AGA ou l'expert-comptable :
le logiciel les enregistre et les trace, il ne se prononce pas.

**Contre-passation.** Une charge déduite en N réapparaît sur le relevé de N+1
au moment de son paiement, et serait alors déduite une seconde fois. L'impact
d'une régularisation est donc porté positivement sur son exercice et
négativement sur le suivant, calculé à la volée plutôt que par une écriture
miroir à maintenir. Le contrôle de la déclaration signale les contre-passations
appliquées.

**Nomenclature métier.** La 2035-A raisonne en codes — BP, BW, BS — que rien
ne rend évidents pour un formateur. Une couche de 29 catégories parlantes est
livrée, chacune adossée à sa case officielle : « Assurance responsabilité
civile professionnelle » suffit, le poste BP en découle. Chacun peut ajouter
les siennes ; les catégories livrées ne sont pas modifiables, seulement
complétées.

**Pré-catégorisation.** Les mots-clés des catégories servent de filet à
l'import, après les règles personnelles qui restent prioritaires. Sans avoir
rien configuré, un prélèvement URSSAF part en charges sociales, une facture
OVH en télécommunications, un billet SNCF en frais de déplacement. Le mot-clé
le plus long l'emporte, pour qu'une correspondance précise prime sur une
générique.

**Comptes bancaires.** Plusieurs comptes professionnels sont possibles, plus
un compte mixte le cas échéant. Chaque opération est rattachée à un compte
réel, reconnu d'après des motifs déclarés — ce que les relevés écrivent pour
désigner le compte — ou imposé au moment de l'import lorsque le fichier ne le
mentionne pas.

**Virements internes.** Un virement entre deux de ses propres comptes apparaît
deux fois : en débit sur l'un, en crédit sur l'autre. Traité comme un flux
ordinaire, le crédit deviendrait une recette et le débit une dépense, gonflant
les cases AA et CE du montant viré chacune. Les paires sont donc appariées —
même montant en sens opposé, comptes distincts, moins de cinq jours d'écart —
et une seule confirmation écarte les deux moitiés, tracées avec leur motif.

**Motifs d'exclusion.** Une opération écartée porte la raison de son exclusion :
privé, virement interne, TVA, apport ou retrait. Un mouvement hors résultat
reste ainsi traçable, au lieu d'être noyé dans un « ignoré » indifférencié.

**Reprise après erreur.** Une synchronisation en échec ne met pas à jour
`derniere_sync` : sans garde, une clé invalide ferait retenter la connexion
à chaque passage du cron. Une connexion en erreur est donc espacée d'une
heure au minimum, sur la foi de sa dernière tentative réelle. `--force`
passe outre.

**Immobilisations.** Un bien durable n'est jamais une dépense déductible :
seule la dotation annuelle entre en case CH de la 2035-A, et le bien figure au
tableau de l'annexe 2035-B. Le seuil de tolérance retenu est de 500 €, en deçà
duquel un bien de faible valeur peut passer directement en charge ; un contrôle
signale les dépenses qui dépassent ce seuil.

**Amortissement linéaire.** Taux = 1 / durée. La première annuité est réduite
au prorata temporis depuis la mise en service, en jours sur une année de 360
(mois de 30 jours), usage constant de l'administration. Le reliquat tombe sur
une annuité supplémentaire en fin de plan — un bien mis en service en avril
s'amortit donc sur six exercices pour une durée de cinq ans.

**Amortissement dégressif.** Taux = taux linéaire × coefficient, celui-ci
valant 1,25 de 3 à 4 ans, 1,75 de 5 à 6 ans et 2,25 au-delà. Le prorata de
première annuité se compte en mois entiers depuis l'acquisition, le mois
d'acquisition comptant plein. La bascule en linéaire sur la valeur résiduelle
intervient dès que le taux linéaire calculé sur la durée restante dépasse le
taux dégressif. Le mode est refusé pour les véhicules et se replie sur le
linéaire en deçà de trois ans de durée.

**Plus-values de cession.** Plus-value = prix de cession − valeur nette
comptable, la VNC étant arrêtée à la clôture de l'exercice précédent puisque
l'année de cession ne porte pas de dotation. La plus-value est à court terme à
hauteur des amortissements pratiqués et à long terme au-delà, le long terme
supposant une détention d'au moins deux ans. Une moins-value est toujours
intégralement à court terme. Ces montants sont présentés à part : leur
traitement fiscal dépend du régime et n'est pas intégré au résultat.

**Barème kilométrique.** L'option est annuelle et globale : elle vaut pour
tous les véhicules de l'exercice. L'indemnité couvre carburant, entretien,
assurance, pneumatiques et dépréciation ; en contrepartie les frais réels
saisis en case BR sont écartés du calcul et l'amortissement des véhicules
rattachés est exclu de la case CH. Le montant est déterminé par la tranche
correspondant au total annuel parcouru, dont la formule s'applique à la
totalité des kilomètres — ce n'est pas un barème progressif par paliers. Une
majoration de 20 % s'applique aux véhicules électriques.

**Coefficients du barème.** Ils sont fixés par arrêté chaque année : ils
vivent en base et sont éditables, jamais figés dans le code. Les valeurs
livrées sont celles de 2024 et servent de point de départ ; une fonction de
report duplique une année vers la suivante pour n'avoir plus qu'à corriger
les coefficients. Un exercice sous option sans barème saisi est signalé comme
une erreur plutôt que calculé avec des valeurs périmées.

**Registre des trajets.** Les kilomètres déclarés se justifient par un relevé
daté portant le motif du déplacement, exportable en CSV. Le total par véhicule
alimente le calcul de l'indemnité.

**Transactions bancaires Altoviz.** Confirmé par la référence REST
officielle (developer.altoviz.com/api, tag bankaccounts) :
`GET /v1/BankAccounts` liste les comptes connectés, et
`GET /v1/BankAccounts/{id}/Transactions` les transactions de chacun — du
JSON paginé (en-tête `X-PAGE-NEXT`), sans aucun mécanisme d'export ou de
format CSV. Une piste antérieure supposait une famille d'API d'export dédiée
d'après le changelog de juin 2026 ; elle s'est révélée être une
fonctionnalité différente (téléchargement de registres comptables complets),
les cinq chemins essayés répondant tous 404 en 35-43 ms. Le guide de
pagination officiel, avec son exemple réel sur `/v1/customers`, a confirmé
que les ressources répondent directement en JSON.

Les noms de champs exacts d'une transaction (date, montant, libellé) ne sont
pas publiés en clair : la lecture essaie plusieurs noms plausibles
(`date`/`transactionDate`/`valueDate`…) et échoue explicitement, sans
produire de montant erroné, si aucun ne correspond — le journal des appels
garde alors la réponse brute pour ajuster.

Le rattachement à un compte AltoCompta se fait par reconnaissance du
libellé de compte Altoviz, exactement comme pour un import manuel.

**Règles d'affectation.** Évaluées par priorité croissante, avec quatre
types de correspondance et un filtre de sens. Une expression régulière
invalide est rejetée à la saisie et, si elle passe malgré tout, ne fait
échouer aucune synchronisation.

**Quote-part professionnelle.** Une dépense d'usage mixte n'entre dans la
2035 que pour sa part professionnelle. Les totaux appliquent
`montant × quote_part_pro / 100` partout.

**Numérotation des recettes.** `numero_chrono` est unique par
`(user_id, exercice_id)`, attribué sous verrou dans la transaction
d'insertion. Une suppression renumérote la suite pour que la séquence
reste continue et sans trou.

**Justificatifs.** Stockés hors docroot, nommés par leur empreinte, servis
par PHP après contrôle de propriété. Le type est déterminé en lisant le
contenu (`finfo`), jamais d'après ce que déclare le navigateur. Un fichier
partagé par plusieurs justificatifs n'est effacé qu'au dernier retrait.

**Cloisonnement.** Toutes les tables portent `user_id`, filtré dans chaque
requête, et les index uniques sont composés avec lui.

**Secrets.** Les clés API sont chiffrées (libsodium, repli AES-256-GCM)
avec une clé maître vivant dans `config/config.php`, hors base et hors Git.
Sauvegarder ce fichier : sans lui, les clés API sont à régénérer.

## Journal des appels API

La page `/altoviz` de l'extension Pro affiche désormais chaque appel HTTP
envoyé à Altoviz — chemin exact, code retour, extrait de la réponse, durée —
succès comme échec. Sans lui, un 404 ne dit ni quel chemin candidat a été
essayé ni ce qu'Altoviz a réellement répondu, ce qui rend le diagnostic
impossible sans compte de test. Le journal garde les 200 dernières lignes
par utilisateur et peut être vidé depuis l'interface.

Vérifié contre un faux serveur Altoviz local reproduisant un vrai 404 : les
cinq chemins candidats de l'export bancaire sont tracés un par un avec leur
extrait JSON, le journal se vide et le bloc disparaît de la page une fois vide.

## Menu latéral en tuiles

La navigation est une grille de tuiles colorées à coins arrondis, dans
l'esprit Windows 10 : un pictogramme par destination (Iconoir, licence MIT,
traits 1,5 px, https://iconoir.com), sur un fond de couleur propre à chaque
page, groupées par section (Registres, Banque, Déclaration, Réglages). La
tuile de la page courante porte un fin liseré blanc. « À affecter » montre
le nombre d'opérations en attente dans un badge.

Les pictogrammes vivent sous forme de contenu SVG brut dans `src/icones.php`
(seize icônes), chargés par `View::icone()` — pas de dépendance externe,
conforme au principe du projet. Une extension ajoute ses propres entrées de
menu avec une icône et une couleur par défaut si elle n'en déclare pas.

Icône et libellé sont centrés dans chaque tuile. Le panneau latéral a été
élargi (280 → 300 px) et le logo agrandi en conséquence, en restant dans la
limite que permet son ratio sans distorsion : au-delà d'une certaine
hauteur, une largeur figée combinée à `max-width` aurait écrasé le logo
plutôt que de simplement le recadrer.

La classe `.tuile` des nouvelles tuiles de navigation portait le même nom
qu'une classe déjà utilisée par les cartes du tableau de bord
(`Recettes encaissées`, etc.), sans qu'aucun des deux usages ne le laisse
deviner au premier regard — une collision qui n'avait pas cassé l'affichage
par chance (la couleur de fond passe par un style en ligne, prioritaire sur
les deux règles), mais qui restait fragile. Renommée en `.tuile-nav` avant
qu'un futur changement sur l'une des deux cartes ne vienne perturber
l'autre.

Sur mobile, où la barre devient horizontale, les tuiles passent en icône
seule à 40×40 px. Un bug de spécificité CSS a été trouvé puis corrigé à ce
sujet : une règle `.nav a{width:100%}` héritée de l'ancien menu en liste
(non supprimée lors de la refonte) l'emportait sur la largeur voulue malgré
un ordre de déclaration plus tardif, à cause d'une spécificité plus élevée —
corrigé en retirant ce bloc devenu mort. Vérifié par sondage direct du DOM
(`getBoundingClientRect`), pas seulement par relecture du CSS.

## Identité visuelle

Le logo vit sous `public/assets/img/` en plusieurs déclinaisons de taille.
La version couleur porte un liseret blanc autour des lettres, qui la rend
lisible aussi bien sur fond clair (écran de connexion, en-tête des PDF) que
sur le fond sombre de la barre latérale et de l'écran d'installation — une
version monochrome blanche avait d'abord été générée par précaution, avant
vérification qu'elle était inutile. Favicons dérivées du pictogramme seul.

Le générateur PDF maison (`src/Pdf.php`) sait embarquer une image PNG avec
transparence : décodage via GD, plans RGB et alpha compressés séparément
(XObject Image et son SMask), un logo répété sur plusieurs pages n'étant
encodé qu'une fois. Une image absente ou illisible ne fait pas échouer le
document.

Le logo est affiché en largeur fixe (240 px) sur les écrans de connexion et
d'installation, en hauteur fixe (76 px) dans la barre latérale — élargie à
280 px pour l'accueillir sans déborder ni toucher les bords. Sur mobile, où
la navigation devient une barre horizontale, le logo repasse à 28 px pour
ne pas écraser les liens de navigation.

**Invalidation de cache.** Les fichiers statiques (feuille de style, logo)
sont servis avec un paramètre de version fondé sur leur date de
modification (`View::asset()`). Sans lui, un navigateur ayant déjà mis en
cache une ancienne version continue de la servir après une mise à jour,
jusqu'à un rechargement forcé — visible notamment quand le logo reste
petit malgré une modification livrée.

## TVA : l'éclatement HT / TVA des assujettis

Tout ceci n'existe que si le régime de TVA de **Mon compte** est « assujetti ». Pour un autre régime (franchise en
base, exonération), rien ne change : ni les écrans, ni les exports, ni les calculs, ni le contenu du journal.

### Le principe : le montant reste le TTC

`montant` est **toujours le TTC du virement**. L'affectation, les doublons, les virements internes, les règles et les
connecteurs bancaires ne connaissent que lui et ne changent pas. L'éclatement se fait **ensuite, sur la fiche de
l'écriture** (« Modifier »), dans un bloc « Éclatement HT / TVA » :

| Champ | Rôle |
|---|---|
| Taux de TVA | facultatif ; sert à calculer, et sera indispensable aux déclarations CA3 / CA12 (il est donc conservé) |
| Montant HT, TVA | tels qu'imprimés sur la facture ; on peut n'en saisir qu'un, ou seulement le taux : le reste se déduit du TTC |
| TVA non récupérable | dépenses seulement, facultatif (0 par défaut) : la part de TVA que l'on ne peut pas déduire (véhicule de tourisme, cadeaux…). Elle reste **une charge** |

Le serveur contrôle tout, au centime, en entiers : HT + TVA doit égaler le TTC, HT et TVA ont le signe du montant
(négatifs pour un avoir ou un remboursement), la TVA ne dépasse pas le TTC, et le taux doit s'accorder aux montants à
quelques centimes d'arrondi près. Rien n'est « arrangé » en silence : une saisie incohérente est refusée avec la raison.
Pour une facture à plusieurs taux, laisser le taux vide.

### Ce que ça change pour un assujetti

- **La 2035 se calcule hors taxes** : recettes en HT ; dépenses en HT **plus la TVA non récupérable**, puis quote-part
  professionnelle. Il en va de même du tableau de bord, des provisions (qui partent de la déclaration), du seuil
  d'immobilisation (500 € s'apprécie sur le HT) et de l'extension Local à domicile (≥ 1.0.1).
- **Tant qu'une écriture n'est pas éclatée, elle compte pour son TTC**, faute de mieux. La déclaration le signale :
  « N écritures à éclater (x recettes, y dépenses) », avec un lien vers le registre filtré (« À éclater »).
- **Les registres** affichent TTC, HT et TVA ; une ligne non éclatée porte un bouton « à éclater ». Les exports CSV
  et les registres PDF ajoutent les colonnes HT et TVA (le CSV garde son format d'avant pour les autres régimes).
- **Éclater une écriture existante est une correction**, comme tout changement de montant : l'originale est annulée
  par une écriture d'annulation, puis la nouvelle est inscrite. Chaque éclatement ajoute donc **deux lignes** au
  registre (masquables : « masquer les lignes de correction »). L'écriture d'annulation porte l'éclatement
  **opposé**, de sorte que la somme du registre reste nulle en HT comme en TTC.
- **Un exercice clos ne se corrige plus** : éclater avant de clore.
- **Le journal et les archives** consignent l'éclatement (archive au format 2). Les archives et le journal scellés avant
  cette version restent valides : ils conservent leur contenu sérialisé et vérifient son empreinte, sans le recalculer.
- **Une extension qui reconstruit une écriture à la main** (et ne connaît pas l'éclatement) ne le détruit pas : le cœur le
  reprend de l'originale, tant que le TTC n'a pas changé. Si le TTC change sans nouvel éclatement, l'ancien serait
  faux : l'écriture repasse « à éclater ».
- **Changer de régime** ne supprime rien : les éclatements restent en base, ignorés tant que l'on n'est pas assujetti,
  et retrouvés si on le redevient.

### La page TVA

Menu **Déclaration › TVA**, réservé aux assujettis (pour les autres, la page renvoie à l'accueil et la tuile n'existe pas).
Elle répond à une question : **que dois-je à l'État, période par période ?**

| Colonne | Contenu |
|---|---|
| TVA collectée | la TVA des recettes encaissées dans la période ; un avoir la diminue |
| Déductible, dépenses | la TVA des dépenses payées, **moins la part non récupérable**, au prorata professionnel ; un remboursement fournisseur la diminue |
| Déductible, acquisitions | la TVA des biens immobilisés, saisie sur chaque bien par l'extension Immobilisations (≥ 1.1.0), voir plus bas |
| TVA nette | collectée − déductibles |
| Crédit reporté | ce qui n'a pas pu être déduit à la période précédente |
| À payer / Crédit à reporter | la nette moins le crédit reporté : positive, elle se paie ; négative, elle devient un crédit pour la période suivante |

- **La périodicité se choisit** (mensuelle, trimestrielle, annuelle) : elle dépend du régime d'imposition à la TVA, que l'application ne
  peut pas deviner. Tant qu'elle n'est pas choisie, l'exercice est totalisé d'un bloc et la page invite à la choisir.
- **Calendrier civil** : mois ou trimestres. Un exercice qui ne commence pas au début d'une période (le premier, souvent) a une première
  période partielle ; un exercice qui chevauche deux années (juillet → juin) est découpé de la même façon.
- **La TVA se compte à la date de l'écriture** : à l'encaissement pour les recettes, au paiement pour les dépenses. C'est la règle des
  prestations de services. Une vente de **biens** (exigible à la livraison) et l'**option pour les débits** ne sont pas gérées.
- **Une écriture non éclatée en HT / TVA n'a pas de TVA connue** : elle n'est **pas comptée**, et la page le dit — en haut, avec le nombre
  d'écritures concernées, et par période. Ne jamais lire ces chiffres comme complets tant qu'il en reste.
- **Le crédit d'ouverture** se saisit par exercice (premier exercice suivi ici, ou crédit antérieur à AltoCompta). Si l'exercice précédent
  s'est achevé sur un crédit, la page propose de le reprendre ; elle ne le fait pas d'elle-même. Un exercice clos ne se modifie plus.
- **Les paiements de TVA** déjà repérés (opérations bancaires écartées avec le motif « TVA ») sont listés, avec le total versé et remboursé.
  Ils ne sont pas rapprochés automatiquement des périodes : la TVA d'une période se paie le mois suivant, à cheval sur deux exercices au
  changement d'année.
- **Export CSV** de la page, période par période, pour l'expert-comptable.
- **Ce n'est pas la CA3 ni la CA12** : ce sont les montants à y reporter, à contrôler.

**Les acquisitions.** Un bien acheté est immobilisé : il n'est dans aucune dépense, donc sa TVA n'est dans aucun registre. Elle se
**saisit sur le bien lui-même, comme pour une dépense** (extension Immobilisations ≥ 1.1.0) : montant HT, TVA, taux, TVA non
récupérable. Si le bien est lié à un virement, HT + TVA doit égaler son TTC, comme pour une dépense ; on peut aussi ne saisir que le
taux. La TVA est comptée **à la date d'acquisition** (celle de la facture ou de la livraison, quand la TVA devient exigible pour un
bien), jamais à la mise en service, qui ne sert qu'à démarrer l'amortissement.

- **La valeur d'entrée qui s'amortit est le HT plus la TVA non récupérable.** Un véhicule dont toute la TVA est non récupérable s'amortit
  donc sur son TTC, sans règle particulière.
- **Rien n'est déduit ni deviné** : un bien sans TVA saisie (créé avant, ou oublié) est « **TVA à renseigner** », non compté, et la page
  l'annonce avec le nombre de biens concernés. Les biens existants ne sont pas modifiés par la mise à jour de l'extension.

| État | Cas | Comptée ? |
|---|---|---|
| comptée | TVA saisie, au prorata professionnel, moins la part non récupérable | oui |
| sans TVA | TVA saisie à zéro | sans objet |
| TVA à renseigner | aucune TVA saisie | non |

Si l'extension Immobilisations est active **sans pouvoir déclarer ses acquisitions** (versions antérieures à 1.0.2), la page affiche un
avertissement (la TVA à payer est alors surévaluée du montant de la TVA récupérable sur les achats de matériel) plutôt que de se taire.

### Ce qui n'est pas fait

Les lignes de la **CA3** et de la **CA12**, et les **acomptes** du régime simplifié. La vente de **biens** et l'**option pour les débits**.
Le rapprochement automatique des paiements de TVA avec les périodes. Les opérations intracommunautaires et l'autoliquidation. La TVA de
l'acquisition d'un bien saisie explicitement (elle est déduite du virement). À valider avec un expert-comptable.

### Poste et catégorie d'une dépense

Une **catégorie** a toujours **un** poste de la 2035 (la case en découle). Un **poste**, lui, peut avoir **plusieurs** catégories (les
catégories livrées en comptent une ou deux par poste), si bien que choisir un poste ne désigne pas toujours une catégorie. Une règle
d'affectation ne connaît qu'un poste : les écritures qu'elle crée ont donc un poste et, selon le cas, pas de catégorie.

- **À l'affectation**, si aucune catégorie n'est choisie ni suggérée, l'application enregistre la catégorie du poste **quand il n'y en a
  qu'une** (le lien est alors sans ambiguïté). Avec plusieurs, elle n'en invente aucune. Une écriture existante n'est jamais modifiée.
- **Sur la fiche d'une écriture sans catégorie**, la catégorie unique du poste est présélectionnée ; sinon le poste est **conservé** (champ
  caché) et la catégorie devient facultative : on peut éclater HT et TVA sans que le poste change. Choisir une catégorie remplace le
  poste par celui de cette catégorie.

### Fichiers statiques et cache

Le CSS et le JavaScript sont chargés avec un numéro de version (`app.js?v=…`) : après une mise à jour, le navigateur recharge le nouveau
fichier au lieu de garder l'ancien. Le fichier est cherché là où le serveur web le sert (`DOCUMENT_ROOT`), puis dans `public/` et
`public_html/` ; à défaut, le numéro de version de l'application sert de repère. Sans cela, sur un hébergement mutualisé dont le dossier public
ne s'appelle pas `public`, aucun fichier n'était versionné.

## Boutique et extensions

### Ce que l'on livre

| Fichier | Contenu | Où l'importer |
|---|---|---|
| `altocompta-core-<version>.zip` | le cœur, **sans** extension | installation, puis Mise à jour |
| `altocompta-<extension>-<version>.zip` | une extension | Extensions › Importer |

Il n'y a **pas de clé de licence** : l'achat donne un fichier `.zip`, qu'il suffit d'importer. La clé de
licence et son champ ont été retirés de la page Extensions.

### Importer une extension

**Pas à pas.** Menu **Extensions** (ou le bouton « Importer une extension (.zip) » de la **Boutique**) › choisir le fichier
`.zip` reçu après l'achat › **Analyser le fichier** › vérifier l'aperçu › cocher la case de confiance › **Installer l'extension** ›
revenir sur **Extensions** et cliquer **Activer**.

Extensions › Importer une extension, en deux temps : une **analyse** affiche un aperçu (nom, version, nombre de
fichiers, empreinte SHA-256, installation ou mise à jour), puis une **confirmation** explicite, cochée, installe.
Rien n'est écrit avant. Une mise à jour sauvegarde d'abord l'ancienne version dans `storage/sauvegardes/` et la
rétablit si le remplacement échoue. Les données de l'extension en base sont conservées ; ses migrations
s'appliquent au chargement suivant.

**Une extension est du code PHP exécuté avec les droits de l'application**, avec accès à toutes les données.
L'analyse vérifie la *forme* du fichier, pas ses *intentions* : n'importez que des fichiers dont vous connaissez
la provenance. Ce que le module garantit :

- **Aucun code n'est exécuté avant la confirmation.** Les métadonnées viennent de `manifeste.json`, lu comme des
  données.
- **Le zip ne peut écrire que sous `plugins/<identifiant>/`** : un seul dossier racine, aucun `..`, aucun chemin
  absolu, aucun lien symbolique, aucun fichier caché, noms limités à `A-Z a-z 0-9 . _ - /` (ce qui écarte aussi
  les noms qui trompent l'œil, comme le renversement de texte Unicode), types limités à `php sql md txt json css js
  svg png jpg jpeg gif webp` et aux fichiers `LICENSE`, `NOTICE`, `README`, `LISEZMOI`.
- **Les tailles sont limitées sur les octets réellement lus**, non sur ceux que l'archive déclare : 20 Mo par zip,
  600 fichiers, 10 Mo par fichier, 40 Mo une fois décompressé.
- **L'installation revalide tout** : l'aperçu et l'installation sont deux requêtes, on ne se fie à rien d'affirmé
  entre les deux, et le fichier en attente est comparé à son empreinte.
- **L'empreinte SHA-256 est confrontée au catalogue** : si une boutique publie celle de cette version et que le fichier
  diffère, l'aperçu l'affirme en rouge.
- Une version plus ancienne que celle installée est refusée ; le cœur doit être au moins en `core_min`.

**Format d'un paquet.** Un dossier racine nommé comme l'extension, contenant `plugin.php` et `manifeste.json` :

```json
{ "id": "domicile", "nom": "Local à domicile", "version": "1.0.0", "core_min": "2026.10.11",
  "auteur": "Kidlogis", "licence": "propriétaire", "description": "…" }
```

L'identifiant doit correspondre au dossier ; `pro` est réservé (ancienne extension remplacée).

### La boutique

Le menu **Boutique** affiche le catalogue des extensions : couverture, titre, résumé, prix, détails, lien d'achat,
et l'état sur cette installation (non installée, installée, active, mise à jour disponible, incompatible). Le
paiement a lieu sur le site du vendeur : AltoCompta ne gère ni argent ni clé.

- **La boutique de Kidlogis est embarquée** (`boutique/kidlogis.json`) : elle fonctionne hors ligne. Ses couvertures
  (`boutique/images/`) sont **servies par l'application** (`/boutique/image/<nom>`, réservé aux administrateurs), et
  non comme fichiers statiques du dossier public : cela ne dépend ni des droits des dossiers ni de la configuration du
  serveur web.
- **On peut importer d'autres boutiques**, depuis une adresse https ou un fichier `.json`, puis les actualiser ou les
  retirer. Une boutique importée est étiquetée « tierce, non vérifiée ».
- **Une boutique ne peut rien installer** : elle présente et renvoie vers l'achat. L'installation reste le geste
  local décrit plus haut.

**Une boutique importée est un contenu non fiable.** Elle est validée champ par champ avant d'être conservée
(un champ invalide refuse la boutique entière, avec la raison) ; ses textes ne sont jamais interprétés comme du
HTML ; ses liens et images doivent être en https, sans identifiants ni caractères propres à casser une page ; elle
est limitée à 512 Ko et 100 produits. L'import **par adresse** est un appel du serveur vers un site tiers : il est
limité à https sur le port 443, refuse toute adresse qui résout vers un réseau interne (loopback, réseaux privés,
adresses de métadonnées cloud, plages réservées, IPv4 déguisée en IPv6), se connecte à l'adresse IP **qu'il vient de
contrôler** (un nom de domaine ne peut pas répondre « public » au contrôle et « 127.0.0.1 » à la connexion), ne
suit aucune redirection, et coupe à 15 secondes. Le réglage `boutique.reseau_prive` dans `config.php` lève ces
restrictions : **réservé aux essais en local**, jamais en production.

**Format d'une boutique (schéma 1).**

| Champ | Règle |
|---|---|
| `schema` | `1` (un schéma plus récent est refusé avec son numéro) |
| `boutique` | `id` (identifiant, `kidlogis` réservé), `nom`, `url` (https, facultatif), `description` |
| `produits[].id`, `.extension` | identifiants ; `extension` désigne le dossier de l'extension (par défaut `id`) |
| `.titre`, `.resume`, `.description`, `.prerequis`, `.editeur` | texte brut, longueurs bornées ; `description` peut avoir des retours à la ligne |
| `.points_forts` | liste de 12 textes au plus |
| `.version`, `.core_min` | numéros de version (`1.2.0`) |
| `.prix` | `montant` (0 = gratuit), `devise` (EUR), `periodicite` (`unique`, `annuel`, `mensuel`), `libelle` (remplace l'affichage : « Sur devis ») |
| `.url_achat`, `.image` | https ; une image locale (`images/couverture.webp`, dans `boutique/images/`) n'est permise qu'à la boutique embarquée |
| `.sha256` | empreinte du zip (facultatif), 64 caractères hexadécimaux minuscules |

Les champs inconnus sont ignorés : le format peut évoluer. Le contenu commercial de `boutique/` est sous
licence « tous droits réservés » (voir `boutique/LICENSE`), à la différence du cœur.

### Fabriquer les paquets

```
php bin/construire-paquets.php --sortie=/chemin [--url-images=https://site/boutique/]
```

Produit un zip par extension et le zip du cœur sans extensions. Les zips sont **reproductibles** (fichiers triés,
dates et droits fixés) : reconstruire sans rien changer donne la même empreinte, qui est inscrite dans
`boutique/kidlogis.json` avant la fabrication du cœur. Le script vérifie que chaque extension passe l'analyse de
l'application, et échoue sinon. Avec `--url-images`, il écrit aussi `boutique-publique/` : le catalogue de
Kidlogis avec des images en https, que n'importe quelle installation peut importer.

## Licences

| Partie | Licence |
|---|---|
| **Cœur** (tout ce qui n'est pas sous `plugins/`) | GNU Affero General Public License v3 (`AGPL-3.0-only`). Texte intégral dans `LICENSE`. |
| **Extensions** (`plugins/*`) | **Tous droits réservés.** Chaque extension porte son propre `LICENSE` : l'acquisition d'une licence donne le droit de l'utiliser, pas de la redistribuer. |
| **Extensions de tiers** | La licence de leur auteur, libre ou propriétaire : l'AGPL ne s'impose pas à elles (voir `LICENSE-EXCEPTION`). |

**Cœur (AGPL v3).** Vous pouvez l'utiliser, l'étudier, le modifier et le redistribuer. La
licence AGPL ajoute une règle pour les services en réseau : si vous faites fonctionner une
version **modifiée** d'AltoCompta et que des utilisateurs s'y connectent à distance, vous
devez leur offrir le code source de votre version modifiée (art. 13). Une version non
modifiée n'y est pas soumise.

**Extensions.** Elles ne sont pas couvertes par l'AGPL. Leur code est livré avec le cœur pour
qu'elles fonctionnent, mais le fait de pouvoir le lire ne donne aucun droit de le copier, de
le revendre ou de le publier.

**Exception pour les extensions.** Chacun est libre d'écrire des extensions pour AltoCompta et
de les distribuer sous la licence de son choix — libre ou propriétaire, avec ou sans leur code
source. C'est une permission supplémentaire à l'AGPL (art. 7), énoncée dans
`LICENSE-EXCEPTION`. Elle couvre les modules chargés par le mécanisme d'extensions
(`src/Plugin.php`) qui ne modifient aucun fichier du cœur. Elle ne dispense pas de respecter
l'AGPL pour le cœur lui-même : modifier le cœur, ou le redistribuer, reste soumis à la licence,
et l'article 13 s'applique à une version modifiée du cœur proposée à distance.

**Pictogrammes.** Les icônes viennent d'Iconoir (licence MIT), intégrées dans `src/icones.php`.

## Conformité : AltoCompta n'est pas un système de caisse

Le droit français (CGI, art. 286, I-3° bis ; BOFiP BOI-TVA-DECLA-30-10-30) oblige certains
assujettis à la TVA à utiliser un logiciel ou système de caisse sécurisé, justifié par un
certificat d'un organisme accrédité ou, depuis le 21 février 2026, par une attestation
individuelle de l'éditeur.

**AltoCompta n'est pas un système de caisse.** Une fonction de caisse enregistre, hors
comptabilité, les paiements reçus en contrepartie de ventes ou de prestations. AltoCompta tient
un registre BNC de trésorerie : il ne saisit ni vente, ni ticket, ni clôture journalière. Il
n'est ni certifié, ni accompagné d'une attestation, et ne prétend pas l'être.

**Qui est concerné, d'après le BOFiP.** L'obligation vise les assujettis qui vendent à des
particuliers et enregistrent leurs règlements avec une fonction de caisse. Sont exclus :
- les assujettis en franchise en base de TVA ;
- ceux qui ne réalisent que des opérations exonérées de TVA ;
- ceux dont tout le chiffre d'affaires se fait avec des professionnels (B2B).

Aucune disposition n'oblige à s'équiper d'un logiciel ou d'un système de caisse.

**Logiciel libre.** Pour un logiciel libre, la doctrine considère l'entreprise utilisatrice
comme « l'éditeur » : si elle entre dans le champ, c'est à elle de faire certifier la version
qu'elle utilise. Aucune attestation n'est délivrée avec le logiciel libre, ni par son auteur ni
par la communauté.

Ce texte ne traite pas de l'obligation de remettre les écritures à l'administration en cas de
contrôle (fichier des écritures comptables), qui relève d'autres dispositions. **Il ne
constitue pas un avis fiscal** : en cas de doute sur votre situation, consultez votre
expert-comptable.

## Inaltérabilité des écritures

Inaltérabilité, sécurisation, conservation et archivage sont les quatre propriétés que le
droit français exige des logiciels de caisse (CGI, art. 286, I-3° bis). AltoCompta n'est pas
un système de caisse et n'est pas certifié (voir « Conformité » plus haut) ; il s'en
inspire parce que l'intégrité d'un registre comptable le justifie de toute façon. Le
dispositif mis en place ici :

**Aucune suppression, aucune renumérotation.** Une écriture n'est jamais
effacée : elle est *annulée*. Elle garde son numéro, reste au livre marquée
comme telle, et sort des totaux et de la déclaration. Un trou dans la suite
des numéros serait précisément le signe d'une suppression — l'ancienne
version du code effaçait la ligne puis resserrait la numérotation des
suivantes, ce qui faisait disparaître toute trace.

**Aucune modification en place : écriture d'annulation, puis nouvelle écriture.**
Corriger une écriture — la reclasser, changer un montant — n'écrase rien. Le
registre inscrit, dans l'ordre :

1. l'écriture d'origine, désormais marquée *annulée* ;
2. une **écriture d'annulation**, au montant exactement opposé, à la date de
   l'originale pour la compenser dans la même période ;
3. la **nouvelle écriture**, qui porte les valeurs corrigées.

Les trois lignes restent reliées en base (`contrepasse_id` : l'annulation vers
l'originale ; `rectifie_id` : la nouvelle vers l'originale). La somme des
montants imprimés égale donc le total déclaré, ce qu'un contrôleur peut
vérifier en additionnant les lignes. Sur un livre des recettes, l'annulation
reçoit son propre numéro : la suite reste continue. À l'écran, chaque
annulation est rangée à côté de son original ; un lien « masquer les lignes de
correction » épure la vue sans rien retirer du registre, du PDF ni du CSV.

Les justificatifs suivent la correction, parce qu'une pièce justifie
l'opération et non telle ligne. Annuler une écriture sans la remplacer rend
l'opération bancaire à la file d'affectation, au lieu de la laisser pointer
vers une écriture annulée — donc perdue de vue.

Les immobilisations gardent une annulation par statut, sans ligne d'annulation :
ce ne sont pas des lignes de montant dans un journal chronologique.

**Reprise des corrections antérieures.** Avant ce dispositif, une correction
marquait seulement l'original « annulé » : la liste montrait l'ancienne et la
nouvelle ligne comme deux écritures ordinaires (trois reclassements d'une
dépense de 48 € affichaient 144 €), alors que le total restait juste. La
migration inscrit après coup la ligne d'annulation manquante de chacune. Elle
ne fait qu'ajouter, consigne chaque ajout au journal, porte un marqueur et ne
se rejoue jamais.

**Journal chaîné.** Chaque acte (création, rectification, annulation,
clôture, archivage) dépose une entrée dans `journal_evenements`, en ajout
seul — la classe `Journal` n'expose volontairement aucune méthode de
modification ni de suppression. L'empreinte SHA-256 de chaque entrée couvre
son contenu *et* l'empreinte de la précédente. `Journal::verifier()` rejoue
la chaîne et signale la première rupture : contenu altéré, chaînage rompu,
ou trou dans la numérotation.

Ce que le journal prouve, et ce qu'il ne prouve pas : il rend visible toute
retouche *partielle* de la base, ce qu'exige la réglementation d'un logiciel.
Il ne prétend pas empêcher une réécriture complète et cohérente par qui a la
main sur le serveur — aucun dispositif logiciel seul ne le peut.

**Totaux et déclaration.** Les écritures annulées sont exclues partout :
`Recette::total()`, `Depense::total()`, `Depense::parPoste()`, les
agrégations de `Declaration::calculer()`, le contrôle du seuil
d'immobilisation du plugin Pro et le tableau de bord. Sans ce filtre, une
écriture annulée serait restée dans la 2035 — faute invisible et
directement fautive sur la déclaration.

## Inaltérabilité du registre des immobilisations

Le même dispositif s'applique au registre des immobilisations, porté par
l'extension Pro : leur dotation alimente la case CH de la 2035, donc une
immobilisation effaçable ou modifiable en place laisserait exactement le
même trou de traçabilité que celui corrigé sur les recettes et dépenses.

Une immobilisation n'est plus supprimée mais annulée : elle reste au
registre, son plan cesse d'alimenter la dotation. Une correction crée une
écriture rectificative reliée à l'originale (`rectifie_id`). Création,
rectification, annulation, cession et annulation de cession sont toutes
consignées au journal chaîné — la cession en particulier, parce qu'elle
arrête le plan d'amortissement et détermine la plus ou moins-value portée
en 2035-B.

Les événements d'immobilisation ne portent pas d'exercice — un bien
s'amortit sur plusieurs — et sont donc rattachés à toute vue filtrée du
journal, pour ne pas disparaître de l'affichage par défaut.

## Archives d'exercice

Volet « conservation et archivage ». Clore un exercice fige désormais un
instantané complet — écritures actives **et** annulées, totaux, déclaration
calculée, identité du déclarant — sérialisé en JSON puis empreint en
SHA-256. L'archive est créée avant le changement de statut, pour refléter
l'exercice tel qu'il était au moment de la décision de clore.

Les écritures annulées, et leurs lignes d'annulation, sont incluses à dessein : une archive qui ne
garderait que les écritures actives perdrait la trace de ce qui a été
corrigé, ce que l'inaltérabilité cherche justement à préserver.

Le fichier téléchargeable est servi exactement tel qu'enregistré, octet pour
octet — l'empreinte se recalcule donc hors de l'application, avec n'importe
quel outil produisant un SHA-256. C'est ce qui permet, des années plus tard,
de rapporter la preuve que ce qui a été déclaré n'a pas bougé, sans avoir à
faire confiance au logiciel lui-même.

Rouvrir puis reclore produit une archive supplémentaire horodatée sans
écraser la précédente : la suite des états successifs est elle-même une
information. Clôture et réouverture sont consignées au journal — une
réouverture après déclaration est précisément le genre d'événement qu'un
contrôle veut pouvoir retracer.

## Local à domicile (extension)

Pour qui exerce chez soi : calcule la quote-part professionnelle du logement et l'applique aux dépenses du
logement. Tout est une option, car les situations diffèrent :

| Option | Choix |
|---|---|
| **Statut** | locataire · propriétaire, logement resté dans le patrimoine privé · propriétaire, logement inscrit à l'actif |
| **Local professionnel** | une pièce réservée à l'activité · une pièce aussi utilisée à titre privé (avec un coefficient d'usage) · un local entièrement affecté (100 %) |
| **Méthode** | prorata de la surface · prorata du nombre de pièces · pourcentage fixé |
| **Arrondi** | à l'entier inférieur (défaut, prudent) · au plus proche |
| **Catégories** | celles proportionnelles à la surface (loyer, énergie, assurance, taxe foncière…) et celles d'usage (internet, téléphone), avec leur propre quote-part |

Le statut ne change aucun chiffre : il change ce qui se déduit, donc les repères affichés. Le « loyer à soi-même »
d'un propriétaire n'est pas proposé : les sources divergent et le risque de redressement est réel. La quote-part du
cœur est un **entier** (0 à 100) : une quote-part de 21,28 % est donc arrondie, et l'écart est montré.

**Les dépenses ne sont jamais réécrites en silence.** Le bouton « Aligner » passe par la rectification du cœur :
chaque dépense corrigée laisse une écriture d'annulation et une nouvelle écriture, journalisées ; les justificatifs
et l'opération bancaire suivent. Il applique les réglages **enregistrés**, recalculés côté serveur, sans effet sur
un exercice clos, 200 corrections au plus par clic, et il est sans effet une fois les dépenses alignées. La
déclaration signale (avertissement) toute dépense du logement qui ne porte pas la quote-part configurée.

La **fiche de calcul** (imprimable, PDF par l'impression du navigateur) détaille la situation, la formule, les
dépenses telles qu'elles sont aux livres, et un texte prêt à coller dans le commentaire libre de la 2035. Les repères
viennent de la pratique des cabinets et de la presse professionnelle, pas d'un texte officiel cité : à valider avec
un expert-comptable. Les réglages sont propres à chaque exercice, et le suivant reprend ceux du précédent à confirmer.

## Provisions : cotisations et impôt

La page **Provisions** (menu Déclaration) estime les cotisations sociales et l'impôt sur le revenu que le
résultat entraîne, donc ce qu'il faut mettre de côté. Elle fait partie du cœur : tout BNC au réel s'en
préoccupe, quel que soit son service bancaire.

**D'où viennent les chiffres.** Pas d'une table de taux tenue ici : de l'API publique de l'Urssaf
(`POST https://mon-entreprise.urssaf.fr/api/v1/evaluate`), qui exécute les règles que l'Urssaf publie en
open source et met à jour chaque année. Rien ne s'envoie sans un clic sur « Calculer l'estimation ».
Partent : recettes, charges, date de début d'activité et profil du foyer fiscal (situation familiale,
enfants, autres revenus). Ni nom, ni SIRET, ni adresse.

**La base de calcul.**
- Les charges envoyées excluent le poste BW (cotisations personnelles déjà versées) : le modèle recalcule
  lui-même les cotisations et leur déduction, les lui donner déduites reviendrait à les déduire deux fois.
- *À ce jour* : les écritures telles qu'elles sont. *Projeté* : au prorata des jours écoulés, sans projection
  avant 30 jours. *Mes propres montants* : pour simuler un autre niveau d'activité.
- Un déficit n'est pas un zéro : le modèle applique des cotisations minimales, et la page le dit.
- L'impôt est celui du **foyer**, autres revenus compris, selon le barème standard (quotient familial et
  décote). La méthode « taux neutre » de la retenue à la source donnerait zéro.

**Ce qui peut casser, et comment ça se voit.** L'Urssaf prévient que ses règles « expérimentales » peuvent
être renommées sans changement de version majeure ; l'exemple de sa propre documentation cite d'ailleurs une
règle qui n'existe plus. Une règle disparue ou une situation refusée affiche donc un message précis, jamais un
chiffre inventé, et la dernière estimation réussie reste affichée. Les expressions sont dans `Urssaf::SORTIES`.
Un détail qui ne totalise pas le total est signalé par une ligne « Autres ».

**Réglages** (`config.php`, tous facultatifs) : `urssaf.base_url` (défaut `https://mon-entreprise.urssaf.fr`),
`urssaf.timeout` (20 s), `urssaf.actif` (`false` désactive la page). Un délai de 15 secondes sépare deux appels.

**Limites.** Indépendant en BNC, EI à l'IR, déclaration contrôlée, profession libérale **non réglementée**.
Une profession réglementée (Cipav…) relève d'une autre caisse. Hors champ : exonérations, cotisations
facultatives, activité à l'étranger, outre-mer, conjoint collaborateur. C'est une estimation : les cotisations
réellement appelées procèdent d'acomptes puis d'une régularisation.

**Tester sans le service distant.** `tests/outils/urssaf/serveur.mjs` exécute les règles publiées
(`modele-social`, versions figées) derrière la même interface, avec des pannes simulables (`/__regle`,
`/__indisponible`, `/__lent`…). Voir son `LISEZMOI.md`. Les tests d'intégration des provisions s'appuient dessus :
le calcul éprouvé est le vrai, pas une réponse inventée. L'API en production elle-même n'a pas été appelée.

**Fuseaux horaires.** Les dates sont écrites par PHP dans le fuseau de l'application ; les comparer à l'heure
de la base (`NOW()`) mélange deux fuseaux. Les cadences de synchronisation des connecteurs et l'anti-rafale du
webhook faisaient cette erreur : avec Paris côté PHP et UTC côté base, une synchronisation « horaire » ne
partait qu'après trois heures. Les seuils sont désormais calculés en PHP. La fréquence « manuelle uniquement »,
qui était à tort toujours échue, ne l'est plus jamais.

## Fiche entreprise

La page « Mon compte » rassemble l'identité du déclarant : adresse, SIRET, code APE, TVA, NDA, rattachement
fiscal, assurance. La **forme juridique est un texte libre** (100 caractères) : la liste fermée qui la
précédait ne couvrait pas toutes les situations, et l'une de ses options, « micro-entrepreneur ayant opté
pour le réel », n'existe pas — opter pour le réel fait sortir du régime micro. La migration 007 convertit
les anciens codes en libellés (`ei` devient « Entreprise individuelle (EI) ») et laisse intact tout texte déjà libre.

## Exercices

Le menu « Exercice » en haut à droite change l'exercice affiché ; la clôture se fait depuis la page
Formulaire 2035 (voir Archives). Le bouton **+ Nouvel exercice**, à côté du menu, crée l'exercice d'une
année donnée, du 1er janvier au 31 décembre, et l'affiche. L'année proposée est la suivante ; si elle
n'est plus acceptable, celle qui précède le plus ancien, pour saisir un exercice passé. Années
acceptées : de 2000 à l'année en cours plus une, car une faute de frappe (2072) créerait un exercice
inutilisable. Le doublon et l'année invalide sont refusés avec un message. Créer un exercice ne clôt pas
le précédent. Le chemin de retour de ces formulaires est limité aux adresses internes.

## Opérations exclues

Une opération ignorée (motif privé, virement interne, TVA, apport/retrait,
autre) n'entre pas dans le calcul de la 2035, mais reste consultable sur
`/affectation/exclues` avec son motif — utile en cas de contrôle, ou pour
corriger un « Ignorer » fait par erreur via le bouton « Rouvrir », qui
remet l'opération dans la file d'attente. Un lien depuis l'écran de triage
affiche le nombre d'opérations exclues sur l'exercice.

## Mise à jour depuis l'interface

Plus besoin de FTP, de SSH ni de tâche CRON ponctuelle pour mettre à jour :
`/mise-a-jour` (réservé aux administrateurs) accepte directement l'archive
livrée. Le parcours en deux temps — analyse puis confirmation — affiche un
aperçu (nombre de fichiers, version annoncée) avant toute écriture ; rien ne
s'applique en un seul clic silencieux.

Le zip du cœur (`altocompta-core-<version>.zip`) ne contient **aucune extension** : la mise à
jour ne touche jamais à `plugins/`, donc aux extensions installées.

**Ce que la mise à jour ne touche jamais**, même si l'archive en
contenait : `config/config.php` et tout le contenu de `storage/` — les
justificatifs déposés, les sauvegardes elles-mêmes. Une sauvegarde complète
de l'installation (code et données, cette fois sans exception) est créée
avant toute écriture ; les cinq plus récentes sont conservées, téléchargeables
depuis la même page.

**Dossiers publics lisibles.** Les dossiers créés sous la racine publique le sont en `755`, et ceux qu'une version
antérieure avait créés en `750` sont rendus lisibles : sur un hébergement mutualisé, le serveur web qui sert les fichiers
statiques n'est pas toujours le propriétaire ni dans le groupe, et renverrait un 403 sur tout ce qu'un dossier `750` contient.

**Protection contre le zip slip.** Accorder à un compte le pouvoir d'écraser
le code qui s'exécute revient, en substance, à une exécution de code à
distance : c'est une fonctionnalité à haut risque par construction, pas
seulement une commodité. Chaque chemin contenu dans l'archive est validé
avant toute écriture — rejeté s'il contient `..`, s'il est absolu, ou si sa
résolution finale sortirait du dossier visé — puis revalidé une seconde fois
au moment d'appliquer, sans faire confiance à l'aperçu précédent. Une
batterie de tentatives classiques (traversée de répertoire, chemins absolus,
octets nuls, segments vides) est vérifiée par test automatisé.

**Partagé avec la CLI.** `php db/migrate.php` et le module web appellent
désormais la même classe (`Bnc\Migration`) : les deux chemins ne peuvent
plus diverger, contrairement à l'écart trouvé entre `schema.sql` et une
migration au tour précédent — c'est précisément pour éviter de reproduire ce
genre de bug que la logique n'existe plus qu'à un seul endroit.

## Import, identité des opérations et doublons

Deux défauts opposés ont été corrigés. L'empreinte d'une opération reposait
sur sa date, son montant, son libellé et son compte, et l'identifiant natif
de la source, stocké, ne servait à rien :

- **Trop agressive.** Deux virements réellement distincts de même date, même
  montant et même libellé (deux versements CPF de 412,50 € le même jour) avaient
  la même empreinte : la seconde était écartée comme « déjà connue », et du
  chiffre d'affaires manquait sans qu'aucun message ne le dise.
- **Trop faible.** La même opération arrivant avec un autre libellé ou une autre
  date — une opération « en attente », puis sa version définitive — produisait
  deux lignes.

L'identité d'une opération se détermine désormais ainsi :

- **identifiant natif** pour Altoviz et pour OFX (FITID, par compte) : il fait foi
  quel que soit le libellé ou la date. Une colonne `id` de CSV n'est pas retenue,
  beaucoup de banques n'y mettent qu'un numéro de ligne ;
- sinon, **contenu et rang d'occurrence** : la deuxième opération identique du
  même jour est une deuxième opération. Le rang 1 donne l'ancienne empreinte, de
  sorte que les données déjà importées restent reconnues.

Les lignes importées avant ce changement n'ont pas toujours d'identifiant : elles
sont reconnues à leur contenu et reçoivent l'identifiant au passage, pour qu'une
première synchronisation ne réimporte pas la semaine de recouvrement en double.
Réimporter un relevé déjà passé récupère les jumelles autrefois écartées.

**Contrôle des doublons** (`/doublons`). Il propose des paires à examiner, sans
rien supprimer ni décider : même montant, dates à moins de quatre jours, libellés
qui désignent le même tiers (mots techniques, dates, références et accents
écartés). Deux opérations identiques d'un même compte le même jour ne sont jamais
proposées. « Ce ne sont pas des doublons » mémorise le choix. Écarter une
opération déjà passée en écriture annule celle-ci par une ligne d'annulation.

## Altoviz : opérations en attente, comptes synchronisés

Une opération « en attente » (autorisation de carte, virement non comptabilisé)
change de libellé et de date, ou disparaît, quand la banque envoie l'écriture
définitive : l'importer crée un doublon ou une ligne qui s'évanouit. Elles sont
donc ignorées jusqu'à leur écriture définitive. Le vocabulaire exact de l'API
n'étant pas publié, on reconnaît les formulations usuelles (`Pending`,
`En attente`, `Authorized`, `isPending`, `booked = false`…) ; « À rapprocher »
n'en fait pas partie, c'est l'état ordinaire d'une opération définitive.
Chaque synchronisation consigne les statuts rencontrés et le nombre d'opérations
ignorées (survol de la colonne « En attente » du journal), de sorte qu'une
formulation inconnue se voit. Des valeurs supplémentaires se déclarent dans
`config.php`, clé `altoviz.statuts_en_attente`.

On ne conclut que sur un signal positif : mieux vaut importer une opération douteuse,
que le contrôle des doublons rattrapera, que d'en perdre une réelle.

La page Altoviz permet de **choisir les comptes synchronisés** : un ancien compte
« importé » qui recoupe le compte réellement connecté donne chaque opération deux
fois. On mémorise les comptes exclus plutôt que les retenus, de sorte qu'un
compte apparu depuis est synchronisé par défaut. « Recharger tout l'exercice »
reprend l'historique sans rien réimporter en double.

## Connecteurs bancaires, tâche planifiée et webhook

**Tâche planifiée.** `php bin/synchroniser.php --silencieux`, toutes les 30 minutes : chaque
connexion applique ensuite sa propre cadence. Le script ne connaît aucun service par son nom, il
appelle le point d'entrée « synchroniser » que chaque extension déclare dans son manifeste.
Options : `--force` (ignore la cadence), `--source=altoviz|evoliz`, `--connexion=N` (exige
`--source`), `--silencieux`. `bin/sync-altoviz.php` subsiste comme alias limité à Altoviz. Un
verrou évite deux passages simultanés ; le code de sortie est non nul si toutes les connexions
ont échoué.

Ce script et `public/webhook.php` n'existaient plus depuis que le connecteur était devenu une
extension : seul le bouton manuel fonctionnait. Rétabli et vérifié.

**Webhook Altoviz.** `https://VOTRE-DOMAINE/webhook.php?c=<connexion>&s=<secret>` : un secret
faux et une connexion inconnue reçoivent la même réponse, une rafale dans la minute est
absorbée. Evoliz n'offre pas de webhook.

**Evoliz.** Clés publique et secrète générées dans Evoliz (Compte › Applications › Connecteurs
disponibles › Evoliz API › Créer une clé), échangées contre un jeton réutilisé d'un passage à l'autre (la documentation
donne 15 et 20 minutes selon les pages : on retient la plus courte) ; limite de 100 appels par minute. L'utilisateur Evoliz lié aux clés doit pouvoir
consulter les banques. Le numéro client (point d'interrogation en haut à droite d'Evoliz, première partie du
numéro affiché) est facultatif pour un utilisateur rattaché à une seule société. Adresse de l'API : `evoliz.base_url` dans `config.php` (par défaut
`https://www.evoliz.io`). L'identifiant `bank_itemid` de chaque ligne fait foi pour reconnaître
une opération déjà importée. Le sens de chaque mouvement vient du filtre `movement`, non du
signe de `amount` que la documentation ne précise pas ; si le filtre était ignoré et les
montants sans signe, l'import est refusé plutôt que de risquer d'inverser des opérations.
Le chemin de connexion (`/api/v1/login` ou `/api/login`) est essayé dans cet ordre. Le journal
des appels de la page montre les réponses exactes, sans clé secrète ni jeton.

## Erreurs et base de données en retard

Une exception non rattrapée donnait la page blanche d'un 500. Elle est désormais
consignée au journal du serveur avec une référence et remplacée par une page qui
dit quoi faire. Le cas le plus fréquent après une mise à jour — une base restée
en retard sur le code, donc une colonne ou une table absente — reçoit un message
à part, sans détail SQL (qui reste au journal ; le mode débogage l'affiche).

Les extensions actives se mettent à niveau d'elles-mêmes quand leur version
change (`Plugin::mettreANiveau`), sans dépendre du module de mise à jour : c'est
la *version précédente* du module qui applique la livraison suivante, et elle ne
connaît pas encore ce que la nouvelle version lui demanderait. La page Mise à
jour liste les migrations en attente et offre « Appliquer les migrations » —
le seul recours sur un mutualisé sans SSH.

Un défaut de schéma d'une extension fait tomber les pages qui en dépendent
(immobilisations, déclaration 2035, annexe 2035-E, véhicules) plutôt que de les
afficher sans sa contribution : une 2035 amputée de la dotation aux
amortissements serait fausse sans que rien ne le dise.

## Écran de triage

La ligne d'une opération à affecter est structurée en deux niveaux : les
informations (date, libellé, montant) sur une rangée, les actions
(Recette, catégorie, Dépense, Immo, virement interne, Ignorer, justificatif)
sur une rangée pleine largeur juste en dessous, qui s'enroule proprement
plutôt que de chevaucher le texte voisin. Une régression s'était glissée au
fil des tours : la colonne d'actions était restée figée à sa largeur
d'origine pendant que de nouveaux contrôles (sélecteur de catégorie, motif
d'exclusion) s'y ajoutaient, provoquant un chevauchement visuel. Corrigé et
vérifié par capture d'écran réelle (Playwright/Chromium), à trois largeurs :
bureau large, bureau standard, mobile.

## Mise à jour vers l'inaltérabilité

Les tables et colonnes du chantier d'inaltérabilité sont portées par
`db/migrations/002_inalterabilite.sql` — la première migration réelle depuis
que `db/schema.sql` est devenu la référence unique (voir plus bas). Sur une
installation déjà en service :

```bash
php db/migrate.php --etat    # doit signaler 002_inalterabilite en attente
php db/migrate.php           # l'applique
```

Une installation neuve reçoit directement le schéma à jour via
`db/schema.sql` ; rien à faire dans ce cas. Vérifié que les deux chemins
convergent vers un schéma strictement identique (201 colonnes comparées).

Un oubli a été trouvé et corrigé à cette occasion : lors de la rédaction de
`db/schema.sql`, deux index secondaires (`ix_recettes_statut`,
`ix_recettes_rectifie` et leurs équivalents sur `depenses`) avaient été
ajoutés dans la migration mais pas reportés dans le schéma de référence —
une installation neuve s'en serait trouvée durablement privée, sans qu'aucun
message d'erreur ne le signale.

## Contrôles de cohérence

Un audit mécanique du dépôt vérifie, plutôt qu'une relecture :

- toute table et toute colonne écrites par le code existent au schéma ;
- tout lien d'un gabarit correspond à une route déclarée, et tout gabarit
  appelé existe ;
- toute route POST exige une session et un jeton anti-CSRF ;
- aucune requête ne réutilise un paramètre nommé, refusé par PDO en
  préparation native ;
- les tables cloisonnées sont filtrées par `user_id` ;
- aucune classe n'est référencée sans être résolvable ;
- le texte libre est échappé à l'affichage ;
- les écritures qui alimentent la déclaration refusent un exercice clos ;
- le cœur ne référence aucune table d'extension, pas même par jointure ;
- une installation neuve et une mise à jour produisent le même schéma ;
- un PDF généré s'ouvre, se rend et se relit correctement (pypdf, poppler).

## Tests

```bash
sh tests/lancer.sh              # cœur
sh plugins/immobilisations/tests/lancer.sh
sh plugins/altoviz/tests/lancer.sh
sh plugins/evoliz/tests/lancer.sh
sh plugins/domicile/tests/lancer.sh
```

Plus de 350 assertions pour le cœur et 67 pour l'extension, sans base de données : parseurs de montants et de dates,
lecture CSV et OFX, règles d'affectation, routeur, plans d'amortissement et
plus-values de cession, validation de la fiche entreprise, métriques PDF, rapprochement de libellés,
sécurité des chemins de mise à jour, détection des opérations en attente. Les vérifications d'intégration (schéma, parcours HTTP,
migrations) demandent une base et sont décrites ci-dessous.

## Vérifications passées

Mise à jour par l'interface vérifiée sur le chemin réel : la version précédente
installée telle quelle (zip livré, ancien module de mise à jour), mise dans l'état
d'une installation en retard (Pro non migré, corrections sans ligne d'annulation),
puis mise à jour par le nouveau zip, serveur resté en vie, pages interrogées sans
pause. Avant : erreur `Unknown column 'statut'` sur les immobilisations, la
déclaration 2035, l'annexe 2035-E, le PDF et les véhicules ; 144 € de lignes
affichées pour un total de 48 €. Après : 154 fichiers remplacés, 5 migrations
(dont celles de Pro et la reprise des corrections), sauvegarde créée, `config.php`
intact, toutes les pages en 200, sommes revenues à 48 € et 3 500 €, journal intact,
aucune migration en attente. Deux défauts n'apparaissent que dans ces conditions :
le cache d'opcodes servait un mélange d'anciens et de nouveaux fichiers juste après
le remplacement, et l'ancien module exécutait la reprise avec des classes déjà
chargées dans leur version précédente. D'où `Opcache::vider()`, la classe
`Corrections` (jamais chargée avant la reprise) et la finalisation des migrations
dans une requête distincte pour les mises à jour suivantes.

Corrections vérifiées sur base réelle (36 assertions) : trois reclassements d'une
dépense (BA, BN, BY) donnent cinq lignes dont la somme est le total déclaré ; le
justificatif suit ; l'opération bancaire pointe vers la nouvelle écriture ou, si
l'écriture est annulée sans remplaçante, retourne à la file ; numérotation continue
des recettes ; reprise idempotente. Import, synchronisation Altoviz sur faux serveur
et doublons (38 assertions) : jumelles conservées, rang d'occurrence, FITID par
compte, opération en attente ignorée puis version définitive importée une fois,
libellé enrichi sans doublon, ligne ancienne sans identifiant rattachée, exclusion
d'un compte, résolution d'un doublon passé en écriture (chiffre d'affaires ramené
de 825 € à 412,50 €). Schéma d'une installation neuve et d'une base mise à jour :
228 colonnes, 75 index et 31 clés étrangères identiques.

Limites : le vocabulaire des statuts d'Altoviz n'est pas publié et n'a été éprouvé
que sur des valeurs usuelles ; le comportement du cache d'opcodes d'un hébergeur
donné (revalidation, `restrict_api`) n'a pu être vérifié que sur le serveur intégré
de PHP.


Schéma appliqué sur MariaDB 10.11 : 11 tables, 33 postes 2035. Les
contraintes rejettent numéro chronologique dupliqué, poste 2035 inexistant,
exercice inexistant, e-mail dupliqué et empreinte dupliquée.

Installateur parcouru de bout en bout, y compris les échecs : mot de passe
MySQL faux, base sans droits, hôte injoignable, mots de passe divergents,
mot de passe trop court, SIRET incomplet, e-mail invalide. Verrouillage
après installation vérifié (HTTP 403).

Import d'un relevé de sept opérations, puis réimport du même fichier :
zéro doublon. Les quatre règles pré-remplies ont pré-catégorisé six
opérations sur sept. Validation en bloc : deux recettes et quatre dépenses
créées, le virement personnel restant à trancher à la main. Réaffecter une
opération déjà traitée est refusé sans double écriture.

Totaux : recettes 2 310,00 − dépenses 1 185,20 = 1 124,80, cohérents entre
la base et la page 2035.

Cloisonnement testé entre deux comptes : lecture, téléchargement de
justificatif, ouverture, suppression et affectation croisées sont toutes
refusées.

Import OFX 1.x et 2.x vérifié : trois transactions lues, identifiant de
compte et FITID capturés, dates avec heure et fuseau, MEMO préféré à NAME
quand il est plus complet. Réimport du même fichier : zéro doublon. Un 30
février et un montant illisible sont écartés sans faire échouer l'import.

PDF relu par pypdf : une page, métadonnées correctes, accents intacts,
toutes les cases et les contrôles présents dans le texte extrait.

Tâche planifiée vérifiée : cadence respectée, verrou empêchant deux passages
simultanés, échec enregistré au journal, recul d'une heure sur une connexion
en erreur, code de sortie exploitable par une supervision.

Webhook vérifié : absence de paramètre, connexion inexistante et secret faux
renvoient tous 404 sans distinction ; un secret valide déclenche la
synchronisation ; une seconde notification dans la minute renvoie 202 sans
relancer d'appel.

Clôture d'exercice vérifiée : une écriture sur exercice clos est refusée
en 403 et les registres restent inchangés ; la réouverture fonctionne.

Amortissements couverts par tests unitaires sur des cas dont le résultat est
connu : linéaire sans et avec prorata, quote-part professionnelle, dégressif
avec bascule en linéaire, coefficients par durée, repli du dégressif, plan d'un
bien non amortissable. Dans chaque cas le total amorti égale la base et la VNC
finale est nulle.

Plus-values vérifiées sur six cas : plus-value inférieure aux amortissements,
plus-value sur bien non amortissable, détention de moins de deux ans,
moins-value, mise au rebut, bien non cédé.

Parcours vérifié de bout en bout : création refusée pour un dégressif sur
véhicule et pour une mise en service antérieure à l'acquisition ; affectation
d'une opération bancaire en immobilisation depuis le triage, qui renvoie au
formulaire pré-rempli et n'écrit aucune dépense ; cession enregistrée avec sa
moins-value ; dotation recalculée après cession ; case CH, tableau 2035-B et
plus-values présents sur la page et dans le PDF.

Valeur ajoutée vérifiée : 200 000 € de recettes moins 14 200 € de
consommations donnent 185 800 €, les 18 000 € d'URSSAF et 350 € de frais
bancaires restant dans la VA. Le seuil est respecté dans les deux sens.

Inaltérabilité des immobilisations vérifiée de bout en bout : un bien à
3 000 € sur 3 ans donne 1 000 € en case CH ; rectifié à 4 500 €, la case
passe à 1 500 € et l'originale subsiste au registre, annulée avec son motif ;
annulée, la case CH disparaît. Les quatre événements correspondants sont
chaînés au journal, qui s'affiche avec désignation, motif et montant.

Deux bugs trouvés à cette occasion. La colonne `version` de la table
`migrations` était limitée à 32 caractères, alors qu'une migration
d'extension est préfixée « plugin:<identifiant>: » : l'activation de Pro
échouait avec une erreur de troncature. Élargie à 128, avec une migration
pour les installations existantes. Et le filtre par exercice du journal
masquait les immobilisations, dont les événements n'en portent aucun.

Mise à jour vérifiée de bout en bout, en conditions réelles : une
installation « ancienne » complète (structure Hostinger, base propre,
compte créé) reçoit une archive construite séparément, avec un préfixe de
dossier volontairement inhabituel pour éprouver la détection. Après
application : le fichier `VERSION` a changé, un fichier modifié porte bien
le nouveau contenu, un fichier entièrement nouveau a été créé, un
justificatif existant n'a pas bougé, `config/config.php` pointe toujours
vers la bonne base, une sauvegarde complète a été produite automatiquement,
et l'application répond encore. Trente assertions couvrent par ailleurs la
résistance aux tentatives de zip slip (traversée de répertoire, chemins
absolus, octets nuls) sans faux positif sur un nom de fichier contenant
légitimement « .. ».

Archivage vérifié de bout en bout : la clôture d'un exercice portant une
écriture rectifiée produit une archive à 2 recettes actives sur 3 lignes,
3 500 € de recettes et 3 357,20 € de résultat, avec les événements du
journal dans le bon ordre (archivage avant clôture). Falsifier un montant
dans le contenu archivé, directement en SQL, rend l'archive « altérée » ;
restaurer la valeur d'origine la rend de nouveau conforme. Une réouverture
suivie d'une reclôture crée bien une seconde archive sans toucher à la
première, et consigne les deux actes. Le SHA-256 du fichier téléchargé,
recalculé hors de l'application avec Python, correspond à l'empreinte
enregistrée en base.

Inaltérabilité vérifiée en conditions réelles, base à l'appui : deux
recettes créées puis l'une rectifiée donnent trois lignes au livre (la n°1
annulée avec son motif, la n°3 la rectifiant via `rectifie_id`), une
numérotation qui ne recule jamais, un total déclaré de 3 500 € sur 4 500 €
de lignes brutes, et quatre événements chaînés au journal. La détection
d'altération a été éprouvée par falsification directe en SQL : modifier un
montant dans le journal est signalé « contenu altéré » à la bonne séquence,
supprimer une entrée est signalé « trou dans la numérotation », et restaurer
la valeur d'origine rend la chaîne de nouveau intacte.

Menu en tuiles vérifié par capture d'écran réelle (Playwright/Chromium) à
trois largeurs, extension active et inactive : desktop, et mobile après
défilement naturel de la barre — chaque tuile, y compris la dernière de la
liste, affiche correctement son icône et son texte. Une première série de
captures laissait croire à du texte manquant sur certaines tuiles ; un
sondage direct des styles calculés du DOM a montré que le HTML et le CSS
réels étaient corrects, et qu'il s'agissait d'un artefact de l'outil de
capture plein-page face à un conteneur à défilement interne, pas d'un bug
de l'application — confirmé en capturant la tuile seule, puis la barre
latérale après défilement naturel.

Registres PDF relus par pypdf : trois pages pour 92 recettes, en-tête « (suite) »
sur les pages suivantes, identité et SIRET présents, total en fin de registre,
accents intacts, et aucune date ni aucun montant tronqué sur 101 lignes.

Connecteur Altoviz vérifié contre un faux serveur reproduisant la pagination
documentée : une liste de comptes, puis des transactions sur deux pages
suivies via `X-PAGE-NEXT`, avec un montant positif et un négatif — les deux
sont importées avec la bonne date, le bon libellé et le bon compte.

Régularisations vérifiées sur deux exercices consécutifs : une charge à payer
de 2 400 € saisie en 2025 ramène le résultat de 40 000 à 37 600 ; en 2026, le
paiement réel de 2 400 € est exactement neutralisé par la contre-passation et
le résultat reste à 50 000. Sans ce mécanisme, 2026 serait tombé à 47 600 avec
la même cotisation déduite deux fois. La justification est obligatoire à la
saisie.

Nomenclature vérifiée sur un relevé de douze opérations, sans aucune règle
personnelle : les douze ont été catégorisées correctement — subvention OPCO,
URSSAF, CFP, CFE, RC pro, SNCF, restaurant, OVH, expert-comptable, frais
bancaires, formation continue, carburant. La catégorie choisie est conservée
sur l'écriture créée, et la saisie manuelle déduit le poste sans jamais
demander le code.

Comptes bancaires vérifiés de bout en bout : deux comptes professionnels,
import de deux relevés distincts, rattachement automatique par motif sans
aucune opération orpheline, appariement du virement de 2 000 € dans les deux
sens, exclusion des deux moitiés en une seule action. Résultat final 2 100,00
sur des recettes de 3 000,00 — là où un traitement naïf aurait déclaré
5 000,00 de recettes et 2 900,00 de dépenses, chacune surévaluée de 2 000 €.
La validation en bloc des suggestions laisse volontairement les virements
internes à confirmer à la main.

Barème vérifié sur les trois tranches, leurs bornes exactes (5 000 / 5 001,
20 000 / 20 001), les paliers de puissance, la majoration électrique et le
report d'une année sur l'autre. Articulation avec la 2035 vérifiée dans les
deux régimes : sans option, BR 1 450,00 et CH 3 000,00 pour un résultat de
25 550,00 ; sous option, BR 954,00 au barème, case CH absente et résultat de
29 046,00, avec les 1 450,00 de frais réels signalés comme écartés.

Montants signés vérifiés sur les quatre combinaisons : crédit en recette,
débit en recette (avoir), débit en dépense, crédit en dépense (remboursement).
Résultat 550,00 là où l'ancien code aurait donné 850,00.

Justificatif déposé au triage puis suivi jusqu'à la dépense créée par
l'affectation. Pagination vérifiée sur 123 lignes, filtres conservés dans les
liens, totaux de pied de tableau calculés hors pagination.

Comptes : création refusée sur mots de passe divergents, trop courts ou
adresse déjà prise ; compte désactivé ne peut plus se connecter ; un
non-administrateur reçoit 403 et ne voit pas l'entrée de menu ; sa tentative
de création d'un administrateur n'écrit rien. Changement de mot de passe
refusé si l'actuel est faux ou si le nouveau est identique.

Migration d'une base à l'ancien schéma vérifiée : quatre colonnes ajoutées,
données préservées, relance sans effet.

Parseurs couverts par tests unitaires : formats de montants français,
anglo-saxons, parenthèses comptables et symboles ; six formats de date ;
BOM, tabulations, Windows-1252, lignes invalides.
