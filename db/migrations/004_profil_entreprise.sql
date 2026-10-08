-- Fiche entreprise complète.
--
-- Jusqu'ici le profil ne portait que le nom, la dénomination et le SIRET.
-- Une déclaration 2035 et les registres opposables identifient le déclarant
-- bien plus précisément : adresse, activité, code APE, régime de TVA,
-- rattachement fiscal, organisme de gestion agréé. Un organisme de formation
-- y ajoute son numéro de déclaration d'activité.
--
-- Colonnes toutes facultatives : rien n'est exigé pour utiliser le logiciel,
-- et une fiche incomplète n'empêche aucun calcul.

ALTER TABLE users
  ADD COLUMN forme_juridique    VARCHAR(30)  NULL AFTER siret,
  ADD COLUMN profession         VARCHAR(190) NULL AFTER forme_juridique,
  ADD COLUMN date_debut_activite DATE        NULL AFTER profession,
  ADD COLUMN code_ape           CHAR(5)      NULL AFTER date_debut_activite,
  ADD COLUMN tva_regime         VARCHAR(20)  NULL AFTER code_ape,
  ADD COLUMN tva_numero         VARCHAR(20)  NULL AFTER tva_regime,
  ADD COLUMN nda                CHAR(11)     NULL AFTER tva_numero,
  ADD COLUMN certifications     VARCHAR(255) NULL AFTER nda,
  ADD COLUMN adresse_1          VARCHAR(190) NULL AFTER certifications,
  ADD COLUMN adresse_2          VARCHAR(190) NULL AFTER adresse_1,
  ADD COLUMN code_postal        VARCHAR(10)  NULL AFTER adresse_2,
  ADD COLUMN ville              VARCHAR(120) NULL AFTER code_postal,
  ADD COLUMN pays               VARCHAR(60)  NULL AFTER ville,
  ADD COLUMN telephone          VARCHAR(30)  NULL AFTER pays,
  ADD COLUMN email_pro          VARCHAR(190) NULL AFTER telephone,
  ADD COLUMN site_web           VARCHAR(190) NULL AFTER email_pro,
  ADD COLUMN sie                VARCHAR(190) NULL AFTER site_web,
  ADD COLUMN aga_nom            VARCHAR(190) NULL AFTER sie,
  ADD COLUMN aga_numero         VARCHAR(40)  NULL AFTER aga_nom,
  ADD COLUMN assurance_nom      VARCHAR(190) NULL AFTER aga_numero,
  ADD COLUMN assurance_contrat  VARCHAR(60)  NULL AFTER assurance_nom;
