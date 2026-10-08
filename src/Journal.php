<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Journal des événements, en ajout seul et chaîné par empreinte.
 *
 * Exigence d'inaltérabilité de l'article 286, I bis du CGI : une écriture
 * enregistrée ne doit pas pouvoir être modifiée ni supprimée sans trace.
 * Chaque acte comptable (création, rectification, annulation, clôture,
 * archivage) dépose ici une entrée dont l'empreinte SHA-256 couvre à la
 * fois son propre contenu et l'empreinte de l'entrée précédente.
 *
 * Conséquence : retirer ou retoucher une entrée a posteriori casse le
 * chaînage à partir de ce point, ce que verifier() détecte en rejouant la
 * chaîne. Le journal ne prouve pas qu'une base n'a jamais été touchée — une
 * réécriture complète et cohérente reste théoriquement possible pour qui a
 * la main sur le serveur — mais il rend toute retouche partielle visible,
 * ce qui est précisément ce que la réglementation exige d'un logiciel.
 *
 * Le journal n'a volontairement aucune méthode de modification ni de
 * suppression : la seule écriture possible est l'ajout.
 */
final class Journal
{
    /** Empreinte conventionnelle de départ, avant toute entrée. */
    public const GENESE = '0000000000000000000000000000000000000000000000000000000000000000';

    public const TYPES = [
        'creation'      => 'Création',
        'rectification' => 'Rectification',
        'annulation'    => 'Annulation',
        'cloture'       => 'Clôture',
        'reouverture'   => 'Réouverture',
        'archivage'     => 'Archivage',
    ];

    /**
     * Consigne un événement. À appeler dans la même transaction que l'acte
     * qu'il décrit, pour qu'un échec de l'un annule l'autre.
     *
     * @param array $donnees état avant/après, librement structuré
     */
    public static function consigner(
        int $userId, string $type, string $objet, ?int $objetId,
        ?int $exerciceId, array $donnees
    ): string {
        $dernier = Database::un(
            'SELECT sequence, empreinte FROM journal_evenements
              WHERE user_id = :u ORDER BY sequence DESC LIMIT 1 FOR UPDATE',
            ['u' => $userId]
        );

        $sequence  = (int) ($dernier['sequence'] ?? 0) + 1;
        $precedent = $dernier['empreinte'] ?? self::GENESE;

        $u = Auth::utilisateur();
        $acteur = $u ? ($u['nom'] . ' <' . $u['email'] . '>') : 'système';

        // Microsecondes : deux actes dans la même seconde doivent rester ordonnés.
        $horodatage = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $charge = json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($charge === false) {
            $charge = json_encode(['erreur_serialisation' => true]);
        }

        $empreinte = self::empreinte($sequence, $horodatage, $type, $objet, $objetId,
                                     $exerciceId, $acteur, (string) $charge, $precedent);

        Database::inserer('journal_evenements', [
            'user_id'              => $userId,
            'sequence'             => $sequence,
            'horodatage'           => $horodatage,
            'type'                 => $type,
            'objet'                => $objet,
            'objet_id'             => $objetId,
            'exercice_id'          => $exerciceId,
            'acteur'               => mb_substr($acteur, 0, 190),
            'donnees'              => $charge,
            'empreinte_precedente' => $precedent,
            'empreinte'            => $empreinte,
        ]);

        return $empreinte;
    }

    /**
     * Empreinte d'une entrée. Les champs sont concaténés dans un ordre fixe,
     * séparés par un caractère qui ne peut pas apparaître dans les valeurs,
     * pour qu'aucune combinaison de contenus ne puisse produire la même
     * chaîne qu'une autre.
     */
    private static function empreinte(
        int $sequence, string $horodatage, string $type, string $objet, ?int $objetId,
        ?int $exerciceId, string $acteur, string $charge, string $precedent
    ): string {
        return hash('sha256', implode("\x1F", [
            (string) $sequence, $horodatage, $type, $objet,
            (string) ($objetId ?? ''), (string) ($exerciceId ?? ''),
            $acteur, $charge, $precedent,
        ]));
    }

