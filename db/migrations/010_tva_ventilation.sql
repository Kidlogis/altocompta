-- Éclatement HT / TVA des écritures, pour les assujettis à la TVA.
--
-- « montant » reste le TTC : c'est le montant du virement, celui que rapprochent l'affectation, les doublons, les
-- virements internes, les règles et les connecteurs bancaires. L'éclatement s'ajoute à côté, sur la fiche de
-- l'écriture, et reste NULL tant qu'il n'a pas été fait (ou si l'on n'est pas assujetti).
--
--   montant_ht, montant_tva  : même signe que montant (négatifs pour un avoir ou un remboursement)
--   taux_tva                 : le taux de la facture, facultatif (indispensable plus tard aux déclarations CA3/CA12)
--   tva_non_recuperable      : dépenses seulement — la part de TVA qui n'est pas déductible et reste donc une charge
--
-- Une écriture se corrige par rectification, jamais par modification : l'éclatement d'une écriture existante en
-- passe donc par une écriture d'annulation et une nouvelle écriture, comme tout changement de montant.

ALTER TABLE recettes
  ADD COLUMN montant_ht  DECIMAL(12,2) NULL AFTER montant,
  ADD COLUMN montant_tva DECIMAL(12,2) NULL AFTER montant_ht,
  ADD COLUMN taux_tva    DECIMAL(5,2)  NULL AFTER montant_tva;

ALTER TABLE depenses
  ADD COLUMN montant_ht  DECIMAL(12,2) NULL AFTER montant,
  ADD COLUMN montant_tva DECIMAL(12,2) NULL AFTER montant_ht,
  ADD COLUMN taux_tva    DECIMAL(5,2)  NULL AFTER montant_tva,
  ADD COLUMN tva_non_recuperable DECIMAL(12,2) NULL AFTER taux_tva;
