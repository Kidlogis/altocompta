# Migrations historiques

Ces fichiers retracent la construction du schéma pendant le développement,
avant toute mise en production. Ils ne sont plus dans la chaîne d'exécution.

`db/schema.sql` est désormais la référence unique et intègre tout ce que ces
migrations apportaient. Une installation neuve part de lui.

Ils sont conservés pour mémoire des raisons de chaque changement. Ne pas les
remettre dans `db/migrations/` : la migration 007 y supprime des clés
étrangères qui n'existent plus dans le schéma actuel, et échouerait.

Les évolutions du schéma à partir d'ici prennent des numéros à partir de 002
dans `db/migrations/`, et doivent être ajoutées **à la fois** au fichier de
migration et à `db/schema.sql`, pour qu'installation neuve et mise à jour
convergent.
