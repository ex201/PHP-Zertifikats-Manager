<?php
declare(strict_types=1);

/**
 * CLI Skript zur Prüfung ablaufender Zertifikate
 * Nutzung: php check_expiry.php
 */

$dbFile = __DIR__ . '/../data/certificates.sqlite';
$logFile = __DIR__ . '/../data/expiry_warnings.log';

if (!file_exists($dbFile)) {
    die("Datenbank nicht gefunden.\n");
}

try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Alle Zertifikate laden
    $stmt = $db->query("SELECT id, original_filename, not_after, subject FROM certificates");
    $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTime();
    $warnings = [];

    foreach ($certs as $cert) {
        $expiry = new DateTime($cert['not_after']);
        $diff = $now->diff($expiry);
        $daysLeft = (int)$diff->format("%r%a");

        // Schwellenwerte für Warnungen (z.B. abgelaufen oder < 30 Tage)
        if ($daysLeft <= 0) {
            $warnings[] = "[KRITISCH] ABGELAUFEN: {$cert['original_filename']} (ID: {$cert['id']}) am {$cert['not_after']}";
        } elseif ($daysLeft <= 30) {
            $warnings[] = "[WARNUNG] Läuft bald ab ({$daysLeft} Tage): {$cert['original_filename']} am {$cert['not_after']}";
        }
    }

    if (!empty($warnings)) {
        $logContent = date('Y-m-d H:i:s') . " - Prüfungsergebnis:\n" . implode("\n", $warnings) . "\n" . str_repeat('-', 30) . "\n";
        
        // In Datei loggen
        file_put_contents($logFile, $logContent, FILE_APPEND);
        
        // Ausgabe auf der Konsole
        echo implode("\n", $warnings) . "\n";
    } else {
        echo "Alle Zertifikate sind im grünen Bereich.\n";
    }

} catch (Exception $e) {
    error_log("Fehler bei Zertifikatsprüfung: " . $e->getMessage());
    echo "Fehler: " . $e->getMessage() . "\n";
}