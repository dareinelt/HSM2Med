# Aufgabe

Entwickle eine vollständig lokale, offlinefähige Webanwendung zur Verarbeitung von **Abbott/St. Jude Medical Merlin**-Auslesedaten eines Herzschrittmachers.

Die Anwendung soll eine vom Merlin-System exportierte Textdatei einlesen, die enthaltenen Parameter strukturiert darstellen, die Daten dauerhaft in einer MySQL-Datenbank speichern und daraus einen professionell strukturierten PDF-Bericht erzeugen.

Bereits importierte historische Berichte müssen jederzeit erneut aus der Datenbank als PDF exportiert werden können.

Die Anwendung ist ausdrücklich **keine medizinische Diagnosesoftware**. Sie stellt importierte Daten strukturiert dar und darf keine medizinische Bewertung oder Diagnose erzeugen.

---

# 1. Verbindliche technische Vorgaben

## Backend

* PHP **8.5**
* Keine PHP-Frameworks
* Keine Laravel-, Symfony-, Slim- oder vergleichbaren Frameworks
* Native PHP-Anwendung
* PDO für Datenbankzugriff
* Strikte Typisierung:

```php
declare(strict_types=1);
```

## Datenbank

* **MySQL 9.7 LTS**
* UTF-8:

```text
utf8mb4
```

* Foreign Keys verwenden
* Transaktionen verwenden
* Prepared Statements verwenden
* Keine SQL-Strings mit ungefilterten Benutzereingaben

## Frontend

* HTML5
* CSS3
* Vanilla JavaScript
* Keine JavaScript-Frameworks
* Kein jQuery
* Keine externen Bibliotheken aus CDN
* Keine externen Fonts
* Keine externen Bilder
* Keine externen APIs

Die Anwendung muss vollständig **offline** funktionieren.

## PDF

Die PDF-Erzeugung muss lokal erfolgen.

Es darf keine externe PDF-API verwendet werden.

Bevorzugt soll eine lokal installierte PHP-PDF-Bibliothek verwendet werden, sofern diese ohne Framework und ohne Internetzugriff betrieben werden kann.

Alternativ darf eine eigene, ausreichend robuste PDF-Erzeugung implementiert werden.

Alle benötigten Abhängigkeiten müssen Bestandteil des Docker-Images bzw. des Projekts sein.

## Deployment

Die Anwendung muss containerisiert sein:

```text
Docker
Docker Compose
```

Mindestens:

```text
PHP 8.5 + Apache
MySQL 9.7 LTS
```

Die Anwendung darf für den Betrieb keinerlei Internetzugriff benötigen.

---

# 2. Grundprinzip

Der Datenfluss soll folgendermaßen aussehen:

```text
Merlin TXT-Datei
       │
       ▼
   PHP Parser
       │
       ▼
strukturierte Datensätze
       │
       ▼
 Validierung
       │
       ▼
    MySQL
       │
       ├───────────────┐
       │               │
       ▼               ▼
aktueller Bericht   Historische Berichte
       │               │
       └───────┬───────┘
               ▼
          PDF Generator
               │
               ▼
         PDF-Bericht
```

Die Datenbank ist die **dauerhafte Quelle für historische Berichte**.

Ein späterer PDF-Export eines historischen Berichts darf **nicht erneut die ursprüngliche TXT-Datei benötigen**.

---

# 3. Eingabeformat

Die Merlin-Datei ist eine Textdatei mit Datensätzen nach folgendem grundsätzlichen Muster:

```text
<Parameter-ID><SEP><Parameter-Bezeichnung><SEP><Wert><SEP><Einheit><SEP>
```

Dabei ist:

```text
<SEP> = ASCII 0x1C
```

also das Steuerzeichen:

```text
File Separator / FS
```

Beispiel:

```text
306RV Pulse Amplitude2.5V
2457Model Number: SJM Atrial Lead2088TC Tendril STS
2432Follow-up Physician
2680Event Histogram Auto Mode Switch Count0.0
1606RV. Capture Test Threshold Amplitude0.5V
101Programmer Model Number3650
875RV Lead Monitoring: Lower Limit200Ohms
9015Percent Ventricular Pacing AlertOn
203Device Last Interrogation Date and Time10/07/2026 07:03:24
```

