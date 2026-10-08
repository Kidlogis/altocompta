-- Nomenclature métier.
--
-- Les 29 postes de la 2035-A sont exacts mais opaques : un formateur ne sait
-- pas que sa RC professionnelle tombe en BP ni sa CFE en BS. Cette table pose
-- une couche de libellés parlants, chacun rattaché à son poste officiel.
--
-- user_id NULL = catégorie fournie avec le logiciel, commune à tous.
-- Les mots-clés servent à pré-catégoriser les opérations importées lorsque
-- aucune règle personnelle ne s'applique.

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

ALTER TABLE recettes ADD COLUMN categorie_id INT UNSIGNED NULL AFTER poste_2035;
ALTER TABLE depenses ADD COLUMN categorie_id INT UNSIGNED NULL AFTER poste_2035;

ALTER TABLE transactions_brutes
  ADD COLUMN suggestion_categorie INT UNSIGNED NULL AFTER suggestion_poste;

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
