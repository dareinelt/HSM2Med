// HSM2Med – kleine Komfortfunktionen (CSP-konform, kein Inline-JavaScript, keine externen Bibliotheken)
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    // Hoehe des festen Rahmens (Titelleiste + Funktionsband) als CSS-Variable bereitstellen,
    // damit Tabellenkoepfe und Sprungmarken nicht darunter verschwinden.
    const chrome = document.querySelector('.app-chrome');
    if (chrome) {
        const syncChromeHeight = () => {
            document.documentElement.style.setProperty('--chrome-h', Math.round(chrome.getBoundingClientRect().height) + 'px');
        };
        syncChromeHeight();
        window.addEventListener('resize', syncChromeHeight);
        window.addEventListener('load', syncChromeHeight);
    }

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
            if (ext !== 'txt' && ext !== 'log' && ext !== 'xml') {
                message = 'Nur Dateien mit der Endung .txt, .log oder .xml sind erlaubt.';
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
            window.setTimeout(() => {
                button.disabled = true;
                // Nur die Beschriftung tauschen, damit ein Symbol in der Schaltflaeche erhalten bleibt.
                const label = button.querySelector('[data-label]');
                (label || button).textContent = 'Wird gespeichert …';
            }, 0);
        });
    });

    // Brief-Assistent: Zusammenfassung und Schaltflaeche folgen der Empfaengerauswahl
    document.querySelectorAll('[data-recipient-choices]').forEach((fieldset) => {
        const form = fieldset.closest('form');
        if (!form) {
            return;
        }
        const update = () => {
            const checked = Array.from(fieldset.querySelectorAll('input[name="recipients[]"]'))
                .filter((input) => input.checked && !input.disabled)
                .map((input) => input.value);
            form.querySelectorAll('[data-recipient-item]').forEach((item) => {
                item.hidden = !checked.includes(item.dataset.recipientItem);
            });
            form.querySelectorAll('[data-recipient-none]').forEach((item) => {
                item.hidden = checked.length > 0;
            });
            form.querySelectorAll('[data-recipient-submit]').forEach((label) => {
                label.textContent = checked.length > 1 ? checked.length + ' Briefe erzeugen' : 'Brief erzeugen';
            });
        };
        fieldset.addEventListener('change', update);
        update();
    });

    // Kontrollkaestchen, das einen zugehoerigen Abschnitt ein- und ausblendet (z. B. Anhang und
    // Messwerttabelle im Brief-Assistenten). Ohne JavaScript bleibt der Abschnitt sichtbar.
    document.querySelectorAll('[data-toggle-target]').forEach((checkbox) => {
        const group = document.querySelectorAll(`[data-toggle-group="${checkbox.dataset.toggleTarget}"]`);
        if (group.length === 0) {
            return;
        }
        const update = () => {
            group.forEach((element) => {
                element.hidden = !checkbox.checked;
            });
        };
        checkbox.addEventListener('change', update);
        update();
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

    // Autoren-Info in der Statusleiste: Overlay oeffnen, geschlossen wird nur ueber das Kreuz.
    const authorInfo = document.querySelector('dialog[data-author-info-dialog]');
    if (authorInfo) {
        document.querySelectorAll('[data-author-info]').forEach((trigger) => {
            trigger.addEventListener('click', () => {
                if (typeof authorInfo.showModal === 'function') {
                    authorInfo.showModal();
                    return;
                }
                // Ohne Unterstuetzung fuer modale Dialoge bleibt der Hinweis wenigstens sichtbar.
                authorInfo.setAttribute('open', '');
            });
        });
    }

    // Patientenwahl in der Titelleiste: Rechtsklick auf die Kachel oeffnet ein eigenes
    // Kontextmenue zum Zuruecksetzen der Auswahl. Die Kachel traegt das Ausloeser-Attribut
    // nur bei aktivem Patienten; ohne Auswahl gibt es daher auch kein Menue.
    const patientMenuTrigger = document.querySelector('[data-patient-menu-trigger]');
    const patientMenu = document.querySelector('[data-patient-menu-panel]');
    if (patientMenuTrigger && patientMenu) {
        let patientMenuOpen = false;
        const closePatientMenu = (restoreFocus) => {
            if (!patientMenuOpen) {
                return;
            }
            patientMenuOpen = false;
            patientMenu.hidden = true;
            patientMenuTrigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) {
                patientMenuTrigger.focus();
            }
        };
        const openPatientMenu = (x, y) => {
            patientMenu.hidden = false;
            patientMenuOpen = true;
            patientMenuTrigger.setAttribute('aria-expanded', 'true');
            // Das Menue bleibt vollstaendig im sichtbaren Bereich.
            const rect = patientMenu.getBoundingClientRect();
            const left = Math.max(4, Math.min(x, window.innerWidth - rect.width - 4));
            const top = Math.max(4, Math.min(y, window.innerHeight - rect.height - 4));
            patientMenu.style.left = left + 'px';
            patientMenu.style.top = top + 'px';
            const firstItem = patientMenu.querySelector('[role="menuitem"]');
            if (firstItem) {
                firstItem.focus({ preventScroll: true });
            }
        };
        patientMenuTrigger.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            openPatientMenu(event.clientX, event.clientY);
        });
        // Tastatur: Kontextmenuetaste und Umschalt+F10 oeffnen das Menue an der Kachel.
        patientMenuTrigger.addEventListener('keydown', (event) => {
            if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
                event.preventDefault();
                const rect = patientMenuTrigger.getBoundingClientRect();
                openPatientMenu(rect.left, rect.bottom + 4);
            }
        });
        document.addEventListener('click', (event) => {
            if (patientMenuOpen && !patientMenu.contains(event.target)) {
                closePatientMenu(false);
            }
        });
        document.addEventListener('keydown', (event) => {
            if (patientMenuOpen && event.key === 'Escape') {
                closePatientMenu(true);
            }
        });
        document.addEventListener('scroll', () => closePatientMenu(false), true);
        window.addEventListener('resize', () => closePatientMenu(false));
    }

    // Brief-Assistent: geaenderte Messwertauswahl -> Rueckfrage, ob die Auswahl als Standard
    // fuer alle kuenftigen Briefe gespeichert werden soll. Gefragt wird einmal je Formular.
    // Ohne JavaScript bleibt das Kontrollkaestchen im Schritt sichtbar und wird beim Absenden
    // ausgewertet; gueltig ist in jedem Fall die serverseitige Auswertung.
    const measurementDialog = document.querySelector('dialog[data-measurement-default-dialog]');
    document.querySelectorAll('form[data-wizard]').forEach((form) => {
        const remember = form.querySelector('[data-measurement-remember]');
        const choices = Array.from(form.querySelectorAll('input[name="measurements[]"]'));
        const include = form.querySelector('input[name="include_measurements"]');
        if (!remember || choices.length === 0) {
            return;
        }
        // Die Rueckfrage ersetzt das Kontrollkaestchen, solange JavaScript verfuegbar ist.
        const row = form.querySelector('[data-measurement-remember-row]');
        if (row) {
            row.hidden = true;
        }
        const state = () => (include && !include.checked ? '0' : '1')
            + choices.map((input) => (input.checked ? '1' : '0')).join('');
        const initial = state();
        let asked = false;
        const answer = (keep) => {
            remember.checked = keep;
            asked = true;
            if (measurementDialog && measurementDialog.open) {
                measurementDialog.close();
            }
        };
        const ask = () => {
            if (asked || state() === initial || !measurementDialog || measurementDialog.open) {
                return;
            }
            if (typeof measurementDialog.showModal === 'function') {
                measurementDialog.showModal();
                return;
            }
            measurementDialog.setAttribute('open', '');
        };
        choices.forEach((input) => input.addEventListener('change', ask));
        if (include) {
            include.addEventListener('change', ask);
        }
        if (measurementDialog) {
            measurementDialog.querySelectorAll('[data-measurement-default-answer]').forEach((button) => {
                button.addEventListener('click', () => {
                    answer(button.dataset.measurementDefaultAnswer === '1');
                });
            });
            const close = measurementDialog.querySelector('[data-measurement-default-close]');
            if (close) {
                close.addEventListener('click', () => {
                    answer(false);
                });
            }
        }
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

    // Bestaetigung vor unwiderruflichen Aktionen (z. B. Gruppe loeschen).
    // Ohne JavaScript wird die Aktion ausgefuehrt; der Server prueft in jedem Fall selbst.
    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const button = form.querySelector('button[data-confirm]');
            if (!button || event.submitter !== button) {
                return;
            }
            if (!window.confirm(button.dataset.confirm || 'Aktion ausfuehren?')) {
                event.preventDefault();
            }
        });
    });

    // Rechtematrix: alle Rechte einer Gruppe auf einmal setzen oder aufheben.
    document.querySelectorAll('table[data-matrix]').forEach((matrix) => {
        const boxes = Array.from(matrix.querySelectorAll('input[name="permissions[]"]'));
        const setAll = (checked) => boxes.forEach((box) => { box.checked = checked; });
        document.querySelectorAll('[data-matrix-all]').forEach((button) => {
            button.addEventListener('click', () => setAll(true));
        });
        document.querySelectorAll('[data-matrix-none]').forEach((button) => {
            button.addEventListener('click', () => setAll(false));
        });
    });

    // Kennwortstaerke: reine Anzeige fuer die Person, die ein Kennwort vergibt. Verbindlich
    // bleibt die Pruefung auf dem Server (Mindestlaenge, nicht gleich dem Anmeldenamen);
    // die Anzeige blockiert das Absenden nicht.
    const strengthLevels = ['', 'Sehr schwach', 'Schwach', 'Mittel', 'Stark', 'Sehr stark'];
    document.querySelectorAll('[data-password-strength]').forEach((meter) => {
        const input = document.getElementById(meter.dataset.passwordStrength);
        if (!input) {
            return;
        }
        const minimum = parseInt(meter.dataset.passwordMinimum || '0', 10);
        const bar = meter.querySelector('[data-strength-bar]');
        const label = meter.querySelector('[data-strength-label]');
        const rules = Array.from(meter.querySelectorAll('[data-strength-rule]')).map((item) => ({
            item,
            // Gross-/Kleinbuchstaben, Ziffern und Sonderzeichen: deutsche Umlaute zaehlen mit.
            test: {
                length: (value) => value.length >= minimum,
                upper: (value) => /[A-ZÄÖÜ]/u.test(value),
                lower: (value) => /[a-zäöüß]/u.test(value),
                digit: (value) => /[0-9]/u.test(value),
                special: (value) => /[^\p{L}\p{N}]/u.test(value),
            }[item.dataset.strengthRule] || (() => false),
        }));
        const update = () => {
            const value = input.value;
            let score = 0;
            rules.forEach((rule) => {
                const met = rule.test(value);
                rule.item.dataset.met = met ? 'true' : 'false';
                if (met) {
                    score += 1;
                }
            });
            if (value === '') {
                score = 0;
            }
            meter.dataset.level = String(score);
            if (bar) {
                bar.dataset.level = String(score);
            }
            if (label) {
                label.textContent = value === ''
                    ? 'Kennwortstärke wird beim Tippen angezeigt.'
                    : strengthLevels[score];
            }
        };
        input.addEventListener('input', update);
        update();
    });
});