Die tatsächlichen Dateien können mehrere hundert Parameter enthalten.

---

# 4. Parser

Implementiere einen robusten Parser für dieses Format.

## Anforderungen

* UTF-8 bevorzugen.
* Falls UTF-8 nicht funktioniert, kontrolliert auf Windows-1252/ISO-8859-1 zurückfallen.
* ASCII `0x1C` als primäres Feldtrennzeichen verwenden.
* Zeilenumbrüche dürfen nicht als zwingende Datensatzgrenze angenommen werden.
* Leere Felder müssen erhalten bleiben.
* Leere Werte dürfen nicht mit `NULL`, `0` oder einem anderen Wert verwechselt werden.
* Leere Einheiten müssen erhalten bleiben.
* Originalwerte dürfen nicht verändert werden.
* Keine automatische Rundung.
* Keine automatische medizinische Interpretation.
* Unbekannte Parameter dürfen nicht verworfen werden.
* Fehlerhafte Datensätze sollen protokolliert werden, ohne den gesamten Import abzubrechen.

Ein Parameter soll intern mindestens folgende Struktur besitzen:

```php
[
    'parameter_id' => string,
    'name'         => string,
    'value'        => ?string,
    'unit'         => ?string,
    'raw_record'   => string,
    'position'     => int,
]
```

Zusätzliche normalisierte Werte dürfen gespeichert werden, dürfen aber niemals den Originalwert ersetzen.

---

# 5. Datenmodell

Entwickle ein relationales Datenmodell.

Mindestens folgende Tabellen sind vorzusehen:

```text
patients
devices
leads
imports
reports
report_parameters
parameter_definitions
import_errors
```

Die konkrete Struktur darf sinnvoll erweitert werden.

## Patienten

Beispielsweise:

```text
patients
---------
id
patient_id
patient_name
date_of_birth
created_at
updated_at
```

Patienteninformationen sind besonders sensibel zu behandeln.

---

# 6. Geräte

Beispielsweise:

```text
devices
-------
id
patient_id
manufacturer
model_name
model_number
serial_number
implant_date
created_at
updated_at
```

Beispieldaten aus der Datei:

```text
Manufacturer: Abbott / St. Jude Medical
Device Model Name: Endurity Core
Device Model Number: 2152
Device Serial Number: 5809481
Implant Date: 06/18/2024 00:00:00
```

Der tatsächliche Herstellerwert soll aus der Datei übernommen werden. Keine Herstellerangaben erfinden.

---

# 7. Sonden / Leads

Sonden müssen separat modelliert werden, soweit die Daten dies zulassen.

Beispielsweise:

```text
leads
-----
id
device_id
chamber
manufacturer
model_number
serial_number
lead_type
implant_date
created_at
updated_at
```

Beispiele:

```text
Model Number: SJM Atrial Lead
2088TC Tendril STS

Model Number: SJM RV Pace/Sense Lead
2088TC Tendril STS
```

Auch hier gilt:

**Keine Informationen ergänzen, die nicht aus den Quelldaten hervorgehen.**

---

# 8. Import

Jeder Import muss als eigener Vorgang gespeichert werden.

Tabelle:

```text
imports
-------
id
filename
file_hash
encoding
imported_at
parser_version
record_count
valid_record_count
error_count
status
```

Der Hash der Originaldatei soll gespeichert werden, beispielsweise SHA-256.

Dadurch kann erkannt werden, ob dieselbe Datei bereits importiert wurde.

---

# 9. Reports

Jeder erfolgreiche Import erzeugt einen Bericht.

Beispielsweise:

```text
reports
-------
id
import_id
patient_id
device_id
session_timestamp
interrogation_timestamp
created_at
report_version
```

Ein Bericht muss unabhängig von der ursprünglichen TXT-Datei erhalten bleiben.

---

# 10. Report-Parameter

Alle importierten Parameter müssen mit dem jeweiligen Bericht verknüpft werden.

Beispielsweise:

```text
report_parameters
-----------------
id
report_id
parameter_id
parameter_name
value
unit
category
original_position
raw_record
created_at
```

Wichtig:

