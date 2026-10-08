<?php
/**
 * Anzeige eines erstellten Briefes: Kopfdaten, Inhalt des Brieftextes, Anhang und Nachweise.
 *
 * Der Brief ist unveraenderlich; gezeigt wird der gespeicherte Snapshot. Das PDF wird ausschliesslich
 * aus diesem Snapshot erzeugt.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, mixed> $letter
 * @var array<string, mixed> $snapshot
 * @var list<array<string, mixed>> $previous
 */
$id = (int) $letter['id'];
$patientId = (int) $letter['patient_id'];
$document = (array) ($snapshot['document'] ?? []);
$master = (array) ($snapshot['master'] ?? []);
$patient = (array) ($snapshot['patient'] ?? []);
$report = $snapshot['report'] ?? null;
$appendix = (array) ($snapshot['appendix'] ?? []);
$mrt = (array) ($snapshot['mrt'] ?? []);
$sections = (array) ($appendix['sections'] ?? []);
$textParts = [
    'Anamnese' => (array) ($snapshot['anamnesis'] ?? []),
    'Vormedikation' => (array) ($snapshot['premedication'] ?? []),
    'Epikrise' => (array) ($snapshot['epicrisis'] ?? []),
];
?>
<div class="page-head">
    <div>
        <h1><?= $icon('letters', 'app-icon app-icon--lg') ?><span>Brief Nr. <?= $e($id) ?></span></h1>
        <p class="lead">
            <?= $e($letter['patient_name']) ?>
            · geboren am <?= $e($view::dateTime($letter['date_of_birth'], true)) ?>
            · Briefnummer <?= $e($letter['sequence_no']) ?> für diesen Patienten
            · Fassung <?= $e($letter['letter_version']) ?>
        </p>
    </div>
    <div class="actions">
        <a class="button primary" href="/letters/<?= $e($id) ?>/pdf" target="_blank" rel="noopener"><?= $icon('printer') ?> <span>PDF anzeigen</span></a>
        <a class="button" href="/letters/<?= $e($id) ?>/pdf?download=1"><?= $icon('download') ?> <span>PDF herunterladen</span></a>
        <a class="button" href="/letters/patients/<?= $e($patientId) ?>"><?= $icon('letters') ?> <span>Briefe des Patienten</span></a>
        <a class="button" href="/letters"><?= $icon('back') ?> <span>Zur Übersicht</span></a>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2><?= $icon('letters', 'app-icon app-icon--sm') ?> Dokument</h2>
        <table class="kv">
            <tr><th>Dokumentnummer</th><td><?= $e($document['document_number'] ?? '') ?></td></tr>
            <tr><th>Briefdatum</th><td><?= $e($view::dateTime($document['letter_date'] ?? '', true)) ?></td></tr>
            <tr><th>Erstellt</th><td><?= $e($view::dateTime($letter['created_at'])) ?></td></tr>
            <tr><th>Bericht (Befundteil)</th><td>
                <?php if ($report === null): ?>
                    <span class="muted">ohne Bericht – der Befundteil entfällt</span>
                <?php else: ?>
                    <a href="/reports/<?= $e($letter['report_id']) ?>">Nr. <?= $e($letter['report_id']) ?></a>
                    <br><small class="muted"><?= $e($report['meta'] ?? '') ?></small>
                <?php endif; ?>
            </td></tr>
            <tr><th>Anhang</th><td><?= $sections === []
                ? '<span class="muted">kein Anhang</span>'
                : $e(count($sections)) . ' Abschnitt(e)' . (($appendix['device_type_label'] ?? '') !== ''
                    ? ', ' . $e($appendix['device_type_label']) : '') ?></td></tr>
            <tr><th>MRT-Tauglichkeit</th><td><?= ($mrt['available'] ?? false) === true
                ? $e($mrt['value']) . (trim((string) ($mrt['note'] ?? '')) !== '' ? ' · ' . $e($mrt['note']) : '')
                    . '<br><small class="muted">' . $e($mrt['source_label'] ?? '') . '</small>'
                : '<span class="muted">keine Angabe im Patientenausweis</span>' ?></td></tr>
        </table>
        <h3>Stammdatenfassung</h3>
        <table class="kv">
            <tr><th>Fassung</th><td><?= $e($master['settings_version'] ?? '') ?></td></tr>
            <tr><th>Nachsorgezentrum</th><td><?= $e($master['center_name'] ?? '') ?></td></tr>
            <tr><th>Anschrift</th><td><?= nl2br($e($master['center_address'] ?? '')) ?></td></tr>
            <tr><th>Logo</th><td><?= ($master['logo_sha256'] ?? '') === '' ? 'kein Logo hinterlegt' : $e($master['logo_filename'] ?? '') ?></td></tr>
        </table>
        <h3>Patientendaten (eingefroren)</h3>
        <table class="kv">
            <tr><th>Name</th><td><?= $e($patient['patient_name'] ?? '') ?></td></tr>
            <tr><th>Geburtsdatum</th><td><?= $e($view::dateTime($patient['date_of_birth'] ?? '', true)) ?></td></tr>
            <tr><th>Patienten-ID</th><td><?= $e($patient['patient_identifier'] ?? '') ?></td></tr>
            <tr><th>Anschrift</th><td><?= $e(trim(implode(' · ', array_filter([
                $patient['address']['street'] ?? '',
                trim(($patient['address']['postal_code'] ?? '') . ' ' . ($patient['address']['city'] ?? '')),
                ($patient['address']['phone'] ?? '') === '' ? '' : 'Telefon: ' . $patient['address']['phone'],
            ])))) ?></td></tr>
        </table>
    </section>

    <section class="card">
        <h2><?= $icon('printer', 'app-icon app-icon--sm') ?> PDF</h2>
        <table class="kv">
            <tr><th>Dateiname</th><td class="break"><?= $e($letter['pdf_filename']) ?></td></tr>
            <tr><th>Größe</th><td><?= $e(number_format(((int) $letter['pdf_size']) / 1024, 0, ',', '.')) ?> kB</td></tr>
            <tr><th>SHA-256</th><td class="hash"><?= $e($letter['pdf_sha256']) ?></td></tr>
            <tr><th>Snapshot-Fassung</th><td>Brief <?= $e($snapshot['letter_version'] ?? '') ?>,
                Vorlage <?= $e($snapshot['letter_template_version'] ?? '') ?></td></tr>
            <tr><th>Eingefrorene Bausteine</th><td>
                <?php foreach ((array) ($snapshot['source']['record_versions'] ?? []) as $type => $info): ?>
                    <?= $e($type) ?>:
                    <?= ($info['version'] ?? null) === null
                        ? 'nicht erfasst'
                        : 'Fassung ' . $e($info['version']) ?><br>
                <?php endforeach; ?>
            </td></tr>
        </table>
        <h3>Hinweise</h3>
        <p class="muted">Der Brief ist ausschließlich aus dem gespeicherten Snapshot reproduzierbar. Spätere
            Änderungen an der Akte, am Bericht oder an den Ausweis-Stammdaten verändern diesen Brief nicht.</p>
        <?php if ($previous !== []): ?>
            <h3>Weitere Briefe dieses Patienten</h3>
            <ul class="chips">
                <?php foreach ($previous as $other): ?>
                    <?php if ((int) $other['id'] === $id) { continue; } ?>
                    <li><a href="/letters/<?= $e($other['id']) ?>">Nr. <?= $e($other['id']) ?>
                        vom <?= $e($view::dateTime($other['created_at'], true)) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <h2><?= $icon('letters', 'app-icon app-icon--sm') ?> Brieftext</h2>
    <?php foreach ($textParts as $label => $part): ?>
        <h3><?= $e($label) ?></h3>
        <?php if (($part['present'] ?? false) !== true): ?>
            <p class="muted">nicht angegeben</p>
        <?php else: ?>
            <p class="muted"><span class="badge">Fassung <?= $e($part['version'] ?? '') ?></span>
                <?= ($part['author_name'] ?? '') === '' ? '' : ' · erfasst von ' . $e($part['author_name']) ?></p>
            <?php if (trim((string) ($part['text'] ?? '')) !== ''): ?>
                <p><?= nl2br($e($part['text'])) ?></p>
            <?php endif; ?>
            <?php if (($part['entries'] ?? []) !== []): ?>
                <div class="table-scroll">
                    <table class="table compact">
                        <tbody>
                        <?php foreach ($part['entries'] as $entry): ?>
                            <tr><td><?= $e(implode(' · ', array_filter(array_map('strval', $entry)))) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>

    <h3>Befund „Schrittmacher-/ICD-Abfrage"</h3>
    <?php if (!is_array($report)): ?>
        <p class="muted">Kein Bericht zugeordnet – der Befundteil entfällt.</p>
    <?php else: ?>
        <p class="muted"><?= $e($report['meta'] ?? '') ?></p>
        <?php if (($report['rows'] ?? []) !== []): ?>
            <table class="kv">
                <?php foreach ($report['rows'] as $row): ?>
                    <tr><th><?= $e($row['label']) ?></th><td><?= $e($row['value']) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php foreach (['leads' => 'Elektroden', 'groups' => 'Parameter'] as $key => $label): ?>
            <?php foreach ((array) ($report[$key] ?? []) as $group): ?>
                <details>
                    <summary><?= $e($label) ?>: <?= $e($group['label']) ?> (<?= $e(count($group['rows'])) ?>)</summary>
                    <table class="kv">
                        <?php foreach ($group['rows'] as $row): ?>
                            <tr><th><?= $e($row['label']) ?></th><td><?= $e($row['value']) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </details>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= $icon('pulse', 'app-icon app-icon--sm') ?> Anhang: vollständige Schrittmacher-/ICD-Abfrage</h2>
    <?php if ($sections === []): ?>
        <p class="muted">Kein Anhang – zum Zeitpunkt der Erstellung lag keine Abfrage vor.</p>
    <?php else: ?>
        <p class="muted"><?= $e(count($sections)) ?> Abschnitt(e), im PDF mehrseitig mit wiederholter Kopfzeile.</p>
        <?php foreach ($sections as $section): ?>
            <details>
                <summary><?= $e($section['label']) ?> (<?= $e(count($section['rows'])) ?>)</summary>
                <table class="kv">
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr><th><?= $e($row['label']) ?></th><td><?= $e($row['value']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </details>
        <?php endforeach; ?>
        <?php $notes = trim((string) ($appendix['notes'] ?? '')); ?>
        <?php if ($notes !== ''): ?>
            <h3>Hinweise zur Abfrage</h3>
            <ul><?php foreach (explode("\n", $notes) as $note): ?>
                <?php if (trim($note) !== ''): ?><li><?= $e(trim($note)) ?></li><?php endif; ?>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    <?php endif; ?>
</section>
