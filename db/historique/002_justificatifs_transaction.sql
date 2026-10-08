-- Un justificatif peut être déposé sur une opération bancaire avant même
-- qu'elle soit affectée. Il suit ensuite l'écriture créée.
ALTER TABLE justificatifs
  ADD COLUMN transaction_id INT UNSIGNED NULL AFTER recette_id,
  ADD KEY ix_justif_transaction (transaction_id),
  ADD CONSTRAINT fk_justif_transaction FOREIGN KEY (transaction_id)
      REFERENCES transactions_brutes (id) ON DELETE SET NULL;
