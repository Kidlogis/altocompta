<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Inscription des écritures d'annulation au registre.
 *
 * Isolée dans sa propre classe pour une raison de déploiement : la reprise des
 * corrections antérieures s'exécute pendant une mise à jour, dans une requête
 * qui peut avoir déjà chargé en mémoire l'ancienne version de Recette ou de
 * Depense — sans la méthode dont la reprise a besoin. Une classe qui n'existait
 * pas encore ne peut pas être périmée : elle est lue sur le disque, version
 * neuve, au premier appel.
 */
final class Corrections
{
    /**
     * Écriture d'annulation d'une recette déjà marquée annulée : montant opposé,
     * même date (pour compenser dans la même période), numéro suivant.
     *
     * @return array{id:int, numero:int}
     */
    public static function recette(int $userId, array $origine, string $motif): array
    {
        $exerciceId = (int) $origine['exercice_id'];

        $numero = (int) Database::valeur(
            'SELECT COALESCE(MAX(numero_chrono),0) + 1 FROM recettes
              WHERE user_id = :u AND exercice_id = :e FOR UPDATE',
            ['u' => $userId, 'e' => $exerciceId], 1
        );

        $id = Database::inserer('recettes', [
            'user_id'           => $userId,
            'exercice_id'       => $exerciceId,
            'numero_chrono'     => $numero,
            'date_encaissement' => $origine['date_encaissement'],
            'client'            => $origine['client'],
            'nature'            => mb_substr('Annulation de la recette n° '
                                   . str_pad((string) $origine['numero_chrono'], 3, '0', STR_PAD_LEFT), 0, 190),
            'montant'           => -1 * (float) $origine['montant'],
            'est_avoir'         => (int) $origine['est_avoir'],
            'mode_reglement'    => $origine['mode_reglement'],
            'poste_2035'        => $origine['poste_2035'],
            'categorie_id'      => $origine['categorie_id'],
            'statut'            => 'annulation',
            'contrepasse_id'    => (int) $origine['id'],
            'motif_annulation'  => $motif !== '' ? mb_substr($motif, 0, 255) : null,
            'reference_facture' => $origine['reference_facture'],
            'notes'             => $motif !== '' ? mb_substr($motif, 0, 255) : null,
            'cree_le'           => date('Y-m-d H:i:s'),
        ] + Tva::oppose($origine, Tva::CHAMPS_RECETTE));

        return ['id' => $id, 'numero' => $numero];
    }

    /**
     * Écriture d'annulation d'une dépense déjà marquée annulée.
     *
     * @return array{id:int}
     */
    public static function depense(int $userId, array $origine, string $motif): array
    {
        $id = Database::inserer('depenses', [
            'user_id'           => $userId,
            'exercice_id'       => (int) $origine['exercice_id'],
            'date_decaissement' => $origine['date_decaissement'],
            'fournisseur'       => $origine['fournisseur'],
            'libelle'           => mb_substr('Annulation : ' . $origine['libelle'], 0, 255),
            'montant'           => -1 * (float) $origine['montant'],
            'est_remboursement' => (int) $origine['est_remboursement'],
            'poste_2035'        => $origine['poste_2035'],
            'categorie_id'      => $origine['categorie_id'],
            'quote_part_pro'    => (int) $origine['quote_part_pro'],
            'mode_reglement'    => $origine['mode_reglement'],
            'statut'            => 'annulation',
            'contrepasse_id'    => (int) $origine['id'],
            'motif_annulation'  => $motif !== '' ? mb_substr($motif, 0, 255) : null,
            'notes'             => $motif !== '' ? $motif : null,
            'cree_le'           => date('Y-m-d H:i:s'),
        ] + Tva::oppose($origine, Tva::CHAMPS_DEPENSE));

        return ['id' => $id];
    }
}
