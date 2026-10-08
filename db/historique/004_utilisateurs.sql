-- Traçabilité des comptes gérés depuis l'interface.
ALTER TABLE users
  ADD COLUMN cree_par INT UNSIGNED NULL AFTER role,
  ADD KEY ix_users_cree_par (cree_par);
