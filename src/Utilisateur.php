<?php
declare(strict_types=1);

namespace Bnc;

/** Comptes utilisateurs, gérés depuis l'interface. */
final class Utilisateur
{
    public const LONGUEUR_MIN = 12;

    public static function lister(): array
    {
        return Database::tous(
            'SELECT u.id, u.email, u.nom, u.denomination, u.role, u.actif,
                    u.derniere_connexion, u.cree_le,
                    (SELECT COUNT(*) FROM exercices e WHERE e.user_id = u.id) AS nb_exercices
               FROM users u ORDER BY u.nom'
        );
    }

    public static function trouver(int $id): ?array
    {
        return Database::un('SELECT * FROM users WHERE id = :i', ['i' => $id]);
    }

    public static function estAdmin(?array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /** @return array{0:?int, 1:array<int,string>} identifiant créé, erreurs */
    public static function creer(array $d, int $parId): array
    {
        $e = [];
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        $nom   = trim((string) ($d['nom'] ?? ''));
        $mdp   = (string) ($d['mot_de_passe'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $e[] = 'Adresse e-mail invalide.';
        if ($nom === '')                                 $e[] = 'Le nom est obligatoire.';
        if (strlen($mdp) < self::LONGUEUR_MIN)           $e[] = 'Le mot de passe doit faire ' . self::LONGUEUR_MIN . ' caractères au minimum.';
        if ($mdp !== (string) ($d['confirmation'] ?? '')) $e[] = 'Les deux mots de passe ne correspondent pas.';

        if (!$e && Database::valeur('SELECT id FROM users WHERE email = :e', ['e' => $email])) {
            $e[] = 'Un compte existe déjà avec cette adresse.';
        }
        if ($e) {
            return [null, $e];
        }

        $id = Database::inserer('users', [
            'email'        => $email,
            'mot_de_passe' => password_hash($mdp, PASSWORD_DEFAULT),
            'nom'          => mb_substr($nom, 0, 120),
            'denomination' => mb_substr(trim((string) ($d['denomination'] ?? '')), 0, 190) ?: null,
            'siret'        => self::siret($d['siret'] ?? null),
            'role'         => ($d['role'] ?? 'user') === 'admin' ? 'admin' : 'user',
            'actif'        => 1,
            'cree_par'     => $parId,
            'cree_le'      => date('Y-m-d H:i:s'),
        ]);

        // Un compte sans exercice ouvert ne peut rien enregistrer.
        Exercice::creer($id, (int) date('Y'));
        return [$id, []];
    }

    // --- Fiche entreprise -----------------------------------------------

    public const REGIMES_TVA = [
        'exonere_261' => 'Exonéré — formation professionnelle (art. 261-4-4° CGI)',
        'franchise'   => 'Franchise en base (art. 293 B CGI)',
        'assujetti'   => 'Assujetti à la TVA',
    ];

    /**
     * Colonnes de la fiche entreprise, hors identité de connexion. Liste unique,
     * partagée par la lecture du profil (Auth::utilisateur), l'archive
     * d'exercice et le test qui la rapproche de valider() : une colonne
     * ajoutée à l'une sans les autres serait enregistrée mais jamais relue.
     */
    public const COLONNES_PROFIL = [
        'denomination', 'siret', 'forme_juridique', 'profession', 'date_debut_activite',
        'code_ape', 'tva_regime', 'tva_numero', 'nda', 'certifications',
        'adresse_1', 'adresse_2', 'code_postal', 'ville', 'pays',
        'telephone', 'email_pro', 'site_web', 'sie', 'aga_nom', 'aga_numero',
        'assurance_nom', 'assurance_contrat',
    ];

    /** Champs texte libres et leur longueur maximale : source unique, qui sert à valider comme à enregistrer. */
    private const CHAMPS_TEXTE = [
        'denomination' => 190, 'forme_juridique' => 100, 'profession' => 190, 'certifications' => 255,
        'adresse_1' => 190, 'adresse_2' => 190, 'ville' => 120, 'pays' => 60,
        'sie' => 190, 'aga_nom' => 190, 'aga_numero' => 40,
        'assurance_nom' => 190, 'assurance_contrat' => 60,
    ];

    /**
     * Valide et normalise la fiche entreprise, sans toucher à la base.
     *
     * Les erreurs bloquent l'enregistrement ; les avertissements, non : une
     * fiche douteuse reste enregistrable, parce que le logiciel n'a pas à
     * interdire à quelqu'un de saisir ce qu'il sait mieux que lui — il
     * signale seulement ce qui ressemble à une faute de frappe.
     *
     * @return array{0:array<string,mixed>, 1:array<int,string>, 2:array<int,string>}
     *         données normalisées, erreurs, avertissements
     */
    public static function valider(array $d): array
    {
        $out = [];
        $err = [];
        $avert = [];

        $nom = trim((string) ($d['nom'] ?? ''));
        if ($nom === '') {
            $err[] = 'Le nom est obligatoire.';
        }
        $out['nom'] = mb_substr($nom, 0, 120);

        foreach (self::CHAMPS_TEXTE as $champ => $max) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($d[$champ] ?? '')) ?? '');
            $out[$champ] = $v === '' ? null : mb_substr($v, 0, $max);
        }

        // SIRET : 14 chiffres ; la clé de contrôle n'est qu'un avertissement,
        // car des établissements légitimes y échappent (ceux de La Poste).
        $brutSiret = (string) ($d['siret'] ?? '');
        $out['siret'] = null;
        if (trim($brutSiret) !== '') {
            $siret = self::siret($brutSiret);
            if ($siret === null) {
                $err[] = 'Le SIRET doit comporter 14 chiffres.';
            } else {
                $out['siret'] = $siret;
                if (!self::luhn($siret)) {
                    $avert[] = 'La clé de contrôle de ce SIRET ne correspond pas : vérifier la saisie.';
                }
            }
        }

        // Code APE : 4 chiffres et une lettre, saisi avec ou sans point.
        $out['code_ape'] = null;
        $ape = strtoupper((string) preg_replace('/[\s.\-]/', '', (string) ($d['code_ape'] ?? '')));
        if ($ape !== '') {
            if (preg_match('/^\d{4}[A-Z]$/', $ape)) {
                $out['code_ape'] = $ape;
            } else {
                $err[] = 'Le code APE comporte quatre chiffres suivis d\'une lettre (ex. 8559A).';
            }
        }

        // N° de TVA intracommunautaire : préfixe pays puis 2 à 12 caractères.
        $out['tva_numero'] = null;
        $tva = strtoupper((string) preg_replace('/[\s.\-]/', '', (string) ($d['tva_numero'] ?? '')));
        if ($tva !== '') {
            if (preg_match('/^[A-Z]{2}[A-Z0-9]{2,12}$/', $tva)) {
                $out['tva_numero'] = $tva;
            } else {
                $err[] = 'Le numéro de TVA commence par le code pays (ex. FR) suivi de 2 à 12 caractères.';
            }
        }

        // NDA : onze chiffres.
        $out['nda'] = null;
        $nda = (string) preg_replace('/\D/', '', (string) ($d['nda'] ?? ''));
        if (trim((string) ($d['nda'] ?? '')) !== '') {
            if (strlen($nda) === 11) {
                $out['nda'] = $nda;
            } else {
                $err[] = 'Le numéro de déclaration d\'activité comporte 11 chiffres.';
            }
        }

        $regime = (string) ($d['tva_regime'] ?? '');
        $out['tva_regime'] = null;
        if ($regime !== '') {
            if (isset(self::REGIMES_TVA[$regime])) {
                $out['tva_regime'] = $regime;
            } else {
                $err[] = 'Régime de TVA inconnu.';
            }
        }
        if ($out['tva_regime'] === 'assujetti' && $out['tva_numero'] === null) {
            $avert[] = 'Assujetti à la TVA, mais aucun numéro de TVA renseigné.';
        }

        // Date de début d'activité.
        $out['date_debut_activite'] = null;
        $brutDate = trim((string) ($d['date_debut_activite'] ?? ''));
        if ($brutDate !== '') {
            $date = Montant::lireDate($brutDate);
            if ($date === null) {
                $err[] = 'La date de début d\'activité est illisible.';
            } else {
                $out['date_debut_activite'] = $date;
                if ($date > date('Y-m-d')) {
                    $avert[] = 'La date de début d\'activité est dans le futur.';
                }
            }
        }

        // Adresse : le code postal français a cinq chiffres ; ailleurs, format libre.
        $francais = $out['pays'] === null || preg_match('/^france$/iu', (string) $out['pays']) === 1;
        $cp = trim((string) ($d['code_postal'] ?? ''));
        $out['code_postal'] = null;
        if ($cp !== '') {
            if ($francais && !preg_match('/^\d{5}$/', $cp)) {
                $err[] = 'Le code postal français comporte cinq chiffres.';
            } else {
                $out['code_postal'] = mb_substr($cp, 0, 10);
            }
        }

        $tel = trim((string) ($d['telephone'] ?? ''));
        $out['telephone'] = null;
        if ($tel !== '') {
            if (preg_match('/^[0-9+().\s-]{8,30}$/', $tel)) {
                $out['telephone'] = preg_replace('/\s+/', ' ', $tel);
            } else {
                $err[] = 'Le numéro de téléphone est invalide.';
            }
        }

        $mail = strtolower(trim((string) ($d['email_pro'] ?? '')));
        $out['email_pro'] = null;
        if ($mail !== '') {
            if (filter_var($mail, FILTER_VALIDATE_EMAIL) && strlen($mail) <= 190) {
                $out['email_pro'] = $mail;
            } else {
                $err[] = 'L\'adresse e-mail professionnelle est invalide.';
            }
        }

        $site = trim((string) ($d['site_web'] ?? ''));
        $out['site_web'] = null;
        if ($site !== '') {
            if (!preg_match('#^https?://#i', $site)) {
                $site = 'https://' . $site;
            }
            if (filter_var($site, FILTER_VALIDATE_URL) && strlen($site) <= 190
                && preg_match('#^https?://#i', $site)) {
                $out['site_web'] = $site;
            } else {
                $err[] = 'L\'adresse du site web est invalide.';
            }
        }

        return [$out, $err, $avert];
    }

