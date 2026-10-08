<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Provisions : combien mettre de côté pour les cotisations sociales et l'impôt sur le revenu.
 *
 * Fait partie du cœur, pas d'une extension : tout BNC au réel se pose la question, quel que soit
 * son service bancaire. Les calculs eux-mêmes sont ceux de l'Urssaf (voir Urssaf) ; ce qu'on fait
 * ici est de leur fournir la bonne base, de retenir ce qu'ils répondent, et de ne pas faire dire
 * à une estimation plus qu'elle ne dit.
 *
 * Deux choix de base méritent d'être connus :
 *  - on envoie les charges SANS le poste BW (cotisations sociales personnelles déjà versées) :
 *    le modèle calcule lui-même les cotisations et leur déduction, les lui fournir déduites
 *    reviendrait à les déduire deux fois ;
 *  - un exercice en cours n'est pas un exercice complet : le mode « à ce jour » le dit, et le
 *    mode « projeté » ramène les montants à l'année au prorata des jours écoulés, sans prétendre
 *    à plus qu'une proportionnalité.
 */
final class Provisions
{
    public const MODES = [
        'ce_jour'   => 'À ce jour',
        'annualise' => 'Projeté sur l\'année',
        'libre'     => 'Mes propres montants',
    ];

    public const FAMILLES = [
        'célibataire' => 'Célibataire, divorcé(e) ou séparé(e)',
        'couple'      => 'Marié(e) ou pacsé(e)',
        'veuf'        => 'Veuf ou veuve',
    ];

    /** Sous ce nombre de jours écoulés, une projection ne vaut rien : elle multiplierait un hasard. */
    public const JOURS_MIN_PROJECTION = 30;

    /** Délai minimal entre deux appels d'un même utilisateur, par égard pour un service public gratuit. */
    public const DELAI_ENTRE_APPELS = 15;

    /** Estimations conservées par utilisateur. */
    public const CONSERVEES = 30;

    // --- Paramètres du foyer ---------------------------------------------

    /** @return array{situation_famille:string, enfants:int, parent_isole:bool, autres_revenus:float, mode:string, recettes_libre:?float, charges_libre:?float} */
    public static function parametres(int $userId): array
    {
        $l = Database::un('SELECT * FROM estimation_parametres WHERE user_id = :u', ['u' => $userId]);
        return [
            'situation_famille' => (string) ($l['situation_famille'] ?? 'célibataire'),
            'enfants'           => (int) ($l['enfants'] ?? 0),
            'parent_isole'      => (bool) ($l['parent_isole'] ?? false),
            'autres_revenus'    => (float) ($l['autres_revenus'] ?? 0),
            'mode'              => (string) ($l['mode'] ?? 'annualise'),
            'recettes_libre'    => isset($l['recettes_libre']) ? (float) $l['recettes_libre'] : null,
            'charges_libre'     => isset($l['charges_libre']) ? (float) $l['charges_libre'] : null,
        ];
    }

    /**
     * Valide les paramètres saisis.
     *
     * @return array{0:array, 1:array<int,string>} paramètres normalisés, erreurs
     */
    public static function valider(array $post): array
    {
        $err = [];
        $famille = (string) ($post['famille'] ?? '');
        if (!in_array($famille, Urssaf::FAMILLES, true)) {
            $err[] = 'Situation familiale inconnue.';
            $famille = 'célibataire';
        }
        $enfants = filter_var($post['enfants'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10]]);
        if ($enfants === false) {
            $err[] = 'Le nombre d\'enfants à charge est compris entre 0 et 10.';
            $enfants = 0;
        }
        $autres = self::montant((string) ($post['autres_revenus'] ?? ''), true);
        if ($autres === null || $autres > 10_000_000) {
            $err[] = 'Les autres revenus imposables du foyer doivent être un montant positif.';
            $autres = 0.0;
        }
        $mode = (string) ($post['mode'] ?? '');
        if (!isset(self::MODES[$mode])) {
            $err[] = 'Base de calcul inconnue.';
            $mode = 'annualise';
        }

