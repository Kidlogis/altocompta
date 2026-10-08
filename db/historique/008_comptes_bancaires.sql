-- Comptes bancaires et virements internes.
--
-- Un virement entre deux comptes du même utilisateur apparaît deux fois :
-- en débit sur l'un, en crédit sur l'autre. Sans traitement, le crédit est
-- proposé en recette et le débit en dépense : l'erreur atteint alors le
-- double du montant viré. D'où le rapprochement des paires.

CREATE TABLE comptes_bancaires (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  libelle     VARCHAR(120) NOT NULL,
  banque      VARCHAR(120) NULL,
  iban_fin    VARCHAR(8) NULL,           -- 4 derniers caractères, pour reconnaître sans stocker l'IBAN
  nature      ENUM('professionnel','mixte','personnel') NOT NULL DEFAULT 'professionnel',
  motifs      VARCHAR(255) NULL,         -- libellés du relevé rattachés à ce compte, séparés par |
  actif       TINYINT(1) NOT NULL DEFAULT 1,
  cree_le     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_comptes_user (user_id, actif),
  CONSTRAINT fk_comptes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions_brutes
  ADD COLUMN compte_id       INT UNSIGNED NULL AFTER compte,
  ADD COLUMN contrepartie_id INT UNSIGNED NULL AFTER affecte_id,
  ADD COLUMN motif_exclusion ENUM('prive','virement_interne','tva','apport_retrait','autre') NULL
      AFTER contrepartie_id,
  ADD KEY ix_tx_compte (user_id, compte_id, date_operation),
  ADD KEY ix_tx_contrepartie (contrepartie_id),
  ADD CONSTRAINT fk_tx_compte FOREIGN KEY (compte_id)
      REFERENCES comptes_bancaires (id) ON DELETE SET NULL;

-- Le virement interne devient un type d'affectation à part entière,
-- au lieu d'être noyé dans un seau « ignoré » indifférencié.
ALTER TABLE transactions_brutes
  MODIFY suggestion_type ENUM('recette','depense','immobilisation','virement_interne','hors_bnc') NULL;

ALTER TABLE regles_affectation
  MODIFY cible_type ENUM('recette','depense','immobilisation','virement_interne','hors_bnc') NOT NULL;
