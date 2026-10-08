-- Boutiques d'extensions importées.
--
-- La boutique de Kidlogis est embarquée dans l'application (boutique/kidlogis.json) et n'a pas de ligne ici.
-- Une boutique importée est un contenu non fiable : on conserve le catalogue VALIDÉ, tel que Bnc\\Boutique
-- l'a normalisé, jamais le fichier brut.

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
