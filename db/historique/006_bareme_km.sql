-- Barème kilométrique.
--
-- Les coefficients sont fixés par arrêté chaque année : ils vivent en base,
-- éditables, plutôt qu'en dur dans le code. Un exercice sans barème saisi
-- est signalé au lieu d'être calculé avec des valeurs périmées.

CREATE TABLE bareme_km (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  annee        SMALLINT UNSIGNED NOT NULL,
  type_vehicule ENUM('voiture','moto','cyclomoteur') NOT NULL DEFAULT 'voiture',
  puissance_min TINYINT UNSIGNED NOT NULL,   -- borne basse en CV fiscaux
  puissance_max TINYINT UNSIGNED NOT NULL,   -- 99 pour « et plus »
  tranche_min   INT UNSIGNED NOT NULL,       -- borne basse de la tranche, en km
  tranche_max   INT UNSIGNED NOT NULL,       -- 999999 pour « au-delà »
  coefficient   DECIMAL(6,4) NOT NULL,       -- multiplié par les km
  constante     DECIMAL(8,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bareme (annee, type_vehicule, puissance_min, tranche_min),
  KEY ix_bareme_annee (annee, type_vehicule)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicules (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           INT UNSIGNED NOT NULL,
  designation       VARCHAR(190) NOT NULL,
  immatriculation   VARCHAR(20) NULL,
  type_vehicule     ENUM('voiture','moto','cyclomoteur') NOT NULL DEFAULT 'voiture',
  puissance_cv      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  electrique        TINYINT(1) NOT NULL DEFAULT 0,
  immobilisation_id INT UNSIGNED NULL,       -- référence libre vers une extension, sans FK
  actif             TINYINT(1) NOT NULL DEFAULT 1,
  cree_le           DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_vehicules_user (user_id, actif),
  CONSTRAINT fk_vehicules_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registre des trajets : l'administration attend un relevé justifiant
-- les kilomètres déclarés, pas seulement un total.
CREATE TABLE trajets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  vehicule_id INT UNSIGNED NOT NULL,
  exercice_id INT UNSIGNED NOT NULL,
  date_trajet DATE NOT NULL,
  motif       VARCHAR(255) NOT NULL,
  kilometres  DECIMAL(8,1) NOT NULL,
  cree_le     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_trajets (user_id, exercice_id, vehicule_id, date_trajet),
  CONSTRAINT fk_trajets_user     FOREIGN KEY (user_id)     REFERENCES users (id)       ON DELETE CASCADE,
  CONSTRAINT fk_trajets_vehicule FOREIGN KEY (vehicule_id) REFERENCES vehicules (id)   ON DELETE CASCADE,
  CONSTRAINT fk_trajets_exercice FOREIGN KEY (exercice_id) REFERENCES exercices (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- L'option pour le barème est annuelle et globale : elle vaut pour tous
-- les véhicules de l'exercice.
ALTER TABLE exercices
  ADD COLUMN option_bareme_km TINYINT(1) NOT NULL DEFAULT 0 AFTER statut;

-- Barème 2024 des voitures, à vérifier et compléter chaque année.
INSERT INTO bareme_km (annee, type_vehicule, puissance_min, puissance_max, tranche_min, tranche_max, coefficient, constante) VALUES
  (2024,'voiture',1,3,     0,  5000, 0.5290,    0),
  (2024,'voiture',1,3,  5001, 20000, 0.3160, 1065),
  (2024,'voiture',1,3, 20001,999999, 0.3700,    0),
  (2024,'voiture',4,4,     0,  5000, 0.6060,    0),
  (2024,'voiture',4,4,  5001, 20000, 0.3400, 1330),
  (2024,'voiture',4,4, 20001,999999, 0.4070,    0),
  (2024,'voiture',5,5,     0,  5000, 0.6360,    0),
  (2024,'voiture',5,5,  5001, 20000, 0.3570, 1395),
  (2024,'voiture',5,5, 20001,999999, 0.4270,    0),
  (2024,'voiture',6,6,     0,  5000, 0.6650,    0),
  (2024,'voiture',6,6,  5001, 20000, 0.3740, 1457),
  (2024,'voiture',6,6, 20001,999999, 0.4470,    0),
  (2024,'voiture',7,99,    0,  5000, 0.6970,    0),
  (2024,'voiture',7,99, 5001, 20000, 0.3940, 1515),
  (2024,'voiture',7,99,20001,999999, 0.4700,    0);
