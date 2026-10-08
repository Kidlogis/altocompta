<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Chiffrement des secrets stockés en base (clés API des connecteurs).
 *
 * libsodium (secretbox, XSalsa20-Poly1305) quand l'extension est là,
 * repli AES-256-GCM via OpenSSL sinon. Les deux sont authentifiés :
 * une donnée altérée est rejetée, pas déchiffrée de travers.
 */
final class Crypto
{
    private const MARQUE_SODIUM = "\x01";
    private const MARQUE_OPENSSL = "\x02";

    public static function genererCleMaitre(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function cleMaitre(): string
    {
        $brut = base64_decode((string) Config::requis('cle_maitre'), true);
        if ($brut === false || strlen($brut) !== 32) {
            throw new \RuntimeException(
                'cle_maitre invalide : 32 octets encodés en base64 attendus. Générer avec bin/generer-cle.php'
            );
        }
        return $brut;
    }

    public static function chiffrer(string $clair): string
    {
        $cle = self::cleMaitre();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return self::MARQUE_SODIUM . $nonce . sodium_crypto_secretbox($clair, $nonce, $cle);
        }

        $iv = random_bytes(12);
        $tag = '';
        $chiffre = openssl_encrypt($clair, 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $iv, $tag);
        if ($chiffre === false) {
            throw new \RuntimeException('Échec du chiffrement.');
        }
        return self::MARQUE_OPENSSL . $iv . $tag . $chiffre;
    }

    public static function dechiffrer(string $paquet): string
    {
        if ($paquet === '') {
            throw new \RuntimeException('Paquet chiffré vide.');
        }
        $marque = $paquet[0];
        $corps  = substr($paquet, 1);
        $cle    = self::cleMaitre();

        if ($marque === self::MARQUE_SODIUM) {
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $clair = sodium_crypto_secretbox_open(substr($corps, $n), substr($corps, 0, $n), $cle);
        } elseif ($marque === self::MARQUE_OPENSSL) {
            $clair = openssl_decrypt(
                substr($corps, 28), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA,
                substr($corps, 0, 12), substr($corps, 12, 16)
            );
        } else {
            throw new \RuntimeException('Format de paquet chiffré inconnu.');
        }

        if ($clair === false) {
            throw new \RuntimeException('Déchiffrement impossible : clé maître changée ou donnée altérée.');
        }
        return $clair;
    }

    /**
     * Empreinte déterministe d'une opération bancaire — garantit l'idempotence des imports.
     *
     * Le rang distingue les occurrences d'une même opération : deux virements
     * de même date, même montant et même libellé sont deux opérations, pas un
     * doublon. Le rang 1 donne exactement l'empreinte d'avant l'introduction du
     * rang, de sorte que les données déjà importées restent reconnues.
     */
    public static function empreinteTransaction(
        int $userId, string $date, string $montant, string $libelle, ?string $compte = null, int $rang = 1
    ): string {
        $champs = [
            $userId,
            $date,
            number_format((float) $montant, 2, '.', ''),
            mb_strtolower(preg_replace('/\s+/u', ' ', trim($libelle)) ?? ''),
            $compte ?? '',
        ];
        if ($rang > 1) {
            $champs[] = '#' . $rang;
        }
        return hash('sha256', implode('|', $champs));
    }

    /** Empreinte d'une opération identifiée par la source elle-même (Altoviz, Evoliz, FITID d'un OFX). */
    public static function empreinteIdentifiant(int $userId, string $source, string $compte, string $identifiant): string
    {
        return hash('sha256', implode('|', [$userId, 'id', $source, $compte, $identifiant]));
    }
}
