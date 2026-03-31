<?php
declare(strict_types=1);

namespace App;

class CertificateParser {
    /**
     * @param string $filePath Der Pfad zur temporären oder gespeicherten Datei
     * @param string|null $password Passwort für PFX
     * @param string|null $originalName Der ursprüngliche Name (für die Endung)
     */
    public static function parse(string $filePath, ?string $password = null, ?string $originalName = null): array {
        $content = file_get_contents($filePath);
        if (!$content) throw new \Exception("Datei konnte nicht gelesen werden.");
        
        $certData = null;
        $format = 'PEM/DER';
        $pfxProtected = false;

        // Wir prüfen die Endung des Originalnamens oder des Pfads
        $nameToCheck = strtolower($originalName ?? $filePath);

        if (str_ends_with($nameToCheck, '.pfx') || str_ends_with($nameToCheck, '.p12')) {
            $pfxProtected = !empty($password);
            
            // Versuch das PFX zu lesen
            // Hinweis: Manche Server benötigen openssl_pkcs12_read($content, $certs, $password, 0) 
            // wenn es ein altes Export-Format ist.
            if (!openssl_pkcs12_read($content, $certs, $password ?? '')) {
                throw new \Exception("PFX konnte nicht gelesen werden. Passwort falsch oder Datei beschädigt?");
            }
            
            if (!isset($certs['cert'])) {
                throw new \Exception("PFX enthält kein gültiges Zertifikat.");
            }
            
            $certData = $certs['cert'];
            $format = 'PFX/P12';
        } else {
            // PEM oder DER Logik
            if (!str_contains($content, '-----BEGIN CERTIFICATE-----')) {
                $certData = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($content), 64) . "-----END CERTIFICATE-----";
                $format = 'DER';
            } else {
                $certData = $content;
            }
        }

        $resource = openssl_x509_read($certData);
        if (!$resource) {
            throw new \Exception("Ungültiges Zertifikatsformat (X.509 konnte nicht extrahiert werden).");
        }

        $parsed = openssl_x509_parse($resource);
        $fingerprint = openssl_x509_fingerprint($resource, 'sha256');

        return [
            'subject' => self::formatName($parsed['subject'] ?? []),
            'issuer' => self::formatName($parsed['issuer'] ?? []),
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
        return $parts ? implode(', ', $parts) : 'Unbekannt';
    }
}