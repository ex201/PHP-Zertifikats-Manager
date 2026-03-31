CREATE TABLE IF NOT EXISTS certificates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_filename TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    upload_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    subject TEXT,
    issuer TEXT,
    not_before DATETIME,
    not_after DATETIME,
    thumbprint TEXT,
    file_format TEXT,
    pfx_protected BOOLEAN
);
CREATE INDEX idx_not_after ON certificates(not_after);