    /**
     * Rejoue la chaîne et signale la première rupture.
     *
     * @return array{intacte:bool, nb:int, rupture:?array, derniere_empreinte:?string}
     */
    public static function verifier(int $userId): array
    {
        $precedent = self::GENESE;
        $attendue  = 1;
        $nb = 0;
        $derniere = null;

        $lignes = Database::tous(
            'SELECT * FROM journal_evenements WHERE user_id = :u ORDER BY sequence',
            ['u' => $userId]
        );

        foreach ($lignes as $l) {
            $nb++;
            $sequence = (int) $l['sequence'];

            if ($sequence !== $attendue) {
                return ['intacte' => false, 'nb' => $nb, 'derniere_empreinte' => $derniere,
                    'rupture' => ['sequence' => $sequence, 'motif' => 'trou dans la numérotation',
                        'detail' => 'La séquence ' . $attendue . ' est absente : une entrée a été retirée.']];
            }

            if ($l['empreinte_precedente'] !== $precedent) {
                return ['intacte' => false, 'nb' => $nb, 'derniere_empreinte' => $derniere,
                    'rupture' => ['sequence' => $sequence, 'motif' => 'chaînage rompu',
                        'detail' => 'Cette entrée ne se rattache pas à la précédente.']];
            }

            $recalculee = self::empreinte(
                $sequence, $l['horodatage'], $l['type'], $l['objet'],
                $l['objet_id'] !== null ? (int) $l['objet_id'] : null,
                $l['exercice_id'] !== null ? (int) $l['exercice_id'] : null,
                $l['acteur'], $l['donnees'], $l['empreinte_precedente']
            );

            if (!hash_equals($l['empreinte'], $recalculee)) {
                return ['intacte' => false, 'nb' => $nb, 'derniere_empreinte' => $derniere,
                    'rupture' => ['sequence' => $sequence, 'motif' => 'contenu altéré',
                        'detail' => 'Le contenu de cette entrée ne correspond plus à son empreinte.']];
            }

            $precedent = $l['empreinte'];
            $derniere  = $l['empreinte'];
            $attendue++;
        }

        return ['intacte' => true, 'nb' => $nb, 'rupture' => null, 'derniere_empreinte' => $derniere];
    }

    /** @return array<int,array> entrées les plus récentes d'abord */
    public static function lister(int $userId, ?int $exerciceId = null, int $limite = 200): array
    {
        $sql = 'SELECT * FROM journal_evenements WHERE user_id = :u';
        $p = ['u' => $userId];
        if ($exerciceId !== null) {
            // Les événements sans exercice — une immobilisation s'amortit sur
            // plusieurs — resteraient invisibles dans toute vue filtrée : on
            // les joint à l'exercice consulté plutôt que de les perdre.
            $sql .= ' AND (exercice_id = :e OR exercice_id IS NULL)';
            $p['e'] = $exerciceId;
        }
        return Database::tous($sql . ' ORDER BY sequence DESC LIMIT ' . (int) $limite, $p);
    }

    /** Historique complet d'une écriture donnée, du plus ancien au plus récent. */
    public static function pourObjet(int $userId, string $objet, int $objetId): array
    {
        return Database::tous(
            'SELECT * FROM journal_evenements
              WHERE user_id = :u AND objet = :o AND objet_id = :i ORDER BY sequence',
            ['u' => $userId, 'o' => $objet, 'i' => $objetId]
        );
    }

    public static function nb(int $userId): int
    {
        return (int) Database::valeur(
            'SELECT COUNT(*) FROM journal_evenements WHERE user_id = :u', ['u' => $userId], 0
        );
    }

    public static function derniereSequence(int $userId): int
    {
        return (int) Database::valeur(
            'SELECT COALESCE(MAX(sequence),0) FROM journal_evenements WHERE user_id = :u',
            ['u' => $userId], 0
        );
    }
}
