-- =====================================================================
--  Compta BNC — schéma de référence
--  MySQL 8 / MariaDB 10.6+   ·   InnoDB   ·   utf8mb4
--
--  Principes :
--   - Tout est cloisonné par user_id (multi-comptes) et exercice_id.
--   - Les montants sont en DECIMAL(12,2), jamais en float.
--   - Comptabilité de trésorerie : la date qui fait foi est celle de
--     l'encaissement ou du décaissement, pas celle de la facture.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
--  Suivi des migrations
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
  -- Les migrations d'extension sont préfixées « plugin:<identifiant>: »,
  -- ce qui dépasse vite une colonne courte : 128 laisse de la marge.
  version     VARCHAR(128) NOT NULL,
  applique_le DATETIME     NOT NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Utilisateurs
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190) NOT NULL,
  mot_de_passe    VARCHAR(255) NOT NULL,      -- password_hash()
  nom             VARCHAR(120) NOT NULL,
  denomination    VARCHAR(190) NULL,          -- raison sociale portée sur la 2035
  siret           CHAR(14)     NULL,
  forme_juridique VARCHAR(100) NULL,
  profession      VARCHAR(190) NULL,
  date_debut_activite DATE     NULL,
  code_ape        CHAR(5)      NULL,
  tva_regime      VARCHAR(20)  NULL,
  tva_numero      VARCHAR(20)  NULL,
  tva_periodicite ENUM('mensuelle','trimestrielle','annuelle') NULL,   -- page TVA : choisie par l'assujetti
  nda             CHAR(11)     NULL,          -- numéro de déclaration d'activité (formation)
  certifications  VARCHAR(255) NULL,
  adresse_1       VARCHAR(190) NULL,
  adresse_2       VARCHAR(190) NULL,
  code_postal     VARCHAR(10)  NULL,
  ville           VARCHAR(120) NULL,
  pays            VARCHAR(60)  NULL,
  telephone       VARCHAR(30)  NULL,
  email_pro       VARCHAR(190) NULL,
  site_web        VARCHAR(190) NULL,
  sie             VARCHAR(190) NULL,          -- service des impôts des entreprises
  aga_nom         VARCHAR(190) NULL,
  aga_numero      VARCHAR(40)  NULL,
  assurance_nom   VARCHAR(190) NULL,
  assurance_contrat VARCHAR(60) NULL,
  role            ENUM('admin','user') NOT NULL DEFAULT 'user',
  cree_par        INT UNSIGNED NULL,
  actif           TINYINT(1)   NOT NULL DEFAULT 1,
  derniere_connexion DATETIME  NULL,
  cree_le         DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_cree_par (cree_par)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Exercices comptables
