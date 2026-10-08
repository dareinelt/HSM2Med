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

    // Assistent zur Ausweiserstellung: Schritte umschalten (ohne JavaScript bleiben alle sichtbar)
    document.querySelectorAll('form[data-wizard]').forEach((form) => {
        const steps = Array.from(form.querySelectorAll('.wizard-step'));
        if (steps.length === 0) {
            return;
        }
        const links = Array.from(form.querySelectorAll('[data-wizard-goto]'));
        // Die Schritte werden ueber ihre Nummer angesteuert, nicht ueber ihre Position:
        // im Brief-Assistenten beginnt das Formular erst mit Schritt 2 (Schritt 1 ist die
        // Patientenauswahl auf einer eigenen Seite).
        const numbers = steps
            .map((section) => parseInt(section.dataset.step, 10))
            .filter((number) => !Number.isNaN(number))
            .sort((a, b) => a - b);
        if (numbers.length === 0) {
            return;
        }
        const first = numbers[0];
        const last = numbers[numbers.length - 1];
        let current = parseInt(form.dataset.step || '', 10);
        if (!numbers.includes(current)) {
            current = first;
        }

        const show = (step, focus) => {
            current = numbers.includes(step) ? step : (step < first ? first : last);
            form.dataset.step = String(current);
            form.dataset.wizardLast = current === last ? 'on' : 'off';
            steps.forEach((section) => {
                section.classList.toggle('is-active', parseInt(section.dataset.step, 10) === current);
            });
            links.forEach((link) => {
                const isCurrent = parseInt(link.dataset.wizardGoto, 10) === current;
                if (isCurrent) {
                    link.setAttribute('aria-current', 'step');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
            if (focus) {
                const target = form.querySelector('.wizard-step.is-active');
                if (target) {
                    target.scrollIntoView({ block: 'start' });
                    const firstField = target.querySelector('input, textarea, select');
                    if (firstField) {
                        firstField.focus({ preventScroll: true });
                    }
                }
            }
        };

        // Nachbarschritt in Richtung delta, ohne Luecken in der Nummerierung zu ueberspringen.
        const neighbour = (delta) => {
            const index = numbers.indexOf(current);
            const next = numbers[index + delta];
            return next === undefined ? current : next;
        };

        form.dataset.wizard = 'on';
        show(current, false);

        form.addEventListener('click', (event) => {
            const goto = event.target.closest('[data-wizard-goto]');
            if (goto) {
                event.preventDefault();
                show(parseInt(goto.dataset.wizardGoto, 10), true);
                return;
            }
            if (event.target.closest('[data-wizard-next]')) {
                event.preventDefault();
                show(neighbour(1), true);
                return;
            }
            if (event.target.closest('[data-wizard-prev]')) {
                event.preventDefault();
                show(neighbour(-1), true);
            }
        });
    });

    // Wiederholbare Zeilen (z. B. Arzneimittelzeilen der Vormedikation, Sondenzeilen der Abfrage)
    document.querySelectorAll('[data-repeat]').forEach((container) => {
        const template = container.querySelector('template[data-repeat-template]');
        const list = container.querySelector('[data-repeat-rows]');
        const addButton = container.querySelector('[data-repeat-add]');
        if (!template || !list || !addButton) {
            return;
        }
        const limit = parseInt(container.dataset.repeatLimit || '0', 10);
        const prefix = container.dataset.repeatName || 'medication';
        const pattern = new RegExp(prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[[^\\]]*\\]');
        const rows = () => Array.from(list.querySelectorAll('[data-repeat-row]'));
        const sync = () => {
            const count = rows().length;
            addButton.disabled = limit > 0 && count >= limit;
            rows().forEach((row, index) => {
                row.querySelectorAll('[name]').forEach((field) => {
                    field.name = field.name.replace(pattern, prefix + '[' + index + ']');
                });
                const remove = row.querySelector('[data-repeat-remove]');
                if (remove) {
                    remove.disabled = count <= 1;
                }
            });
        };
        addButton.addEventListener('click', () => {
            const markup = template.innerHTML.replace(/__INDEX__/g, String(rows().length));
            list.insertAdjacentHTML('beforeend', markup);
            sync();
            const added = rows().pop();
            const first = added && added.querySelector('input');
            if (first) {
                first.focus();
            }
        });
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-repeat-remove]');
            if (!button || rows().length <= 1) {
                return;
            }
            event.preventDefault();
            const row = button.closest('[data-repeat-row]');
            if (row) {
                row.remove();
                sync();
            }
        });
        sync();
    });

    // Schrittmacher-/ICD-Abfrage: Abschnitte und Felder nach Geraeteart ein- und ausblenden.
    // Ohne JavaScript bleibt alles sichtbar; gueltig ist serverseitig die gewaehlte Geraeteart.
    document.querySelectorAll('[data-device-type]').forEach((select) => {
        const form = select.closest('form');
        if (!form) {
            return;
        }
        const sync = () => {
            const deviceType = select.value;
            form.querySelectorAll('[data-devices]').forEach((node) => {
                const devices = (node.dataset.devices || '').split(/\s+/).filter(Boolean);
                const visible = devices.length === 0 || devices.includes(deviceType);
                node.hidden = !visible;
                node.querySelectorAll('input, select, textarea').forEach((field) => {
                    if (field.closest('[data-locked]')) {
                        return;
                    }
                    field.disabled = !visible;
                });
            });
        };
        select.addEventListener('change', sync);
        sync();
    });
});