Der Bericht muss einen **Snapshot der importierten Daten** enthalten.

Wenn später eine Parameterdefinition oder Kategorie geändert wird, darf sich dadurch ein bereits gespeicherter historischer Bericht nicht unkontrolliert verändern.

---

# 11. Historische Berichte

Die Webanwendung benötigt eine Seite:

```text
Berichte
```

Dort sollen historische Berichte angezeigt werden.

Mindestens:

* Datum
* Patient
* Gerät
* Modell
* Seriennummer
* Importdatei
* Importstatus
* Berichtsversion

Sortierung:

```text
neueste zuerst
```

Suchmöglichkeiten:

* Patient
* Patient-ID
* Geräte-Seriennummer
* Modell
* Datum
* Importdateiname

---

# 12. Historischen Bericht erneut als PDF exportieren

Das ist eine zentrale Anforderung.

Ein Benutzer soll einen historischen Bericht auswählen können:

```text
Berichte → Bericht öffnen → PDF exportieren
```

Der PDF-Generator muss dafür ausschließlich die gespeicherten Daten aus MySQL verwenden.

Es darf **nicht erforderlich sein**, die ursprüngliche TXT-Datei erneut hochzuladen.

Beispiel:

```text
GET /reports/123/pdf
```

oder eine vergleichbare native-PHP-Route.

Der PDF-Export muss deterministisch sein:

```text
gleicher Datenbank-Snapshot
        ↓
gleiche PDF-Inhalte
```

Die PDF kann sich technisch durch PDF-Metadaten wie Erstellungszeit unterscheiden, aber die medizinischen/technischen Inhalte müssen identisch bleiben.

---

# 13. Originaldatei archivieren

Optional, aber empfohlen:

Speichere die ursprüngliche importierte TXT-Datei lokal im Docker-Volume bzw. Dateisystem.

Beispielsweise:

```text
/data/imports/<sha256>.txt
```

Die Datenbank soll trotzdem der primäre Speicher für die strukturierten Berichtsdaten sein.

Die Anwendung muss historische PDFs weiterhin aus der Datenbank erzeugen können, selbst wenn die Original-TXT-Datei nicht mehr vorhanden ist.

---

# 14. Kategorisierung

Die Parameter sollen für die PDF-Ausgabe sinnvoll gruppiert werden.

Beispielkategorien:

## Patient

* Patient Name
* Patient ID
* Patient Date of Birth
* Indications for Implant
* Ejection Fraction

## Gerät

* Device Model Name
* Device Model Number
* Device Serial Number
* Manufacturer
* Implant Date
* Mode
* Base Rate
* Maximum Pacing Rate
* Session Timestamp
* Device Last Interrogation Date and Time

## Batterie

* Battery Current
* Unloaded Battery Voltage
* Longevity Estimate
* Magnet Rate
* Magnet Response

## Atrium

* Atrial Lead Type
* Atrial Lead Serial Number
* Manufacturer: Atrial Lead
* Model Number: SJM Atrial Lead
* Atrial Signal Amplitude
* Atrial Paced - Lifetime
* Atrial Pulse Parameters

## Ventrikel / RV

* RV Lead Type
* RV Lead Serial Number
* Manufacturer: RV Lead
* Model Number: SJM RV Pace/Sense Lead
* RV Pacing Lead Impedance
* Ventricular Signal Amplitude
* RV Pulse Amplitude
* RV Pulse Width
* Ventricular Sensitivity
* V Pace Polarity
* V Sense Polarity

## Stimulation / Programmierung

* Mode
* Base Rate
* Maximum Pacing Rate
* AV Delay
* PVARP/VREF
* Hysteresis
* Rate Response
* Pulse Configuration
* Pulse Amplitude
* Pulse Width

## Pacing-Statistik

* Atrial Paced - Lifetime
* Ventricular Paced - Lifetime
* Event Histogram Percent Paced In Atrium
* Event Histogram Percent Paced In Ventricle
* AT/AF Episodes
* Total Time in AT/AF

## Arrhythmie / Ereignisse

* VT/VF Detection Rate
* VT/VF No. of Consecutive Cycles
* VT Episode Trigger Priority
* High Ventricular Rate Alert
* Mode Switch
* Noise Reversion

