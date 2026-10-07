# Nur fuer die Dokumentation: Screenshot-Werkzeug (nicht Teil des Produktivbetriebs).
FROM mcr.microsoft.com/playwright/python:v1.55.0-noble

RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/* \
    && pip3 install --no-cache-dir --break-system-packages playwright==1.55.0

WORKDIR /work
ENTRYPOINT ["python3", "/work/docs/screenshots/capture.py"]
