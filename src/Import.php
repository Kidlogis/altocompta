<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Intégration d'opérations bancaires dans le staging, quelle qu'en soit
 * la provenance : import manuel d'un CSV ou d'un OFX, ou récupération par
 * un connecteur.
 *
 * Idempotence : un même relevé peut être réimporté sans créer de doublon, ce
 * qui permet aux connecteurs de reprendre quelques jours en arrière à chaque
 * passage. L'identité d'une opération se détermine de deux façons :
 *
 *  - quand la source en fournit un identifiant fiable (Altoviz, Evoliz,
 *    FITID d'un OFX), c'est lui qui fait foi, quel que soit le libellé ou la date — une
 *    opération dont le libellé s'enrichit entre deux passages reste la même ;
 *
 *  - sinon, par son contenu (date, montant, libellé, compte) ET son rang
 *    d'occurrence : deux virements identiques le même jour sont deux
 *    opérations, pas un doublon. Un contenu seul les confondait, et la
 *    seconde était écartée comme « déjà connue ».
 *
 * Un identifiant de CSV n'est pas retenu : beaucoup de banques y mettent un
 * simple numéro de ligne, qui se répéterait d'un export à l'autre.
 */
final class Import
{
    /**
     * @param array<int,array> $lignes opérations normalisées
     * @return array{nouveaux:int, doublons:int, hors_exercice:int}
     */
    public static function integrer(int $userId, array $lignes, ?int $connexionId, string $source,
                                    ?int $compteId = null): array
    {
        $regles = Regle::actives($userId);
        $nouveaux = $doublons = $horsExercice = 0;
        $exercices = [];
        $rangs = [];   // contenu normalisé => occurrences déjà vues dans cet appel

        foreach ($lignes as $l) {
            $annee = substr($l['date_operation'], 0, 4);
            if (!array_key_exists($annee, $exercices)) {
                $exercices[$annee] = Exercice::pourDate($userId, $l['date_operation']);
            }
            $ex = $exercices[$annee];

            // Une opération hors de tout exercice n'a nulle part où aller.
            if ($ex === null) {
                $horsExercice++;
                continue;
            }

            $identifiant = self::identifiantFiable($source, $l);
            if ($identifiant !== null) {
                $compteCle = $source === 'ofx' ? (string) ($l['compte'] ?? '') : '';
                if (self::dejaConnue($userId, $source, $identifiant, $compteCle, $l)) {
                    $doublons++;
                    continue;
                }
                $empreinte = Crypto::empreinteIdentifiant($userId, $source, $compteCle, $identifiant);
            } else {
                $cle = $l['date_operation'] . '|' . number_format((float) $l['montant'], 2, '.', '') . '|'
                     . mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $l['libelle'])) ?? '')
                     . '|' . ($l['compte'] ?? '');
                $rangs[$cle] = ($rangs[$cle] ?? 0) + 1;
                $empreinte = Crypto::empreinteTransaction(
                    $userId, $l['date_operation'], (string) $l['montant'], $l['libelle'],
                    $l['compte'] ?? null, $rangs[$cle]
                );
            }

            // Rattachement au compte : celui choisi à l'import s'il est imposé,
            // sinon reconnu d'après le libellé de compte porté par le relevé.
            $compte = $compteId ?? CompteBancaire::reconnaitre($userId, $l['compte'] ?? null);

            $regle = Regle::appliquer($regles, $l);

            // Sans règle personnelle, la nomenclature métier sert de filet :
            // un prélèvement URSSAF tombe en charges sociales sans rien configurer.
            $categorie = null;
            $poste     = $regle['poste_2035'] ?? null;
            $type      = $regle['cible_type'] ?? self::sensParDefaut((float) $l['montant']);

            if ($poste === null && in_array($type, ['recette', 'depense'], true)) {
                $categorie = CategorieMetier::deviner($userId, $l['libelle'], $type);
                if ($categorie !== null) {
                    $poste = $categorie['poste_2035'];
                }
            }

            $st = Database::executer(
                'INSERT IGNORE INTO transactions_brutes
                   (user_id, exercice_id, connexion_id, source, empreinte, external_id,
                    date_operation, libelle, montant, compte, compte_id, statut,
                    suggestion_type, suggestion_poste, suggestion_categorie, suggestion_regle, importe_le)
                 VALUES (:u, :e, :c, :src, :emp, :ext, :dat, :lib, :mnt, :cpt, :cid, "nouveau",
                         :stype, :sposte, :scat, :sregle, :imp)',
                [
                    'u' => $userId, 'e' => (int) $ex['id'], 'c' => $connexionId, 'src' => $source,
                    'emp' => $empreinte, 'ext' => $l['external_id'] ?? null,
                    'dat' => $l['date_operation'], 'lib' => $l['libelle'], 'mnt' => $l['montant'],
                    'cpt' => $l['compte'] ?? null, 'cid' => $compte,
                    'stype'  => $type,
                    'sposte' => $poste,
                    'scat'   => $categorie['id'] ?? null,
                    'sregle' => $regle['id'] ?? null,
                    'imp' => date('Y-m-d H:i:s'),
                ]
            );

            if ($st->rowCount() > 0) {
                $nouveaux++;
                if ($regle !== null) {
                    Database::executer(
                        // user_id redondant ici, la règle venant déjà de Regle::actives(),
                        // mais le filtre coûte moins cher qu'une régression future.
                        'UPDATE regles_affectation SET nb_appliquee = nb_appliquee + 1
                          WHERE id = :i AND user_id = :u',
                        ['i' => $regle['id'], 'u' => $userId]
                    );
                }
            } else {
                $doublons++;
            }
        }

        return ['nouveaux' => $nouveaux, 'doublons' => $doublons, 'hors_exercice' => $horsExercice];
    }

    /** Identifiant fourni par la source, s'il est de ceux sur lesquels on peut s'appuyer. */
    private static function identifiantFiable(string $source, array $ligne): ?string
    {
        if (!in_array($source, ['altoviz', 'evoliz', 'ofx'], true)) {
            return null;
        }
        $id = trim((string) ($ligne['external_id'] ?? ''));
        return $id !== '' ? mb_substr($id, 0, 64) : null;
    }

    /**
     * Une opération portant cet identifiant existe-t-elle déjà ?
     *
     * Les lignes importées avant que l'identifiant ne serve à quelque chose
     * n'en portent pas toujours : on les reconnaît alors à leur contenu et on
     * leur rattache l'identifiant au passage. Sans cela, la première
     * synchronisation après la mise à jour réimporterait en double la
     * semaine de recouvrement.
     */
    private static function dejaConnue(int $userId, string $source, string $identifiant, string $compteCle, array $l): bool
    {
        $sql = 'SELECT id FROM transactions_brutes WHERE user_id = :u AND source = :s AND external_id = :x';
        $p = ['u' => $userId, 's' => $source, 'x' => $identifiant];
        if ($source === 'ofx') {
            $sql .= " AND COALESCE(compte, '') = :c";
            $p['c'] = $compteCle;
        }
        if (Database::valeur($sql . ' LIMIT 1', $p) !== null) {
            return true;
        }

        $ancienne = Database::valeur(
            'SELECT id FROM transactions_brutes
              WHERE user_id = :u AND source = :s AND external_id IS NULL AND empreinte = :e LIMIT 1',
            ['u' => $userId, 's' => $source, 'e' => Crypto::empreinteTransaction(
                $userId, $l['date_operation'], (string) $l['montant'], $l['libelle'], $l['compte'] ?? null
            )]
        );
        if ($ancienne !== null) {
            Database::modifier('transactions_brutes', ['external_id' => $identifiant],
                'id = :i AND user_id = :u', ['i' => (int) $ancienne, 'u' => $userId]);
            return true;
        }
        return false;
    }

    private static function sensParDefaut(float $montant): string
    {
        return $montant >= 0 ? 'recette' : 'depense';
    }

    /**
     * Lit un relevé et l'intègre. Le format est déduit du contenu, pas de
     * l'extension : un .qfx reste de l'OFX, et certaines banques servent du
     * CSV sous une autre extension.
     *
     * @return array{ok:bool, message:string, nouveaux:int}
     */
    public static function depuisFichier(int $userId, string $contenu, ?int $compteId = null): array
    {
        if (OfxParser::estOfx($contenu)) {
            $a = OfxParser::analyser($contenu);
            $source = 'ofx';
            $manque = 'Aucune opération lisible dans ce fichier OFX : aucun bloc de transaction exploitable.';
        } else {
            $a = ReleveParser::analyser($contenu);
            $source = 'csv';
            $manque = 'Aucune opération lisible dans ce fichier. Colonnes attendues : une date, un libellé, '
                    . 'et un montant (ou deux colonnes crédit et débit).';
        }

        if (!$a['lignes']) {
            return ['ok' => false, 'message' => $manque, 'nouveaux' => 0];
        }

        $res = self::integrer($userId, $a['lignes'], null, $source, $compteId);
        $msg = sprintf('%d opération(s) lue(s) au format %s, %d nouvelle(s), %d déjà connue(s).',
            count($a['lignes']), mb_strtoupper($source), $res['nouveaux'], $res['doublons']);
        if ($res['hors_exercice'] > 0) {
            $msg .= sprintf(' %d hors de tout exercice existant, à créer d\'abord.', $res['hors_exercice']);
        }

        // Un virement entre deux comptes ne se voit qu'une fois les deux
        // moitiés importées : la détection tourne donc après l'intégration.
        $ex = Exercice::actif($userId);
        $paires = VirementInterne::detecter($userId, (int) $ex['id']);
        if ($paires > 0) {
            $msg .= sprintf(' %d virement(s) interne(s) repéré(s).', $paires);
        }

        $dbl = Doublons::compter($userId);
        if ($dbl > 0) {
            $msg .= sprintf(' %d doublon(s) probable(s) à examiner.', $dbl);
        }

        return ['ok' => true, 'message' => $msg, 'nouveaux' => $res['nouveaux']];
    }
}
