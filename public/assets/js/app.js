// HSM2Med – kleine Komfortfunktionen (CSP-konform, kein Inline-JavaScript, keine externen Bibliotheken)
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    // Clientseitige Vorpruefung der Uploadgroesse/-endung (serverseitige Pruefung bleibt massgeblich)
    document.querySelectorAll('form.upload-form').forEach((form) => {
        const input = form.querySelector('input[type=file]');
        const hint = form.querySelector('[data-upload-hint]');
        const maxBytes = parseInt(form.dataset.maxBytes || '0', 10);
        if (!input || !hint) {
            return;
        }
        input.addEventListener('change', () => {
            hint.hidden = true;
            const file = input.files && input.files[0];
            if (!file) {
                return;
            }
            const ext = file.name.toLowerCase().split('.').pop();
            let message = '';
            if (ext !== 'txt' && ext !== 'log') {
                message = 'Nur Dateien mit der Endung .txt oder .log sind erlaubt.';
            } else if (maxBytes > 0 && file.size > maxBytes) {
                message = 'Die Datei ist zu groß.';
            }
            if (message !== '') {
                hint.textContent = message;
                hint.hidden = false;
            }
        });
    });

    // Doppeltes Absenden verhindern
    document.querySelectorAll('button[data-once]').forEach((button) => {
        const form = button.closest('form');
        if (!form) {
            return;
        }
        form.addEventListener('submit', () => {
            window.setTimeout(() => { button.disabled = true; button.textContent = 'Wird gespeichert …'; }, 0);
        });
    });
});
