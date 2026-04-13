<?php
declare(strict_types=1);

/*
 * CERTIFICATE HEALTH CHECKER - PROTOTYP
 */

// 1. Fehleranzeige (fuer Entwicklung)
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/CertificateParser.php';
use App\CertificateParser;

// 2. Pfade und Datenbank-Setup
$dbFile = __DIR__ . '/../data/certificates.sqlite';
$storageDir = __DIR__ . '/../storage/';

try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Tabelle und Migration
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
    
    // Spalte 'remarks' sicherheitshalber nachruesten
    $columns = $db->query("PRAGMA table_info(certificates)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('remarks', $columns)) {
        $db->exec("ALTER TABLE certificates ADD COLUMN remarks TEXT");
    }
} catch (PDOException $e) {
    die("Datenbank-Fehler: " . $e->getMessage());
}

$message = "";

// 3. LOESCH-LOGIK (Muss vor jeder HTML-Ausgabe stehen wegen Redirect)
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];
        $stmt = $db->prepare("SELECT storage_path FROM certificates WHERE id = ?");
        $stmt->execute([$id]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cert) {
            $fullPath = $storageDir . $cert['storage_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            $db->prepare("DELETE FROM certificates WHERE id = ?")->execute([$id]);
            
            // Redirect verhindert "Double Post" und aktualisiert die Ansicht sofort
            header("Location: index.php?status=deleted");
            exit; 
        }
    } catch (\Exception $e) {
        $message = "<div class='alert error'>Fehler beim Loeschen: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// 4. ERFOLGSMELDUNG NACH REDIRECT ABFANGEN
if (isset($_GET['status']) && $_GET['status'] === 'deleted') {
    $message = "<div class='alert success'>Zertifikat wurde erfolgreich geloescht.</div>";
}

// 5. UPLOAD-LOGIK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cert_file'])) {
    try {
        $file = $_FILES['cert_file'];
        $pwd = $_POST['password'] ?: "";
        $remarks = $_POST['remarks'] ?: "";
        $tempPath = $file['tmp_name'];
        
        if (empty($tempPath) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception("Bitte waehlen Sie eine gueltige Datei aus.");
        }

        $metadata = CertificateParser::parse($tempPath, $pwd, $file['name']);
        
        $safeName = bin2hex(random_bytes(16)) . ".dat";
        if (!move_uploaded_file($tempPath, $storageDir . $safeName)) {
            throw new \Exception("Speicherfehler im Storage-Verzeichnis.");
        }

        $stmt = $db->prepare("INSERT INTO certificates (original_filename, storage_path, subject, issuer, not_before, not_after, thumbprint, file_format, pfx_protected, remarks) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $file['name'], $safeName, $metadata['subject'], $metadata['issuer'],
            $metadata['not_before'], $metadata['not_after'], $metadata['thumbprint'],
            $metadata['format'], $metadata['pfx_protected'] ? 1 : 0, $remarks
        ]);
        
        // Nach Upload auch redirecten, um F5-Doppel-Uploads zu vermeiden
        header("Location: index.php?status=uploaded");
        exit;
    } catch (\Exception $e) {
        $message = "<div class='alert error'>Fehler: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'uploaded') {
    $message = "<div class='alert success'>Zertifikat erfolgreich hinzugefuegt!</div>";
}

