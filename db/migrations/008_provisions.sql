-- Provisions : estimation des cotisations sociales et de l'impôt sur le revenu.
--
-- Une estimation est un appel à l'API publique de l'Urssaf (mon-entreprise.urssaf.fr). On conserve
-- ce qui a été envoyé et ce qui est revenu, avec la date : l'estimation s'affiche sans rappeler
-- l'API, et un résultat inattendu peut être expliqué après coup.

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
