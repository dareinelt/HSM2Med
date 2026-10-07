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
        const total = steps.length;
        let current = parseInt(form.dataset.step || '1', 10);
        if (!(current >= 1 && current <= total)) {
            current = 1;
        }

        const show = (step, focus) => {
            current = Math.min(Math.max(step, 1), total);
            form.dataset.step = String(current);
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
                show(current + 1, true);
                return;
            }
            if (event.target.closest('[data-wizard-prev]')) {
                event.preventDefault();
                show(current - 1, true);
            }
        });
    });
});
