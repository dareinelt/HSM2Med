"""
Erzeugt Screenshots der Weboberflaeche und des PDF-Berichts fuer die Dokumentation.

Laeuft ausschliesslich im Docker-Container gegen eine eigene, fluechtige Instanz
(Profil "docs" in docker-compose.yml); Produktivdaten werden nicht beruehrt:

    docker compose --profile docs run --rm screenshots
    docker compose --profile docs down

Verwendet wird nur die anonymisierte Beispieldatei tests/fixtures/merlin_sample.log.
"""

from __future__ import annotations

import os
import struct
import subprocess
import sys
import tempfile
import zlib
from pathlib import Path

from playwright.sync_api import Page, sync_playwright

BASE_URL = os.environ.get("BASE_URL", "http://web-docs").rstrip("/")
SAMPLE = Path(os.environ.get("SAMPLE_FILE", "/work/tests/fixtures/merlin_sample.log"))
OUT = Path(os.environ.get("OUT_DIR", "/work/docs/screenshots"))
VIEWPORT = {"width": 1366, "height": 900}

# Zugangsdaten der Screenshot-Instanz (siehe Profil "docs" in docker-compose.yml).
ADMIN_USERNAME = os.environ.get("ADMIN_USERNAME", "docs-admin")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "docs-screenshot-kennwort")

# Beispielstammdaten des Patientenausweises (frei erfunden, kein Patientenbezug).
CENTER_NAME = "Herzzentrum Musterstadt - Nachsorgezentrum"
CENTER_ADDRESS = "Nachsorgezentrum Schrittmacher\nMusterstrasse 1\n12345 Musterstadt\nTelefon 01234 56789"
NOTICE_TEXT = (
    "Dieser Ausweis enthaelt die technischen Daten des implantierten Systems und dient der "
    "Weitergabe an medizinisches Fachpersonal. Bitte fuehren Sie ihn bei jeder Kontrolle mit. "
    "Die Angaben ersetzen keine aerztliche Beurteilung."
)
FLIGHT_NOTICE_DE = (
    "Achtung Flugsicherheit: Das implantierte System kann Metalldetektoren ausloesen. Bitte "
    "weisen Sie das Sicherheitspersonal auf diesen Ausweis hin und bitten Sie um eine Kontrolle "
    "ohne Handscanner."
)
FLIGHT_NOTICE_EN = (
    "Attention Airline Security: This implanted device may trigger metal detectors. Please show "
    "this card and ask for a screening that avoids the hand-held scanner."
)

# Beispielakte (frei erfunden, kein Patientenbezug).
AKTE_LAST_NAME = "Beispielpatient"
AKTE_FIRST_NAME = "Berta"
AKTE_BIRTH_DATE = "04.05.1949"
AKTE_IDENTIFIER = "MUSTER-1001"
AKTE_INDICATION = "Bradykardie mit Synkope, geplante Schrittmacherimplantation"
AKTE_ANAMNESIS = (
    "Belastungsdyspnoe seit etwa drei Monaten, zuletzt auch in Ruhe.\n"
    "Synkope am 12.06.2026 mit Sturz ohne Verletzungsfolge.\n"
    "Arterielle Hypertonie, Z.n. Katarakt-Operation beidseits.\n"
    "Keine Allergien bekannt, keine Antikoagulation."
)
AKTE_AUTHOR = "Dr. med. Anna Beispiel"
AKTE_BEFUND = (
    "Regelmaessiger Eigenrhythmus unter Ruhebedingungen, keine Sondenauffaelligkeit.\n"
    "Reizschwellen und Wahrnehmung stabil, Programmierung unveraendert gelassen.\n"
    "Kontrolle der Sonde in sechs Monaten."
)
AKTE_PREMEDICATION = [
    ("Bisoprolol", "2,5", "mg", "1-0-0", "Bradykardie", "01.03.2024"),
    ("Ramipril", "5", "mg", "1-0-0", "Arterielle Hypertonie", "01.03.2024"),
]
AKTE_EPICRISIS = (
    "Stationaere Aufnahme zur Schrittmacherimplantation bei symptomatischer Bradykardie.\n"
    "Komplikationsloser Verlauf, Entlassung am dritten postoperativen Tag.\n"
    "Empfehlung: Kontrolle der Sonde in sechs Monaten, Ausweis bei jeder Kontrolle vorlegen."
)


