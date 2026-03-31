<?php
declare(strict_types=1);

// Fehleranzeige aktivieren (nur für Entwicklung hilfreich)
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/CertificateParser.php';
use App\CertificateParser;

// Pfade definieren
$dbFile = __DIR__ . '/../data/certificates.sqlite';
$storageDir = __DIR__ . '/../storage/';

// Datenbank-Verbindung & Initialisierung
try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS certificates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        original_filename TEXT, 
        storage_path TEXT, 
        upload_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, 
        subject TEXT, 
        issuer TEXT, 
        not_before DATETIME, 
        not_after DATETIME, 
        thumbprint TEXT, 
        file_format TEXT, 
        pfx_protected BOOLEAN
    )");
} catch (PDOException $e) {
    die("Datenbank-Fehler: " . $e->getMessage());
}

$message = "";

// --- START UPLOAD HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cert_file'])) {
    try {
        $file = $_FILES['cert_file'];
        $pwd = $_POST['password'] ?: ""; // Leeres Passwort statt null
        $tempPath = $file['tmp_name'];
        
        if (empty($tempPath)) {
            throw new \Exception("Keine Datei ausgewählt oder Upload-Limit überschritten.");
        }

        // 1. ZUERST Parsen (mit Originalname für die Typerkennung)
        $metadata = CertificateParser::parse($tempPath, $pwd, $file['name']);
        
        // 2. WENN Parsen erfolgreich, DANACH sicher speichern
        $safeName = bin2hex(random_bytes(16)) . ".dat";
        if (!move_uploaded_file($tempPath, $storageDir . $safeName)) {
            throw new \Exception("Datei konnte nicht im storage-Ordner gespeichert werden. Rechte prüfen!");
        }

        // 3. In Datenbank eintragen
        $stmt = $db->prepare("INSERT INTO certificates (original_filename, storage_path, subject, issuer, not_before, not_after, thumbprint, file_format, pfx_protected) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $file['name'], 
            $safeName, 
            $metadata['subject'], 
            $metadata['issuer'],
            $metadata['not_before'], 
            $metadata['not_after'], 
            $metadata['thumbprint'],
            $metadata['format'], 
            $metadata['pfx_protected'] ? 1 : 0
        ]);
        
        $message = "<div style='color:green; padding:10px; border:1px solid green;'>✔ Erfolgreich hochgeladen!</div>";
    } catch (\Exception $e) {
        $message = "<div style='color:red; padding:10px; border:1px solid red;'>✘ Fehler: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
// --- ENDE UPLOAD HANDLING ---

// Daten für Dashboard abrufen
$sort = $_GET['sort'] ?? 'not_after';
$allowedSort = ['not_after', 'subject', 'original_filename'];
if (!in_array($sort, $allowedSort)) $sort = 'not_after';

$certs = $db->query("SELECT * FROM certificates ORDER BY $sort ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zertifikats-Checker</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 40px; background: #f0f2f5; color: #333; }
        .container { max-width: 1100px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { color: #1a73e8; border-bottom: 2px solid #e8eaed; padding-bottom: 10px; }
        .upload-section { background: #f8f9fa; border: 2px dashed #dadce0; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 15px; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; text-transform: uppercase; font-size: 0.85em; letter-spacing: 1px; }
        .status-dot { height: 12px; width: 12px; border-radius: 50%; display: inline-block; margin-right: 8px; }
        .red { background-color: #d93025; box-shadow: 0 0 8px #d93025; }
        .yellow { background-color: #f9ab00; box-shadow: 0 0 8px #f9ab00; }
        .green { background-color: #1e8e3e; box-shadow: 0 0 8px #1e8e3e; }
        .thumb { font-family: monospace; font-size: 0.85em; color: #666; }
        a { color: #1a73e8; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <h1>Certificate Health Dashboard</h1>
    
    <div class="upload-section">
        <h3>Neues Zertifikat hinzufügen</h3>
        <?= $message ?>
        <form method="POST" enctype="multipart/form-data" style="margin-top:15px;">
            <input type="file" name="cert_file" required>
            <input type="password" name="password" placeholder="Passwort (nur für PFX)">
            <button type="submit" style="background:#1a73e8; color:white; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Hochladen</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th><a href="?sort=original_filename">Datei</a></th>
                <th><a href="?sort=subject">Subject</a></th>
                <th><a href="?sort=not_after">Ablaufdatum</a></th>
                <th>Thumbprint</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($certs as $c): 
                $daysLeft = (strtotime($c['not_after']) - time()) / 86400;
                $statusClass = ($daysLeft <= 0) ? 'red' : (($daysLeft <= 180) ? 'yellow' : 'green');
                $dt = new DateTime($c['not_after']);
            ?>
            <tr>
                <td><span class="status-dot <?= $statusClass ?>"></span> <?= ($daysLeft <= 0) ? 'Abgelaufen' : round($daysLeft) . ' Tage' ?></td>
                <td><strong><?= htmlspecialchars($c['original_filename']) ?></strong><br><small><?= $c['file_format'] ?></small></td>
                <td style="font-size: 0.85em; max-width: 300px; overflow-wrap: break-word;"><?= htmlspecialchars($c['subject']) ?></td>
                <td>
                    <?= $dt->format('d.m.Y') ?><br>
                    <small style="color:#888;"><?= $dt->format('H:i') ?> UTC</small>
                </td>
                <td class="thumb"><?= substr($c['thumbprint'], 0, 16) ?>...</td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($certs)): ?>
                <tr><td colspan="5" style="text-align:center; color:#999; padding:40px;">Noch keine Zertifikate hochgeladen.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>