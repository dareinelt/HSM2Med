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


def shot(page: Page, name: str, full_page: bool = True) -> None:
    target = OUT / f"{name}.png"
    page.screenshot(path=str(target), full_page=full_page)
    print(f"  {target.name}")


def upload(page: Page, path: str) -> None:
    page.goto(f"{BASE_URL}/import")
    page.set_input_files("input[name=file]", path)
    page.click("form.upload-form button[type=submit]")
    page.wait_for_load_state()


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
        page.goto(f"{BASE_URL}/")
        shot(page, "01-dashboard-leer")

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

        # --- Patientenausweis: Stammdaten, Assistent, Konflikte, Historie -------
        report_id = report_url.rstrip("/").split("/")[-1]
        logo = Path(tempfile.gettempdir()) / "logo_beispiel.png"
        logo.write_bytes(logo_png())

        page.goto(f"{BASE_URL}/patient-cards/settings")
        page.set_input_files("input[name=logo]", str(logo))
        page.fill("input[name=center_name]", CENTER_NAME)
        page.fill("textarea[name=center_address]", CENTER_ADDRESS)
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

    return 0


if __name__ == "__main__":
    sys.exit(main())
