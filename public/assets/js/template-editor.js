/*
 * HSM2Med – Vorlageneditor fuer Briefe nach DIN 5008 (Form B).
 *
 * Eigenstaendige Seite ohne Fremdbibliotheken; CSP-konform (keine Inline-Skripte, keine
 * Inline-Styles im Markup – Masse werden ueber element.style gesetzt). Die Daten kommen aus dem
 * JSON-Datenblock #template-editor-data. Gespeichert wird per fetch() als neue Fassung; die
 * Pruefung der Inhalte erfolgt verbindlich auf dem Server (LetterTemplate::normalize).
 */
(() => {
    'use strict';

    const dataNode = document.getElementById('template-editor-data');
    if (!dataNode) {
        return;
    }
    const data = JSON.parse(dataNode.textContent || '{}');
    const def = data.definition;
    const $ = (selector) => document.querySelector(selector);

    // Seitengeometrie in Millimetern (wie LetterPdfGenerator).
    const PAGE = { width: 210, height: 297, left: 25, right: 20, bodyTop: 98.46, footer: 274 };

    const el = {
        title: $('[data-te-title]'),
        state: $('[data-te-state]'),
        message: $('[data-te-message]'),
        name: $('[data-te-name]'),
        nameError: $('[data-te-error="name"]'),
        blocksError: $('[data-te-error="blocks"]'),
        zones: $('[data-te-zones]'),
        blocks: $('[data-te-blocks]'),
        paper: $('[data-te-paper]'),
        props: $('[data-te-props]'),
        statusVersion: $('[data-te-status-version]'),
        statusBlocks: $('[data-te-status-blocks]'),
        saveDialog: $('[data-te-save-dialog]'),
        comment: $('[data-te-comment]'),
        versionsDialog: $('[data-te-versions-dialog]'),
        versionsBody: $('[data-te-versions]'),
        helpDialog: $('[data-te-help-dialog]'),
        previewForm: $('[data-te-preview-form]'),
        previewContent: $('[data-te-preview-content]'),
        icons: $('[data-te-icons]'),
    };

    const state = {
        base: data.current,
        baseContent: prepare(data.current.content),
        content: prepare(data.current.content),
        versions: data.versions || [],
        selected: { kind: 'block', key: null },
        errors: {},
        lastField: null,
        busy: false,
    };
    state.selected.key = state.content.blocks.length > 0 ? state.content.blocks[0].id : null;
    if (state.selected.key === null) {
        state.selected = { kind: 'zone', key: Object.keys(def.zones)[0] };
    }

    // ------------------------------------------------------------------ Hilfen

    /** PHP liefert leere Objekte als Array – hier einheitlich als Objekt behandeln. */
    function obj(value) {
        return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    /** Bringt eine Vorlage in die vollstaendige Form der Definition (Reihenfolge der Schluessel fest). */
    function prepare(content) {
        const source = obj(content);
        const result = { schema: def.schema, name: String(source.name || ''), zones: {}, blocks: [] };
        const zones = obj(source.zones);
        for (const [key, zoneDef] of Object.entries(def.zones)) {
            const raw = obj(zones[key]);
            result.zones[key] = { options: fillOptions(zoneDef, raw.options), texts: fillTexts(zoneDef, raw.texts) };
        }
        for (const raw of Array.isArray(source.blocks) ? source.blocks : []) {
            const blockDef = def.blocks[raw.type];
            if (!blockDef) {
                continue;
            }
            result.blocks.push({
                id: String(raw.id || raw.type),
                type: raw.type,
                enabled: raw.enabled !== false,
                options: fillOptions(blockDef, raw.options),
                texts: fillTexts(blockDef, raw.texts),
            });
        }
        // Feste Bausteine sind immer vorhanden (hoechstens ausgeblendet).
        for (const [type, blockDef] of Object.entries(def.blocks)) {
            if (blockDef.unique && !result.blocks.some((block) => block.type === type)) {
                result.blocks.push({ id: uniqueId(result.blocks, type), type, enabled: false, options: fillOptions(blockDef, {}), texts: fillTexts(blockDef, {}) });
            }
        }
        return result;
    }

    function fillOptions(definition, raw) {
        const values = obj(raw);
        const result = {};
        for (const [key, optionDef] of Object.entries(obj(definition.options))) {
            const value = key in values ? values[key] : optionDef.default;
            result[key] = optionDef.type === 'bool' ? value === true || value === 1 || value === '1' : String(value);
        }
        return result;
    }

    function fillTexts(definition, raw) {
        const values = obj(raw);
        const result = {};
        for (const [key, textDef] of Object.entries(obj(definition.texts))) {
            result[key] = key in values ? String(values[key]) : String(textDef.default);
        }
        return result;
    }

    function uniqueId(blocks, type) {
        let id = type;
        let suffix = 2;
        while (blocks.some((block) => block.id === id)) {
            id = type + '-' + suffix++;
        }
        return id;
    }

    function serialize(content) {
        return JSON.stringify(content);
    }

    function isDirty() {
        return serialize(state.content) !== serialize(state.baseContent);
    }

    function icon(name) {
        const holder = el.icons.content.querySelector('[data-icon="' + name + '"] svg');
        return holder ? holder.cloneNode(true) : document.createElement('span');
    }

    /** Erzeugt ein Element; Inhalte werden immer als Text gesetzt (kein innerHTML). */
    function h(tag, attrs, ...children) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs || {})) {
            if (value === null || value === undefined || value === false) {
                continue;
            }
            if (key === 'class') {
                node.className = value;
            } else if (key === 'dataset') {
                Object.assign(node.dataset, value);
            } else if (key.startsWith('on') && typeof value === 'function') {
                node.addEventListener(key.slice(2), value);
            } else if (key === 'text') {
                node.textContent = value;
            } else if (value === true) {
                node.setAttribute(key, '');
            } else {
                node.setAttribute(key, String(value));
            }
        }
        for (const child of children.flat()) {
            if (child === null || child === undefined || child === false) {
                continue;
            }
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        }
        return node;
    }

    function formatDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(value || ''));
        if (!match) {
            return String(value || '');
        }
        return match[3] + '.' + match[2] + '.' + match[1] + (match[4] ? ' ' + match[4] + ':' + match[5] : '');
    }

    // ------------------------------------------------------------ Beispieldaten

    const today = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const todayText = pad(today.getDate()) + '.' + pad(today.getMonth() + 1) + '.' + today.getFullYear();
    const settings = obj(data.settings);
    const centerName = settings.center_name || 'Nachsorgezentrum (Name aus den Ausweis-Stammdaten)';
    const centerAddressLines = String(settings.center_address || 'Anschrift aus den Ausweis-Stammdaten').split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const sample = {
        center_name: centerName,
        center_address_line: centerAddressLines.join(', '),
        patient_name: 'MUSTERMANN, ERIKA',
        first_name: 'ERIKA',
        last_name: 'MUSTERMANN',
        date_of_birth: '12.04.1950',
        patient_identifier: 'BEISPIEL-001',
        document_number: 'HSM2Med-Brief-' + today.getFullYear() + pad(today.getMonth() + 1) + pad(today.getDate()) + '-000000-001',
        letter_date: todayText,
        sequence_no: '1',
        page: '1',
        pages: '2',
    };

    function fill(text) {
        return String(text).replace(/\{([a-z_]+)\}/g, (match, key) => (key in sample ? sample[key] : match));
    }

    /** Text mit Zeilenumbruechen; leere Texte werden als Hinweis dargestellt. */
    function textNode(text, emptyHint) {
        const value = fill(text);
        if (value.trim() === '') {
            return emptyHint ? h('span', { class: 'te-empty', text: emptyHint }) : null;
        }
        const span = h('span', { class: 'te-pre' });
        span.textContent = value;
        return span;
    }

    // ------------------------------------------------------------- Darstellung

    function render() {
        el.name.value = state.content.name;
        renderZones();
        renderBlocks();
        renderPaper();
        renderProps();
        renderStatus();
    }

    function renderStatus() {
        const dirty = isDirty();
        el.state.classList.toggle('is-dirty', dirty);
        el.state.replaceChildren(icon(dirty ? 'warning' : 'check'), h('span', { text: dirty ? 'Ungespeicherte Änderungen' : 'Gespeichert' }));
        el.title.textContent = state.content.name + ' – Fassung ' + state.base.version_no + (dirty ? ' (geändert)' : '');
        document.title = (dirty ? '● ' : '') + 'Briefvorlage bearbeiten – HSM2Med';
        el.statusVersion.textContent = 'Grundlage: Fassung ' + state.base.version_no + ' vom ' + formatDate(state.base.created_at);
        const visible = state.content.blocks.filter((block) => block.enabled).length;
        el.statusBlocks.textContent = state.content.blocks.length + ' Bausteine, davon ' + visible + ' eingeblendet';
        // Fehler zu Name und Bausteinliste stehen ausserhalb des Eigenschaftsbereichs.
        el.nameError.hidden = !state.errors.name;
        el.nameError.textContent = state.errors.name || '';
        el.name.classList.toggle('is-invalid', Boolean(state.errors.name));
        el.blocksError.hidden = !state.errors.blocks;
        el.blocksError.textContent = state.errors.blocks || '';
    }

    function isSelected(kind, key) {
        return state.selected.kind === kind && state.selected.key === key;
    }

    function hasErrors(prefix) {
        return Object.keys(state.errors).some((path) => path === prefix || path.startsWith(prefix + '.'));
    }

    function blockPath(id) {
        return 'blocks.' + state.content.blocks.findIndex((block) => block.id === id);
    }

    function blockTitle(block) {
        const blockDef = def.blocks[block.type];
        if (block.type === 'text') {
            const heading = (block.texts.heading || '').trim();
            const text = (block.texts.text || '').trim().split('\n')[0];
            const summary = heading || text;
            return summary ? blockDef.label + ': ' + (summary.length > 32 ? summary.slice(0, 31) + '…' : summary) : blockDef.label;
        }
        return blockDef.label;
    }

    function renderZones() {
        el.zones.replaceChildren(...Object.entries(def.zones).map(([key, zoneDef]) => h('li', {},
            h('button', {
                type: 'button',
                class: 'te-item' + (isSelected('zone', key) ? ' is-selected' : '') + (hasErrors('zones.' + key) ? ' has-error' : ''),
                'aria-pressed': isSelected('zone', key) ? 'true' : 'false',
                onclick: () => select('zone', key),
            }, h('span', { class: 'te-item__label', text: zoneDef.label }), hasErrors('zones.' + key) ? icon('warning') : null),
        )));
    }

    function renderBlocks() {
        const blocks = state.content.blocks;
        el.blocks.replaceChildren(...blocks.map((block, index) => {
            const blockDef = def.blocks[block.type];
            const path = 'blocks.' + index;
            const item = h('li', {
                class: 'te-block' + (isSelected('block', block.id) ? ' is-selected' : '') + (block.enabled ? '' : ' is-off') + (hasErrors(path) ? ' has-error' : ''),
                draggable: 'true',
                dataset: { blockId: block.id },
                tabindex: '0',
                'aria-label': blockTitle(block) + (block.enabled ? '' : ' (ausgeblendet)') + ', Position ' + (index + 1) + ' von ' + blocks.length,
                onclick: (event) => {
                    if (!event.target.closest('button, input')) {
                        select('block', block.id);
                    }
                },
                onkeydown: (event) => {
                    if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                        event.preventDefault();
                        moveBy(block.id, event.key === 'ArrowUp' ? -1 : 1, true);
                    } else if ((event.key === 'Enter' || event.key === ' ') && event.target === item) {
                        event.preventDefault();
                        select('block', block.id);
                    }
                },
            },
            h('span', { class: 'te-grip', 'aria-hidden': 'true', title: 'Zum Verschieben ziehen' }),
            h('input', {
                type: 'checkbox',
                class: 'te-toggle',
                checked: block.enabled,
                title: block.enabled ? 'Baustein ausblenden' : 'Baustein einblenden',
                'aria-label': blockDef.label + ' eingeblendet',
                onchange: (event) => {
                    block.enabled = event.target.checked;
                    changed();
                },
            }),
            h('span', { class: 'te-block__label', text: blockTitle(block) }),
            hasErrors(path) ? icon('warning') : null,
            h('span', { class: 'te-block__tools' },
                h('button', { type: 'button', class: 'te-icon-btn', title: 'Nach oben', 'aria-label': 'Nach oben verschieben', disabled: index === 0, onclick: () => moveBy(block.id, -1, false), text: '↑' }),
                h('button', { type: 'button', class: 'te-icon-btn', title: 'Nach unten', 'aria-label': 'Nach unten verschieben', disabled: index === blocks.length - 1, onclick: () => moveBy(block.id, 1, false), text: '↓' }),
                blockDef.unique ? null : h('button', { type: 'button', class: 'te-icon-btn te-icon-btn--danger', title: 'Textbaustein löschen', 'aria-label': 'Textbaustein löschen', onclick: () => removeBlock(block.id) }, icon('close')),
            ));
            bindDrag(item, block.id);
            return item;
        }));
    }

    function renderPaper() {
        const width = el.paper.parentElement.clientWidth - 32;
        const mm = Math.max(1.6, Math.min(4.2, width / PAGE.width));
        el.paper.style.setProperty('--mm', mm + 'px');
        el.paper.style.width = (PAGE.width * mm) + 'px';
        el.paper.style.minHeight = (PAGE.height * mm) + 'px';
        el.paper.style.fontSize = (mm * 3.6) + 'px';

        const zones = state.content.zones;
        const place = (node, x, y, w, hgt) => {
            node.style.left = (x * mm) + 'px';
            node.style.top = (y * mm) + 'px';
            if (w !== null) {
                node.style.width = (w * mm) + 'px';
            }
            if (hgt !== null) {
                node.style.height = (hgt * mm) + 'px';
            }
            return node;
        };
        const zoneBox = (key, extraClass, ...children) => h('div', {
            class: 'te-zone ' + extraClass + (isSelected('zone', key) ? ' is-selected' : '') + (hasErrors('zones.' + key) ? ' has-error' : ''),
            dataset: { zone: key },
            title: def.zones[key].label + ' – zum Bearbeiten klicken',
            onclick: (event) => {
                event.stopPropagation();
                select('zone', key);
            },
        }, h('span', { class: 'te-zone__tag', text: def.zones[key].label }), ...children);

        const head = h('div', { class: 'te-paper__head' });
        head.style.height = (PAGE.bodyTop * mm) + 'px';

        // Briefkopf
        const letterhead = zones.letterhead;
        head.append(place(zoneBox('letterhead', 'te-zone--letterhead',
            h('div', { class: 'te-lh__text' },
                h('strong', { text: centerName }),
                ...centerAddressLines.map((line) => h('span', { text: line })),
                textNode(letterhead.texts.extra, null),
            ),
            letterhead.options.show_logo ? h('div', { class: 'te-lh__logo', text: settings.has_logo ? 'Logo' : 'Logo (nicht hinterlegt)' }) : null,
        ), PAGE.left, 8, PAGE.width - PAGE.left - PAGE.right, 34));

        // Ruecksendeangabe und Anschriftfeld
        const ret = zones.return_address;
        head.append(place(zoneBox('return_address', 'te-zone--return' + (ret.options.show ? '' : ' is-off'),
            ret.options.show ? h('span', { class: 'te-return', text: fill(ret.texts.text) }) : h('span', { class: 'te-empty', text: 'Rücksendeangabe ausgeblendet' }),
        ), 20, 45, 85, 5));
        const recipient = zones.recipient;
        const recipientLines = recipient.options.source === 'patient'
            ? ['Frau', 'Erika Mustermann', 'Musterstraße 1', '12345 Beispielstadt']
            : fill(recipient.texts.text).split('\n');
        head.append(place(zoneBox('recipient', 'te-zone--recipient',
            recipient.texts.remark.trim() !== '' ? h('span', { class: 'te-remark', text: fill(recipient.texts.remark) }) : null,
            h('div', { class: 'te-recipient' }, ...recipientLines.map((line) => h('span', { text: line }))),
        ), 20, 50.5, 85, 40));

        // Informationsblock
        const info = zones.info_block.texts;
        const infoRows = [
            [info.label_reference, sample.document_number],
            [info.label_patient, sample.patient_name],
            [info.label_birth, sample.date_of_birth],
            [info.label_identifier, sample.patient_identifier],
            [info.label_sequence, sample.sequence_no],
            [info.label_settings, 'Fassung 1'],
            [info.label_date, sample.letter_date],
        ].filter(([label]) => String(label).trim() !== '');
        head.append(place(zoneBox('info_block', 'te-zone--info',
            h('dl', { class: 'te-info' }, ...infoRows.flatMap(([label, value]) => [h('dt', { text: fill(label) }), h('dd', { text: value })])),
        ), 125, 50, 75, 44));

        // Falz- und Lochmarken
        const marks = [];
        if (zones.footer.options.fold_marks) {
            for (const y of [105, 148.5, 210]) {
                const mark = h('span', { class: 'te-mark' + (y === 148.5 ? ' te-mark--hole' : ''), 'aria-hidden': 'true' });
                mark.style.top = (y * mm) + 'px';
                marks.push(mark);
            }
        }

        // Brieftext
        const body = h('div', { class: 'te-paper__body', dataset: { dropzone: 'body' } });
        body.style.padding = '0 ' + (PAGE.right * mm) + 'px 0 ' + (PAGE.left * mm) + 'px';
        for (const block of state.content.blocks) {
            const node = h('div', {
                class: 'te-pblock te-pblock--' + block.type + (isSelected('block', block.id) ? ' is-selected' : '') + (block.enabled ? '' : ' is-off') + (hasErrors(blockPath(block.id)) ? ' has-error' : ''),
                draggable: 'true',
                dataset: { blockId: block.id },
                title: blockTitle(block) + ' – zum Bearbeiten klicken, zum Verschieben ziehen',
                onclick: (event) => {
                    event.stopPropagation();
                    select('block', block.id);
                },
            }, h('span', { class: 'te-zone__tag', text: blockTitle(block) + (block.enabled ? '' : ' · ausgeblendet') }), ...blockPreview(block));
            bindDrag(node, block.id);
            body.append(node);
        }
        if (state.content.blocks.length === 0) {
            body.append(h('p', { class: 'te-empty', text: 'Keine Bausteine vorhanden.' }));
        }

        // Fusszeile
        const footer = zones.footer.texts;
        const foot = zoneBox('footer', 'te-zone--footer',
            h('div', { class: 'te-footer' },
                h('div', {}, textNode(footer.disclaimer, null), h('span', { class: 'te-footer__meta', text: 'Erstellt am ' + todayText + ' · Vorlage Fassung ' + state.base.version_no })),
                h('span', { class: 'te-footer__page', text: fill(footer.page_label) }),
            ),
        );
        foot.style.margin = '0 ' + (PAGE.right * mm) + 'px 0 ' + (PAGE.left * mm) + 'px';

        // Weitere Bereiche (Folgeseiten, Anhang, Allgemein) als Hinweise unterhalb
        const continuation = zoneBox('footer', 'te-zone--continuation',
            h('span', { class: 'te-muted', text: 'Kopfzeile ab Seite 2: ' }), h('span', { text: fill(footer.continuation) }));
        continuation.style.margin = '0 ' + (PAGE.right * mm) + 'px 0 ' + (PAGE.left * mm) + 'px';
        const appendix = zones.appendix;
        const appendixBox = zoneBox('appendix', 'te-zone--appendix' + (appendix.options.show ? '' : ' is-off'),
            h('strong', { text: appendix.options.show ? fill(appendix.texts.heading) : 'Anhang ausgeblendet' }),
            appendix.options.show ? h('span', { class: 'te-muted', text: fill(appendix.texts.intro) + ' · neue Seite' }) : null,
        );
        appendixBox.style.margin = '0 ' + (PAGE.right * mm) + 'px 0 ' + (PAGE.left * mm) + 'px';

        el.paper.replaceChildren(head, ...marks, body, h('div', { class: 'te-paper__grow' }), appendixBox, continuation, foot);
    }

    function headingNode(text) {
        const value = fill(text).trim();
        return value === '' ? null : h('strong', { class: 'te-p-heading', text: value });
    }

    function metaNode(block, text) {
        return block.options.show_meta ? h('span', { class: 'te-p-meta', text }) : null;
    }

    function blockPreview(block) {
        const t = block.texts;
        const empty = fill(state.content.zones.general.texts.empty);
        switch (block.type) {
            case 'subject':
                return [h('strong', { class: 'te-p-subject', text: fill(t.title) }), t.line2.trim() !== '' ? h('strong', { class: 'te-p-subject', text: fill(t.line2) }) : null];
            case 'salutation':
                return [textNode(t.text, 'Keine Anrede')];
            case 'patient':
                return [headingNode(t.heading), h('dl', { class: 'te-p-rows' },
                    ...[[t.label_name, sample.patient_name], [t.label_birth, sample.date_of_birth], [t.label_identifier, sample.patient_identifier],
                        [t.label_address, 'Musterstraße 1, 12345 Beispielstadt · ' + fill(t.label_phone) + ' 01234 567890']]
                        .filter(([label]) => label.trim() !== '')
                        .flatMap(([label, value]) => [h('dt', { text: fill(label) }), h('dd', { text: value })]))];
            case 'anamnesis':
            case 'epicrisis':
                return [headingNode(t.heading), metaNode(block, 'Fassung vom ' + todayText + ' · Beispiel-Anwender'),
                    h('span', { class: 'te-p-sample', text: 'Text aus der Patientenakte (Beispiel). Leere Bausteine erscheinen als „' + empty + '“.' })];
            case 'premedication':
                return [headingNode(t.heading), metaNode(block, 'Fassung vom ' + todayText + ' · Beispiel-Anwender'),
                    h('table', { class: 'te-p-table' },
                        h('thead', {}, h('tr', {}, ...[t.col_substance, t.col_dose, t.col_schedule, t.col_reason, t.col_period].map((label) => h('th', { text: fill(label) })))),
                        h('tbody', {}, h('tr', {}, ...['Beispielpräparat A', '5 mg', '1-0-0', 'Beispielgrund', 'seit 01.01.2024'].map((value) => h('td', { text: value })))))];
            case 'report':
                return [headingNode(t.heading), metaNode(block, 'Bericht vom ' + todayText + ' · Beispieldaten'),
                    h('dl', { class: 'te-p-rows' }, ...[['Gerät', 'Beispielgerät DR'], ['Betriebsart', 'DDD'], ['Sonde RA', 'Impedanz 520 Ohm']].flatMap(([label, value]) => [h('dt', { text: label }), h('dd', { text: value })]))];
            case 'closing':
                return [textNode(t.text, 'Keine Grußformel'), h('span', { class: 'te-p-signature', 'aria-hidden': 'true' }), textNode(t.signature, null)];
            case 'text':
                return [headingNode(t.heading), textNode(t.text, 'Leerer Textbaustein – rechts Text eingeben')];
            default:
                return [];
        }
    }

    // ------------------------------------------------------------- Eigenschaften

    function renderProps() {
        const { kind, key } = state.selected;
        let definition;
        let target;
        let path;
        let block = null;
        if (kind === 'zone') {
            definition = def.zones[key];
            target = state.content.zones[key];
            path = 'zones.' + key;
        } else {
            block = state.content.blocks.find((candidate) => candidate.id === key);
            if (!block) {
                el.props.replaceChildren(h('p', { class: 'te-hint', text: 'Bitte links einen Bereich oder Baustein auswählen.' }));
                return;
            }
            definition = def.blocks[block.type];
            target = block;
            path = blockPath(block.id);
        }

        const nodes = [
            h('h3', { class: 'te-props__title', text: kind === 'zone' ? definition.label : blockTitle(block) }),
            h('p', { class: 'te-hint', text: definition.description }),
        ];
        if (kind === 'zone') {
            nodes.push(h('p', { class: 'te-badge', text: 'Fester Bereich · Lage nach DIN 5008' }));
        }
        if (state.errors[path]) {
            nodes.push(h('p', { class: 'field-error', text: state.errors[path] }));
        }

        if (block) {
            const index = state.content.blocks.indexOf(block);
            nodes.push(h('div', { class: 'te-props__row' },
                h('label', { class: 'check' },
                    h('input', { type: 'checkbox', checked: block.enabled, onchange: (event) => { block.enabled = event.target.checked; changed(); } }),
                    ' Im Brief anzeigen'),
                h('span', { class: 'te-props__tools' },
                    h('button', { type: 'button', disabled: index === 0, onclick: () => moveBy(block.id, -1, false), title: 'Nach oben verschieben', text: '↑ Nach oben' }),
                    h('button', { type: 'button', disabled: index === state.content.blocks.length - 1, onclick: () => moveBy(block.id, 1, false), title: 'Nach unten verschieben', text: '↓ Nach unten' }),
                ),
            ));
        }

        const options = Object.entries(obj(definition.options));
        if (options.length > 0) {
            nodes.push(h('h4', { class: 'te-subhead', text: 'Optionen' }));
            for (const [optionKey, optionDef] of options) {
                const fieldPath = path + '.options.' + optionKey;
                if (optionDef.type === 'bool') {
                    nodes.push(h('label', { class: 'check te-option' },
                        h('input', { type: 'checkbox', checked: target.options[optionKey] === true, onchange: (event) => { target.options[optionKey] = event.target.checked; changed(fieldPath); } }),
                        ' ' + optionDef.label));
                } else {
                    const id = 'te-opt-' + optionKey;
                    nodes.push(h('div', { class: 'te-field' },
                        h('label', { for: id, text: optionDef.label }),
                        h('select', { id, onchange: (event) => { target.options[optionKey] = event.target.value; changed(fieldPath); } },
                            ...Object.entries(optionDef.choices).map(([value, label]) => h('option', { value, selected: target.options[optionKey] === value, text: label }))),
                    ));
                }
                if (state.errors[fieldPath]) {
                    nodes.push(h('p', { class: 'field-error', text: state.errors[fieldPath] }));
                }
            }
        }

        const texts = Object.entries(obj(definition.texts));
        if (texts.length > 0) {
            nodes.push(h('h4', { class: 'te-subhead', text: 'Texte' }));
            for (const [textKey, textDef] of texts) {
                nodes.push(textField(target, path, textKey, textDef));
            }
            nodes.push(placeholderPanel());
        }

        if (block && !definition.unique) {
            nodes.push(h('div', { class: 'te-props__danger' },
                h('button', { type: 'button', class: 'te-danger', onclick: () => removeBlock(block.id) }, icon('close'), ' Textbaustein löschen')));
        }
        el.props.replaceChildren(...nodes);
    }

    function textField(target, path, textKey, textDef) {
        const fieldPath = path + '.texts.' + textKey;
        const id = 'te-text-' + textKey;
        const max = textDef.multiline ? def.limits.multi : def.limits.single;
        const value = target.texts[textKey];
        const counter = h('span', { class: 'te-counter' });
        const updateCounter = () => {
            counter.textContent = target.texts[textKey].length + ' / ' + max;
            counter.classList.toggle('is-over', target.texts[textKey].length > max);
        };
        const attrs = {
            id,
            maxlength: String(max),
            class: state.errors[fieldPath] ? 'is-invalid' : '',
            'aria-invalid': state.errors[fieldPath] ? 'true' : null,
            dataset: { path: fieldPath, page: textDef.page ? '1' : '' },
            oninput: (event) => {
                target.texts[textKey] = textDef.multiline ? event.target.value : event.target.value.replace(/\n/g, ' ');
                updateCounter();
                delete state.errors[fieldPath];
                event.target.classList.remove('is-invalid');
                softChanged();
            },
            onfocus: (event) => {
                state.lastField = { node: event.target, page: textDef.page === true };
                updateChips();
            },
        };
        const input = textDef.multiline
            ? h('textarea', { ...attrs, rows: String(Math.min(8, Math.max(3, value.split('\n').length + 1))) })
            : h('input', { ...attrs, type: 'text', autocomplete: 'off' });
        input.value = value;
        updateCounter();
        const isDefault = value === String(textDef.default);
        return h('div', { class: 'te-field' },
            h('div', { class: 'te-field__head' },
                h('label', { for: id, text: textDef.label }),
                isDefault ? null : h('button', {
                    type: 'button',
                    class: 'te-link',
                    title: 'Standardtext: ' + (textDef.default === '' ? '(leer)' : textDef.default),
                    onclick: () => { target.texts[textKey] = String(textDef.default); delete state.errors[fieldPath]; changed(); },
                    text: 'Standard',
                })),
            input,
            h('div', { class: 'te-field__foot' }, textDef.multiline ? h('span', { class: 'te-hint', text: 'Zeilenumbrüche werden übernommen.' }) : h('span'), counter),
            state.errors[fieldPath] ? h('p', { class: 'field-error', text: state.errors[fieldPath] }) : null,
        );
    }

    function placeholderPanel() {
        const chip = (key, label, page) => h('button', {
            type: 'button',
            class: 'te-chip' + (page ? ' te-chip--page' : ''),
            dataset: { placeholder: key, page: page ? '1' : '' },
            title: label + (page ? ' (nur in der Seitenangabe)' : ''),
            onmousedown: (event) => event.preventDefault(),
            onclick: () => insertPlaceholder(key),
            text: '{' + key + '}',
        });
        const panel = h('div', { class: 'te-placeholders' },
            h('h4', { class: 'te-subhead', text: 'Platzhalter' }),
            h('p', { class: 'te-hint', text: 'In ein Textfeld klicken, dann Platzhalter wählen. Er wird beim Erstellen des Briefes durch die Daten ersetzt.' }),
            h('div', { class: 'te-chips' },
                ...Object.entries(def.placeholders).map(([key, label]) => chip(key, label, false)),
                ...Object.entries(def.pagePlaceholders).map(([key, label]) => chip(key, label, true))),
        );
        return panel;
    }

    function updateChips() {
        const field = state.lastField && document.body.contains(state.lastField.node) ? state.lastField : null;
        for (const chip of el.props.querySelectorAll('.te-chip')) {
            chip.disabled = field === null || (chip.dataset.page === '1' && !field.page);
        }
    }

    function insertPlaceholder(key) {
        const field = state.lastField;
        if (!field || !document.body.contains(field.node)) {
            return;
        }
        const input = field.node;
        const token = '{' + key + '}';
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.setRangeText(token, start, end, 'end');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    }

    // ------------------------------------------------------------- Aenderungen

    let softTimer = null;

    /** Aenderung beim Tippen: Vorschau verzoegert aktualisieren, Fokus im Feld behalten. */
    function softChanged() {
        renderStatus();
        clearTimeout(softTimer);
        softTimer = setTimeout(() => {
            renderPaper();
            renderBlocks();
            renderZones();
            const title = el.props.querySelector('.te-props__title');
            if (title && state.selected.kind === 'block') {
                const block = state.content.blocks.find((candidate) => candidate.id === state.selected.key);
                if (block) {
                    title.textContent = blockTitle(block);
                }
            }
        }, 150);
    }

    function changed(fieldPath) {
        if (fieldPath) {
            delete state.errors[fieldPath];
        }
        const active = document.activeElement;
        const activePath = active && active.dataset ? active.dataset.path : null;
        render();
        if (activePath) {
            const again = el.props.querySelector('[data-path="' + CSS.escape(activePath) + '"]');
            if (again) {
                again.focus();
            }
        }
    }

    function select(kind, key) {
        state.selected = { kind, key };
        state.lastField = null;
        render();
        const selector = kind === 'zone' ? '.te-zone[data-zone="' + key + '"]' : '.te-pblock[data-block-id="' + CSS.escape(key) + '"]';
        const node = el.paper.querySelector(selector);
        if (node) {
            node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        updateChips();
    }

    function move(id, toIndex) {
        const blocks = state.content.blocks;
        const from = blocks.findIndex((block) => block.id === id);
        if (from < 0) {
            return;
        }
        const [block] = blocks.splice(from, 1);
        const target = Math.max(0, Math.min(blocks.length, toIndex > from ? toIndex - 1 : toIndex));
        blocks.splice(target, 0, block);
        remapErrors();
        render();
        if (target !== from) {
            announce(blockTitle(block) + ' an Position ' + (target + 1) + ' verschoben.');
        }
    }

    function moveBy(id, delta, keepFocus) {
        const index = state.content.blocks.findIndex((block) => block.id === id);
        const target = index + delta;
        if (index < 0 || target < 0 || target >= state.content.blocks.length) {
            return;
        }
        move(id, delta > 0 ? target + 1 : target);
        if (keepFocus) {
            const item = el.blocks.querySelector('[data-block-id="' + CSS.escape(id) + '"]');
            if (item) {
                item.focus();
            }
        }
    }

    function addTextBlock() {
        if (state.content.blocks.length >= def.maxBlocks) {
            showMessage('error', 'Eine Vorlage darf höchstens ' + def.maxBlocks + ' Bausteine enthalten.');
            return;
        }
        const id = uniqueId(state.content.blocks, 'text');
        const block = { id, type: 'text', enabled: true, options: fillOptions(def.blocks.text, {}), texts: fillTexts(def.blocks.text, {}) };
        const selected = state.selected.kind === 'block' ? state.content.blocks.findIndex((candidate) => candidate.id === state.selected.key) : -1;
        // Nach dem gewaehlten Baustein einfuegen, sonst vor der Grussformel bzw. am Ende.
        let index = selected >= 0 ? selected + 1 : state.content.blocks.findIndex((candidate) => candidate.type === 'closing');
        if (index < 0) {
            index = state.content.blocks.length;
        }
        state.content.blocks.splice(index, 0, block);
        remapErrors();
        select('block', id);
        const field = el.props.querySelector('textarea, input[type="text"]');
        if (field) {
            field.focus();
        }
    }

    function removeBlock(id) {
        const index = state.content.blocks.findIndex((block) => block.id === id);
        if (index < 0) {
            return;
        }
        const block = state.content.blocks[index];
        const hasText = Object.values(block.texts).some((value) => value.trim() !== '');
        if (hasText && !window.confirm('Den Textbaustein „' + blockTitle(block) + '“ löschen?')) {
            return;
        }
        state.content.blocks.splice(index, 1);
        remapErrors();
        const next = state.content.blocks[Math.min(index, state.content.blocks.length - 1)];
        state.selected = next ? { kind: 'block', key: next.id } : { kind: 'zone', key: Object.keys(def.zones)[0] };
        render();
        announce('Textbaustein gelöscht.');
    }

    /** Fehlerpfade beziehen sich auf die Position beim Speichern; nach Verschieben mitfuehren. */
    let errorBlockIds = [];
    function rememberErrorBlocks() {
        errorBlockIds = state.content.blocks.map((block) => block.id);
    }
    function remapErrors() {
        const remapped = {};
        for (const [path, message] of Object.entries(state.errors)) {
            const match = /^blocks\.(\d+)(.*)$/.exec(path);
            if (!match) {
                remapped[path] = message;
                continue;
            }
            const id = errorBlockIds[Number(match[1])];
            const index = state.content.blocks.findIndex((block) => block.id === id);
            if (index >= 0) {
                remapped['blocks.' + index + match[2]] = message;
            }
        }
        state.errors = remapped;
        rememberErrorBlocks();
    }

    // -------------------------------------------------------------- Drag & Drop

    let dragId = null;

    function clearDropMarks() {
        for (const node of document.querySelectorAll('.is-drop-before, .is-drop-after, .is-dragging')) {
            node.classList.remove('is-drop-before', 'is-drop-after', 'is-dragging');
        }
    }

    function bindDrag(node, id) {
        node.addEventListener('dragstart', (event) => {
            if (event.target.closest('input, textarea, select, button') && event.target !== node) {
                event.preventDefault();
                return;
            }
            dragId = id;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', id);
            node.classList.add('is-dragging');
        });
        node.addEventListener('dragend', () => {
            dragId = null;
            clearDropMarks();
        });
        node.addEventListener('dragover', (event) => {
            if (dragId === null) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const rect = node.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            for (const other of document.querySelectorAll('.is-drop-before, .is-drop-after')) {
                if (other !== node) {
                    other.classList.remove('is-drop-before', 'is-drop-after');
                }
            }
            node.classList.toggle('is-drop-after', after);
            node.classList.toggle('is-drop-before', !after);
        });
        node.addEventListener('dragleave', (event) => {
            if (!node.contains(event.relatedTarget)) {
                node.classList.remove('is-drop-before', 'is-drop-after');
            }
        });
        node.addEventListener('drop', (event) => {
            if (dragId === null) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            const rect = node.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            const index = state.content.blocks.findIndex((block) => block.id === id);
            const moving = dragId;
            dragId = null;
            clearDropMarks();
            if (moving !== id) {
                move(moving, after ? index + 1 : index);
                select('block', moving);
            }
        });
    }

    // ------------------------------------------------------------ Server-Zugriffe

    function showMessage(type, text, extra) {
        el.message.className = 'te-message alert alert-' + type;
        const parts = [icon(type === 'success' ? 'check' : type === 'info' ? 'info' : 'warning'), h('span', { text })];
        if (extra) {
            parts.push(extra);
        }
        parts.push(h('button', { type: 'button', class: 'te-icon-btn te-message__close', 'aria-label': 'Meldung schließen', onclick: () => { el.message.hidden = true; } }, icon('close')));
        el.message.replaceChildren(...parts);
        el.message.hidden = false;
    }

    let liveRegion = null;
    function announce(text) {
        if (!liveRegion) {
            liveRegion = h('div', { class: 'te-sr', 'aria-live': 'polite' });
            document.body.append(liveRegion);
        }
        liveRegion.textContent = text;
    }

    function setBusy(busy) {
        state.busy = busy;
        for (const button of document.querySelectorAll('[data-te-action="save"], [data-te-action="preview"]')) {
            button.disabled = busy;
        }
        document.body.classList.toggle('is-busy', busy);
    }

    async function postForm(url, fields) {
        const body = new URLSearchParams({ _csrf: data.csrf, ...fields });
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            body,
        });
        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }
        return { status: response.status, payload };
    }

    function openSaveDialog() {
        if (state.busy) {
            return;
        }
        if (!isDirty()) {
            showMessage('info', 'Es gibt keine Änderungen gegenüber Fassung ' + state.base.version_no + '.');
            return;
        }
        el.comment.value = '';
        el.saveDialog.showModal();
        el.comment.focus();
    }

    async function save(comment) {
        setBusy(true);
        rememberErrorBlocks();
        try {
            const { status, payload } = await postForm(data.urls.save, {
                content: serialize(state.content),
                comment,
                base_version_id: String(state.base.id),
            });
            if (payload && payload.ok) {
                state.base = payload.current;
                state.baseContent = prepare(payload.current.content);
                state.content = prepare(payload.current.content);
                state.versions = payload.versions || state.versions;
                state.errors = {};
                if (state.selected.kind === 'block' && !state.content.blocks.some((block) => block.id === state.selected.key)) {
                    state.selected = { kind: 'block', key: state.content.blocks[0] ? state.content.blocks[0].id : null };
                }
                render();
                showMessage('success', payload.message);
                return;
            }
            if (payload && status === 422) {
                state.errors = obj(payload.errors);
                render();
                const extra = state.errors.base_version
                    ? h('button', { type: 'button', class: 'te-link', onclick: () => openVersions(), text: 'Fassungen anzeigen' })
                    : null;
                showMessage(state.errors.template || state.errors.base_version ? 'warning' : 'error', describeErrors(payload), extra);
                return;
            }
            showMessage('error', status === 403
                ? 'Die Sitzung ist abgelaufen. Bitte den Editor neu laden (ungespeicherte Änderungen vorher sichern, z. B. per PDF-Vorschau prüfen).'
                : 'Speichern fehlgeschlagen (Status ' + status + ').');
        } catch (error) {
            showMessage('error', 'Speichern fehlgeschlagen: Der Server ist nicht erreichbar.');
        } finally {
            setBusy(false);
        }
    }

    function describeErrors(payload) {
        const errors = obj(payload.errors);
        const count = Object.keys(errors).length;
        if (count === 1) {
            return Object.values(errors)[0];
        }
        return (payload.message || 'Die Vorlage enthält Fehler.') + ' (' + count + ' Hinweise – markierte Bereiche prüfen)';
    }

    function preview() {
        el.previewContent.value = serialize(state.content);
        el.previewForm.submit();
    }

    function renderVersions() {
        el.versionsBody.replaceChildren(...state.versions.map((version) => h('tr', { class: version.id === state.base.id ? 'is-current' : '' },
            h('td', {}, h('strong', { text: String(version.version_no) }), version.id === state.base.id ? h('span', { class: 'te-badge', text: 'aktuell' }) : null),
            h('td', { text: version.name }),
            h('td', { text: formatDate(version.created_at) }),
            h('td', { text: version.comment || '–' }),
            h('td', { class: 'num', text: String(version.letter_count) }),
            h('td', {}, h('button', { type: 'button', onclick: () => loadVersion(version.id), text: 'In Editor laden' })),
        )));
    }

    function openVersions() {
        renderVersions();
        el.versionsDialog.showModal();
    }

    async function loadVersion(id) {
        if (isDirty() && !window.confirm('Ungespeicherte Änderungen gehen verloren. Fassung trotzdem laden?')) {
            return;
        }
        try {
            const response = await fetch(data.urls.version + encodeURIComponent(String(id)), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const payload = await response.json();
            state.content = prepare(payload.version.content);
            state.errors = {};
            state.selected = { kind: 'block', key: state.content.blocks[0] ? state.content.blocks[0].id : null };
            el.versionsDialog.close();
            render();
            showMessage('info', payload.version.id === state.base.id
                ? 'Fassung ' + payload.version.version_no + ' (aktuell) geladen.'
                : 'Fassung ' + payload.version.version_no + ' geladen. Zum Wiederherstellen als neue Fassung speichern.');
        } catch (error) {
            showMessage('error', 'Die Fassung konnte nicht geladen werden.');
        }
    }

    // ------------------------------------------------------------ Aktionen

    const actions = {
        save: openSaveDialog,
        discard: () => {
            if (!isDirty()) {
                showMessage('info', 'Es gibt keine ungespeicherten Änderungen.');
                return;
            }
            if (window.confirm('Alle ungespeicherten Änderungen verwerfen?')) {
                state.content = clone(state.baseContent);
                state.errors = {};
                if (state.selected.kind === 'block' && !state.content.blocks.some((block) => block.id === state.selected.key)) {
                    state.selected = { kind: 'block', key: state.content.blocks[0] ? state.content.blocks[0].id : null };
                }
                render();
                showMessage('info', 'Änderungen verworfen.');
            }
        },
        'add-text': addTextBlock,
        reset: () => {
            if (!window.confirm('Die Standardvorlage laden? Alle Texte und die Reihenfolge werden auf den Auslieferungszustand gesetzt. Wirksam wird dies erst beim Speichern.')) {
                return;
            }
            state.content = prepare(def.default);
            state.errors = {};
            state.selected = { kind: 'block', key: state.content.blocks[0] ? state.content.blocks[0].id : null };
            render();
            showMessage('info', 'Standardvorlage geladen. Zum Übernehmen als neue Fassung speichern.');
        },
        preview,
        versions: openVersions,
        help: () => el.helpDialog.showModal(),
        close: () => {
            if (isDirty() && !window.confirm('Ungespeicherte Änderungen verwerfen und den Editor schließen?')) {
                return;
            }
            window.removeEventListener('beforeunload', beforeUnload);
            window.close();
            // Wurde der Tab nicht per Skript geoeffnet, laesst er sich nicht schliessen.
            setTimeout(() => { window.location.href = '/system'; }, 150);
        },
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-te-action]');
        if (trigger && actions[trigger.dataset.teAction] && !trigger.disabled) {
            event.preventDefault();
            actions[trigger.dataset.teAction]();
        }
    });

    el.saveDialog.addEventListener('close', () => {
        if (el.saveDialog.returnValue === 'save') {
            save(el.comment.value);
        }
        el.saveDialog.returnValue = '';
    });

    el.name.addEventListener('input', () => {
        state.content.name = el.name.value;
        delete state.errors.name;
        renderStatus();
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            if (!document.querySelector('dialog[open]')) {
                openSaveDialog();
            }
        }
    });

    function beforeUnload(event) {
        if (isDirty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }
    window.addEventListener('beforeunload', beforeUnload);

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(renderPaper, 120);
    });

    render();
})();
