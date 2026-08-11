# 8D-Reklamationstool MVP

Eigenständiges PHP/MySQL-Tool für Reklamationen mit 8D-Bericht, Maßnahmen, Uploads, Historie, druckbarem Bericht und Benutzerverwaltung.

## Installation

1. Dateien auf den Webspace hochladen.
2. In phpMyAdmin `database.sql` importieren.
3. In `config.php` die Datenbankdaten prüfen.
4. Schreibrechte für den Ordner `uploads/claims/` setzen.
5. Aufrufen: `login.php`

## Demo-Login

E-Mail: `admin@example.com`  
Passwort: `Admin123!`

Bitte nach dem ersten Login ändern: rechts oben auf den Benutzernamen klicken -> `Mein Profil`.

## Enthalten

- Login
- Dashboard
- Reklamationen erstellen
- 8D-Schritte D1 bis D8 bearbeiten
- Maßnahmen pro D-Schritt
- Fristen und Status
- Datei-/Foto-Upload
- Historie
- druckbarer 8D-Bericht über Browser-PDF
- Admin-Benutzerverwaltung
- Benutzer anlegen/bearbeiten
- Rollen: Admin, Qualität, Mitarbeiter, Leser
- Benutzer aktivieren/deaktivieren
- Admin kann Passwörter zurücksetzen
- Benutzer können ihr eigenes Profil und Passwort ändern

## Rollen

- `Admin`: kompletter Zugriff, Benutzerverwaltung
- `Qualität`: Reklamationen bearbeiten und abschließen
- `Mitarbeiter`: Reklamationen und Maßnahmen bearbeiten
- `Leser`: nur ansehen

## Empfohlene Ordnerrechte

`uploads/claims/` muss beschreibbar sein.

## Datenbank-Konfiguration für Daniels Hosting

Die `config.php` ist bereits auf folgenden Host vorbereitet:

- Host: `db5020714900.hosting-data.io`
- Port: `3306`
- Benutzer: `dbu3212475`
- DB-Name aktuell gesetzt auf: `dbs15796179`

Wichtig: Bei IONOS ist `reklamation8d` nur die Beschreibung. Der echte Datenbankname ist `dbs15796179`.

## Setup prüfen

Nach dem Hochladen und Import von `database.sql` diese Datei im Browser aufrufen:

`setup_check.php`

Wenn alles grün ist, danach `setup_check.php` aus Sicherheitsgründen wieder löschen.

## Nächster Ausbau

- echter PDF-Export mit mPDF
- E-Mail-Erinnerungen
- Kunden-/Lieferantenportal
- Aufgabenansicht „Meine Maßnahmen“
- Login-Versuche begrenzen

## Neue Funktion: Meine Maßnahmen

Die Seite `my_actions.php` zeigt jedem eingeloggten Benutzer seine eigenen Maßnahmen:

- offene Maßnahmen
- überfällige Maßnahmen
- heute fällige Maßnahmen
- erledigte Maßnahmen
- Suche nach Maßnahme, Reklamation, Partner oder Problem
- Schnellaktionen: In Arbeit, Erledigt, Wieder öffnen

Es ist keine zusätzliche Datenbankmigration nötig, weil die Funktion die vorhandene Tabelle `claim_actions` nutzt.

## Neue Funktion: Maßnahmen bearbeiten/löschen + Ampel

Auf der Reklamations-Detailseite wurde die Maßnahmen-Tabelle erweitert:

- Maßnahmen bearbeiten per Modal
- Maßnahmen löschen mit Sicherheitsabfrage
- Verantwortlichen ändern
- Frist ändern
- D-Schritt ändern
- Status ändern
- Schnellaktion erledigen/wieder öffnen
- automatische Ampel je Maßnahme

Ampel-Regel:

- Grün: Maßnahme ist 0 bis 5 Tage alt
- Gelb: Maßnahme ist 6 bis 10 Tage alt
- Rot: Maßnahme ist ab 11 Tagen alt oder die Frist ist überschritten

Die Ampel wird angezeigt in:

- Reklamations-Detailseite
- Meine Maßnahmen
- Dashboard bei offenen Maßnahmen

Keine neue Datenbankmigration nötig.

## Neue Funktion: E-Mail-Erinnerung

Auf der Reklamations-Detailseite gibt es bei offenen Maßnahmen einen Button `Erinnern`.

Voraussetzungen:

- der Verantwortliche hat eine gültige E-Mail-Adresse im Benutzerprofil
- die PHP-Mailfunktion des Hostings ist aktiv
- in `config.php` sollte `MAIL_FROM_EMAIL` auf eine echte Domain-Mailadresse gesetzt werden

Beispiel:

```php
const MAIL_FROM_EMAIL = 'reklamation@deine-domain.de';
const MAIL_FROM_NAME = '8D Reklamationstool';
```

Hinweis: Der Button nutzt aktuell die einfache PHP-Funktion `mail()`. Für später ist SMTP sauberer, z. B. über PHPMailer.


## Mehrere Standorte

Diese Version ist standortfähig. Nach dem Hochladen als Admin `run_location_migration.php` aufrufen.
Danach können Standorte über `locations.php` verwaltet und Benutzer in der Benutzerverwaltung Standorten zugeordnet werden.
Bestehende Reklamationen werden beim ersten Lauf automatisch Wunstorf zugeordnet.