    /** Clé de contrôle de Luhn, qui s'applique aux SIRET (hors exceptions rares). */
    public static function luhn(string $chiffres): bool
    {
        if ($chiffres === '' || !ctype_digit($chiffres)) {
            return false;
        }
        $somme = 0;
        foreach (array_reverse(str_split($chiffres)) as $i => $c) {
            $v = (int) $c;
            if ($i % 2 === 1) {
                $v *= 2;
                if ($v > 9) {
                    $v -= 9;
                }
            }
            $somme += $v;
        }
        return $somme % 10 === 0;
    }

    /** SIREN : les neuf premiers chiffres du SIRET. */
    public static function siren(?string $siret): ?string
    {
        return $siret !== null && strlen($siret) === 14 ? substr($siret, 0, 9) : null;
    }

    /** Adresse sur une ligne, pour les en-têtes de document. */
    public static function adresseLigne(array $u): string
    {
        $cpVille = trim(((string) ($u['code_postal'] ?? '')) . ' ' . ((string) ($u['ville'] ?? '')));
        $parts = array_filter([
            trim((string) ($u['adresse_1'] ?? '')),
            trim((string) ($u['adresse_2'] ?? '')),
            $cpVille,
        ], static fn($x) => $x !== '');

        $pays = trim((string) ($u['pays'] ?? ''));
        if ($pays !== '' && !preg_match('/^france$/iu', $pays)) {
            $parts[] = $pays;
        }
        return implode(', ', $parts);
    }

