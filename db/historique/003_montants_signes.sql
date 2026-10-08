-- Les avoirs et remboursements sont des écritures de sens inverse :
-- une recette peut être négative, une dépense aussi.
-- Les colonnes DECIMAL(12,2) acceptent déjà le signe ; on documente la
-- possibilité et on ajoute le champ qui distingue une écriture d'annulation.
ALTER TABLE recettes
  ADD COLUMN est_avoir TINYINT(1) NOT NULL DEFAULT 0 AFTER montant;

ALTER TABLE depenses
  ADD COLUMN est_remboursement TINYINT(1) NOT NULL DEFAULT 0 AFTER montant;
