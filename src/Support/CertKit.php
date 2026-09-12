<?php

namespace Bitdreamit\QzTray\Support;

/**
 * CertKit — shared OpenSSL helpers for the v1.4.0 "Zero-Prompt" tooling.
 *
 * WHY THIS EXISTS
 * QZ Tray shows its "Untrusted website" prompt when the certificate served by
 * /qz/certificate does not chain to a root QZ Tray trusts. There are exactly
 * three free ways to make the prompt disappear:
 *
 *   1. Use a real CA certificate (Let's Encrypt / cPanel AutoSSL) — QZ Tray
 *      silently trusts anything chaining to a public root.
 *   2. Run your OWN root CA and deploy the CA certificate to every client as
 *      QZ Tray's override.crt (see docs/zero-prompt.md) — the self-signed leaf
 *      then chains to a root QZ Tray trusts and the prompt never appears.
 *   3. Whitelist the exact site certificate into QZ Tray's allowed.dat
 *      (`java -jar qz-tray.jar --allow cert.pem` / provision.json "cert" type).
 *
 * CertKit implements the OpenSSL plumbing for options 2 and 3: generating a
 * dedicated Root CA, generating SAN-enabled leaf certificates signed by that
 * CA, fingerprint formatting, and atomic file writes.
 */
class CertKit
{
    /**
     * Build the openssl.cnf body used for Root CA generation.
     * The section is referenced from configargs as x509_extensions => 'v3_ca'.
     */
    public static function caConf(): string
    {
        return <<<'CONF'
        [ req ]
        default_md = sha256
        distinguished_name = req_dn
        prompt = no

        [ req_dn ]

        [ v3_ca ]
        subjectKeyIdentifier = hash
        authorityKeyIdentifier = keyid:always,issuer:always
        basicConstraints = critical, CA:true, pathlen:0
        keyUsage = critical, keyCertSign, cRLSign
        nsComment = Laravel QZ Tray Root CA

        CONF;
    }

    /**
     * Build the openssl.cnf body used for leaf (site) certificate generation.
     *
     * @param  array<int, string>  $sans  e.g. ['*.example.com', 'example.com']
     */
    public static function leafConf(array $sans = []): string
    {
        $lines = '';
        $i = 0;
        foreach ($sans as $san) {
            $san = trim((string) $san);
            if ($san === '') {
                continue;
            }
            $i++;
            $lines .= 'DNS.'.$i.' = '.$san."\n";
        }

        $sanSection = $i > 0 ? "subjectAltName = @alt_names\n" : '';
        $altNames   = $i > 0 ? "\n[ alt_names ]\n".$lines : '';

        return "[ req ]\n"
            ."default_md = sha256\n"
            ."distinguished_name = req_dn\n"
            ."prompt = no\n"
            ."\n[ req_dn ]\n"
            ."\n[ v3_leaf ]\n"
            ."basicConstraints = CA:FALSE\n"
            ."keyUsage = digitalSignature, keyEncipherment\n"
            ."extendedKeyUsage = serverAuth, clientAuth\n"
            .$sanSection
            ."nsComment = Laravel QZ Tray Site Certificate\n"
            .$altNames;
    }

    /**
     * Write an openssl.cnf to a temp file and return its path.
     * The caller MUST delete the returned path when done (self::cleanup).
     */
    public static function writeTempConf(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qzcnf');

        if ($path === false) {
            throw new \RuntimeException('Unable to create temporary openssl.cnf file.');
        }

        if (file_put_contents($path, $body) === false) {
            throw new \RuntimeException('Unable to write temporary openssl.cnf file.');
        }

        return $path;
    }

    /** Remove a temp file, ignoring failures. */
    public static function cleanup(string $path): void
    {
        @unlink($path);
    }

    /**
     * Write $content to $path atomically (temp file in same dir + rename) and
     * apply permissions. Prevents concurrent readers from seeing partial PEMs.
     */
    public static function writeAtomic(string $path, string $content, int $perms = 0644): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory: {$dir}");
        }

        $tmp = tempnam($dir, 'qztmp');

        if ($tmp === false) {
            throw new \RuntimeException("Unable to create temporary file next to {$path}");
        }

        try {
            if (file_put_contents($tmp, $content) === false) {
                throw new \RuntimeException("Unable to write temporary file for {$path}");
            }

            chmod($tmp, $perms);

            if (! rename($tmp, $path)) {
                throw new \RuntimeException("Unable to move file into place at {$path}");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);

            throw $e;
        }
    }

    /** Timestamped .bak copy of an existing file. Returns the backup path or null. */
    public static function backupExisting(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $backup = $path.'.bak-'.date('YmdHis');

        return copy($path, $backup) ? $backup : null;
    }

    /** Colon-grouped SHA-1 fingerprint — the exact format QZ Tray's dialog shows. */
    public static function fingerprintSha1Pretty(string $certPem): ?string
    {
        $sha1 = openssl_x509_fingerprint($certPem, 'sha1');

        return $sha1 ? implode(':', str_split($sha1, 2)) : null;
    }

    /** Colon-grouped SHA-256 fingerprint for docs/status pages. */
    public static function fingerprintSha256Pretty(string $certPem): ?string
    {
        $sha256 = openssl_x509_fingerprint($certPem, 'sha256');

        return $sha256 ? implode(':', str_split($sha256, 2)) : null;
    }

    /** True when the two PEMs are a matching certificate/key pair. */
    public static function keyMatchesCert(string $certPem, string $keyPem): bool
    {
        $cert = openssl_x509_read($certPem);
        $key  = openssl_pkey_get_private($keyPem);

        if (! $cert || ! $key) {
            return false;
        }

        $certPub = openssl_pkey_get_details(openssl_pkey_get_public($cert))['key'] ?? '';
        $keyPub  = openssl_pkey_get_details($key)['key'] ?? '';

        return $certPub !== '' && $certPub === $keyPub;
    }

    /** Number of days until the certificate expires (negative = expired). */
    public static function daysUntilExpiry(string $certPem): ?int
    {
        $parsed = openssl_x509_parse($certPem);

        if (! $parsed || ! isset($parsed['validTo_time_t'])) {
            return null;
        }

        return (int) floor(($parsed['validTo_time_t'] - time()) / 86400);
    }

    /** True when the leaf appears to be signed by the given CA cert (issuer === CA subject). */
    public static function leafChainsTo(string $leafPem, string $caPem): bool
    {
        $leaf = openssl_x509_parse($leafPem);
        $ca   = openssl_x509_parse($caPem);

        if (! $leaf || ! $ca) {
            return false;
        }

        return ($leaf['issuer'] ?? []) === ($ca['subject'] ?? []);
    }

    /** True when the certificate is self-signed (issuer === subject). */
    public static function isSelfSigned(string $certPem): bool
    {
        $parsed = openssl_x509_parse($certPem);

        if (! $parsed) {
            return true;
        }

        return ($parsed['issuer'] ?? []) === ($parsed['subject'] ?? []);
    }

    /** Whitelist of digests usable for QZ Tray signing (2.1+: SHA512/SHA256). */
    public static function safeDigest(string $digest): string
    {
        return in_array(strtolower($digest), ['sha256', 'sha384', 'sha512'], true)
            ? strtolower($digest)
            : 'sha256';
    }
}
