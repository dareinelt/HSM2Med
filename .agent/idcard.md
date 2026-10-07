Aufgabe

Erweitere das bestehende Projekt HSM2Med um die Funktion „Patientenausweis“.

Die Anwendung soll aus den bereits importierten Merlin-Nachsorgeberichten automatisch einen zweiseitigen Patientenausweis für Herzschrittmacher/ICD-Patienten als DIN-A4-PDF erzeugen können.

Die bestehende Architektur, Sicherheitsregeln, Snapshot-Prinzipien und Coding-Konventionen des Projekts sind verbindlich. HSM2Med ist eine PHP-8.5-/MySQL-Anwendung ohne Frameworks und ohne externe Laufzeitabhängigkeiten.

Die bestehende Import- und Berichtspipeline muss erhalten bleiben. Insbesondere sind importierte Berichte unveränderliche Snapshots und dürfen nachträglich nicht verändert werden.

Als visuelle Referenz dient das bereitgestellte Bild eines bestehenden Patientenausweises.

1. Zielbild

Es soll im Webfrontend eine Funktion geben:

„Neuen Patientenausweis erstellen“

Ausgangspunkt ist ein bereits importierter Nachsorgebericht.

Der Workflow soll:

einen bestehenden Nachsorgebericht auswählen,
daraus die verfügbaren Patientendaten, Geräte- und Sondendaten übernehmen,

den Patienten anhand von

Name
Vorname
Geburtsdatum

identifizieren,

bereits vorhandene Patientenausweisdaten und historische Daten ermitteln,
fehlende Informationen über einen mehrstufigen Assistenten mit Overlays/Modal-Dialogen abfragen,
dem Benutzer vor der endgültigen Zusammenführung eine Vorschau der alten und neuen Daten zeigen,
den Benutzer ausdrücklich bestätigen lassen:
dass die Daten korrekt sind,
dass es sich um den richtigen Patienten handelt,
anschließend den Patientenausweis als zweiseitiges DIN-A4-PDF erzeugen,
den Patientenausweis zusammen mit dem zugrunde liegenden Nachsorgebericht zum Download anbieten,
historische Patientenausweise und historische Nachsorgeberichte weiterhin verfügbar halten.
2. Bestehende Architektur zuerst analysieren

Bevor Code geändert wird:

Repository vollständig analysieren.
Bestehende Controller, Services, Repositories, Templates und PDF-Erzeugung identifizieren.
Bestehendes Datenbankschema und Migrationen analysieren.
Bestehende Import-/Report-Datenstrukturen analysieren.
Vorhandene PDF-Komponenten wiederverwenden.
Bestehende Tests und Test-Infrastruktur analysieren.
Keine neue Architektur oder Framework-Schicht einführen, wenn dies nicht erforderlich ist.

Die aktuelle Anwendung verwendet:

PHP 8.5 mit strict_types
PDO/MySQL
Vanilla JavaScript
handgeschriebenes CSS
eigenen PDF-Writer
keine Composer-/npm-/CDN-Abhängigkeiten.

Die bestehenden Schichten sollen beibehalten werden.

Insbesondere sollen neue Funktionen analog zu den vorhandenen Bereichen in

src/Http/Controller/
src/Repository/
src/... Service-/Domain-Schichten
templates/
public/assets/
database/migrations/
tests/

implementiert werden.

3. Stammdaten

Folgende Informationen müssen zentral als Stammdaten der Anwendung hinterlegt und administrierbar sein:

3.1 Logo

Das auf dem Patientenausweis verwendete Logo.

Es soll nicht pro Patient gespeichert werden.

Das Logo ist eine globale Einstellung und wird beim Erzeugen des PDFs verwendet.

Berücksichtige dabei:

Upload bzw. Hinterlegung des Logos,
persistente Speicherung,
Validierung des Dateityps,
geeignete Größen-/Dateigrößenbegrenzung,
Verwendung im PDF,
Austausch des Logos ohne Änderung historischer Patientenausweise.

Wichtig: Historische PDFs dürfen durch spätere Änderungen der Stammdaten nicht verändert werden.

Daher muss entweder:

das tatsächlich verwendete Logo im erzeugten Patientenausweis versioniert/snapshotartig referenziert werden oder
das erzeugte PDF selbst als unveränderliches Dokument archiviert werden.

