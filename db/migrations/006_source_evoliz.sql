-- Nouvelle source d'opérations : le connecteur Evoliz.
--
-- La valeur est ajoutée en fin de liste, ce qui ne réécrit pas la table.
ALTER TABLE transactions_brutes
  MODIFY source ENUM('altoviz','csv','ofx','manuel','evoliz') NOT NULL;