// Daten abrufen
$certs = $db->query("SELECT * FROM certificates ORDER BY not_after ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Zertifikats-Manager</title>
    <style>
        :root { --primary: #1a73e8; --red: #d93025; --green: #1e8e3e; --yellow: #f9ab00; --bg: #f8f9fa; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; margin: 40px; background: var(--bg); color: #3c4043; }
        .container { max-width: 1400px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        h1 { font-weight: 400; color: var(--primary); margin-bottom: 30px; }
        .upload-section { background: #ffffff; border: 1px solid #dadce0; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        
        form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        input, textarea, button { padding: 10px; border: 1px solid #dadce0; border-radius: 4px; }
        textarea { width: 250px; height: 38px; vertical-align: middle; }
        button { background: var(--primary); color: white; border: none; cursor: pointer; font-weight: 500; }
        button:hover { background: #1765cc; }

        .alert { padding: 12px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; }
        .success { background: #e6f4ea; color: var(--green); border: 1px solid var(--green); }
        .error { background: #fce8e6; color: var(--red); border: 1px solid var(--red); }

        /* Table Resizing & Layout */
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { 
            padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; 
            position: relative; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; 
        }
        th { background: #f1f3f4; font-size: 0.85rem; text-transform: uppercase; cursor: pointer; user-select: none; }
        
        .resizer { position: absolute; right: 0; top: 0; height: 100%; width: 4px; cursor: col-resize; z-index: 1; }
        .resizer:hover { background: var(--primary); }

        .dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 8px; }
        .bg-red { background: var(--red); }
        .bg-yellow { background: var(--yellow); }
        .bg-green { background: var(--green); }

        .btn-del { color: var(--red); text-decoration: none; font-size: 0.8rem; border: 1px solid var(--red); padding: 4px 8px; border-radius: 4px; }
        .btn-del:hover { background: var(--red); color: white; }
    </style>
</head>
<body>

<div class="container">
    <h1>Zertifikats-Dashboard</h1>

    <div class="upload-section">
        <?= $message ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="cert_file" required title="Zertifikatsdatei waehlen">
            <input type="password" name="password" placeholder="Passwort (fuer PFX)">
            <textarea name="remarks" placeholder="Bemerkungen / Notizen..."></textarea>
            <button type="submit">Hinzufuegen</button>
        </form>
    </div>

    <div class="table-wrapper">
        <table id="certTable">
            <thead>
                <tr>
                    <th style="width: 140px;">Status <div class="resizer"></div></th>
                    <th style="width: 180px;">Datei <div class="resizer"></div></th>
                    <th style="width: 250px;">Subject <div class="resizer"></div></th>
                    <th style="width: 150px;">Ablaufdatum <div class="resizer"></div></th>
                    <th style="width: 200px;">Bemerkungen <div class="resizer"></div></th>
                    <th style="width: 100px;">Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certs as $c): 
                    $days = (strtotime($c['not_after']) - time()) / 86400;
                    $status = ($days <= 0) ? 'bg-red' : (($days <= 180) ? 'bg-yellow' : 'bg-green');
                    $dt = new DateTime($c['not_after']);
                ?>
                <tr>
                    <td data-sort="<?= $days ?>">
                        <span class="dot <?= $status ?>"></span> <?= ($days <= 0) ? 'Abgelaufen' : round($days) . ' Tage' ?>
                    </td>
                    <td title="<?= htmlspecialchars($c['original_filename']) ?>"><?= htmlspecialchars($c['original_filename']) ?></td>
                    <td title="<?= htmlspecialchars($c['subject']) ?>" style="font-size: 0.85rem;"><?= htmlspecialchars($c['subject']) ?></td>
                    <td data-sort="<?= $dt->getTimestamp() ?>"><strong><?= $dt->format('d.m.Y') ?></strong></td>
                    <td title="<?= htmlspecialchars($c['remarks'] ?? '') ?>"><?= htmlspecialchars($c['remarks'] ?? '-') ?></td>
                    <td>
                        <a href="?delete=<?= $c['id'] ?>" class="btn-del" onclick="return confirm('Soll dieses Zertifikat wirklich dauerhaft geloescht werden?');">Loeschen</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
/** 1. SORTIERUNG **/
document.querySelectorAll('th').forEach((header, index) => {
    let asc = true;
    header.addEventListener('click', (e) => {
        if (e.target.classList.contains('resizer')) return;
        const tbody = header.closest('table').querySelector('tbody');
        const rows = Array.from(tbody.rows);
        
        rows.sort((a, b) => {
            const valA = a.cells[index].getAttribute('data-sort') || a.cells[index].innerText.toLowerCase();
            const valB = b.cells[index].getAttribute('data-sort') || b.cells[index].innerText.toLowerCase();
            return asc ? (valA > valB ? 1 : -1) : (valA < valB ? 1 : -1);
        });
        
        asc = !asc;
        tbody.append(...rows);
    });
});

/** 2. RESIZING **/
document.querySelectorAll('th').forEach(th => {
    const resizer = th.querySelector('.resizer');
    if (!resizer) return;
    resizer.addEventListener('mousedown', e => {
        const startX = e.pageX;
        const startW = th.offsetWidth;
        const move = e => { th.style.width = (startW + (e.pageX - startX)) + 'px'; };
        const up = () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    });
});
</script>

</body>
</html>