-- Préparation du registre des immobilisations (annexe 2035-B).
--
-- La table elle-même appartient à l'extension Pro et est créée à son
-- activation : le cœur ne pose ici que les colonnes de liaison et les
-- valeurs d'énumération, sans clé étrangère vers une table qui peut
-- ne jamais exister.

-- Une opération bancaire peut désormais être affectée en immobilisation.
ALTER TABLE transactions_brutes
  MODIFY suggestion_type ENUM('recette','depense','immobilisation','hors_bnc') NULL,
  MODIFY affecte_type    ENUM('recette','depense','immobilisation') NULL;

ALTER TABLE regles_affectation
  MODIFY cible_type ENUM('recette','depense','immobilisation','hors_bnc') NOT NULL;

-- Un justificatif peut porter sur une immobilisation.
ALTER TABLE justificatifs
  ADD COLUMN immobilisation_id INT UNSIGNED NULL AFTER transaction_id,
  ADD KEY ix_justif_immo (immobilisation_id);

-- Postes 2035 manquants : la dotation et les plus-values.
INSERT INTO postes_2035 (code, sens, libelle, ordre, saisissable) VALUES
  ('CH','depense', 'Dotation aux amortissements', 335, 0)
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);
