# Verbindliche Abnahmefälle aus dem Auftrag (Originalwortlaut, Stand 12.09.2026)

Quelle: Masterprompt "Erweiterung des Immoware24-Connectors, interne Mail- und Vorgangsbearbeitung", Abschnitt 21. Diese Liste ist maßgeblich; die Zuordnung zu Testklassen steht in `06-testplan.md`.

1. Doppelte Gmail-Events erzeugen keine doppelten Aktionen.
2. Ein abgelaufener Sync-Cursor wird ohne stillen Nachrichtenverlust behandelt.
3. Eine Antwort direkt in Gmail wird erkannt, offene Fachaufgaben bleiben bestehen.
4. Ein gelöschter Entwurf gilt nicht als gesendet; ein unklarer Versand wird nicht blind wiederholt.
5. Gleichnamige Kontakte führen nicht zur falschen Änderung.
6. IBAN mit gültiger Prüfziffer, aber fehlender Identitätsprüfung bleibt gesperrt.
7. Bankdatenänderung ohne notwendige zweite Freigabe ist auch per direktem API-Aufruf unmöglich.
8. Veralteter Action Plan oder zwischenzeitlich geänderter Datensatz erzwingt erneute Prüfung.
9. Adressänderung gelingt nur in einem System: sichtbarer Teilerfolg statt falschem Abschluss.
10. Nicht erreichbares Lexware wird nicht als "Kunde nicht vorhanden" interpretiert.
11. Nicht API-änderbarer Lexware-Kontakt verliert keine Mehrfacheinträge.
12. Neue Adresse plus aktueller Wasserschaden werden getrennt bearbeitet.
13. Wochenende, Feiertag, Sommerzeit, Wiedervorlage und importierte Altmail beeinflussen Fristen korrekt.
14. Unbestätigter Notfall eskaliert und blockiert nicht hinter einem Massenimport.
15. Prompt Injection verändert keine Rechte und löst keine fremde Aktion aus.
16. Unberechtigter Benutzer erhält weder Mail- noch Drive-Inhalte, auch nicht über Suche oder KI-Kontext.
17. Wiederholte Jobs nach Timeout oder Worker-Neustart verursachen keine unkontrollierten Doppeländerungen.
18. Reine Auskunft lässt sich ohne künstliche Schreibaktion fachlich abschließen.
19. Später wirksame Änderung wird nicht vorzeitig umgesetzt.
20. Bisherige Connector-Funktionen und bestehende Domains bestehen ihre Regressionstests.
