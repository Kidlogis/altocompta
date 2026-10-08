-- Forme juridique en texte libre.
--
-- La liste fermée (EI, EURL, société civile…) ne couvrait pas la diversité des situations, et l'une
-- de ses options, « micro-entrepreneur ayant opté pour le réel », n'existe pas : opter pour le réel
-- fait sortir du régime micro, on est alors une entreprise individuelle comme une autre.
--
-- La colonne passe de 30 à 100 caractères, et les codes déjà enregistrés deviennent de vrais libellés.
-- « autre » n'ayant aucun sens dans un champ libre, il redevient vide. Rejouable sans effet.

ALTER TABLE users MODIFY forme_juridique VARCHAR(100) NULL;

UPDATE users
   SET forme_juridique = CASE forme_juridique
         WHEN 'ei'         THEN 'Entreprise individuelle (EI)'
         WHEN 'micro_reel' THEN 'Entreprise individuelle (EI)'
         WHEN 'eurl_ir'    THEN 'EURL soumise à l''impôt sur le revenu'
         WHEN 'societe_ir' THEN 'Société civile ou d''exercice libéral à l''IR'
         ELSE NULL
       END
 WHERE forme_juridique IN ('ei', 'micro_reel', 'eurl_ir', 'societe_ir', 'autre');