## MRI

* MRI Mode
* MRI Base Rate
* MRI Atrial Pulse Amplitude
* MRI RV Pulse Amplitude
* MRI Atrial Pulse Width
* MRI RV Pulse Width
* MRI Paced AV Delay
* MRI Pulse Configuration

## Programmiergerät / Software

* Programmer Marketing Name
* Programmer Model Number
* Programmer Serial Number
* Programmer Language
* Application Software Version Number
* Application Build Number

## Sonstige

Alle nicht zugeordneten Parameter.

**Kein Parameter darf aufgrund fehlender Zuordnung verloren gehen.**

---

# 15. Parameter-Mapping

Das Mapping muss außerhalb der PDF-Logik definiert werden.

Beispielsweise:

```php
$parameterMapping = [
    '306' => [
        'category' => 'Ventrikulär / RV',
        'display_name' => 'RV Pulse Amplitude',
    ],
    '302' => [
        'category' => 'Stimulation / Programmierung',
        'display_name' => 'Base Rate',
    ],
];
```

Eine explizite ID-Zuordnung hat Vorrang.

Zusätzlich kann der Parametername als Fallback verwendet werden.

Unbekannte Parameter werden automatisch unter:

```text
Sonstige / Nicht kategorisiert
```

angezeigt.

---

# 16. Keine unbelegten Standardzuordnungen

Die Anwendung darf nicht behaupten, dass die IDs wie:

```text
306
302
2709
9048
```

automatisch IEEE-11073-IDs oder andere internationale Standard-IDs sind.

Die Software soll diese IDs zunächst als **Quellformat-Parameter-IDs des Merlin-Exports** behandeln.

Eine spätere Standardzuordnung muss separat und explizit dokumentiert werden.

---

# 17. PDF-Aufbau

Der PDF-Bericht soll professionell aussehen.

Beispiel:

```text
HERZSCHRITTMACHER – AUSLESEBERICHT
Automatisch erzeugter Datenbericht
Keine originale Abbott-/Merlin-Dokumentation
```

Danach:

```text
Patient
-----------------------------------------
Name              LASTNAME, FIRSTNAME
Patient-ID        10358141
Geburtsdatum      21.10.1938
```

Dann:

```text
Gerät
-----------------------------------------
Hersteller        St. Jude Medical
Modell            Endurity Core
Modellnummer      2152
Seriennummer      5809481
Implantation      18.06.2024
Modus             VVI
Grundfrequenz     60 bpm
```

Danach die übrigen Kategorien.

---

# 18. Tabellen

Parameter sollen grundsätzlich tabellarisch dargestellt werden:

| Parameter                |  Wert | Einheit |
| ------------------------ | ----: | ------- |
| RV Pulse Amplitude       |   2.5 | V       |
| RV Pulse Width           |   0.4 | ms      |
| Ventricular Sensitivity  |   2.0 | mV      |
| RV Pacing Lead Impedance | 462.5 | Ohm     |

Die Originalwerte müssen erhalten bleiben.

Keine unnötige Rundung.

---

# 19. Rohdaten-Anhang

Optional über eine Einstellung aktivierbar:

```text
Originaldaten / Importdaten
```

Der Anhang soll sämtliche importierten Datensätze in der ursprünglichen Reihenfolge enthalten.

Format:

```text
ID | Parameter | Wert | Einheit
```

Beispiel:

```text
306 | RV Pulse Amplitude | 2.5 | V
2457 | Model Number: SJM Atrial Lead | 2088TC Tendril STS |
2432 | Follow-up Physician | |
```

---

# 20. Medizinische Neutralität

Die Anwendung darf ausschließlich Daten darstellen.

Nicht erlaubt sind automatisch generierte Aussagen wie:

```text
Batterie ist gesund.
Sonde ist defekt.
Patient ist schrittmacherabhängig.
Therapie ist optimal.
Wert ist pathologisch.
Auslesung ist unauffällig.
```

Es darf keine medizinische Diagnose oder Empfehlung generiert werden.

---

# 21. Datenschutz

Die Anwendung verarbeitet sensible Patientendaten.

Deshalb:

