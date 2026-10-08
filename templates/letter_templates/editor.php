<?php
/**
 * Vorlageneditor fuer Briefe (eigenstaendige Seite, oeffnet in einem neuen Tab).
 *
 * Rahmen im Office-Stil wie die Hauptanwendung: Titelleiste, Funktionsband, Arbeitsbereich und
 * Statusleiste. Die Bedienung erfolgt ueber public/assets/js/template-editor.js; die Daten kommen
 * als nicht ausfuehrbarer JSON-Datenblock (CSP: keine Inline-Skripte).
 *
 * @var Closure $e
 * @var Closure $icon
 * @var Closure $csrf
 * @var string $title
 * @var string $appName
 * @var string $appVersion
 * @var array{id: int, version_no: int, name: string} $current
 * @var string $type Empfaengerart der bearbeiteten Vorlage
 * @var array<string, string> $types Empfaengerarten (Wert => Beschriftung)
 * @var string $data JSON (mit JSON_HEX_TAG kodiert, daher unbedenklich im Script-Block)
 */
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> – <?= $e($appName) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/office.css">
    <link rel="stylesheet" href="/assets/css/template-editor.css">
    <script src="/assets/js/template-editor.js" defer></script>
</head>
<body class="office te-body">
<a class="skip-link" href="#te-workspace">Direkt zum Editor</a>
<div class="app-chrome">
    <header class="titlebar">
        <a class="titlebar__brand" href="/system" title="Zurück zu den Systeminformationen">
            <span class="titlebar__mark"><?= $icon('pulse') ?></span>
            <span class="titlebar__brand-text">
                <strong><?= $e($appName) ?></strong>
                <small>Vorlageneditor</small>
            </span>
        </a>
        <p class="titlebar__doc">
            <span class="titlebar__doc-title" data-te-title>Briefvorlage – Fassung <?= $e($current['version_no']) ?></span>
            <span class="titlebar__doc-area">Briefe nach DIN 5008 (Form B) · Änderungen gelten nur für neu erstellte Briefe</span>
        </p>
        <span class="te-state" data-te-state role="status" aria-live="polite">
            <?= $icon('check', 'app-icon app-icon--sm') ?><span>Gespeichert</span>
        </span>
    </header>
    <nav class="ribbon" aria-label="Funktionsband des Vorlageneditors">
        <ul class="ribbon__tabs">
            <li><span class="ribbon__tab" aria-current="page"><?= $icon('edit') ?><span>Vorlage</span></span></li>
        </ul>
        <div class="ribbon__panel" role="toolbar" aria-label="Aktionen">
            <section class="rgroup">
                <div class="rgroup__items">
                    <button type="button" class="rbtn" data-te-action="save" title="Änderungen als neue Fassung speichern (Strg+S)">
                        <?= $icon('check', 'app-icon app-icon--lg') ?><span class="rbtn__label">Als neue Fassung speichern</span>
                    </button>
                    <button type="button" class="rbtn" data-te-action="discard" title="Alle ungespeicherten Änderungen verwerfen">
                        <?= $icon('undo', 'app-icon app-icon--lg') ?><span class="rbtn__label">Änderungen verwerfen</span>
                    </button>
                </div>
                <p class="rgroup__label">Speichern</p>
            </section>
            <section class="rgroup">
                <div class="rgroup__items">
                    <button type="button" class="rbtn" data-te-action="add-text" title="Freien Textbaustein am Ende des Brieftextes ergänzen">
                        <?= $icon('plus', 'app-icon app-icon--lg') ?><span class="rbtn__label">Textbaustein hinzufügen</span>
                    </button>
                    <button type="button" class="rbtn" data-te-action="reset" title="Die Standardvorlage in den Editor laden (erst beim Speichern wirksam)">
                        <?= $icon('refresh', 'app-icon app-icon--lg') ?><span class="rbtn__label">Standard laden</span>
                    </button>
                </div>
                <p class="rgroup__label">Bausteine</p>
            </section>
            <section class="rgroup">
                <div class="rgroup__items">
                    <button type="button" class="rbtn" data-te-action="preview" title="PDF mit Beispieldaten in einem neuen Tab erzeugen">
                        <?= $icon('printer', 'app-icon app-icon--lg') ?><span class="rbtn__label">PDF-Vorschau</span>
                    </button>
                    <button type="button" class="rbtn" data-te-action="versions" title="Gespeicherte Fassungen anzeigen und eine Fassung in den Editor laden">
                        <?= $icon('clock', 'app-icon app-icon--lg') ?><span class="rbtn__label">Fassungen</span>
                    </button>
                </div>
                <p class="rgroup__label">Ansicht</p>
            </section>
            <section class="rgroup">
                <div class="rgroup__items">
                    <button type="button" class="rbtn" data-te-action="help" title="Kurze Anleitung zum Vorlageneditor">
                        <?= $icon('help', 'app-icon app-icon--lg') ?><span class="rbtn__label">Hilfe</span>
                    </button>
                    <button type="button" class="rbtn" data-te-action="close" title="Editor schließen (Tab schließen)">
                        <?= $icon('close', 'app-icon app-icon--lg') ?><span class="rbtn__label">Schließen</span>
                    </button>
                </div>
                <p class="rgroup__label">Editor</p>
            </section>
        </div>
    </nav>