def shot(page: Page, name: str, full_page: bool = True) -> None:
    target = OUT / f"{name}.png"
    if full_page:
        # Funktionsband und Titelleiste kleben ("sticky"). Nach Eingaben hat Playwright die
        # Seite bereits gescrollt; Chromium rendert klebende Elemente dann an der aktuellen
        # Scrollposition und der Seitenkopf fehlt am oberen Bildrand. Deshalb zurueckscrollen.
        page.evaluate("window.scrollTo(0, 0)")
    page.screenshot(path=str(target), full_page=full_page)
    print(f"  {target.name}")


def upload(page: Page, path: str) -> None:
    page.goto(f"{BASE_URL}/import")
    page.set_input_files("input[name=file]", path)
    page.click("form.upload-form button[type=submit]")
    page.wait_for_load_state()


def login(page: Page, username: str = ADMIN_USERNAME, password: str = ADMIN_PASSWORD) -> None:
    """Meldet sich ueber das Anmeldefenster an.

    Die Anwendung ist ohne Anmeldung nicht bedienbar: Beim Aufruf erscheint die Oberflaeche
    gesperrt hinter dem Anmeldefenster. Alle weiteren Aufrufe setzen diese Anmeldung voraus.
    """

    page.goto(f"{BASE_URL}/")
    page.wait_for_selector(".login-card")
    page.fill("#login-username", username)
    page.fill("#login-password", password)
    page.click(".login-card button[type=submit]")
    page.wait_for_load_state()
    if page.locator(".login-card").count() > 0:
        raise SystemExit("Anmeldung an der Weboberflaeche fehlgeschlagen.")


def logo_png(width: int = 320, height: int = 96) -> bytes:
    """Synthetisches Beispiel-Logo (PNG, 8 Bit RGB) - ohne GD und ohne externe Dateien."""

    def chunk(tag: bytes, data: bytes) -> bytes:
        payload = tag + data
        return struct.pack(">I", len(data)) + payload + struct.pack(">I", zlib.crc32(payload) & 0xFFFFFFFF)

    rows = bytearray()
    for y in range(height):
        rows.append(0)
        for x in range(width):
            border = x < 5 or x >= width - 5 or y < 5 or y >= height - 5
            cross = (width // 2 - 12 <= x < width // 2 + 12 and 24 <= y < height - 24) or (
                60 <= x < width - 60 and height // 2 - 12 <= y < height // 2 + 12
            )
            if border:
                pixel = (0, 84, 160)
            elif cross:
                pixel = (192, 0, 40)
            else:
                pixel = (255, 255, 255)
            rows.extend(pixel)

    header = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", header)
        + chunk(b"IDAT", zlib.compress(bytes(rows), 9))
        + chunk(b"IEND", b"")
    )


def pdf_pages(tmp: Path, data: bytes, stem: str) -> int:
    """Seitenzahl eines PDFs ueber pdfinfo."""

    path = tmp / f"{stem}.pdf"
    path.write_bytes(data)
    result = subprocess.run(["pdfinfo", str(path)], check=True, capture_output=True, text=True)
    for line in result.stdout.splitlines():
        if line.startswith("Pages:"):
            return int(line.split(":", 1)[1].strip())
    raise RuntimeError("pdfinfo liefert keine Seitenzahl")


def pdf_text(tmp: Path, data: bytes, stem: str) -> str:
    """Textebene eines PDFs ueber pdftotext."""

    path = tmp / f"{stem}.pdf"
    path.write_bytes(data)
    result = subprocess.run(
        ["pdftotext", "-layout", str(path), "-"], check=True, capture_output=True, text=True
    )
    return result.stdout