Bevorzugt soll der erzeugte Patientenausweis-PDF als unveränderliches historisches Dokument gespeichert werden, analog zum bestehenden Prinzip unveränderlicher Berichte.

3.2 Betreuendes Nachsorgezentrum

Global hinterlegen:

Betreuendes Nachsorgezentrum:

mit mindestens:

Bezeichnung
Adresse

Die Werte werden automatisch in neu erzeugte Patientenausweise übernommen.

Änderungen an diesen Stammdaten dürfen historische Patientenausweise nicht verändern.

3.3 Hinweistexte

Folgende drei Texte müssen zentral hinterlegt und administrierbar sein:

Hinweise

Hinweise:

Der vollständige Text soll als zentraler Stammdatentext gespeichert werden.

Achtung Flugsicherheit

Achtung Flugsicherheit:

Der vollständige deutsche Text soll zentral gespeichert werden.

Attention Airline Security

Der vollständige englische Text soll zentral gespeichert werden.

Die Texte dürfen nicht hart im PDF-Generator codiert werden.

Auch hier gilt:

Änderungen an den Stammdatentexten dürfen bereits erzeugte historische Patientenausweise nicht verändern.

Die im Screenshot dargestellten Texte dienen als Layout-/Inhaltsreferenz.

4. Patientenausweis-Datenmodell

Es ist eine geeignete Persistenzstruktur für Patientenausweise einzuführen.

Wahrscheinlich werden mindestens benötigt:

Patientenausweis
eindeutige ID
Patient-ID
Erstellungsdatum
zugrunde liegender Nachsorgebericht
PDF-Datei bzw. unveränderlicher PDF-Snapshot
verwendete Stammdaten-Version
Status
Erstellungszeitpunkt
Patientenausweis-Daten

Der Patientenausweis soll die zum Zeitpunkt der Erstellung gültigen Daten enthalten, insbesondere:

Patient
Nachname
Vorname
Geburtsdatum
Straße
PLZ
Wohnort
Telefon
Indikation
Notfallkontakt
Name
Telefon
Hausarzt
Name
Praxis-Adresse
PLZ/Ort
Telefon
Gerät

Aus dem Nachsorgebericht:

Hersteller
Modell
Implantationsort
Implantationsdatum
Seriennummer
Elektroden

Je Elektrode:

Modell
Lokalisation
Implantationsdatum
Seriennummer
Nachsorgezentrum
Bezeichnung
Adresse
Kontrolle
Nächste Kontrolle
Arzt
Hinweis-/Sicherheitstexte
Hinweise
Achtung Flugsicherheit
Attention Airline Security
5. Welche Daten kommen aus dem Nachsorgebericht?

Der bestehende Import speichert den Merlin-Bericht als unveränderlichen Snapshot und leitet Patient/Gerät/Sonden daraus ab.

Der Patientenausweis soll vorhandene Daten nicht erneut vom Benutzer abfragen, wenn sie zuverlässig aus dem Nachsorgebericht gewonnen werden können.

Insbesondere sollen automatisch übernommen werden:

Patientendaten, soweit vorhanden
Gerät
Elektroden/Sonden
Implantationsdaten
Seriennummern
weitere relevante technische Daten

Die bestehende Parameterzuordnung ist zu verwenden. Sie arbeitet nach der Reihenfolge by_id → by_name → name_patterns → Fallback.

Keine medizinische Interpretation vornehmen.

Werte aus dem Import dürfen nicht medizinisch bewertet, gerundet, konvertiert oder normalisiert werden.

6. Daten, die über den Assistenten abgefragt werden

Daten, die nicht aus dem aktuellen Nachsorgebericht hervorgehen, müssen über den Assistenten abgefragt werden.

Insbesondere:

Patient
Name, falls nicht vorhanden
Vorname, falls nicht vorhanden
Geburtsdatum, falls nicht vorhanden
Straße
PLZ
Wohnort
Telefon
Indikation
Notfallkontakt
Name
Telefon
Hausarzt
Name
Praxis-Adresse
PLZ/Ort
Telefon
Kontrolle
Nächste Kontrolle
Arzt