</div>

<main class="te-workspace" id="te-workspace">
    <noscript>
        <div class="alert alert-error">Der Vorlageneditor benötigt JavaScript. Bitte JavaScript im Browser aktivieren.</div>
    </noscript>
    <div class="te-message" data-te-message hidden></div>

    <aside class="te-pane te-structure" aria-label="Aufbau des Briefes">
        <div class="te-pane__head">
            <h2><?= $icon('list', 'app-icon app-icon--sm') ?> Aufbau</h2>
        </div>
        <div class="te-pane__body">
            <div class="te-field">
                <label for="te-type">Art der Vorlage</label>
                <select id="te-type" data-te-type>
                    <?php foreach ($types as $typeKey => $typeLabel): ?>
                        <option value="<?= $e($typeKey) ?>"<?= $typeKey === $type ? ' selected' : '' ?>><?= $e($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="te-hint">Anrede, Empfänger und Anschriftfeld werden je Art getrennt gepflegt. Die Anrede
                    selbst steht in den <a href="/patients" target="_blank" rel="noopener">Stammdaten des Patienten</a>.</p>
            </div>
            <div class="te-field">
                <label for="te-name">Name der Vorlage</label>
                <input type="text" id="te-name" data-te-name maxlength="200" autocomplete="off">
                <p class="field-error" data-te-error="name" hidden></p>
            </div>
            <h3 class="te-subhead">Feste Bereiche <small>(Lage nach DIN 5008)</small></h3>
            <ul class="te-zones" data-te-zones></ul>
            <h3 class="te-subhead">Brieftext <small>(per Drag and Drop sortieren)</small></h3>
            <ol class="te-blocks" data-te-blocks aria-describedby="te-blocks-hint"></ol>
            <p class="field-error" data-te-error="blocks" hidden></p>
            <p class="te-hint" id="te-blocks-hint">Bausteine mit der Maus am Griff ziehen oder mit den Pfeil-Schaltflächen
                bzw. <kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd> verschieben.</p>
            <button type="button" class="te-add" data-te-action="add-text"><?= $icon('plus', 'app-icon app-icon--sm') ?> <span>Textbaustein hinzufügen</span></button>
        </div>
    </aside>

    <section class="te-pane te-canvas" aria-label="Seitenvorschau (vereinfacht)">
        <div class="te-pane__head">
            <h2><?= $icon('eye', 'app-icon app-icon--sm') ?> Seitenvorschau</h2>
            <span class="te-hint">Vereinfacht mit Beispieldaten · Klick wählt einen Bereich · Bausteine lassen sich auch hier ziehen</span>
        </div>
        <div class="te-paper-wrap">
            <div class="te-paper" data-te-paper></div>
        </div>
    </section>

    <aside class="te-pane te-props" aria-label="Eigenschaften">
        <div class="te-pane__head">
            <h2><?= $icon('settings', 'app-icon app-icon--sm') ?> Eigenschaften</h2>
        </div>
        <div class="te-pane__body" data-te-props></div>
    </aside>
</main>

<footer class="statusbar">
    <span class="statusbar__item"><?= $icon('pulse', 'app-icon app-icon--sm') ?> <?= $e($appName) ?> <?= $e($appVersion) ?></span>
    <span class="statusbar__item" data-te-status-version>Grundlage: Fassung <?= $e($current['version_no']) ?></span>
    <span class="statusbar__item" data-te-status-blocks></span>
    <span class="statusbar__grow"></span>
    <span class="statusbar__item statusbar__note">Bereits erstellte Briefe behalten ihre Vorlage und können jederzeit unverändert reproduziert werden.</span>
</footer>

<dialog class="te-dialog" data-te-save-dialog aria-labelledby="te-save-title">
    <form method="dialog" class="te-dialog__body" data-te-save-form>
        <h2 id="te-save-title"><?= $icon('check', 'app-icon app-icon--sm') ?> Als neue Fassung speichern</h2>
        <p>Die Vorlage wird als neue, unveränderliche Fassung gespeichert. Neue Briefe verwenden ab sofort diese
            Fassung; bereits erstellte Briefe bleiben unverändert.</p>
        <div class="te-field">
            <label for="te-comment">Änderungsnotiz <small class="muted">(optional)</small></label>
            <textarea id="te-comment" rows="3" maxlength="500" data-te-comment placeholder="z. B. Grußformel angepasst"></textarea>
        </div>
        <div class="te-dialog__actions">
            <button type="submit" value="cancel" formnovalidate>Abbrechen</button>
            <button type="submit" value="save" class="primary">Speichern</button>
        </div>
    </form>
</dialog>

<dialog class="te-dialog te-dialog--wide" data-te-versions-dialog aria-labelledby="te-versions-title">
    <div class="te-dialog__body">
        <h2 id="te-versions-title"><?= $icon('clock', 'app-icon app-icon--sm') ?> Gespeicherte Fassungen</h2>
        <p class="muted">Eine Fassung kann in den Editor geladen und als neue Fassung gespeichert werden
            (Wiederherstellen). Gespeicherte Fassungen werden nie verändert.</p>
        <div class="table-scroll te-versions-scroll">
            <table class="table compact">
                <thead><tr><th>Fassung</th><th>Name</th><th>Gespeichert</th><th>Notiz</th><th class="num">Briefe</th><th></th></tr></thead>
                <tbody data-te-versions></tbody>
            </table>
        </div>
        <form method="dialog" class="te-dialog__actions">
            <button type="submit">Schließen</button>
        </form>
    </div>
</dialog>

<dialog class="te-dialog" data-te-help-dialog aria-labelledby="te-help-title">
    <div class="te-dialog__body">
        <h2 id="te-help-title"><?= $icon('help', 'app-icon app-icon--sm') ?> So funktioniert der Vorlageneditor</h2>
        <ol class="te-help">
            <li><strong>Art der Vorlage:</strong> Patient, Hausarzt und überweisender Arzt haben je eine eigene
                Vorlage. Die Auswahl oben links wechselt die Vorlage.</li>
            <li><strong>Bereich wählen:</strong> links in der Liste oder direkt in der Seitenvorschau anklicken.</li>
            <li><strong>Texte bearbeiten:</strong> rechts unter „Eigenschaften“. Platzhalter wie <code>{patient_name}</code>
                werden beim Erstellen des Briefes ersetzt – per Klick auf einen Platzhalter einfügen.</li>
            <li><strong>Baustein übernehmen:</strong> Rechtsklick auf einen Baustein öffnet „Übernehmen aus Vorlage“ –
                damit lässt sich der Baustein aus der Vorlage einer anderen Art übernehmen (feste Bausteine ersetzen
                den gleichartigen Baustein, freie Textbausteine werden am Ende angehängt).</li>
            <li><strong>Reihenfolge ändern:</strong> Bausteine des Brieftextes am Griff ziehen (links oder in der Vorschau)
                oder mit den Pfeil-Schaltflächen verschieben.</li>
            <li><strong>Ein- und ausblenden:</strong> Häkchen am Baustein. Eigene Textbausteine können ergänzt und gelöscht werden.</li>
            <li><strong>Prüfen:</strong> „PDF-Vorschau“ erzeugt den Brief mit Beispieldaten in einem neuen Tab.</li>
            <li><strong>Speichern:</strong> erzeugt eine neue Fassung. Bereits erstellte Briefe behalten ihre Fassung.</li>
        </ol>
        <p class="muted">Die Lage von Briefkopf, Anschriftfeld, Informationsblock, Falzmarken und Fußzeile ist durch
            DIN 5008 (Form B) festgelegt; dort sind Texte und Optionen bearbeitbar. Die Anrede eines Briefes steht nicht
            in der Vorlage, sondern in den Stammdaten des Patienten; der Baustein „Anrede“ enthält den Platzhalter
            <code>{salutation}</code>.</p>
        <form method="dialog" class="te-dialog__actions">
            <button type="submit" class="primary">Verstanden</button>
        </form>
    </div>
</dialog>

<form method="post" action="/system/letter-templates/preview" target="_blank" class="te-hidden" data-te-preview-form>
    <?= $csrf() ?>
    <input type="hidden" name="content" value="" data-te-preview-content>
    <input type="hidden" name="type" value="<?= $e($type) ?>" data-te-preview-type>
</form>

<template data-te-icons>
    <?php foreach (['check', 'close', 'warning', 'info', 'eye', 'edit', 'plus', 'settings', 'list', 'download'] as $iconName): ?>
        <span data-icon="<?= $e($iconName) ?>"><?= $icon($iconName, 'app-icon app-icon--sm') ?></span>
    <?php endforeach; ?>
</template>

<script type="application/json" id="template-editor-data"><?= $data ?></script>
</body>
</html>
