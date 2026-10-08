-- Écritures d'annulation et contrôle des doublons.
--
-- 1. Une correction ne se contente plus de marquer l'ancienne écriture : elle
--    inscrit au registre une écriture d'annulation (montant opposé), puis la
--    nouvelle écriture. Les trois lignes restent reliées entre elles :
--    rectifie_id (la nouvelle -> l'originale) et contrepasse_id (l'annulation
--    -> l'originale). Le statut dit laquelle est laquelle.
--
-- 2. Les doublons d'import se repèrent par un identifiant natif quand la
--    source en fournit un, d'où l'index de recherche ; et une paire jugée
--    « pas un doublon » est mémorisée pour ne plus être proposée.

ALTER TABLE recettes
  MODIFY statut ENUM('active','annulee','annulation') NOT NULL DEFAULT 'active',
  ADD COLUMN contrepasse_id INT UNSIGNED NULL AFTER rectifie_id,
  ADD KEY ix_recettes_contrepasse (contrepasse_id);

ALTER TABLE depenses
  MODIFY statut ENUM('active','annulee','annulation') NOT NULL DEFAULT 'active',
  ADD COLUMN contrepasse_id INT UNSIGNED NULL AFTER rectifie_id,
  ADD KEY ix_depenses_contrepasse (contrepasse_id);

ALTER TABLE transactions_brutes
  MODIFY motif_exclusion ENUM('prive','virement_interne','tva','apport_retrait','autre','doublon') NULL,
  ADD KEY ix_tx_externe (user_id, source, external_id),
  ADD KEY ix_tx_montant (user_id, montant, date_operation);

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