Dabei gilt:

Vorhandene Daten sollen nicht unnötig erneut abgefragt werden.

Der Assistent soll nur fehlende oder vom Benutzer zu bestätigende Informationen abfragen.

7. Assistent / Overlay-Workflow

Beim Klick auf

„Neuen Patientenausweis erstellen“

soll kein riesiges Formular erscheinen.

Stattdessen soll ein Wizard mit Overlays/Modal-Dialogen verwendet werden.

Beispiel:

Schritt 1 – Patient identifizieren

Anzeige:

Patient identifizieren

Nachname
Vorname
Geburtsdatum

Diese Daten werden aus dem ausgewählten Nachsorgebericht vorausgefüllt.

Der Benutzer muss bestätigen:

„Ja, dies ist der richtige Patient.“

Ohne diese explizite Bestätigung darf der Workflow nicht fortgesetzt werden.

Schritt 2 – Patientendaten ergänzen

Nur fehlende Daten abfragen:

Straße
PLZ
Wohnort
Telefon
Indikation
Schritt 3 – Notfallkontakt
Name
Telefon
Schritt 4 – Hausarzt
Name
Praxis-Adresse
PLZ/Ort
Telefon
Schritt 5 – Nachsorge / Kontrolle
Nächste Kontrolle
Arzt
Schritt 6 – Zusammenfassung

Vor der Speicherung müssen alle neuen und bereits vorhandenen Daten gemeinsam angezeigt werden.

Dabei muss klar erkennbar sein:

Daten aus dem aktuellen Nachsorgebericht
bereits gespeicherte Daten
neu eingegebene Daten
gegebenenfalls widersprüchliche Daten
8. Zusammenführung alter und neuer Daten

Dieser Punkt ist besonders wichtig.

Vor einer Zusammenführung darf das System nicht einfach automatisch Daten überschreiben.

Stattdessen:

Vergleichsansicht

Beispielsweise:

Feld	Bisheriger Wert	Neuer Wert	Aktion
Straße	Musterstraße 1	Musterstraße 5	neuer Wert
Telefon	0531...	0531...	neuer Wert
Hausarzt	Dr. A	Dr. B	neuer Wert
Implantationsdatum	27.12.2018	27.12.2018	unverändert

Der Benutzer muss die Zusammenführung ausdrücklich bestätigen.

Zusätzlich muss er bestätigen:

„Ich bestätige, dass die angezeigten Daten zum richtigen Patienten gehören und zusammengeführt werden dürfen.“

Ohne diese Bestätigung:

keine Speicherung / keine PDF-Erzeugung.

9. Patientenidentifikation

Patienten werden anhand folgender Kombination identifiziert:

Nachname + Vorname + Geburtsdatum

Diese Kombination muss im Datenmodell eindeutig bzw. eindeutig auflösbar behandelt werden.

Nicht ausschließlich anhand von:

Seriennummer
Patienten-ID
Import-ID
Bericht-ID

identifizieren.

Bei mehreren Treffern darf nicht automatisch entschieden werden.

Dann muss der Benutzer den korrekten Patienten auswählen bzw. bestätigen.

10. Umgang mit historischen Daten

Die Anwendung muss eine Historie führen.

Beispiel:

Patient hat Nachsorgeberichte:

27.12.2018
25.03.2019
25.06.2019
25.09.2019

Bei jedem neuen Nachsorgebericht kann ein neuer Patientenausweis erzeugt werden.

Alte Patientenausweise dürfen nicht überschrieben werden.

Der Benutzer soll historische Dokumente weiterhin herunterladen können.

11. Seite 2 – vergangene Nachsorgeuntersuchungen

Die zweite PDF-Seite muss einen Abschnitt für

Vergangene Nachsorgeuntersuchungen

enthalten.

Dort sollen die historischen Nachsorgeuntersuchungen des Patienten aufgeführt werden.

Mindestens:

Datum
ggf. relevante Berichtsinformation / Arzt / Nachsorgezentrum, sofern aus den vorhandenen Daten ableitbar

Die Daten stammen aus den bereits gespeicherten historischen Nachsorgeberichten.

Die vorhandenen Reports sind unveränderliche Snapshots und müssen als historische Quelle verwendet werden.

