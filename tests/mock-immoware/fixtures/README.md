# Fixtures des Mock-Servers

Ausschließlich synthetische Daten. Keine Kontakte, Objekte oder Dokumente der Hausverwaltung Müller GmbH.

- `files/Posteingang/`, `files/Dokumente/`: WebDAV-Dateien (Präfix HUBTEST_)
- `addressbooks/<name>/*.vcf`: CardDAV-Adressbücher
- `calendars/<name>/*.ics`: CalDAV-Kalender

Per PUT angelegte Dateien landen nicht hier, sondern im Laufzeitverzeichnis des Mock-Servers (siehe `../server.php`).