--  Un exercice clos est verrouillé en écriture.
-- ---------------------------------------------------------------------
CREATE TABLE exercices (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  annee       SMALLINT UNSIGNED NOT NULL,
  date_debut  DATE NOT NULL,
  date_fin    DATE NOT NULL,
  statut      ENUM('ouvert','clos') NOT NULL DEFAULT 'ouvert',
  option_bareme_km TINYINT(1) NOT NULL DEFAULT 0,
  clos_le     DATETIME NULL,
  cree_le     DATETIME NOT NULL,
  tva_credit_initial DECIMAL(12,2) NOT NULL DEFAULT 0,   -- crédit de TVA reporté à l'ouverture (page TVA)
  PRIMARY KEY (id),
  UNIQUE KEY uq_exercice_annee (user_id, annee),
  KEY ix_exercices_user (user_id, statut),
  CONSTRAINT fk_exercices_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Extensions installées
-- ---------------------------------------------------------------------
CREATE TABLE plugins (
  identifiant  VARCHAR(64) NOT NULL,
  actif        TINYINT(1) NOT NULL DEFAULT 0,
  version      VARCHAR(32) NULL,
  licence_cle  VARCHAR(128) NULL,
  licence_etat VARCHAR(32) NULL,
  licence_vue  DATETIME NULL,
  installe_le  DATETIME NULL,
  PRIMARY KEY (identifiant)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Référentiel des postes 2035 (annexe 2035-A)
--  Table globale, non liée à un utilisateur.
-- ---------------------------------------------------------------------
CREATE TABLE postes_2035 (
  code        CHAR(2)      NOT NULL,     -- AA, BW, CE…
  sens        ENUM('recette','depense','total','resultat') NOT NULL,
  libelle     VARCHAR(190) NOT NULL,
  ordre       SMALLINT UNSIGNED NOT NULL,
  saisissable TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 pour les lignes calculées
  PRIMARY KEY (code),
  KEY ix_postes_sens (sens, ordre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Nomenclature métier : libellés parlants adossés aux postes 2035
-- ---------------------------------------------------------------------
CREATE TABLE categories_metier (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  sens       ENUM('recette','depense') NOT NULL,
  libelle    VARCHAR(160) NOT NULL,
  poste_2035 CHAR(2) NOT NULL,
  mots_cles  VARCHAR(255) NULL,
  aide       VARCHAR(255) NULL,
  ordre      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  actif      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_cat_sens (sens, ordre),
  KEY ix_cat_user (user_id),
  CONSTRAINT fk_cat_user  FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_cat_poste FOREIGN KEY (poste_2035) REFERENCES postes_2035 (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Livre des recettes
--  numero_chrono : séquence continue par exercice, sans trou.
-- ---------------------------------------------------------------------
CREATE TABLE recettes (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NOT NULL,
  exercice_id        INT UNSIGNED NOT NULL,
  numero_chrono      INT UNSIGNED NOT NULL,
  date_encaissement  DATE NOT NULL,
  client             VARCHAR(190) NOT NULL,
  nature             VARCHAR(255) NOT NULL,
  montant            DECIMAL(12,2) NOT NULL,   -- signe : negatif pour un avoir
  montant_ht         DECIMAL(12,2) NULL,       -- eclatement HT/TVA (assujetti) : montant reste le TTC du virement
  montant_tva        DECIMAL(12,2) NULL,
  taux_tva           DECIMAL(5,2)  NULL,
  est_avoir          TINYINT(1) NOT NULL DEFAULT 0,
  mode_reglement     ENUM('virement','cheque','carte','especes','prelevement','autre')
                     NOT NULL DEFAULT 'virement',
  poste_2035         CHAR(2) NOT NULL DEFAULT 'AA',
  categorie_id       INT UNSIGNED NULL,
  statut             ENUM('active','annulee','annulation') NOT NULL DEFAULT 'active',
  annule_le          DATETIME NULL,
  motif_annulation   VARCHAR(255) NULL,
  rectifie_id        INT UNSIGNED NULL,
  contrepasse_id     INT UNSIGNED NULL,
  reference_facture  VARCHAR(64) NULL,        -- n° de facture Altoviz
  altoviz_invoice_id VARCHAR(64) NULL,
  notes              TEXT NULL,
  cree_le            DATETIME NOT NULL,
  modifie_le         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recette_chrono (user_id, exercice_id, numero_chrono),
  KEY ix_recettes_date (user_id, exercice_id, date_encaissement),
  KEY ix_recettes_facture (user_id, reference_facture),
  KEY ix_recettes_statut (user_id, exercice_id, statut),
  KEY ix_recettes_rectifie (rectifie_id),
  KEY ix_recettes_contrepasse (contrepasse_id),
  CONSTRAINT fk_recettes_user     FOREIGN KEY (user_id)     REFERENCES users (id)      ON DELETE CASCADE,
  CONSTRAINT fk_recettes_exercice FOREIGN KEY (exercice_id) REFERENCES exercices (id)  ON DELETE CASCADE,
  CONSTRAINT fk_recettes_poste    FOREIGN KEY (poste_2035)  REFERENCES postes_2035 (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Registre des dépenses
-- ---------------------------------------------------------------------
CREATE TABLE depenses (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  exercice_id       INT UNSIGNED NOT NULL,
  date_decaissement DATE NOT NULL,
  fournisseur       VARCHAR(190) NOT NULL,
  libelle           VARCHAR(255) NOT NULL,
  montant           DECIMAL(12,2) NOT NULL,   -- signe : negatif pour un remboursement recu
  montant_ht        DECIMAL(12,2) NULL,       -- eclatement HT/TVA (assujetti) : montant reste le TTC du virement
  montant_tva       DECIMAL(12,2) NULL,
  taux_tva          DECIMAL(5,2)  NULL,
  tva_non_recuperable DECIMAL(12,2) NULL,     -- part de la TVA qui reste une charge (vehicule, cadeau...)
  est_remboursement TINYINT(1) NOT NULL DEFAULT 0,
  poste_2035        CHAR(2) NOT NULL,
  categorie_id      INT UNSIGNED NULL,
  statut            ENUM('active','annulee','annulation') NOT NULL DEFAULT 'active',
  annule_le         DATETIME NULL,
  motif_annulation  VARCHAR(255) NULL,
  rectifie_id       INT UNSIGNED NULL,
  contrepasse_id    INT UNSIGNED NULL,
  quote_part_pro    TINYINT UNSIGNED NOT NULL DEFAULT 100, -- % déductible
  mode_reglement    ENUM('virement','cheque','carte','especes','prelevement','autre')
                    NOT NULL DEFAULT 'carte',
  notes             TEXT NULL,
  cree_le           DATETIME NOT NULL,
  modifie_le        DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_depenses_date  (user_id, exercice_id, date_decaissement),
  KEY ix_depenses_poste (user_id, exercice_id, poste_2035),
  KEY ix_depenses_statut (user_id, exercice_id, statut),
  KEY ix_depenses_rectifie (rectifie_id),
  KEY ix_depenses_contrepasse (contrepasse_id),
  CONSTRAINT fk_depenses_user     FOREIGN KEY (user_id)     REFERENCES users (id)     ON DELETE CASCADE,
  CONSTRAINT fk_depenses_exercice FOREIGN KEY (exercice_id) REFERENCES exercices (id) ON DELETE CASCADE,
  CONSTRAINT fk_depenses_poste    FOREIGN KEY (poste_2035)  REFERENCES postes_2035 (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE journal_evenements (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  sequence      BIGINT UNSIGNED NOT NULL,       -- rang dans la chaîne de cet utilisateur
  horodatage    DATETIME(6) NOT NULL,
  type          ENUM('creation','rectification','annulation','cloture','reouverture','archivage') NOT NULL,
  objet         ENUM('recette','depense','immobilisation','exercice','archive') NOT NULL,
  objet_id      INT UNSIGNED NULL,
  exercice_id   INT UNSIGNED NULL,
  acteur        VARCHAR(190) NOT NULL,          -- qui a agi, figé au moment de l'acte
  donnees       MEDIUMTEXT NOT NULL,            -- état complet au format JSON, avant/après
  empreinte_precedente CHAR(64) NOT NULL,
  empreinte     CHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_sequence (user_id, sequence),
  UNIQUE KEY uq_journal_empreinte (empreinte),
  KEY ix_journal_objet (user_id, objet, objet_id),
  KEY ix_journal_exercice (user_id, exercice_id, sequence),
  CONSTRAINT fk_journal_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE archives_exercice (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  exercice_id   INT UNSIGNED NOT NULL,
  cree_le       DATETIME NOT NULL,
  nb_recettes   INT UNSIGNED NOT NULL DEFAULT 0,
  nb_depenses   INT UNSIGNED NOT NULL DEFAULT 0,
  total_recettes DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_depenses DECIMAL(14,2) NOT NULL DEFAULT 0,
  resultat      DECIMAL(14,2) NOT NULL DEFAULT 0,
  sequence_fin  BIGINT UNSIGNED NOT NULL,       -- dernier événement couvert
  empreinte     CHAR(64) NOT NULL,              -- empreinte du contenu archivé
  contenu       LONGTEXT NOT NULL,              -- instantané JSON complet de l'exercice
  PRIMARY KEY (id),
  KEY ix_archives (user_id, exercice_id, cree_le),
  CONSTRAINT fk_archives_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_archives_exercice FOREIGN KEY (exercice_id) REFERENCES exercices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Régularisations de fin d'exercice
-- ---------------------------------------------------------------------
CREATE TABLE regularisations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  exercice_id    INT UNSIGNED NOT NULL,
  sens           ENUM('charge_a_payer','produit_a_recevoir') NOT NULL DEFAULT 'charge_a_payer',
  libelle        VARCHAR(190) NOT NULL,
  montant        DECIMAL(12,2) NOT NULL,
  poste_2035     CHAR(2) NOT NULL,
  categorie_id   INT UNSIGNED NULL,
  justification  TEXT NULL,
  contrepasser   TINYINT(1) NOT NULL DEFAULT 1,
  cree_le        DATETIME NOT NULL,
  modifie_le     DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_regul (user_id, exercice_id),
  CONSTRAINT fk_regul_user     FOREIGN KEY (user_id)     REFERENCES users (id)      ON DELETE CASCADE,
  CONSTRAINT fk_regul_exercice FOREIGN KEY (exercice_id) REFERENCES exercices (id)  ON DELETE CASCADE,
  CONSTRAINT fk_regul_poste    FOREIGN KEY (poste_2035)  REFERENCES postes_2035 (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Justificatifs
--  Les fichiers vivent hors docroot ; on ne stocke que le chemin relatif.
-- ---------------------------------------------------------------------
CREATE TABLE justificatifs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  depense_id     INT UNSIGNED NULL,
  recette_id     INT UNSIGNED NULL,
  transaction_id    INT UNSIGNED NULL,
  immobilisation_id INT UNSIGNED NULL,
  nom_origine VARCHAR(255) NOT NULL,
  chemin      VARCHAR(255) NOT NULL,      -- relatif à storage/justificatifs
  mime        VARCHAR(100) NOT NULL,
  taille      INT UNSIGNED NOT NULL,
  sha256      CHAR(64) NOT NULL,
  ajoute_le   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_justif_depense (depense_id),
  KEY ix_justif_recette (recette_id),
  KEY ix_justif_transaction (transaction_id),
  KEY ix_justif_immo (immobilisation_id),
  CONSTRAINT fk_justif_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE,
  CONSTRAINT fk_justif_depense FOREIGN KEY (depense_id) REFERENCES depenses (id) ON DELETE CASCADE,
  CONSTRAINT fk_justif_recette FOREIGN KEY (recette_id) REFERENCES recettes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- La contrainte vers transactions_brutes est posee plus bas : cette table
-- n'existe pas encore a ce point du fichier.


-- ---------------------------------------------------------------------
--  Connexions Altoviz
--  api_key_chiffree : sodium_crypto_secretbox, clé maître hors BDD.
-- ---------------------------------------------------------------------
-- ---------------------------------------------------------------------
--  Comptes bancaires
-- ---------------------------------------------------------------------
CREATE TABLE comptes_bancaires (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  libelle     VARCHAR(120) NOT NULL,
  banque      VARCHAR(120) NULL,
  iban_fin    VARCHAR(8) NULL,
  nature      ENUM('professionnel','mixte','personnel') NOT NULL DEFAULT 'professionnel',
  motifs      VARCHAR(255) NULL,
  actif       TINYINT(1) NOT NULL DEFAULT 1,
  cree_le     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_comptes_user (user_id, actif),
  CONSTRAINT fk_comptes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Transactions bancaires brutes (staging avant affectation)
--  empreinte : sha256 déterministe, garantit l'idempotence des syncs.
-- ---------------------------------------------------------------------
CREATE TABLE transactions_brutes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  exercice_id    INT UNSIGNED NOT NULL,
  connexion_id   INT UNSIGNED NULL,   -- reference libre vers un connecteur, sans FK
  source         ENUM('altoviz','csv','ofx','manuel','evoliz') NOT NULL,
  empreinte      CHAR(64) NOT NULL,
  external_id    VARCHAR(64) NULL,             -- id natif si fourni par la source
  date_operation DATE NOT NULL,
  libelle        VARCHAR(255) NOT NULL,
  montant        DECIMAL(12,2) NOT NULL,       -- signé : + crédit, − débit
  compte         VARCHAR(120) NULL,   -- libellé brut du relevé
  compte_id      INT UNSIGNED NULL,
  statut         ENUM('nouveau','affecte','ignore') NOT NULL DEFAULT 'nouveau',
  suggestion_type   ENUM('recette','depense','immobilisation','virement_interne','hors_bnc') NULL,
  suggestion_poste  CHAR(2) NULL,
  suggestion_categorie INT UNSIGNED NULL,
  suggestion_regle  INT UNSIGNED NULL,
  affecte_type   ENUM('recette','depense','immobilisation') NULL,
  affecte_id     INT UNSIGNED NULL,
  contrepartie_id INT UNSIGNED NULL,   -- autre moitié d'un virement interne
  motif_exclusion ENUM('prive','virement_interne','tva','apport_retrait','autre','doublon') NULL,
  importe_le     DATETIME NOT NULL,
  traite_le      DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tx_empreinte (user_id, empreinte),
  KEY ix_tx_attente (user_id, exercice_id, statut, date_operation),
  KEY ix_tx_connexion (connexion_id),
  KEY ix_tx_compte (user_id, compte_id, date_operation),
  KEY ix_tx_contrepartie (contrepartie_id),
  KEY ix_tx_externe (user_id, source, external_id),
  KEY ix_tx_montant (user_id, montant, date_operation),
  CONSTRAINT fk_tx_user      FOREIGN KEY (user_id)      REFERENCES users (id)     ON DELETE CASCADE,
  CONSTRAINT fk_tx_exercice  FOREIGN KEY (exercice_id)  REFERENCES exercices (id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_compte    FOREIGN KEY (compte_id)    REFERENCES comptes_bancaires (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doublons_ecartes (
  user_id INT UNSIGNED NOT NULL,
  tx_a    INT UNSIGNED NOT NULL,      -- toujours le plus petit des deux identifiants
  tx_b    INT UNSIGNED NOT NULL,
  cree_le DATETIME NOT NULL,
  PRIMARY KEY (user_id, tx_a, tx_b),
  KEY ix_dbl_b (tx_b),
  CONSTRAINT fk_dbl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_dbl_a FOREIGN KEY (tx_a) REFERENCES transactions_brutes (id) ON DELETE CASCADE,
  CONSTRAINT fk_dbl_b FOREIGN KEY (tx_b) REFERENCES transactions_brutes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  Règles d'affectation automatique
-- ---------------------------------------------------------------------
CREATE TABLE regles_affectation (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  priorite     SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  motif        VARCHAR(190) NOT NULL,
  type_match   ENUM('contient','commence','regex','montant_exact') NOT NULL DEFAULT 'contient',
  sens_montant ENUM('tous','credit','debit') NOT NULL DEFAULT 'tous',
  cible_type   ENUM('recette','depense','immobilisation','virement_interne','hors_bnc') NOT NULL,
  poste_2035   CHAR(2) NULL,
  actif        TINYINT(1) NOT NULL DEFAULT 1,
  nb_appliquee INT UNSIGNED NOT NULL DEFAULT 0,
  cree_le      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_regles_user (user_id, actif, priorite),
  CONSTRAINT fk_regles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE justificatifs
  ADD CONSTRAINT fk_justif_transaction FOREIGN KEY (transaction_id)
      REFERENCES transactions_brutes (id) ON DELETE SET NULL;


-- ---------------------------------------------------------------------
--  Barème kilométrique, véhicules et registre des trajets
-- ---------------------------------------------------------------------
CREATE TABLE bareme_km (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  annee        SMALLINT UNSIGNED NOT NULL,
  type_vehicule ENUM('voiture','moto','cyclomoteur') NOT NULL DEFAULT 'voiture',
  puissance_min TINYINT UNSIGNED NOT NULL,   -- borne basse en CV fiscaux
  puissance_max TINYINT UNSIGNED NOT NULL,   -- 99 pour « et plus »
  tranche_min   INT UNSIGNED NOT NULL,       -- borne basse de la tranche, en km
  tranche_max   INT UNSIGNED NOT NULL,       -- 999999 pour « au-delà »
  coefficient   DECIMAL(6,4) NOT NULL,       -- multiplié par les km
  constante     DECIMAL(8,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bareme (annee, type_vehicule, puissance_min, tranche_min),
  KEY ix_bareme_annee (annee, type_vehicule)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicules (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  designation       VARCHAR(190) NOT NULL,
  immatriculation   VARCHAR(20) NULL,
  type_vehicule     ENUM('voiture','moto','cyclomoteur') NOT NULL DEFAULT 'voiture',
  puissance_cv      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  electrique        TINYINT(1) NOT NULL DEFAULT 0,
  immobilisation_id INT UNSIGNED NULL,       -- référence libre vers une extension, sans FK
  actif             TINYINT(1) NOT NULL DEFAULT 1,
  cree_le           DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_vehicules_user (user_id, actif),
  CONSTRAINT fk_vehicules_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trajets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  vehicule_id INT UNSIGNED NOT NULL,
  exercice_id INT UNSIGNED NOT NULL,
  date_trajet DATE NOT NULL,
  motif       VARCHAR(255) NOT NULL,
  kilometres  DECIMAL(8,1) NOT NULL,
  cree_le     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_trajets (user_id, exercice_id, vehicule_id, date_trajet),
  CONSTRAINT fk_trajets_user     FOREIGN KEY (user_id)     REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_trajets_vehicule FOREIGN KEY (vehicule_id) REFERENCES vehicules (id)   ON DELETE CASCADE,
  CONSTRAINT fk_trajets_exercice FOREIGN KEY (exercice_id) REFERENCES exercices (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  Référentiel 2035-A
-- =====================================================================
INSERT INTO postes_2035 (code, sens, libelle, ordre, saisissable) VALUES
  ('AA','recette', 'Recettes encaissées y compris les remboursements de frais', 10, 1),
  ('AC','recette', 'Débours payés pour le compte des clients',                  20, 1),
  ('AD','recette', 'Honoraires rétrocédés',                                     30, 1),
  ('AF','total',   'Montant net des recettes',                                  40, 0),
  ('AG','recette', 'Produits financiers',                                       50, 1),
  ('AH','recette', 'Gains divers',                                              60, 1),

  ('BA','depense', 'Achats',                                                   100, 1),
  ('BB','depense', 'Frais de personnel — salaires nets',                       110, 1),
  ('BC','depense', 'Frais de personnel — charges sociales sur salaires',       120, 1),
  ('BD','depense', 'Impôts et taxes — TVA',                                    130, 1),
  ('BS','depense', 'Impôts et taxes — CET',                                    140, 1),
  ('BE','depense', 'Impôts et taxes — autres',                                 150, 1),
  ('BF','depense', 'Loyer et charges locatives',                               160, 1),
  ('BG','depense', 'Location de matériel et de mobilier',                      170, 1),
  ('BH','depense', 'Entretien et réparations',                                 180, 1),
  ('BJ','depense', 'Personnel intérimaire',                                    190, 1),
  ('BK','depense', 'Petit outillage',                                          200, 1),
  ('BM','depense', 'Chauffage, eau, gaz, électricité',                         210, 1),
  ('BN','depense', 'Honoraires ne constituant pas des rétrocessions',          220, 1),
  ('BP','depense', 'Primes d''assurance',                                      230, 1),
  ('BR','depense', 'Frais de véhicules',                                       240, 1),
  ('BV','depense', 'Autres frais de déplacement',                              250, 1),
  ('BW','depense', 'Charges sociales personnelles',                            260, 1),
  ('BX','depense', 'Frais de réception, de représentation et de congrès',      270, 1),
  ('BY','depense', 'Fournitures de bureau, documentation, correspondance, téléphone', 280, 1),
  ('BZ','depense', 'Frais d''actes et de contentieux',                         290, 1),
  ('CA','depense', 'Cotisations syndicales et professionnelles',               300, 1),
  ('CB','depense', 'Autres frais divers de gestion',                           310, 1),
  ('CC','depense', 'Frais financiers',                                         320, 1),
  ('CD','depense', 'Pertes diverses',                                          330, 1),
  ('CH','depense', 'Dotation aux amortissements',                               335, 0),
  ('CE','total',   'Total des dépenses',                                       340, 0),

  ('CP','resultat','Excédent',                                                 400, 0),
  ('CR','resultat','Insuffisance',                                             410, 0);


-- Barème 2024, à vérifier et compléter chaque année.
INSERT INTO bareme_km (annee, type_vehicule, puissance_min, puissance_max, tranche_min, tranche_max, coefficient, constante) VALUES
  (2024,'voiture',1,3,     0,  5000, 0.5290,    0),
  (2024,'voiture',1,3,  5001, 20000, 0.3160, 1065),
  (2024,'voiture',1,3, 20001,999999, 0.3700,    0),
  (2024,'voiture',4,4,     0,  5000, 0.6060,    0),
  (2024,'voiture',4,4,  5001, 20000, 0.3400, 1330),
  (2024,'voiture',4,4, 20001,999999, 0.4070,    0),
  (2024,'voiture',5,5,     0,  5000, 0.6360,    0),
  (2024,'voiture',5,5,  5001, 20000, 0.3570, 1395),
  (2024,'voiture',5,5, 20001,999999, 0.4270,    0),
  (2024,'voiture',6,6,     0,  5000, 0.6650,    0),
  (2024,'voiture',6,6,  5001, 20000, 0.3740, 1457),
  (2024,'voiture',6,6, 20001,999999, 0.4470,    0),
  (2024,'voiture',7,99,    0,  5000, 0.6970,    0),
  (2024,'voiture',7,99, 5001, 20000, 0.3940, 1515),
  (2024,'voiture',7,99,20001,999999, 0.4700,    0);


-- Nomenclature métier livrée avec le logiciel.
INSERT INTO categories_metier (user_id, sens, libelle, poste_2035, mots_cles, aide, ordre) VALUES
  -- Recettes
  (NULL,'recette','Honoraires et prestations de formation','AA','FORMATION|HONORAIRE|PRESTATION|FACTURE',NULL,10),
  (NULL,'recette','Vente de supports et contenus','AA','SUPPORT|LICENCE|ABONNEMENT CLIENT',NULL,20),
  (NULL,'recette','Subventions et aides','AH','SUBVENTION|AIDE|FONDS FORMATION|OPCO','Aides publiques et financements OPCO perçus en propre.',30),
  (NULL,'recette','Indemnités perçues','AH','INDEMNITE|IJ|MAINTIEN DE SALAIRE','Indemnités journalières, assurance, dédommagements.',40),
  (NULL,'recette','Produits financiers','AG','INTERETS|LIVRET',NULL,50),
  (NULL,'recette','Débours refacturés au client','AC','DEBOURS|REFACTURATION','Frais avancés pour le compte du client, à ne pas confondre avec une recette propre.',60),
  (NULL,'recette','Honoraires rétrocédés à un confrère','AD','RETROCESSION',NULL,70),

  -- Dépenses : charges sociales et impôts
  (NULL,'depense','Charges sociales personnelles (URSSAF, retraite, prévoyance)','BW','URSSAF|CARPIMKO|CIPAV|RSI|PREVOYANCE|RETRAITE|MADELIN|SSI','Cotisations du formateur lui-même, pas celles d''un salarié.',10),
  (NULL,'depense','Contribution à la formation professionnelle (CFP)','BE','CFP|CONTRIBUTION FORMATION','Collectée par l''URSSAF, distincte des cotisations sociales.',20),
  (NULL,'depense','Cotisation foncière des entreprises (CFE)','BS','CFE|COTISATION FONCIERE|CVAE',NULL,30),
  (NULL,'depense','Autres impôts et taxes','BE','TAXE|IMPOT|TRESOR PUBLIC|DGFIP',NULL,40),

  -- Locaux
  (NULL,'depense','Loyer de bureau ou de salle de formation','BF','LOYER|BAIL|SCI|LOCATION BUREAU|COWORKING',NULL,50),
  (NULL,'depense','Chauffage, eau, gaz, électricité','BM','EDF|ENGIE|ELECTRICITE|VEOLIA|SUEZ|GAZ',NULL,60),
  (NULL,'depense','Entretien et réparations','BH','ENTRETIEN|REPARATION|PLOMBIER|MENAGE',NULL,70),

  -- Matériel et fournitures
  (NULL,'depense','Matériel pédagogique et fournitures','BA','FOURNITURE|PAPETERIE|MATERIEL PEDAGOGIQUE|BUREAU VALLEE',NULL,80),
  (NULL,'depense','Petit matériel non immobilisé','BK','PETIT MATERIEL|OUTILLAGE','En deçà du seuil d''immobilisation, sinon inscrire au registre.',90),
  (NULL,'depense','Location de matériel et de mobilier','BG','LOCATION MATERIEL|LEASING|LLD',NULL,100),

  -- Déplacements
  (NULL,'depense','Frais de véhicule','BR','CARBURANT|ESSENCE|TOTAL|ESSO|SHELL|PEAGE|GARAGE|PNEU','Frais réels, ou indemnités si l''option barème est retenue.',110),
  (NULL,'depense','Autres frais de déplacement (train, hôtel)','BV','SNCF|TRAIN|HOTEL|BOOKING|TAXI|UBER|AVION|PARKING',NULL,120),
  (NULL,'depense','Frais de réception et repas clients','BX','RESTAURANT|TRAITEUR|RECEPTION',NULL,130),

  -- Services et abonnements
  (NULL,'depense','Télécommunications, logiciels et abonnements','BY','FREE|ORANGE|SFR|BOUYGUES|OVH|IONOS|NETCUP|SCALEWAY|GOOGLE|MICROSOFT|ADOBE|ABONNEMENT|SAAS',NULL,140),
  (NULL,'depense','Documentation professionnelle','BY','DOCUMENTATION|LIVRE|REVUE|EDITIONS',NULL,150),
  (NULL,'depense','Honoraires versés à des tiers (comptable, sous-traitance)','BN','EXPERT COMPTABLE|COMPTABLE|AVOCAT|SOUS TRAITANCE|HONORAIRE VERSE|AGA',NULL,160),
  (NULL,'depense','Assurance responsabilité civile professionnelle','BP','ASSURANCE|MAAF|AXA|MAIF|ALLIANZ|MACIF|RC PRO',NULL,170),
  (NULL,'depense','Cotisations syndicales et professionnelles','CA','SYNDICAT|COTISATION PROFESSIONNELLE|ORDRE|FEDERATION',NULL,180),

  -- Divers
  (NULL,'depense','Formation continue du formateur','CB','FORMATION CONTINUE|INSCRIPTION FORMATION|CERTIFICATION|QUALIOPI','La propre montée en compétences du formateur.',190),
  (NULL,'depense','Frais bancaires','CC','FRAIS BANCAIRE|COMMISSION|AGIOS|TENUE DE COMPTE|QONTO|SHINE',NULL,200),
  (NULL,'depense','Frais d''actes et de contentieux','BZ','HUISSIER|GREFFE|CONTENTIEUX',NULL,210),
  (NULL,'depense','Autres charges diverses','CB',NULL,NULL,220);

-- ---------------------------------------------------------------------
--  Provisions : estimation des cotisations et de l'impôt (API Urssaf)
-- ---------------------------------------------------------------------
CREATE TABLE estimation_parametres (
  user_id           INT UNSIGNED NOT NULL,
  situation_famille VARCHAR(12)  NOT NULL DEFAULT 'célibataire',
  enfants           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  parent_isole      TINYINT(1)   NOT NULL DEFAULT 0,
  autres_revenus    DECIMAL(12,2) NOT NULL DEFAULT 0,
  mode              VARCHAR(12)  NOT NULL DEFAULT 'annualise',
  recettes_libre    DECIMAL(12,2) NULL,        -- montants de la saisie libre, conservés d'une fois sur l'autre
  charges_libre     DECIMAL(12,2) NULL,
  maj_le            DATETIME     NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_estparam_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estimations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  exercice_id INT UNSIGNED NOT NULL,
  cree_le     DATETIME NOT NULL,
  mode        VARCHAR(12) NOT NULL,
  statut      ENUM('ok','erreur') NOT NULL,
  message     VARCHAR(500) NULL,
  entree      TEXT NOT NULL,          -- recettes et charges retenues, mode, paramètres du foyer (JSON)
  situation   TEXT NOT NULL,          -- ce qui a été envoyé à l'Urssaf (JSON)
  resultat    TEXT NULL,              -- valeurs, hypothèses du simulateur, avertissements (JSON)
  PRIMARY KEY (id),
  KEY ix_est_exercice (user_id, exercice_id, cree_le),
  CONSTRAINT fk_est_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_est_exercice FOREIGN KEY (exercice_id) REFERENCES exercices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Boutiques d'extensions importées
-- ---------------------------------------------------------------------
CREATE TABLE boutiques (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cle         VARCHAR(60)  NOT NULL,        -- identifiant déclaré par la boutique (boutique.id)
  nom         VARCHAR(100) NOT NULL,
  source      VARCHAR(500) NULL,            -- adresse d'origine, pour l'actualiser ; NULL si importée depuis un fichier
  contenu     MEDIUMTEXT   NOT NULL,        -- le catalogue validé (JSON)
  importee_le DATETIME     NOT NULL,
  maj_le      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_boutique_cle (cle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