Neue Untersuchungen dürfen alte Untersuchungen nicht verändern.

12. PDF-Layout

Der Patientenausweis muss als

DIN A4, exakt zwei Seiten

erzeugt werden.

Orientiere dich visuell eng an der bereitgestellten Referenz:

Seite 1: Patientenausweis
Seite 2: Mess-/Historien-/Nachsorgeinformationen
klarer Kopfbereich
Logo
Tabellen
deutsche und englische Sicherheitshinweise
medizinisch-technische Informationen
Druckbarkeit auf DIN A4
saubere Seitenumbrüche
keine abgeschnittenen Inhalte

Die bestehende PDF-Infrastruktur soll wiederverwendet werden. Der aktuelle PdfGenerator erzeugt bereits PDFs ausschließlich aus Datenbankdaten und verfügt über Versionsmechanismen.

Für den neuen Patientenausweis soll ein eigener PDF-Dokumenttyp bzw. eine klar getrennte PDF-Generator-Komponente entstehen, statt den bestehenden Nachsorgebericht unübersichtlich zu erweitern.

Beispielsweise:

src/PatientCard/
    PatientCardService.php
    PatientCardData.php
    PatientCardRepository.php
    PatientCardPdfGenerator.php

Die tatsächliche Struktur darf an die bestehende Architektur angepasst werden.

13. Historische PDFs

Ein bereits erzeugter Patientenausweis ist ein historisches Dokument.

Deshalb darf sich ein altes PDF nicht verändern, wenn später:

das Logo geändert wird,
die Adresse des Nachsorgezentrums geändert wird,
Hinweistexte geändert werden,
Patientendaten geändert werden,
neue Nachsorgeberichte importiert werden.

Das erzeugte PDF muss daher unveränderlich archiviert werden.

Der Benutzer muss historische Patientenausweise weiterhin herunterladen können.

14. Download

Im Bereich eines Nachsorgeberichts sollen mindestens folgende Downloads angeboten werden:

Nachsorgebericht PDF
Original/Nachsorge-Importdatei, sofern bereits vorhanden
Patientenausweis PDF, falls vorhanden

Zusätzlich soll es eine historische Übersicht der Patientenausweise geben.

Beispielsweise:

Patientenausweise

27.12.2018   Patientenausweis   PDF
25.03.2019   Patientenausweis   PDF
25.06.2019   Patientenausweis   PDF

Alle historischen Dokumente müssen separat herunterladbar sein.

15. UI / Navigation

Die Funktion soll sich natürlich in die bestehende Oberfläche integrieren.

Die bestehende Anwendung besitzt unter anderem:

Dashboard
Import
Berichtsübersicht
Berichtdetail
Importprotokoll
Systeminformationen.

Auf der Berichtdetailseite soll eine prominent sichtbare Aktion ergänzt werden:

Neuen Patientenausweis erstellen

Falls für den Bericht bereits ein Patientenausweis existiert, soll zusätzlich angezeigt werden:

Patientenausweis anzeigen

bzw.

Patientenausweis herunterladen

und eine Historie der bisherigen Ausweise.

16. Stammdatenverwaltung

Die zentralen Einstellungen sollen über einen neuen Bereich verwaltbar sein, z. B.:

System → Patientenausweis-Stammdaten

Dort:

Nachsorgezentrum
Bezeichnung
Adresse
Logo
Upload
aktuelles Logo anzeigen
Logo ersetzen
Texte
Hinweise
Achtung Flugsicherheit
Attention Airline Security

Die bestehende Anwendung besitzt bereits eine Systemseite.

Die neue Funktion soll sich dort sinnvoll integrieren.

17. Datenbank

Für die neue Funktion sind neue Migrationen anzulegen.

Bestehende Migrationen dürfen niemals verändert werden.

Das Projekt verlangt ausdrücklich neue Migrationen und anschließend die Neuerzeugung von database/schema.sql.

Entwirf ein sauberes Schema, beispielsweise mit:

patient_cards
patient_card_versions oder vergleichbarer Snapshot-Struktur
patient_card_settings
gegebenenfalls patient_card_history
gegebenenfalls gespeicherten PDF-Dateien

Die konkrete Tabellenstruktur ist anhand des bestehenden Schemas zu entscheiden.