* keine Cloud
* keine externe API
* kein CDN
* keine externen Fonts
* keine Telemetrie
* keine Analytics
* kein automatisches Update
* keine Datenübertragung nach außen

Alle Daten bleiben innerhalb der Docker-Umgebung bzw. der vom Betreiber bereitgestellten lokalen Volumes.

---

# 22. Weboberfläche

Erstelle mindestens folgende Bereiche:

```text
Dashboard
Import
Berichte
Bericht anzeigen
Systeminformationen
```

## Dashboard

Zeige beispielsweise:

```text
Anzahl Patienten
Anzahl Geräte
Anzahl Berichte
Letzter Import
Letzter Bericht
```

## Import

Upload:

```text
TXT-Datei auswählen
```

Danach:

```text
Dateiname
Dateigröße
Hash
erkannte Datensätze
Fehler
Patient
Gerät
Auslesedatum
```

Vor dem endgültigen Speichern soll eine Importübersicht angezeigt werden.

---

# 23. Import-Transaktion

Der Import muss atomar sein.

Ablauf:

```text
Datei empfangen
       ↓
Parser
       ↓
Validierung
       ↓
Datenbank-Transaktion BEGIN
       ↓
Patient
Gerät
Leads
Import
Report
Parameter
       ↓
COMMIT
```

Bei Fehlern:

```text
ROLLBACK
```

Es darf kein teilweise gespeicherter Bericht entstehen.

---

# 24. Sicherheit

Implementiere mindestens:

* Prepared Statements
* CSRF-Schutz für POST-Aktionen
* sichere Session-Konfiguration
* Escaping aller HTML-Ausgaben
* Upload-Größenlimit
* Whitelist für erlaubte Dateiendungen
* MIME-/Inhaltsprüfung
* keine direkte Ausführung hochgeladener Dateien
* sichere Dateinamen
* Path-Traversal-Schutz
* keine SQL-Fehlermeldungen an den Benutzer
* Logging technischer Fehler

PDF-Dateien und TXT-Dateien dürfen nicht als ausführbare Inhalte behandelt werden.

---

# 25. Docker

Erstelle:

```text
Dockerfile
docker-compose.yml
```

Beispielarchitektur:

```text
docker-compose
│
├── web
│   ├── PHP 8.5
│   └── Apache
│
└── db
    └── MySQL 9.7 LTS
```

Persistente Volumes:

```text
mysql_data
application_data
import_data
```

Die Datenbank darf beim Neustart des Containers nicht verloren gehen.

---

# 26. Konfiguration

Keine Zugangsdaten hart codieren.

Verwende Umgebungsvariablen:

```text
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
APP_ENV
APP_TIMEZONE
UPLOAD_MAX_SIZE
```

Beispielsweise über:

```text
.env
```

Eine `.env.example` muss Bestandteil des Projekts sein.

---

# 27. Datenbankmigration

Erstelle ein reproduzierbares SQL-Schema:

```text
database/
└── schema.sql
```

Optional zusätzlich:

```text
database/
├── migrations/
│   ├── 001_initial.sql
│   └── ...
```

Die Anwendung soll bei einer neuen Installation reproduzierbar eingerichtet werden können.

---

# 28. Projektstruktur

Verwende beispielsweise:

```text
project/
│
├── public/
│   ├── index.php
│   ├── assets/
│   │   ├── css/
│   │   └── js/
│   └── ...
│
├── src/
│   ├── Config/
│   ├── Database/
│   ├── Import/
│   │   ├── MerlinParser.php
│   │   ├── ImportService.php
│   │   └── ImportValidator.php
│   ├── Model/
│   ├── Repository/
│   ├── Report/
│   │   ├── ReportService.php
│   │   └── PdfGenerator.php
│   ├── Security/
│   └── ...
│
├── templates/
│
├── database/
│   └── schema.sql
│
├── storage/
│
├── tests/
│
├── Dockerfile
├── docker-compose.yml
├── composer.json
├── .env.example
└── README.md
```

Frameworks sind weiterhin nicht erlaubt.

Composer darf ausschließlich für lokal installierte PHP-Bibliotheken verwendet werden.

---

# 29. Offline-Anforderung

Nach erfolgreicher Installation muss beispielsweise:

```text
docker compose up -d
```

