-- Inaltérabilité, sécurisation, conservation et archivage (art. 286 I bis CGI).
--
-- Un logiciel qui enregistre des encaissements doit garantir qu'une écriture,
-- une fois saisie, ne peut plus être modifiée ni supprimée sans laisser de
-- trace. Deux mécanismes complémentaires sont mis en place ici :
--
--  1. Les écritures ne sont plus jamais supprimées ni renumérotées. Elles
--     sont annulées : la ligne reste, son numéro reste, son statut change.
--     Une correction crée une écriture rectificative distincte, reliée à
--     l'originale, qui demeure consultable.
--
--  2. Chaque opération (création, rectification, annulation, clôture) est
--     consignée dans un journal en ajout seul, dont les entrées sont
--     chaînées par empreinte SHA-256 : l'empreinte de chaque ligne couvre
--     son contenu ET l'empreinte de la précédente. Modifier ou retirer une
--     ligne a posteriori casse la chaîne, ce qu'un contrôle d'intégrité
--     détecte immédiatement.

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

-- Archives d'exercice : empreinte figée à la clôture, pour prouver qu'un
-- exercice déclaré n'a pas bougé depuis.
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

-- Statut et chaînage de rectification sur les écritures.
-- Une écriture annulée reste en base, garde son numéro, sort des totaux.
ALTER TABLE recettes
  ADD COLUMN statut ENUM('active','annulee') NOT NULL DEFAULT 'active' AFTER poste_2035,
  ADD COLUMN annule_le DATETIME NULL AFTER statut,
  ADD COLUMN motif_annulation VARCHAR(255) NULL AFTER annule_le,
  ADD COLUMN rectifie_id INT UNSIGNED NULL AFTER motif_annulation,
  ADD KEY ix_recettes_statut (user_id, exercice_id, statut),
  ADD KEY ix_recettes_rectifie (rectifie_id);

ALTER TABLE depenses
  ADD COLUMN statut ENUM('active','annulee') NOT NULL DEFAULT 'active' AFTER poste_2035,
  ADD COLUMN annule_le DATETIME NULL AFTER statut,
  ADD COLUMN motif_annulation VARCHAR(255) NULL AFTER annule_le,
  ADD COLUMN rectifie_id INT UNSIGNED NULL AFTER motif_annulation,
  ADD KEY ix_depenses_statut (user_id, exercice_id, statut),
  ADD KEY ix_depenses_rectifie (rectifie_id);