        $recettes = $charges = null;
        if ($mode === 'libre') {
            $recettes = self::montant((string) ($post['recettes_libre'] ?? ''), false);
            $charges  = self::montant((string) ($post['charges_libre'] ?? ''), true);
            if ($recettes === null || $recettes > 100_000_000) {
                $err[] = 'Saisir un montant de recettes (un nombre positif).';
            }
            if ($charges === null || $charges > 100_000_000) {
                $err[] = 'Saisir un montant de charges (zéro si aucune).';
            }
        }

        return [[
            'situation_famille' => $famille,
            'enfants'           => (int) $enfants,
            'parent_isole'      => !empty($post['parent_isole']) && $enfants > 0 && $famille !== 'couple',
            'autres_revenus'    => (float) $autres,
            'mode'              => $mode,
            'recettes_libre'    => $recettes,
            'charges_libre'     => $charges,
        ], $err];
    }

    /** Montant saisi en français ; vide = 0 si $videEstZero, sinon refus. Jamais négatif. */
    private static function montant(string $brut, bool $videEstZero): ?float
    {
        if (trim($brut) === '') {
            return $videEstZero ? 0.0 : null;
        }
        $v = Montant::lire($brut);
        return ($v === null || $v < 0) ? null : $v;
    }

    public static function enregistrerParametres(int $userId, array $p): void
    {
        Database::executer(
            'INSERT INTO estimation_parametres
               (user_id, situation_famille, enfants, parent_isole, autres_revenus, mode, recettes_libre, charges_libre, maj_le)
             VALUES (:u, :f, :e, :i, :a, :m, :r, :c, :t)
             ON DUPLICATE KEY UPDATE situation_famille = VALUES(situation_famille), enfants = VALUES(enfants),
               parent_isole = VALUES(parent_isole), autres_revenus = VALUES(autres_revenus), mode = VALUES(mode),
               recettes_libre = COALESCE(VALUES(recettes_libre), recettes_libre),
               charges_libre = COALESCE(VALUES(charges_libre), charges_libre), maj_le = VALUES(maj_le)',
            ['u' => $userId, 'f' => $p['situation_famille'], 'e' => $p['enfants'], 'i' => $p['parent_isole'] ? 1 : 0,
             'a' => $p['autres_revenus'], 'm' => $p['mode'], 'r' => $p['recettes_libre'], 'c' => $p['charges_libre'],
             't' => date('Y-m-d H:i:s')]
        );
    }

    // --- Base de calcul (pure) -------------------------------------------

    /**
     * Recettes et charges à envoyer à l'Urssaf.
     *
     * @param array $calc     résultat de Declaration::calculer()
     * @param array $exercice ligne de l'exercice (date_debut, date_fin, statut)
     * @param array{recettes_libre:?float, charges_libre:?float} $libre
     * @return array{recettes:float, charges:float, bw:float, mode:string, mode_effectif:string,
     *               facteur:float, annuel:bool, note:?string, brut_recettes:float, brut_charges:float}
     */
    public static function base(array $calc, array $exercice, string $mode, array $libre, \DateTimeImmutable $aujourdhui): array
    {
        $bw = round((float) ($calc['depenses']['BW']['total'] ?? 0), 2);
        $brutRecettes = round((float) $calc['total_recettes'], 2);
        // Les cotisations personnelles déjà versées sont retirées : le modèle les recalcule.
        $brutCharges = round(max(0.0, (float) $calc['total_depenses'] - $bw), 2);

        $debut = new \DateTimeImmutable($exercice['date_debut']);
        $fin   = new \DateTimeImmutable($exercice['date_fin']);
        $jours = (int) $debut->diff($fin)->days + 1;
        $termine = ($exercice['statut'] ?? '') === 'clos' || $aujourdhui >= $fin;
        $ecoules = $aujourdhui < $debut ? 0 : (int) $debut->diff($termine ? $fin : $aujourdhui)->days + 1;

        $r = [
            'recettes' => $brutRecettes, 'charges' => $brutCharges, 'bw' => $bw, 'mode' => $mode,
            'mode_effectif' => 'ce_jour', 'facteur' => 1.0, 'annuel' => $termine, 'note' => null,
            'brut_recettes' => $brutRecettes, 'brut_charges' => $brutCharges,
        ];

        if ($mode === 'libre' && $libre['recettes_libre'] !== null && $libre['charges_libre'] !== null) {
            return ['recettes' => $libre['recettes_libre'], 'charges' => $libre['charges_libre'],
                    'mode_effectif' => 'libre', 'annuel' => true] + $r;
        }

        if ($mode === 'annualise') {
            if ($termine) {
                $r['note'] = 'Exercice terminé : les montants sont ceux de l\'année entière.';
            } elseif ($ecoules < self::JOURS_MIN_PROJECTION) {
                $r['note'] = 'Moins de ' . self::JOURS_MIN_PROJECTION . ' jours écoulés dans l\'exercice : trop tôt pour projeter, '
                           . 'l\'estimation porte sur les montants à ce jour.';
            } else {
                $f = $jours / $ecoules;
                return ['recettes' => round($brutRecettes * $f, 2), 'charges' => round($brutCharges * $f, 2),
                        'mode_effectif' => 'annualise', 'facteur' => round($f, 4), 'annuel' => true,
                        'note' => 'Projection proportionnelle aux jours écoulés (' . $ecoules . ' sur ' . $jours . ') : '
                                . 'elle ignore la saisonnalité et les gros achats à venir.'] + $r;
            }
        }
        return $r;
    }

    /**
     * Chiffres dérivés d'une réponse de l'Urssaf.
     *
     * @param array<string,?float> $valeurs
     * @param array $base issue de base()
     * @return array<string,mixed>
     */
    public static function chiffres(array $valeurs, array $base): array
    {
        $avant = round((float) $base['recettes'] - (float) $base['charges'], 2);
        $cot   = round((float) $valeurs['cotisations'], 2);
        $imp   = round((float) $valeurs['impot'], 2);
        $total = round($cot + $imp, 2);
        $verse = round((float) ($base['bw'] ?? 0), 2);
        $resteCot = round(max(0.0, $cot - $verse), 2);

        return [
            'avant_cotisations' => $avant,
            'cotisations'       => $cot,
            'resultat_fiscal'   => round((float) $valeurs['resultat_fiscal'], 2),
            'impot'             => $imp,
            'a_prevoir'         => $total,
            'taux'              => $avant > 0 ? round($total / $avant, 4) : null,
            // Un mensuel n'a de sens que sur une année pleine ou projetée.
            'mensuel'           => !empty($base['annuel']) ? round($total / 12, 2) : null,
            'deja_verse'        => $verse,
            'reste_cotisations' => $resteCot,
            'reste_a_mettre'    => round($resteCot + $imp, 2),
            // Déficit : le modèle applique des cotisations minimales, pas zéro.
            'minimales'         => $avant <= 0 && $cot > 0,
        ];
    }

    /**
     * Lignes du détail des cotisations, et ce qui n'y figure pas.
     *
     * Le total vient du modèle et le détail aussi, mais rien ne garantit que ma liste de lignes soit
     * complète : les indemnités journalières, par exemple, m'avaient échappé — 90 € d'écart sur un cas
     * de 10 977 €. Plutôt que d'afficher un détail qui ne totalise pas, la différence est montrée.
     *
     * @param array<string,?float> $valeurs
     * @return array{lignes:array<string,float>, autres:float}
     */
    public static function detailCotisations(array $valeurs): array
    {
        $lignes = [];
        foreach (Urssaf::DETAIL as $cle => $libelle) {
            $v = $valeurs[$cle] ?? null;
            if ($v !== null && abs((float) $v) >= 0.5) {
                $lignes[$libelle] = round((float) $v, 2);
            }
        }
        $ecart = round((float) $valeurs['cotisations'] - array_sum($lignes), 2);
        return ['lignes' => $lignes, 'autres' => abs($ecart) >= 1.0 ? $ecart : 0.0];
    }

    // --- Estimation et mémoire -------------------------------------------

    /**
     * Interroge l'Urssaf et conserve le résultat, réussi ou non.
     *
     * @return array{ok:bool, message:string}
     */
    public static function estimer(int $userId, array $exercice, array $calc, array $p, ?string $debutActivite, \DateTimeImmutable $maintenant): array
    {
        // Le délai se calcule entièrement côté PHP : « cree_le » est écrit par PHP dans son fuseau, et le
        // comparer à l'heure de la base (UNIX_TIMESTAMP, NOW()) mélange deux fuseaux. Avec Paris côté PHP
        // et UTC côté base, la dernière estimation paraîtrait dater d'il y a moins deux heures.
        $dernier = Database::valeur('SELECT MAX(cree_le) FROM estimations WHERE user_id = :u', ['u' => $userId]);
        $depuis = $dernier !== null ? strtotime((string) $dernier) : false;
        if ($depuis !== false && (time() - $depuis) < self::DELAI_ENTRE_APPELS) {
            return ['ok' => false, 'message' => 'Une estimation vient d\'être demandée : patienter quelques secondes avant de recommencer.'];
        }

        $base = self::base($calc, $exercice, $p['mode'], $p, $maintenant);
        $situation = Urssaf::situation([
            'recettes' => $base['recettes'], 'charges' => $base['charges'], 'debut_activite' => $debutActivite,
            'famille' => $p['situation_famille'], 'enfants' => $p['enfants'], 'parent_isole' => $p['parent_isole'],
            'autres_revenus' => $p['autres_revenus'],
        ]);
        $res = Urssaf::evaluer($situation);

        Database::inserer('estimations', [
            'user_id'     => $userId,
            'exercice_id' => (int) $exercice['id'],
            'cree_le'     => date('Y-m-d H:i:s'),
            'mode'        => $base['mode_effectif'],
            'statut'      => $res['ok'] ? 'ok' : 'erreur',
            'message'     => $res['ok'] ? null : mb_substr((string) $res['erreur'], 0, 500),
            'entree'      => json_encode($base + ['parametres' => $p, 'debut_activite' => $debutActivite], JSON_UNESCAPED_UNICODE),
            'situation'   => json_encode($situation, JSON_UNESCAPED_UNICODE),
            'resultat'    => $res['ok'] ? json_encode([
                'valeurs' => $res['valeurs'], 'manquantes' => $res['manquantes'], 'avertissements' => $res['avertissements'],
            ], JSON_UNESCAPED_UNICODE) : null,
        ]);
        Database::executer(
            'DELETE FROM estimations WHERE user_id = :u AND id NOT IN (
                SELECT id FROM (SELECT id FROM estimations WHERE user_id = :u2 ORDER BY id DESC LIMIT ' . self::CONSERVEES . ') r)',
            ['u' => $userId, 'u2' => $userId]
        );

        return $res['ok']
            ? ['ok' => true,  'message' => 'Estimation mise à jour.']
            : ['ok' => false, 'message' => (string) $res['erreur']];
    }

    /** Dernière estimation de l'exercice, décodée ; $statut = 'ok' pour ignorer les échecs. */
    public static function derniere(int $userId, int $exerciceId, ?string $statut = null): ?array
    {
        $l = Database::un(
            'SELECT * FROM estimations WHERE user_id = :u AND exercice_id = :e'
            . ($statut !== null ? ' AND statut = :s' : '') . ' ORDER BY id DESC LIMIT 1',
            ['u' => $userId, 'e' => $exerciceId] + ($statut !== null ? ['s' => $statut] : [])
        );
        return $l === null ? null : self::decoder($l);
    }

    /** @return array<int,array> les plus récentes d'abord */
    public static function historique(int $userId, int $exerciceId, int $limite = 6): array
    {
        return array_map([self::class, 'decoder'], Database::tous(
            'SELECT * FROM estimations WHERE user_id = :u AND exercice_id = :e AND statut = \'ok\' ORDER BY id DESC LIMIT ' . (int) $limite,
            ['u' => $userId, 'e' => $exerciceId]
        ));
    }

    private static function decoder(array $l): array
    {
        $l['entree']    = json_decode((string) $l['entree'], true) ?: [];
        $l['situation'] = json_decode((string) $l['situation'], true) ?: [];
        $l['resultat']  = json_decode((string) ($l['resultat'] ?? ''), true) ?: [];
        return $l;
    }

    /** Les écritures ont-elles changé depuis cette estimation ? */
    public static function perimee(array $estimation, array $baseActuelle): bool
    {
        // En saisie libre, l'estimation porte sur les montants saisis : les écritures n'y changent rien.
        if (($estimation['mode'] ?? '') === 'libre') {
            return false;
        }
        $e = $estimation['entree'] ?? [];
        return abs((float) ($e['brut_recettes'] ?? 0) - (float) $baseActuelle['brut_recettes']) > 1.0
            || abs((float) ($e['brut_charges'] ?? 0) - (float) $baseActuelle['brut_charges']) > 1.0;
    }
}