ausreichen, um die Anwendung zu starten.

Im laufenden Betrieb darf die Anwendung keinen Internetzugriff benötigen.

Alle JavaScript-, CSS-, Font- und PHP-Abhängigkeiten müssen lokal verfügbar sein.

Keine Ressourcen wie:

```text
https://cdn...
https://fonts.googleapis.com...
https://unpkg.com...
```

verwenden.

---

# 30. Beispiel-CLI

Zusätzlich zur Weboberfläche soll optional ein CLI-Import möglich sein:

```bash
php bin/import.php /data/example.txt
```

und:

```bash
php bin/export-pdf.php 123 /data/report.pdf
```

Der CLI-PDF-Export soll dieselbe Report-Service-Logik verwenden wie die Weboberfläche.

Keine doppelte Implementierung des PDF-Generators.

---

# 31. Tests

Erstelle automatisierte Tests für mindestens:

### Parser

1. normales Datensatzformat
2. ASCII 0x1C
3. leere Werte
4. leere Einheiten
5. Dezimalwerte
6. Prozentwerte
7. Datumswerte
8. Sonderzeichen
9. unbekannte Parameter
10. fehlerhafte Datensätze
11. sehr lange Parameterbezeichnungen
12. unterschiedliche Zeilenumbrüche

### Datenbank

13. erfolgreicher Import
14. Rollback bei Fehler
15. Foreign-Key-Verhalten
16. Speicherung aller Parameter
17. erneutes Laden eines Reports
18. historische Reports bleiben unverändert

### PDF

19. PDF-Erzeugung
20. mehrseitige PDF
21. Sonderzeichen
22. lange Tabellen
23. unbekannte Parameter
24. vollständiger Report aus DB ohne Originaldatei

Besonders wichtig:

```text
Anzahl gültiger importierter Parameter
==
Anzahl gespeicherter Report-Parameter
```

abzüglich explizit als ungültig protokollierter Datensätze.

---

# 32. Historische Datenintegrität

Ein einmal importierter Bericht ist ein historischer Snapshot.

Wenn später:

* das Mapping geändert wird,
* eine Kategorie umbenannt wird,
* ein Parameter neu definiert wird,
* eine neue Softwareversion des Parsers existiert,

darf sich ein historischer Bericht nicht unkontrolliert verändern.

Dafür müssen relevante Informationen beim Import im Bericht gespeichert werden.

Beispielsweise:

```text
report_version
parser_version
parameter_name
category
original_value
unit
original_position
```

Der historische PDF-Export verwendet den gespeicherten Snapshot.

---

# 33. Berichtsversionierung

Jeder Bericht erhält eine Version, beispielsweise:

```text
report_version = 1
parser_version = 1.0.0
```

Bei zukünftigen Änderungen muss nachvollziehbar sein, mit welcher Parser-/Berichtsversion der Datensatz importiert wurde.

---

# 34. Beispiel-Eingabedaten

Verwende für automatisierte Tests mindestens:

