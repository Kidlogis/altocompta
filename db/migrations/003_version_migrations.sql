-- Élargissement de la colonne « version » du suivi des migrations.
--
-- Les migrations d'extension sont tracées sous la forme
-- « plugin:<identifiant>:<nom_du_fichier> », ce qui dépasse les 32
-- caractères d'origine dès qu'un nom de fichier est un peu descriptif :
-- l'insertion échouait alors, et l'activation de l'extension avec elle.

ALTER TABLE migrations MODIFY version VARCHAR(128) NOT NULL;
