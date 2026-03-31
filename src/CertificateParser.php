<?php
declare(strict_types=1);

namespace App;

class CertificateParser {
    public static function parse(string $filePath, ?string $password = null): array {
        $content = file_get_contents($filePath);
        $certData = null;
        $format = 'PEM/DER';
        $pfxProtected = false;

        // 1. Check if it is PFX/P12
        if (str_ends_with(strtolower($filePath), '.pfx') || str_ends_with(strtolower($filePath), '.p12')) {
            $pfxProtected = !empty($password);
            if (!openssl_pkcs12_read($content, $certs, $password ?? '')) {
                throw new \Exception("PFX konnte nicht gelesen werden (Passwort falsch?)");
            }
            $certData = $certs['cert'];
            $format = 'PFX/P12';
        } else {
            // 2. Try PEM or DER
            if (!str_contains($content, '-----BEGIN CERTIFICATE-----')) {
                // Assume DER, convert to PEM
                $content = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($content), 64) . "-----END CERTIFICATE-----";
                $format = 'DER';
            }
            $certData = $content;
        }

        $resource = openssl_x509_read($certData);
        if (!$resource) {
            throw new \Exception("Ungültiges Zertifikatsformat.");
        }

        $parsed = openssl_x509_parse($resource);
        $fingerprint = openssl_x509_fingerprint($resource, 'sha256');

        return [
            'subject' => self::formatName($parsed['subject']),
            'issuer' => self::formatName($parsed['issuer']),
            'not_before' => date('Y-m-d H:i:s', $parsed['validFrom_time_t']),
            'not_after' => date('Y-m-d H:i:s', $parsed['validTo_time_t']),
            'thumbprint' => $fingerprint,
            'format' => $format,
            'pfx_protected' => $pfxProtected
        ];
    }

    private static function formatName(array $data): string {
        $parts = [];
        foreach ($data as $key => $val) {
            $value = is_array($val) ? implode(', ', $val) : $val;
            $parts[] = "$key=$value";
        }
        return implode(', ', $parts);
    }
}