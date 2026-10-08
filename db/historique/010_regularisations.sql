-- Régularisations de fin d'exercice.
--
-- Le BNC au réel fonctionne en trésorerie : une charge n'est déductible que
-- décaissée. Certaines écritures dérogent à ce principe — typiquement les
-- cotisations sociales obligatoires dues au 31 décembre mais appelées plus
-- tard. Elles sont saisies ici, à part des registres, avec leur justification.
--
-- Contre-passation : une charge déduite en N réapparaîtra sur le relevé de
-- N+1 au moment du paiement. Sans neutralisation, elle serait déduite deux
-- fois. L'impact d'une régularisation est donc porté positivement sur son
-- exercice et négativement sur le suivant.

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
