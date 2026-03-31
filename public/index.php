<?php
declare(strict_types=1);

/**
 * CERTIFICATE HEALTH CHECKER - PROTOTYP (PHP 8.4)
 * Features: PEM, DER, PFX Support, SQLite, Notizfeld, Resizable & Sortable Table
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/CertificateParser.php';
use App\CertificateParser;

// Pfade und DB Initialisierung
$dbFile = __DIR__ . '/../data/certificates.sqlite';
$storageDir = __DIR__ . '/../storage/';

try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Initiales Tabellen-Schema
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
        pfx_protected BOOLEAN,
        remarks TEXT
    )");

    // Migration: Falls 'remarks' Spalte in existierender DB fehlt
    $columns = $db->query("PRAGMA table_info(certificates)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('remarks', $columns)) {
        $db->exec("ALTER TABLE certificates ADD COLUMN remarks TEXT");
    }
} catch (PDOException $e) {
    die("Kritischer Datenbank-Fehler: " . $e->getMessage());
}

$message = "";

// --- UPLOAD LOGIK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cert_file'])) {
    try {
        $file = $_FILES['cert_file'];
        $pwd = $_POST['password'] ?: "";
        $remarks = $_POST['remarks'] ?: "";
        $tempPath = $file['tmp_name'];
        
        if (empty($tempPath) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception("Upload-Fehler oder keine Datei ausgewählt.");
        }

        // 1. Parsen
        $metadata = CertificateParser::parse($tempPath, $pwd, $file['name']);
        
        // 2. Physisch speichern
        $safeName = bin2hex(random_bytes(16)) . ".dat";
        if (!move_uploaded_file($tempPath, $storageDir . $safeName)) {
            throw new \Exception("Datei konnte nicht in '$storageDir' gespeichert werden. Schreibrechte prüfen!");
        }

        // 3. DB Eintrag
        $stmt = $db->prepare("INSERT INTO certificates (original_filename, storage_path, subject, issuer, not_before, not_after, thumbprint, file_format, pfx_protected, remarks) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $file['name'], $safeName, $metadata['subject'], $metadata['issuer'],
            $metadata['not_before'], $metadata['not_after'], $metadata['thumbprint'],
            $metadata['format'], $metadata['pfx_protected'] ? 1 : 0, $remarks
        ]);
        
        $message = "<div class='alert success'>✔ Zertifikat erfolgreich hinzugefügt!</div>";
    } catch (\Exception $e) {
        $message = "<div class='alert error'>✘ Fehler: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Daten laden
$certs = $db->query("SELECT * FROM certificates ORDER BY not_after ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Cert-Manager Dashboard</title>
    <style>
        :root { --primary: #1a73e8; --bg: #f8f9fa; --border: #dadce0; }
        body { font-family: 'Segoe UI', Roboto, sans-serif; margin: 40px; background: var(--bg); color: #3c4043; }
        .container { max-width: 1400px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
        
        /* Header & Upload */
        h1 { font-weight: 400; margin-bottom: 25px; color: var(--primary); }
        .upload-area { background: #ffffff; border: 1px solid var(--border); padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        input, textarea, button { padding: 10px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; }
        textarea { height: 40px; width: 300px; resize: vertical; }
        button { background: var(--primary); color: white; border: none; cursor: pointer; font-weight: 500; }
        button:hover { background: #1765cc; }
        
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 15px; font-weight: 500; }
        .success { background: #e6f4ea; color: #1e8e3e; border: 1px solid #1e8e3e; }
        .error { background: #fce8e6; color: #d93025; border: 1px solid #d93025; }

        /* Resizable Table */
        .table-container { overflow-x: auto; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { 
            padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; 
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; position: relative;
        }
        th { background: #f1f3f4; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; user-select: none; }
        th:hover { background: #e8eaed; }

        /* Resizer Griff */
        .resizer {
            position: absolute; right: 0; top: 0; height: 100%; width: 4px;
            cursor: col-resize; z-index: 10;
        }
        .resizer:hover { background: var(--primary); }

        /* Ampelsystem */
        .status-cell { display: flex; align-items: center; gap: 8px; }
        .dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; }
        .red { background: #d93025; box-shadow: 0 0 5px #d93025; }
        .yellow { background: #f9ab00; box-shadow: 0 0 5px #f9ab00; }
        .green { background: #1e8e3e; box-shadow: 0 0 5px #1e8e3e; }
        
        .remarks { font-style: italic; color: #70757a; font-size: 0.9em; }
        .mono { font-family: 'Courier New', monospace; font-size: 0.85em; color: #5f6368; }
    </style>
</head>
<body>

<div class="container">
    <h1>Zertifikats-Bestand</h1>

    <div class="upload-area">
        <?= $message ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="cert_file" required>
            <input type="password" name="password" placeholder="Passwort (für PFX)">
            <textarea name="remarks" placeholder="Bemerkungen eingeben..."></textarea>
            <button type="submit">Hinzufügen</button>
        </form>
    </div>

    <div class="table-container">
        <table id="certTable">
            <thead>
                <tr>
                    <th style="width: 130px;">Status <div class="resizer"></div></th>
                    <th style="width: 180px;">Dateiname <div class="resizer"></div></th>
                    <th style="width: 250px;">Subject <div class="resizer"></div></th>
                    <th style="width: 140px;">Ablaufdatum <div class="resizer"></div></th>
                    <th style="width: 200px;">Bemerkungen <div class="resizer"></div></th>
                    <th style="width: 150px;">Thumbprint <div class="resizer"></div></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certs as $c): 
                    $days = (strtotime($c['not_after']) - time()) / 86400;
                    $class = ($days <= 0) ? 'red' : (($days <= 180) ? 'yellow' : 'green');
                    $dt = new DateTime($c['not_after']);
                ?>
                <tr>
                    <td data-sort="<?= $days ?>" class="status-cell">
                        <span class="dot <?= $class ?>"></span>
                        <?= ($days <= 0) ? 'Abgelaufen' : round($days) . ' Tage' ?>
                    </td>
                    <td title="<?= htmlspecialchars($c['original_filename']) ?>"><?= htmlspecialchars($c['original_filename']) ?></td>
                    <td title="<?= htmlspecialchars($c['subject']) ?>" style="font-size:0.85em;"><?= htmlspecialchars($c['subject']) ?></td>
                    <td data-sort="<?= $dt->getTimestamp() ?>">
                        <strong><?= $dt->format('d.m.Y') ?></strong>
                    </td>
                    <td class="remarks" title="<?= htmlspecialchars($c['remarks'] ?? '') ?>">
                        <?= htmlspecialchars($c['remarks'] ?? '-') ?>
                    </td>
                    <td class="mono"><?= substr($c['thumbprint'], 0, 12) ?>...</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($certs)): ?>
                <tr><td colspan="6" style="text-align:center; padding:50px; color:#999;">Keine Daten vorhanden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
/** 1. SPALTENBREITE ÄNDERN (RESIZING) **/
document.querySelectorAll('th').forEach(th => {
    const resizer = th.querySelector('.resizer');
    if (!resizer) return;

    resizer.addEventListener('mousedown', function(e) {
        const startX = e.pageX;
        const startWidth = th.offsetWidth;

        const onMouseMove = (e) => {
            const newWidth = startWidth + (e.pageX - startX);
            if (newWidth > 50) th.style.width = newWidth + 'px';
        };

        const onMouseUp = () => {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            document.body.style.cursor = 'default';
        };

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        document.body.style.cursor = 'col-resize';
        e.preventDefault();
    });
});

/** 2. SORTIERUNG (BIDIREKTIONAL) **/
const table = document.getElementById('certTable');
const tbody = table.querySelector('tbody');
const headers = table.querySelectorAll('th');

headers.forEach((th, index) => {
    let asc = true;
    th.addEventListener('click', (e) => {
        if (e.target.classList.contains('resizer')) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length < 1 || rows[0].cells.length < 2) return;

        const sorted = rows.sort((a, b) => {
            const valA = a.cells[index].getAttribute('data-sort') || a.cells[index].innerText.toLowerCase();
            const valB = b.cells[index].getAttribute('data-sort') || b.cells[index].innerText.toLowerCase();
            
            // Numerischer Vergleich für data-sort, sonst String
            const isNum = !isNaN(valA) && !isNaN(valB);
            if (isNum) return asc ? valA - valB : valB - valA;
            return asc ? valA.localeCompare(valB) : valB.localeCompare(valA);
        });

        asc = !asc;
        tbody.append(...sorted);
    });
});
</script>

</body>
</html>