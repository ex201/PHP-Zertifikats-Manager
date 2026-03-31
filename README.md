\# Docker

Mit der __docker-compose.yml__ einen Container erstellen

\# SQLite

Ordner erstellen: Erstelle im Projektverzeichnis den Ordner __data/__.  
Rechte prüfen: Der Webserver (z. B. __www-data__ oder __\_www__) muss Schreibrechte für diesen Ordner haben.  
Skript aufrufen: Öffne die __index.php__ in deinem Browser.  
PHP bemerkt, dass __certificates.sqlite__ nicht existiert.

Die Zeile

$db = new \*\*PDO\*\*(\*sqlite:$dbFile\*);

erstellt die Datei.

Die Zeile

$db->exec(\*\*\*CREATE\*\* \*\*TABLE\*\* IF \*\*NOT\*\* \*\*EXISTS\*\*...\*); 

legt die Struktur an.

  

\# Cron/Background-Prüfung

Um automatisiert benachrichtigt zu werden, erstellen Sie ein Skript __bin/check\_expiry.php__:

Skript liest alle Zeilen aus der SQLite DB.  
Berechnet Differenz von __not\_after__ zu __now()__.  
Wenn Differenz < 30 Tage: Log-Eintrag schreiben oder error\_log() triggern.

Cronjob-Eintrag:

0 0 \* \* \* /usr/bin/php /path/to/bin/check\_expiry.php

Um die \*Sicherheit\* zu gewährleisten, trennen wir den öffentlichen Web-Zugriff (__public/__) von der Anwendungslogik und den sensiblen Daten (__storage/, data/__).
