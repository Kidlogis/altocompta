-- Page TVA : périodicité de déclaration et crédit de TVA reporté à l'ouverture d'un exercice.
--
--   users.tva_periodicite    : mensuelle, trimestrielle ou annuelle. NULL tant que l'assujetti ne l'a pas choisie :
--                              elle dépend de son régime d'imposition, que l'application ne peut pas deviner.
--   exercices.tva_credit_initial : le crédit de TVA qui vient de l'exercice précédent (ou d'avant AltoCompta).
--                              Un crédit se reporte de déclaration en déclaration jusqu'à son remboursement.

ALTER TABLE users
  ADD COLUMN tva_periodicite ENUM('mensuelle','trimestrielle','annuelle') NULL AFTER tva_numero;

ALTER TABLE exercices
  ADD COLUMN tva_credit_initial DECIMAL(12,2) NOT NULL DEFAULT 0;