```text
306RV Pulse Amplitude2.5V
2457Model Number: SJM Atrial Lead2088TC Tendril STS
2432Follow-up Physician
2680Event Histogram Auto Mode Switch Count0.0
1606RV. Capture Test Threshold Amplitude0.5V
101Programmer Model Number3650
875RV Lead Monitoring: Lower Limit200Ohms
9015Percent Ventricular Pacing AlertOn
203Device Last Interrogation Date and Time10/07/2026 07:03:24
2459Implant Date: Atrial Lead06/18/2024 00:00:00
9048VT/VF Detection Rate175bpm
520Battery Current9uA
2221Ventricular Sense Refractory250ms
519Unloaded Battery Voltage2.97792V
305RV Pulse Width0.4ms
103Application Software Version Number3330 v28.9.2 rev1
104Application Build Numberupsw-IRChinaSKU_Unity_3650-250617
9018Percentage Ventricular Pacing Limit40%
605MRI Atrial Pulse Width1.0ms
2463Implant Date: RV Lead06/18/2024 00:00:00
2722Ventricular Signal Amplitude4.9mV
413Measured Auto Slope10
2024Maximum Pacing Rate130bpm
848ATHR Threshold1.0mV
361Ventricular TriggeringOff
2755Total Time in AT/AF Recent Week0
1607RV. Capture Test Pulse Width0.4ms
604MRI RV Pulse Amplitude5.0V
201Device Model Number2152
302Base Rate60bpm
2008RV Pulse ConfigurationBipolar
2431Patient Date of Birth10/21/1938 00:00:00
308Ventricular Sensitivity2.0mV
309Ventricular Pace Refractory250ms
307Ventricular Sense ConfigurationBipolar
353RV AutoCaptureOff
2709Ventricular Paced - Lifetime (RVP)82.0%
2442Implant Date: Device06/18/2024 00:00:00
2754Total Number of AT/AF Episodes Since Last Cleared0
501Magnet Rate100.0ppm
873RV Lead MonitoringMonitor
2904A Sense PolarityBipolar
2708Atrial Paced - Lifetime6.4%
105Session Timestamp10/07/2026 07:03:24
2470RV Lead Serial NumberEEM126412
601MRI Base Rate85bpm
874RV Lead Monitoring: Upper Limit2000Ohms
2681Event Histogram Percent Paced In Ventricle67.0%
208Atrial Lead TypeBipolar
2461Model Number: SJM RV Pace/Sense Lead2088TC Tendril STS
406Maximum Sensor Rate130bpm
301ModeVVI
200Device Model NameEndurity Core
9049VT/VF No. of Consecutive Cycles5
606MRI RV Pulse Width1.0ms
202Device Serial Number5809481
533Longevity Estimate8.9yrs
321Magnet ResponseBattery Test
2456Manufacturer: Atrial LeadSt. Jude Medical
211RV Lead TypeBipolar
2468Atrial Lead Serial NumberEEL193668
602MRI Paced AV Delay120ms
303Hysteresis RateOff
603MRI Atrial Pulse Amplitude5.0V
204Patient ID10358141
2430Patient NameLASTNAME, FIRSTNAME
2460Manufacturer: RV LeadSt. Jude Medical
100Programmer Marketing NameMerlin
2004Ventricular Sensing ChamberRV
600MRI ModeDOO
2721Atrial Signal Amplitude2.2mV
507RV Pacing Lead Impedance462.5Ohm
2604V. Pulse Amp Decrement Capture Test: Capture Threshold (Pulse Amp)0.5V
2605V. Pulse Amp Decrement Capture Test: Test Pulse Width0.4ms
2913V. Pulse Amp Decrement Capture Test: Pulse ConfigurationB
```

---

# 35. Akzeptanzkriterien

Die Implementierung gilt erst dann als fertig, wenn alle folgenden Punkte erfüllt sind:

### Import

* Merlin-TXT-Datei kann hochgeladen werden.
* `0x1C` wird korrekt erkannt.
* Alle gültigen Datensätze werden gelesen.
* Keine unbekannten Parameter gehen verloren.
* Importfehler werden protokolliert.
* Import wird transaktional gespeichert.

### Datenbank

* Patienten werden gespeichert.
* Gerät wird gespeichert.
* Leads werden gespeichert.
* Import wird gespeichert.
* Report wird gespeichert.
* Alle Parameter werden als Report-Snapshot gespeichert.
* Historische Reports sind unabhängig von der Originaldatei verfügbar.

### Weboberfläche

* Dashboard vorhanden.
* Importseite vorhanden.
* Berichtsliste vorhanden.
* Berichtdetailseite vorhanden.
* Historischer Bericht kann erneut exportiert werden.

### PDF

* professionelles Layout
* mehrseitige PDFs
* Tabellen
* Kategorien
* Seitenzahlen
* Erstellungsdatum
* Hinweis auf automatisch erzeugten Bericht
* keine medizinischen Interpretationen
* unbekannte Parameter enthalten
* Originalwerte erhalten

### Offline

* keine CDN-Ressourcen
* keine externen APIs
* keine Internetabhängigkeit
* Docker-Deployment funktioniert offline

### Sicherheit

* Prepared Statements
* CSRF-Schutz
* sichere Uploads
* sichere Sessions
* HTML-Escaping
* keine direkte Dateiausführung
* keine Path-Traversal-Lücken

### Historie

Ein Benutzer kann beispielsweise einen Bericht von:

```text
10.07.2026
```

auch Monate später erneut als PDF exportieren, obwohl die ursprüngliche TXT-Datei nicht mehr vorhanden ist.

Der erzeugte Bericht muss auf dem damals gespeicherten Daten-Snapshot basieren.

---

# 36. Dokumentation

Erstelle eine ausführliche:

```text
README.md
```

mit:

1. Voraussetzungen
2. Docker-Installation
3. Konfiguration
4. Datenbankinitialisierung
5. Start der Anwendung
6. Import einer Merlin-Datei
7. Erzeugung eines PDFs
8. Export historischer Berichte
9. Backup
10. Restore
11. Datenbankstruktur
12. Parserbeschreibung
13. PDF-Generator
14. Sicherheitskonzept
15. Offlinebetrieb
16. Erweiterung des Parameter-Mappings

---

# 37. Backup und Restore

Da die Daten medizinisch relevante historische Informationen enthalten können, muss die Dokumentation ein Backup-Konzept beschreiben.

Mindestens:

```bash
mysqldump ...
```

sowie Wiederherstellung.

Auch das Verzeichnis mit archivierten Originaldateien soll berücksichtigt werden.

---

# 38. Entwicklungsprinzipien

Prioritäten:

1. Datenintegrität
2. Keine Datenverluste
3. Reproduzierbarkeit historischer Berichte
4. Sicherheit
5. Offlinefähigkeit
6. Wartbarkeit
7. Übersichtliche Benutzeroberfläche

Nicht auf Kosten der Datenintegrität vereinfachen.

Insbesondere darf der Parser nicht einfach „bereinigen“, runden oder interpretieren, wenn dadurch Informationen verloren gehen könnten.

---

# 39. Vorgehensweise des Coding-Agenten

Arbeite in folgenden Schritten:

### Phase 1 – Analyse

* Eingabeformat analysieren
* `0x1C` überprüfen
* Encoding prüfen
* Datensatzstruktur bestimmen
* Besonderheiten dokumentieren

### Phase 2 – Datenmodell

* MySQL-Schema erstellen
* Foreign Keys definieren
* Indizes definieren
* Snapshot-Konzept implementieren

### Phase 3 – Parser

* MerlinParser implementieren
* Unit-Tests erstellen
* vollständigen Beispielimport testen

### Phase 4 – Import

* Upload
* Validierung
* Transaktion
* Datenbankpersistenz

### Phase 5 – Weboberfläche

* Dashboard
* Import
* Berichtsliste
* Berichtdetail

### Phase 6 – PDF

* PDF-Generator
* Kategorien
* Tabellen
* Seitenumbrüche
* historische Exporte

### Phase 7 – Docker

* Dockerfile
* docker-compose.yml
* Volumes
* Konfiguration

### Phase 8 – Tests

* Unit-Tests
* Integrationstests
* Importtests
* PDF-Tests
* historische Exporttests

### Phase 9 – Dokumentation

* README
* Installation
* Backup/Restore
* Architektur
* Erweiterung des Mappings

---

# 40. Wichtigste Anforderung

Die Anwendung ist **kein einfacher TXT-zu-PDF-Konverter**.

Sie soll ein kleines lokales Informationssystem sein:

```text
Merlin-Auslesung
       ↓
    Import
       ↓
 strukturierter
 Daten-Snapshot
       ↓
     MySQL
       ↓
 ┌───────────────┐
 │               │
 ▼               ▼
Webansicht      PDF
 │               │
 └───────┬───────┘
         ▼
 Historische Berichte
```

Ein einmal importierter Bericht muss dauerhaft nachvollziehbar bleiben.

**Der historische Bericht muss auch dann erneut als PDF erzeugt werden können, wenn die ursprüngliche Merlin-TXT-Datei nicht mehr vorhanden ist.**

Die Implementierung darf keine medizinischen Interpretationen vornehmen und darf keine Annahmen über nicht explizit vorhandene Daten treffen.

Beginne mit der Analyse des Datenformats und der Erstellung des Datenmodells. Implementiere anschließend die Anwendung vollständig und liefere eine lokal ausführbare Docker-Umgebung inklusive Tests und Dokumentation.