    /**
     * Enregistre la fiche entreprise.
     *
     * @return array{erreurs:array<int,string>, avertissements:array<int,string>}
     */
    public static function enregistrerProfil(int $id, array $d): array
    {
        [$donnees, $erreurs, $avertissements] = self::valider($d);
        if ($erreurs) {
            return ['erreurs' => $erreurs, 'avertissements' => []];
        }
        Database::modifier('users', $donnees, 'id = :i', ['i' => $id]);
        return ['erreurs' => [], 'avertissements' => $avertissements];
    }

    /** Compatibilité : ancien point d'entrée, qui ne renvoie que les erreurs. */
    public static function majProfil(int $id, array $d): array
    {
        return self::enregistrerProfil($id, $d)['erreurs'];
    }

    public static function changerMotDePasse(int $id, string $actuel, string $nouveau, string $confirmation): array
    {
        $u = self::trouver($id);
        if ($u === null || !password_verify($actuel, (string) $u['mot_de_passe'])) {
            return ['Le mot de passe actuel est incorrect.'];
        }
        if (strlen($nouveau) < self::LONGUEUR_MIN) {
            return ['Le nouveau mot de passe doit faire ' . self::LONGUEUR_MIN . ' caractères au minimum.'];
        }
        if ($nouveau !== $confirmation) {
            return ['Les deux saisies ne correspondent pas.'];
        }
        if ($nouveau === $actuel) {
            return ['Le nouveau mot de passe doit être différent de l\'ancien.'];
        }

        Database::modifier('users',
            ['mot_de_passe' => password_hash($nouveau, PASSWORD_DEFAULT)],
            'id = :i', ['i' => $id]
        );
        return [];
    }

    /**
     * Active ou désactive un compte. Le dernier administrateur actif ne peut
     * pas être désactivé, sans quoi plus personne ne pourrait administrer.
     */
    public static function basculerActif(int $id, int $parId): array
    {
        if ($id === $parId) {
            return ['Impossible de désactiver son propre compte.'];
        }
        $u = self::trouver($id);
        if ($u === null) {
            return ['Compte introuvable.'];
        }

        if ((int) $u['actif'] === 1 && $u['role'] === 'admin') {
            $autres = (int) Database::valeur(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND actif = 1 AND id <> :i",
                ['i' => $id], 0
            );
            if ($autres === 0) {
                return ['C\'est le dernier administrateur actif : il doit le rester.'];
            }
        }

        Database::executer('UPDATE users SET actif = 1 - actif WHERE id = :i', ['i' => $id]);
        return [];
    }

    public static function reinitialiserMotDePasse(int $id, string $nouveau): array
    {
        if (strlen($nouveau) < self::LONGUEUR_MIN) {
            return ['Le mot de passe doit faire ' . self::LONGUEUR_MIN . ' caractères au minimum.'];
        }
        Database::modifier('users',
            ['mot_de_passe' => password_hash($nouveau, PASSWORD_DEFAULT)],
            'id = :i', ['i' => $id]
        );
        return [];
    }

    private static function siret($brut): ?string
    {
        $s = preg_replace('/\D/', '', (string) $brut) ?? '';
        return strlen($s) === 14 ? $s : null;
    }
}
