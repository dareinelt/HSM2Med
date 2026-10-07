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
import subprocess
import sys
import tempfile
from pathlib import Path

from playwright.sync_api import Page, sync_playwright

BASE_URL = os.environ.get("BASE_URL", "http://web-docs").rstrip("/")
SAMPLE = Path(os.environ.get("SAMPLE_FILE", "/work/tests/fixtures/merlin_sample.log"))
OUT = Path(os.environ.get("OUT_DIR", "/work/docs/screenshots"))
VIEWPORT = {"width": 1366, "height": 900}


def shot(page: Page, name: str, full_page: bool = True) -> None:
    target = OUT / f"{name}.png"
    page.screenshot(path=str(target), full_page=full_page)
    print(f"  {target.name}")


def upload(page: Page, path: str) -> None:
    page.goto(f"{BASE_URL}/import")
    page.set_input_files("input[name=file]", path)
    page.click("form.upload-form button[type=submit]")
    page.wait_for_load_state()


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

        pdf = context.request.get(f"{report_url}/pdf?raw=1&download=1")
        if not pdf.ok or not pdf.body().startswith(b"%PDF-"):
            print(f"PDF-Abruf fehlgeschlagen: HTTP {pdf.status}", file=sys.stderr)
            return 1
        pdf_bytes = pdf.body()
        browser.close()

    with tempfile.TemporaryDirectory() as tmp:
        pdf_path = Path(tmp) / "bericht.pdf"
        pdf_path.write_bytes(pdf_bytes)
        prefix = Path(tmp) / "seite"
        subprocess.run(["pdftoppm", "-png", "-r", "80", "-f", "1", "-l", "3", str(pdf_path), str(prefix)], check=True)
        for index, page_file in enumerate(sorted(Path(tmp).glob("seite-*.png")), start=1):
            target = OUT / f"12-pdf-seite-{index}.png"
            target.write_bytes(page_file.read_bytes())
            print(f"  {target.name}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
