-- Socle d'extensions.
--
-- Le cœur ne connaît plus les tables propres à un connecteur : la référence
-- vers une connexion externe devient un simple entier, sans clé étrangère,
-- pour que la table du plugin puisse vivre et disparaître indépendamment.

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

ALTER TABLE transactions_brutes DROP FOREIGN KEY fk_tx_connexion;