Wichtig:

Fremdschlüssel
ON DELETE RESTRICT
eindeutige Patientenzuordnung
unveränderliche historische Dokumente
Zeitstempel
referenzierter Nachsorgebericht
Snapshot der für das PDF verwendeten Daten

Bestehende Berichte dürfen nicht verändert werden.

18. Versionsverwaltung

Der bestehende Bericht verwendet:

report_version
parser_version
mapping_version

und historische Berichte müssen weiterhin reproduzierbar bleiben.

Für den Patientenausweis ist ein vergleichbares Versionskonzept einzuführen.

Beispielsweise:

PATIENT_CARD_VERSION = 1.0

Wenn sich das strukturelle Datenmodell oder das PDF-Layout später inkompatibel ändert, muss die Version erhöht werden.

Alte Patientenausweise müssen weiterhin heruntergeladen werden können.

19. Sicherheit

Alle bestehenden Sicherheitsregeln gelten auch für die neue Funktion.

Insbesondere:

alle POST-Anfragen mit CSRF
alle Ausgaben escapen
vorbereitete SQL-Statements
keine Patientendaten in Logs
keine sensiblen Daten in URL-Query-Strings
keine neuen PHP-Dateien unter public/
keine Zugangsdaten im Code
Uploads validieren
PDF-Dateien nicht direkt aus beliebigen Benutzerpfaden ausliefern.

Die bestehenden Sicherheitsanforderungen umfassen CSRF, sichere Sessions, CSP, vorbereitete Statements und besonderen Schutz der Gesundheitsdaten nach Art. 9 DSGVO.

20. Datenschutz / Logging

Keine medizinischen oder persönlichen Patientendaten in Logs schreiben.

Auch insbesondere nicht:

Name
Vorname
Geburtsdatum
Adresse
Telefonnummer
Hausarzt
Notfallkontakt
medizinische Parameter.

Nur technische Referenz-IDs verwenden.

Das entspricht dem bestehenden Logging-Modell.

21. Keine medizinische Logik

Der Patientenausweis ist ein Dokumentengenerator.

Die Anwendung darf:

Werte übernehmen,
Werte anzeigen,
historische Werte darstellen.

Sie darf keine medizinische Bewertung durchführen.

Keine:

Diagnose
Therapieempfehlung
Risikobewertung
Interpretation von Messwerten
automatische Korrektur medizinischer Werte.

Dies ist eine harte Projektregel.

22. Konflikte zwischen Daten

Wenn beispielsweise ein bestehender Patient folgende Adresse besitzt:

Musterstraße 1

und der neue Nachsorgebericht bzw. Benutzer eingibt:

Musterstraße 5

darf das System nicht stillschweigend überschreiben.

Stattdessen:

Bisher:
Musterstraße 1

Neu:
Musterstraße 5

und Benutzerentscheidung/Bestätigung verlangen.

Das gleiche gilt für:

Telefon
Hausarzt
Notfallkontakt
Patientendaten.

Technische Gerätedaten aus dem aktuellen Nachsorgebericht sollen dagegen als Bestandteil dieses konkreten Nachsorge-Snapshots erhalten bleiben.

23. Validierung

Der Wizard muss sinnvolle Validierungen besitzen.

Mindestens:

Pflichtfelder für Name/Vorname/Geburtsdatum
gültiges Geburtsdatum
keine versehentliche Zuordnung zu einem anderen Patienten
Bestätigung des richtigen Patienten
Bestätigung der Zusammenführung
sinnvolle Pflichtfelder für die PDF-Erzeugung.

Keine medizinische Validierung durchführen.

24. Bestehenden Import nicht beschädigen

Die Erweiterung darf die bestehende Importpipeline nicht verändern, außer wenn dies für die neue Funktion zwingend erforderlich ist.

Die bestehende Pipeline lautet:

Upload
→ Validierung
→ PendingUploadStore
→ MerlinParser
→ ParameterMapping
→ ReportSummaryBuilder
→ ImportValidator
→ Import
→ unveränderlicher Report-Snapshot

und muss erhalten bleiben.

25. Tests

Implementiere umfangreiche Tests.

Mindestens:

Patientenerkennung
gleicher Name + Vorname + Geburtsdatum → gleicher Patient
anderer Geburtstag → anderer Patient
gleicher Name/Vorname, aber mehrere Geburtsdaten → unterschiedliche Patienten
mehrere Treffer → keine automatische Entscheidung
Datenübernahme
vorhandene Daten werden korrekt übernommen
fehlende Daten werden erkannt
Benutzerangaben werden gespeichert
Importdaten werden nicht verändert
Konflikte
alte und neue Adresse
alter und neuer Hausarzt
alter und neuer Notfallkontakt
Konflikt muss angezeigt werden
keine stille Überschreibung
Bestätigungen
ohne Patientenbestätigung kein Patientenausweis
ohne Zusammenführungsbestätigung kein Speichern
ohne Bestätigung keine PDF-Erzeugung
Historie
alter Patientenausweis bleibt erhalten
neuer Patientenausweis erzeugt neue Version
neue Nachsorgeuntersuchung verändert alte Dokumente nicht
historische Untersuchungen werden auf Seite 2 dargestellt
Stammdaten
Logo
Nachsorgezentrum
deutsche Hinweistexte
englischer Sicherheitstext
Änderung der Stammdaten beeinflusst alte PDFs nicht
PDF
exakt zwei Seiten
DIN A4
Patientendaten enthalten
Gerät enthalten
Elektroden enthalten
Sicherheitshinweise enthalten
historische Nachsorgeuntersuchungen enthalten
keine abgeschnittenen Inhalte.

Die bestehende Teststruktur verwendet eigene Unit-/Integrationstests ohne externe Testbibliothek.

26. PDF-Regressionstest

Erstelle mindestens einen automatisierten Test, der das erzeugte PDF strukturell überprüft.

Der Test soll mindestens feststellen:

PDF existiert
→ PDF ist lesbar
→ Anzahl Seiten = 2
→ A4-Format
→ erwartete Schlüsseltexte vorhanden

Die bestehende Testumgebung besitzt bereits Unterstützung zur PDF-Textauswertung (tests/Support/PdfText.php).

Nutze diese Infrastruktur, statt eine neue externe PDF-Testbibliothek einzuführen.

27. Keine neuen Abhängigkeiten

Keine Composer-Abhängigkeit.

Kein npm-Paket.

Kein CDN.

Keine externe JavaScript-Bibliothek.

Das Projekt ist bewusst vollständig abhängigkeitsfrei.

Wizard/Overlay daher mit Vanilla JavaScript umsetzen.

28. Visuelles Ergebnis

Die bereitgestellte Patientenausweis-Grafik ist als Referenz zu verwenden.

Das Ergebnis soll optisch einen professionellen medizinischen Patientenausweis darstellen:

klare Typografie
Logo oben links
Überschrift „Schrittmacher - Patientenausweis“
englische Bezeichnung „Patient Identification Card“
strukturierte Patientendaten
Gerätetabelle
Elektrodentabelle
Hinweise
Flugsicherheit
Nachsorgezentrum
nächste Kontrolle
Arzt
historische Nachsorgeuntersuchungen auf Seite 2.

Nicht einfach einen bestehenden Nachsorgebericht verkleinern oder kopieren.

Es soll ein eigener Dokumenttyp „Patientenausweis“ entstehen.

29. Dateinamen

Für Patientenausweise einen konsistenten Dateinamen einführen, z. B.:

Patientenausweis_<Patient>_<Datum>.pdf

Dabei keine problematischen Sonderzeichen verwenden.

Die bestehende FileName::sanitize()-Logik soll verwendet bzw. entsprechend erweitert werden.

30. Vorgehensweise des Coding-Agenten

Arbeite in dieser Reihenfolge:

Phase 1 – Analyse
Repository untersuchen.
Datenbankschema untersuchen.
bestehende Patient-/Report-Repositories untersuchen.
PDF-Implementierung untersuchen.
UI-/Routing-Struktur untersuchen.
vorhandene Tests untersuchen.
feststellen, welche Merlin-Parameter für Patient, Gerät und Elektroden bereits verfügbar sind.
Phase 2 – Architektur
Datenmodell entwerfen.
neue Migration erstellen.
Service-/Repository-Struktur festlegen.
PDF-Snapshot-Strategie festlegen.
Wizard-Workflow entwerfen.
Phase 3 – Implementierung
Migration
Models/DTOs/Repositories
Patientenausweis-Service
Stammdatenverwaltung
Controller/Routen
Wizard/Overlays
Zusammenführungs-/Bestätigungsdialog
Patientenausweis-PDF
Historienansicht
Download-Funktionen
Phase 4 – Tests
Unit-Tests
Integrationstests
PDF-Tests
Regressionstests bestehender Funktionalität.
Phase 5 – UI-Prüfung

Screenshots mit der vorhandenen Dokumentations-/Screenshot-Infrastruktur erzeugen. Die bestehende Infrastruktur verwendet hierfür Playwright/Chromium.

Das UI insbesondere auf:

Overlay-Verhalten
Tabellen
responsive Darstellung
PDF-Vorschau
Fehlermeldungen
Bestätigungsdialoge

prüfen.

31. Akzeptanzkriterien

Die Aufgabe ist erst abgeschlossen, wenn alle folgenden Kriterien erfüllt sind:

 „Neuen Patientenausweis erstellen“ ist aus einem Nachsorgebericht erreichbar.
 Patient wird anhand von Nachname + Vorname + Geburtsdatum identifiziert.
 Benutzer muss bestätigen, dass es der richtige Patient ist.
 Daten aus dem Nachsorgebericht werden automatisch übernommen.
 Fehlende Daten werden über einen Wizard abgefragt.
 Wizard verwendet Overlays/Modal-Dialoge.
 Notfallkontakt kann erfasst werden.
 Hausarzt kann erfasst werden.
 restliche Patientendaten können erfasst werden.
 nächste Kontrolle kann erfasst werden.
 Arzt kann erfasst werden.
 alte und neue Daten werden vor Zusammenführung angezeigt.
 Benutzer muss die Zusammenführung ausdrücklich bestätigen.
 Logo ist zentral konfigurierbar.
 Nachsorgezentrum ist zentral konfigurierbar.
 Hinweistext ist zentral konfigurierbar.
 deutscher Flugsicherheitstext ist zentral konfigurierbar.
 englischer Flugsicherheitstext ist zentral konfigurierbar.
 Änderungen an Stammdaten verändern alte PDFs nicht.
 Patientenausweis ist exakt zwei Seiten lang.
 Format ist DIN A4.
 Seite 2 enthält vergangene Nachsorgeuntersuchungen.
 historische Patientenausweise bleiben verfügbar.
 historische Nachsorgeberichte bleiben verfügbar.
 Patientenausweis und Nachsorgebericht können heruntergeladen werden.
 keine bestehende Nachsorgebericht-Daten werden überschrieben.
 keine medizinische Bewertung wird eingeführt.
 keine neuen externen Laufzeitabhängigkeiten.
 CSRF-/Security-Regeln bleiben eingehalten.
 keine Patientendaten werden geloggt.
 Migrationen sind neu und unveränderlich.
 database/schema.sql wird nach Migration neu generiert.
 Unit- und Integrationstests bestehen.
 bestehende Tests bestehen weiterhin.
 PDF-Regressionstest besteht.
 PHP-Syntaxprüfung besteht.
32. Abschlussbericht des Coding-Agenten

Nach der Implementierung keinen allgemeinen Fließtext liefern, sondern einen strukturierten Abschlussbericht mit:

## Implementiert

- ...

## Datenbankänderungen

- ...

## Neue Routen

- ...

## Neue Komponenten

- ...

## PDF

- ...

## Tests

- ...

## Manuell geprüft

- ...

## Offene Punkte

- ...

Außerdem die tatsächlich ausgeführten Testbefehle und deren Ergebnis nennen.

Mindestens ausführen:

php -l ...
docker compose --profile test run --rm tests

und bei relevanten Änderungen zusätzlich die passenden gezielten Tests.

Die Projektkonvention schreibt nach Änderungen mindestens Syntaxprüfung und den passenden Testlauf vor.

Wichtig: Nicht nur Code schreiben. Erst die vorhandene Architektur verstehen, anschließend implementieren und abschließend die komplette Funktion einschließlich PDF, Historie, UI und Tests verifizieren.
