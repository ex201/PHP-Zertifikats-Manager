<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/CertificateParser.php';

use App\CertificateParser;

$dbFile = __DIR__ . '/../data/certificates.sqlite';
$storageDir = __DIR__ . '/../storage/';
$db = new PDO("sqlite:$dbFile");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialisiere DB
$db->exec("CREATE TABLE IF NOT EXISTS certificates (id INTEGER PRIMARY KEY AUTOINCREMENT, original_filename TEXT, storage_path TEXT, upload_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, subject TEXT, issuer TEXT, not_before DATETIME, not_after DATETIME, thumbprint TEXT, file_format TEXT, pfx_protected BOOLEAN)");

$message = "";

// Upload Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cert_file'])) {
    try {
        $file = $_FILES['cert_file'];
        $pwd = $_POST['password'] ?? null;
        $tempPath = $file['tmp_name'];
        
        $metadata = CertificateParser::parse($tempPath, $pwd);
        
        $safeName = bin2hex(random_bytes(16)) . ".dat";
        $finalPath = $storageDir . $safeName;
        move_uploaded_file($tempPath, $finalPath);

        $stmt = $db->prepare("INSERT INTO certificates (original_filename, storage_path, subject, issuer, not_before, not_after, thumbprint, file_format, pfx_protected) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $file['name'], $safeName, $metadata['subject'], $metadata['issuer'],
            $metadata['not_before'], $metadata['not_after'], $metadata['thumbprint'],
            $metadata['format'], $metadata['pfx_protected'] ? 1 : 0
        ]);
        $message = "<p style='color:green'>Erfolgreich hochgeladen!</p>";
    } catch (\Exception $e) {
        $message = "<p style='color:red'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Daten abrufen
$sort = $_GET['sort'] ?? 'not_after';
$order = ($sort === 'not_after') ? 'ASC' : 'ASC';
$allowedSort = ['not_after', 'subject'];
if (!in_array($sort, $allowedSort)) $sort = 'not_after';

$certs = $db->query("SELECT * FROM certificates ORDER BY $sort $order")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Cert-Manager Prototyp</title>
    <style>
        body { font-family: sans-serif; margin: 40px; background: #f4f4f9; }
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #ddd; }
        .status-dot { height: 15px; width: 15px; border-radius: 50%; display: inline-block; }
        .red { background-color: #ff4d4d; }
        .yellow { background-color: #ffcc00; }
        .green { background-color: #2eb82e; }
        .upload-area { border: 2px dashed #ccc; padding: 20px; margin-bottom: 20px; border-radius: 8px; }
    </style>
</head>
<body>

<div class="container">
    <h1>Zertifikats-Dashboard</h1>
    
    <div class="upload-area">
        <h3>Neues Zertifikat hochladen</h3>
        <?= $message ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="cert_file" required>
            <input type="password" name="password" placeholder="Passwort (für PFX/P12)">
            <button type="submit">Hochladen & Prüfen</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>Status</th>
                <th><a href="?sort=original_filename">Datei</a></th>
                <th><a href="?sort=subject">Subject</a></th>
                <th>Issuer</th>
                <th><a href="?sort=not_after">Ablaufdatum</a></th>
                <th>Thumbprint</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($certs as $c): 
                $daysLeft = (strtotime($c['not_after']) - time()) / 86400;
                $status = 'red';
                if ($daysLeft > 180) $status = 'green';
                elseif ($daysLeft > 0) $status = 'yellow';
                
                $dt = new DateTime($c['not_after']);
            ?>
            <tr>
                <td><span class="status-dot <?= $status ?>" title="<?= round($daysLeft) ?> Tage verbleibend"></span></td>
                <td><?= htmlspecialchars($c['original_filename']) ?></td>
                <td style="font-size: 0.8em;"><?= htmlspecialchars($c['subject']) ?></td>
                <td style="font-size: 0.8em;"><?= htmlspecialchars($c['issuer']) ?></td>
                <td>
                    <strong><?= $dt->format('Y-m-d') ?></strong><br>
                    <small><?= $dt->format('d.m.Y H:i') ?></small>
                </td>
                <td><small><?= substr($c['thumbprint'], 0, 12) ?>...</small></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>