def render_pdf(tmp: Path, data: bytes, names: list[str]) -> None:
    """Rendert die ersten len(names) Seiten eines PDFs als PNG-Screenshots."""

    stem = names[0]
    pdf_path = tmp / f"{stem}.pdf"
    pdf_path.write_bytes(data)
    prefix = tmp / f"{stem}-page"
    subprocess.run(
        ["pdftoppm", "-png", "-r", "80", "-f", "1", "-l", str(len(names)), str(pdf_path), str(prefix)],
        check=True,
    )
    for index, page_file in enumerate(sorted(tmp.glob(f"{stem}-page-*.png"))):
        if index >= len(names):
            break
        target = OUT / f"{names[index]}.png"
        target.write_bytes(page_file.read_bytes())
        print(f"  {target.name}")


def main() -> int:
    if not SAMPLE.is_file():
        print(f"Beispieldatei fehlt: {SAMPLE}", file=sys.stderr)
        return 1
    OUT.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(viewport=VIEWPORT, locale="de-DE")
        page = context.new_page()

        print("Screenshots:")
        # Ohne Anmeldung wird die Oberflaeche gesperrt hinter dem Anmeldefenster geladen.
        page.goto(f"{BASE_URL}/")
        if page.locator(".login-card").count() == 0:
            raise SystemExit("Das Anmeldefenster fehlt.")
        shot(page, "54-anmeldung", full_page=False)

        login(page)
        page.goto(f"{BASE_URL}/")
        shot(page, "01-dashboard-leer")

        # Der Patientenvorgang ist fuehrend: ohne aktiven Patienten leitet der
        # Import auf die Patientenauswahl um.
        page.goto(f"{BASE_URL}/import")
        if "Kein Patient gewählt" not in page.content():
            raise SystemExit("Der Hinweis auf den fehlenden Patienten fehlt.")
        shot(page, "47-patientenvorgang-gesperrt")

        # --- Patientenakte: Patient anlegen, wird automatisch aktiv ------------
        page.goto(f"{BASE_URL}/patients")
        shot(page, "24-akte-uebersicht")

        page.goto(f"{BASE_URL}/patients/new")
        shot(page, "25-akte-anlegen")

        page.fill("input[name=last_name]", AKTE_LAST_NAME)
        page.fill("input[name=first_name]", AKTE_FIRST_NAME)
        page.fill("input[name=date_of_birth]", AKTE_BIRTH_DATE)
        page.fill("input[name=patient_identifier]", AKTE_IDENTIFIER)
        page.fill("input[name=street]", "Aktenweg 3")
        page.fill("input[name=postal_code]", "12345")
        page.fill("input[name=city]", "Musterstadt")
        page.fill("input[name=phone]", "01234 567893")
        page.fill("textarea[name=indication]", AKTE_INDICATION)
        page.click("form button[type=submit]")
        page.wait_for_load_state()
        patient_url = page.url
        shot(page, "26-akte-patient")

        page.goto(f"{BASE_URL}/patients")
        if "Aktiver Patient" not in page.content():
            raise SystemExit("Der neu angelegte Patient wurde nicht aktiv gesetzt.")
        shot(page, "48-patient-aktiv")

        # --- Import: jetzt ist der Patientenvorgang freigeschaltet -------------
        page.goto(f"{BASE_URL}/import")
        shot(page, "02-import-formular")

        upload(page, str(SAMPLE))
        page.wait_for_url("**/import/*")
        shot(page, "03-import-vorschau")

        duplicate = page.locator("input[name=allow_duplicate]")
        if duplicate.count() > 0:
            duplicate.check()
        page.click("button[data-once]")
        page.wait_for_url("**/reports/*")
        shot(page, "04-bericht-detail")
        report_url = page.url

        page.goto(f"{BASE_URL}/reports")
        shot(page, "05-berichtsuebersicht")

        page.goto(f"{BASE_URL}/imports")
        shot(page, "06-importprotokoll")

        page.locator("a[href^='/imports/']").first.click()
        page.wait_for_load_state()
        shot(page, "07-import-detail")

        page.goto(f"{BASE_URL}/system")
        shot(page, "08-systemstatus")

        # Erneuter Upload derselben Datei: Dublettenhinweis
        upload(page, str(SAMPLE))
        shot(page, "09-import-dublette")

        # Ungueltige Datei: Validierung blockiert den Import
        with tempfile.NamedTemporaryFile("wb", suffix=".txt", delete=False) as bad:
            bad.write(b"Dies ist keine Merlin-Exportdatei.\n")
        upload(page, bad.name)
        shot(page, "10-import-abgelehnt")

        page.goto(f"{BASE_URL}/")
        shot(page, "11-dashboard")

        # --- Bausteine der Akte: Anamnese, Vormedikation -----------------------
        page.goto(f"{patient_url}/records/anamnesis")
        page.fill("textarea[name=text]", AKTE_ANAMNESIS)
        page.fill("input[name=author_name]", AKTE_AUTHOR)
        page.click("form button[type=submit]")
        page.wait_for_load_state()
        shot(page, "27-akte-anamnese")

        page.goto(f"{patient_url}/records/premedication")
        for index, (substance, dose, unit, schedule, reason, since) in enumerate(AKTE_PREMEDICATION):
            if index > 0:
                page.click("[data-repeat-add]")
            page.fill(f'input[name="medication[{index}][substance]"]', substance)
            page.fill(f'input[name="medication[{index}][dose]"]', dose)
            page.fill(f'input[name="medication[{index}][unit]"]', unit)
            page.fill(f'input[name="medication[{index}][schedule]"]', schedule)
            page.fill(f'input[name="medication[{index}][reason]"]', reason)
            page.fill(f'input[name="medication[{index}][from]"]', since)
        page.fill("input[name=author_name]", AKTE_AUTHOR)
        page.click("form button[type=submit]")
        page.wait_for_load_state()
        shot(page, "28-akte-vormedikation")

        page.goto(f"{patient_url}")
        shot(page, "29-akte-patient-mit-bausteinen")

        # --- Patientenausweis: Stammdaten, Assistent, Konflikte, Historie -------
        report_id = report_url.rstrip("/").split("/")[-1]
        logo = Path(tempfile.gettempdir()) / "logo_beispiel.png"
        logo.write_bytes(logo_png())

        # Praxis-Informationen und Logo werden im Bereich System gepflegt und von
        # Briefen wie Ausweisen gemeinsam genutzt.
        page.goto(f"{BASE_URL}/system/settings")
        page.set_input_files("input[name=logo]", str(logo))
        page.fill("input[name=center_name]", CENTER_NAME)
        page.fill("textarea[name=center_address]", CENTER_ADDRESS)
        page.click("form button[type=submit]")
        page.wait_for_load_state()
        shot(page, "59-praxis-informationen")

        # Die Ausweistexte haben eine eigene Seite mit eigener Fassung.
        page.goto(f"{BASE_URL}/patient-cards/settings")
        page.fill("textarea[name=notice_text]", NOTICE_TEXT)
        page.fill("textarea[name=flight_notice_de]", FLIGHT_NOTICE_DE)
        page.fill("textarea[name=flight_notice_en]", FLIGHT_NOTICE_EN)
        page.click("form button[type=submit]")
        page.wait_for_load_state()
        shot(page, "13-ausweis-stammdaten")

        page.goto(f"{BASE_URL}/patient-cards/new")
        shot(page, "14-ausweis-bericht-waehlen")

        page.locator("a[href^='/patient-cards/reports/']").first.click()
        page.wait_for_url("**/patient-cards/reports/*")
        shot(page, "15-ausweis-assistent-schritt1")

        page.click("[data-wizard-next]")
        page.fill("input[name=street]", "Beispielweg 12")
        page.fill("input[name=postal_code]", "12345")
        page.fill("input[name=city]", "Musterstadt")
        page.fill("input[name=phone]", "01234 567890")
        page.fill("input[name=device_implant_location]", "links pectoral")
        page.select_option("select[name=mrt_compatibility]", "MRT-bedingt tauglich")
        page.fill("input[name=mrt_compatibility_note]", "Kontrolle der Sonde jährlich")
        shot(page, "16-ausweis-assistent-schritt2")

        page.click("[data-wizard-next]")
        page.fill("input[name=emergency_contact_name]", "Erika Beispiel")
        page.fill("input[name=emergency_contact_phone]", "01234 567891")
        page.click("[data-wizard-next]")
        page.fill("input[name=physician_name]", "Dr. med. Anna Beispiel")
        page.fill("input[name=physician_practice]", "Gemeinschaftspraxis am Markt")
        page.fill("input[name=physician_postal_code]", "12345")
        page.fill("input[name=physician_city]", "Musterstadt")
        page.fill("input[name=physician_phone]", "01234 567892")
        page.click("[data-wizard-next]")
        page.fill("input[name=next_control_date]", "07.04.2027")
        page.click("[data-wizard-next]")
        shot(page, "17-ausweis-assistent-zusammenfassung")

        page.check("input[name=confirm_patient]")
        page.check("input[name=confirm_merge]")
        page.click("button[data-wizard-submit]")
        page.wait_for_load_state()
        card_url = page.url
        if not card_url.rstrip("/").split("/")[-1].isdigit():
            print(f"Ausweis wurde nicht erzeugt: {card_url}", file=sys.stderr)
            return 1
        shot(page, "18-ausweis-detail")

        # Zweiter Durchlauf mit abweichender Angabe: Konfliktentscheidung je Feld
        page.goto(f"{BASE_URL}/patient-cards/reports/{report_id}")
        page.click("[data-wizard-next]")
        page.fill("input[name=street]", "Anderer Weg 5")
        page.select_option("select[name=mrt_compatibility]", "MRT-bedingt tauglich")
        for _ in range(4):
            page.click("[data-wizard-next]")
        page.check("input[name=confirm_patient]")
        page.check("input[name=confirm_merge]")
        page.click("button[data-wizard-submit]")
        page.wait_for_load_state()
        shot(page, "19-ausweis-konflikte")

        page.goto(f"{BASE_URL}/patient-cards")
        shot(page, "20-ausweis-uebersicht")

        page.goto(card_url)
        page.locator("a[href^='/patient-cards/patients/']").first.click()
        page.wait_for_url("**/patient-cards/patients/*")
        shot(page, "21-ausweis-patient")

        page.goto(f"{BASE_URL}/reports/{report_id}")
        shot(page, "23-ausweis-bericht")

        # --- Aktenbaustein "Schrittmacher-/ICD-Abfrage" -------------------------
        page.goto(card_url)
        card_patient_href = page.locator("a[href^='/patient-cards/patients/']").first.get_attribute("href")
        if not card_patient_href:
            print("Patientenverknuepfung des Ausweises nicht gefunden.", file=sys.stderr)
            return 1
        card_patient_id = card_patient_href.rstrip("/").split("/")[-1]
        check_url = f"{BASE_URL}/patients/{card_patient_id}/records/device_check"
        akte_url = f"{BASE_URL}/patients/{card_patient_id}"

        page.goto(check_url)
        shot(page, "30-akte-abfrage-formular")

        page.select_option("select[name=device_type]", "crt_d")
        page.click("button[formaction$='/prefill']")
        page.wait_for_load_state()
        shot(page, "31-akte-abfrage-vorbefuellt")

        # Der Ausweis fuehrt die MRT-Tauglichkeit: das Feld ist gesperrt und wird mitgesendet.
        if page.locator("select[name='values[device.mrt_compatibility]'][disabled]").count() != 1:
            print("MRT-Tauglichkeit ist im Abfrage-Formular nicht gesperrt.", file=sys.stderr)
            return 1
        if page.input_value("input[name='values[device.mrt_compatibility_note]']") != "Kontrolle der Sonde jährlich":
            print("Zusatzangabe zur MRT-Tauglichkeit fehlt im Abfrage-Formular.", file=sys.stderr)
            return 1

        page.fill("input[name='values[lv.output]']", "2,5/0,4")
        page.fill("input[name='values[lv.sensitivity]']", "1,0")
        page.fill("input[name='values[tachy.vt1.rate]']", "170")
        page.fill("input[name='values[tachy.vt1.cycle_length]']", "350")
        page.fill("input[name='values[tachy.vt1.therapy]']", "ATP, Schock 35 J")
        page.fill("textarea[name=notes]", "Kontrolle in sechs Monaten geplant.")
        page.fill("input[name=author_name]", AKTE_AUTHOR)
        page.click("button[data-once]")
        page.wait_for_load_state()
        if "Fassung 1" not in page.content():
            print("Die Abfrage wurde nicht gespeichert.", file=sys.stderr)
            return 1
        shot(page, "32-akte-abfrage")

        page.goto(akte_url)
        if "CRT-D" not in page.content():
            print("Die Abfrage fehlt in der Patientenakte.", file=sys.stderr)
            return 1
        shot(page, "33-akte-mit-abfrage")

        # --- Brief zur Schrittmacher-/ICD-Abfrage -------------------------------
        # Bausteine fuer den Patienten des Ausweises ergaenzen, damit der Brief vollstaendig ist.
        page.goto(f"{akte_url}/records/anamnesis")
        page.fill("textarea[name=text]", AKTE_ANAMNESIS)
        page.fill("input[name=author_name]", AKTE_AUTHOR)
        page.click("form button[type=submit]")
        page.wait_for_load_state()

        page.goto(f"{akte_url}/records/premedication")
        for index, (substance, dose, unit, schedule, reason, since) in enumerate(AKTE_PREMEDICATION):
            if index > 0:
                page.click("[data-repeat-add]")
            page.fill(f'input[name="medication[{index}][substance]"]', substance)
            page.fill(f'input[name="medication[{index}][dose]"]', dose)
            page.fill(f'input[name="medication[{index}][unit]"]', unit)
            page.fill(f'input[name="medication[{index}][schedule]"]', schedule)
            page.fill(f'input[name="medication[{index}][reason]"]', reason)
            page.fill(f'input[name="medication[{index}][from]"]', since)
        page.fill("input[name=author_name]", AKTE_AUTHOR)
        page.click("form button[type=submit]")
        page.wait_for_load_state()

        page.goto(f"{akte_url}/records/befund")
        page.fill("textarea[name=text]", AKTE_BEFUND)
        page.fill("input[name=author_name]", AKTE_AUTHOR)
        page.click("form button[type=submit]")
        page.wait_for_load_state()

        page.goto(f"{akte_url}/records/epicrisis")
        page.fill("textarea[name=text]", AKTE_EPICRISIS)
        page.fill("input[name=author_name]", AKTE_AUTHOR)
        page.click("form button[type=submit]")
        page.wait_for_load_state()
        shot(page, "34-akte-epikrise")

        # Empfaenger der Briefe: Hausarzt (aus dem Ausweis) mit Strasse ergaenzen, ueberweisenden Arzt pflegen.
        page.goto(f"{akte_url}/edit")
        page.fill("input[name=physician_street]", "Marktplatz 3")
        page.fill("input[name=referrer_name]", "Dr. med. Jonas Klein")
        page.fill("input[name=referrer_practice]", "Kardiologische Praxis am Dom")
        page.fill("input[name=referrer_street]", "Domplatz 1")
        page.fill("input[name=referrer_postal_code]", "12347")
        page.fill("input[name=referrer_city]", "Musterstadt")
        page.fill("input[name=referrer_phone]", "01234 567893")
        shot(page, "49-akte-aerzte")
        page.locator("button[type=submit]", has_text="Stammdaten speichern").click()
        page.wait_for_load_state()
        if "Überweisender Arzt" not in page.content() or "Dr. med. Jonas Klein" not in page.content():
            print("Der ueberweisende Arzt fehlt in den Stammdaten.", file=sys.stderr)
            return 1

        page.goto(f"{BASE_URL}/letters")
        shot(page, "35-brief-uebersicht-leer")

        page.goto(f"{BASE_URL}/letters/new")
        shot(page, "36-brief-patient-waehlen")

        # Schritt 2: Bericht fuer den Baustein "Berichte" zuordnen (ohne Bericht entfaellt der Anhang).
        page.goto(f"{BASE_URL}/letters/new?patient={card_patient_id}")
        shot(page, "37-brief-assistent-bericht-waehlen")

        page.goto(f"{BASE_URL}/letters/new?patient={card_patient_id}&report={report_id}")
        shot(page, "38-brief-assistent-bericht")

        page.click("[data-wizard-next]")
        shot(page, "39-brief-assistent-bausteine")

        # Schritt 4: Empfaenger – je Haken entsteht ein eigener Brief.
        page.click("[data-wizard-next]")
        page.check("input[name='recipients[]'][value=patient]")
        shot(page, "50-brief-assistent-empfaenger")

        page.click("[data-wizard-next]")
        shot(page, "40-brief-assistent-zusammenfassung")

        page.click("[data-wizard-next]")
        page.check("input[name=confirm_data]")
        page.check("input[name=confirm_letter]")
        shot(page, "41-brief-assistent-bestaetigen")

        page.click("button[data-wizard-submit]")
        page.wait_for_load_state()
        # Drei Empfaenger, drei Briefe: Weiterleitung auf die Briefe des Patienten.
        if not page.url.rstrip("/").endswith(f"/letters/patients/{card_patient_id}"):
            print(f"Briefe wurden nicht erzeugt: {page.url}", file=sys.stderr)
            return 1
        if "3 Briefe wurden erstellt" not in page.content():
            print("Hinweis auf drei erzeugte Briefe fehlt.", file=sys.stderr)
            return 1
        shot(page, "44-brief-patient")

        doctor_row = page.locator("tr", has_text="Hausarzt: Dr. med. Anna Beispiel")
        letter_href = doctor_row.locator("a[href^='/letters/']").first.get_attribute("href")
        if not letter_href or not letter_href.rstrip("/").split("/")[-1].isdigit():
            print("Brief an den Hausarzt nicht gefunden.", file=sys.stderr)
            return 1
        letter_url = f"{BASE_URL}{letter_href}"
        page.goto(letter_url)
        shot(page, "42-brief-detail")

        page.goto(f"{BASE_URL}/letters")
        if "3 Brief(e) gefunden" not in page.content():
            print("Die Briefe fehlen in der Briefuebersicht.", file=sys.stderr)
            return 1
        shot(page, "43-brief-uebersicht")

        page.goto(f"{BASE_URL}/patients/{card_patient_id}")
        shot(page, "45-akte-mit-brief")

        # Vorlageneditor (System -> Briefvorlage bearbeiten, eigener Tab).
        page.goto(f"{BASE_URL}/system")
        with context.expect_page() as editor_info:
            page.locator("a[href='/system/letter-templates']", has_text="Briefvorlage bearbeiten").click()
        editor = editor_info.value
        editor.set_viewport_size({"width": 1600, "height": 1000})
        editor.wait_for_load_state()
        editor.wait_for_selector(".te-block")
        shot(editor, "51-vorlageneditor", full_page=False)
        editor.locator(".te-zone--recipient").first.click()
        shot(editor, "52-vorlageneditor-empfaenger", full_page=False)
        editor.click("[data-te-action=versions]")
        editor.wait_for_selector("[data-te-versions-dialog][open]")
        shot(editor, "53-vorlageneditor-fassungen", full_page=False)
        editor.close()

        # Ausweiseditor (System -> Ausweisvorlage bearbeiten, eigener Tab).
        page.goto(f"{BASE_URL}/system")
        with context.expect_page() as card_editor_info:
            page.locator(
                "a[href='/system/patient-card-templates']", has_text="Ausweisvorlage bearbeiten"
            ).click()
        card_editor = card_editor_info.value
        card_editor.set_viewport_size({"width": 1600, "height": 1000})
        card_editor.wait_for_load_state()
        card_editor.wait_for_selector(".te-block")
        shot(card_editor, "60-ausweiseditor", full_page=False)
        card_editor.locator(".te-zone--card-page2").first.click()
        shot(card_editor, "61-ausweiseditor-seite2", full_page=False)
        card_editor.click("[data-te-action=versions]")
        card_editor.wait_for_selector("[data-te-versions-dialog][open]")
        shot(card_editor, "62-ausweiseditor-fassungen", full_page=False)
        card_editor.close()

        # --- Benutzerverwaltung: Konten, Gruppen und Berechtigungsmatrix --------
        page.goto(f"{BASE_URL}/system/users")
        if "Benutzerverwaltung" not in page.content():
            print("Die Benutzerverwaltung ist nicht erreichbar.", file=sys.stderr)
            return 1
        shot(page, "55-benutzerverwaltung")

        page.goto(f"{BASE_URL}/system/users/new")
        page.fill("input[name=username]", "dr.muster")
        page.fill("input[name=display_name]", "Dr. med. Erika Muster")
        page.locator("input[name='groups[]']").last.check()
        page.fill("input[name=password]", "beispiel-kennwort-2026")
        page.fill("input[name=repeat_password]", "beispiel-kennwort-2026")
        shot(page, "56-benutzer-anlegen")

        page.goto(f"{BASE_URL}/system/users/groups")
        shot(page, "57-benutzer-gruppen")

        # Berechtigungsmatrix einer Gruppe: Rechte werden hier gepflegt, nicht im Code.
        page.locator("a[href^='/system/users/groups/']").first.click()
        page.wait_for_load_state()
        page.wait_for_selector("table[data-matrix]")
        shot(page, "58-gruppen-rechte")

        letter_pdf = context.request.get(f"{letter_url}/pdf?download=1")
        if not letter_pdf.ok or not letter_pdf.body().startswith(b"%PDF-"):
            print(f"Brief-PDF-Abruf fehlgeschlagen: HTTP {letter_pdf.status}", file=sys.stderr)
            return 1
        letter_pdf_bytes = letter_pdf.body()

        pdf = context.request.get(f"{report_url}/pdf?raw=1&download=1")
        if not pdf.ok or not pdf.body().startswith(b"%PDF-"):
            print(f"PDF-Abruf fehlgeschlagen: HTTP {pdf.status}", file=sys.stderr)
            return 1
        pdf_bytes = pdf.body()

        card_pdf = context.request.get(f"{card_url}/pdf?download=1")
        if not card_pdf.ok or not card_pdf.body().startswith(b"%PDF-"):
            print(f"Ausweis-PDF-Abruf fehlgeschlagen: HTTP {card_pdf.status}", file=sys.stderr)
            return 1
        card_pdf_bytes = card_pdf.body()
        browser.close()

    with tempfile.TemporaryDirectory() as tmp:
        directory = Path(tmp)
        render_pdf(directory, pdf_bytes, ["12-pdf-seite-1", "12-pdf-seite-2", "12-pdf-seite-3"])
        render_pdf(directory, card_pdf_bytes, ["22-ausweis-pdf-seite-1", "22-ausweis-pdf-seite-2"])
        render_pdf(directory, letter_pdf_bytes, [
            "46-brief-pdf-seite-1",
            "46-brief-pdf-seite-2",
            "46-brief-pdf-seite-3",
            "46-brief-pdf-seite-4",
        ])

        # Der Brief ist mehrseitig: Brieftext, Anhang "Berichte" auf neuer Seite und
        # Anhang mit der vollstaendigen Abfragetabelle.
        letter_pages = pdf_pages(directory, letter_pdf_bytes, "45-brief-pruefung")
        if letter_pages < 4:
            print(f"Brief-PDF hat {letter_pages} Seiten statt mindestens 4.", file=sys.stderr)
            return 1
        letter_text = pdf_text(directory, letter_pdf_bytes, "45-brief-pruefung")
        for needle in (
            "Brief zur Schrittmacher-/ICD-Abfrage",
            "Anamnese",
            "Vormedikation",
            "Befund",
            "Regelmaessiger Eigenrhythmus",
            "Epikrise",
            "Berichte",
            "Bericht Nr. 1",
            "Anhang: Schrittmacher-/ICD-Abfrage (vollständige Tabelle)",
            "MRT-Tauglichkeit",
            "Tachykardie",
            "Gemeinschaftspraxis am Markt",
            "Marktplatz 3",
        ):
            if needle not in letter_text:
                print(f"Angabe fehlt im Brief-PDF: {needle}", file=sys.stderr)
                return 1

        # Harte Anforderung: Der Ausweis umfasst genau zwei Seiten, Seite 1 nennt die MRT-Tauglichkeit.
        pages = pdf_pages(directory, card_pdf_bytes, "22-ausweis-pruefung")
        if pages != 2:
            print(f"Ausweis-PDF hat {pages} Seiten statt 2.", file=sys.stderr)
            return 1
        text = pdf_text(directory, card_pdf_bytes, "22-ausweis-pruefung")
        for needle in ("MRT-Tauglichkeit:", "MRT-bedingt tauglich"):
            if needle not in text:
                print(f"Angabe fehlt im Ausweis-PDF: {needle}", file=sys.stderr)
                return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
