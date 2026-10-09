<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var Closure $csrf
 * @var App\Http\View $view
 * @var string $token
 * @var App\Import\ImportAnalysis $analysis
 * @var array<string, mixed> $snapshot
 */
$result = $analysis->parseResult;
$valid = $analysis->validation->isValid();
$sections = [];
foreach ($snapshot['sections'] as $section) {
    $sections[$section['key']] = $section;
}
$cell = static function (?string $value) use ($e): string {
    if ($value === null) {
        return '<span class="muted">nicht in den Quelldaten enthalten</span>';
    }
    return $value === '' ? '<span class="muted">(leer)</span>' : $e($value);
};
?>
<div class="page-head">
    <div>
        <h1><?= $icon('eye', 'app-icon app-icon--lg') ?><span>Importübersicht</span></h1>
        <p class="lead">Bitte prüfen Sie die erkannten Daten. Es wurde noch nichts gespeichert.</p>
    </div>
</div>

<?php if (!$valid): ?>
    <div class="alert alert-error" role="alert">
        <strong>Import nicht möglich:</strong>
        <ul><?php foreach ($analysis->validation->blockingErrors as $message): ?><li><?= $e($message) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($analysis->isDuplicate()): ?>
    <div class="alert alert-warning" role="alert">
        <strong>Diese Datei (gleicher SHA-256-Hash) wurde bereits importiert:</strong>
        <ul>
            <?php foreach ($analysis->previousImports as $previous): ?>
                <li>Import Nr. <?= $e($previous['id']) ?> vom <?= $e($view::dateTime($previous['imported_at'])) ?>
                    <?php if ($previous['report_id'] !== null): ?> – <a href="/reports/<?= $e($previous['report_id']) ?>">Bericht Nr. <?= $e($previous['report_id']) ?> öffnen</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        Ein erneuter Import erzeugt einen weiteren, eigenständigen Bericht und muss ausdrücklich bestätigt werden.
    </div>
<?php endif; ?>

<div class="grid-2">
    <section class="card">
        <h2><?= $icon('info', 'app-icon app-icon--sm') ?> Datei</h2>
        <table class="kv">
            <tr><th>Dateiname</th><td><?= $e($analysis->filename) ?></td></tr>
            <tr><th>Dateigröße</th><td><?= $e(App\Security\UploadValidator::formatBytes($analysis->fileSize)) ?> (<?= $e($analysis->fileSize) ?> Byte)</td></tr>
            <tr><th>SHA-256</th><td><code class="hash"><?= $e($analysis->fileHash) ?></code></td></tr>
            <tr><th>Kodierung</th><td><?= $e($result->encoding) ?></td></tr>
            <tr><th>Erkannte Datensätze</th><td><?= $e($result->recordCount) ?></td></tr>
            <tr><th>Gültige Datensätze</th><td><?= $e($result->validRecordCount()) ?></td></tr>
            <tr><th>Fehler</th><td class="<?= $analysis->errorCount() > 0 ? 'text-error' : '' ?>"><?= $e($analysis->errorCount()) ?></td></tr>
            <tr><th>Warnungen</th><td class="<?= $analysis->warningCount() > 0 ? 'text-warning' : '' ?>"><?= $e($analysis->warningCount()) ?></td></tr>
            <tr><th>Parser</th><td><?= $e($analysis->parserName . ' ' . $analysis->parserVersion) ?></td></tr>
        </table>
    </section>
    <section class="card">
        <h2><?= $icon('patients', 'app-icon app-icon--sm') ?> Patient</h2>
        <table class="kv">
            <?php foreach ($sections['patient']['rows'] as $row): ?>
                <tr><th><?= $e($row['label']) ?></th><td><?= $cell($row['value']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <h2><?= $icon('pulse', 'app-icon app-icon--sm') ?> Gerät</h2>
        <table class="kv">
            <?php foreach ($sections['device']['rows'] as $row): ?>
                <tr><th><?= $e($row['label'] === 'Sitzungszeitpunkt' ? 'Auslesedatum (Sitzung)' : $row['label']) ?></th>
                    <td><?= $cell($row['value']) ?><?= $row['value'] !== null && $row['unit'] !== '' ? ' ' . $e($row['unit']) : '' ?></td></tr>
            <?php endforeach; ?>
        </table>
    </section>
</div>

<section class="card">
    <h2><?= $icon('list', 'app-icon app-icon--sm') ?> Sonden</h2>
    <?php if ($snapshot['leads'] === []): ?>
        <p class="muted">Keine Sondenangaben in der Datei.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Kammer</th><th>Hersteller</th><th>Modell</th><th>Seriennummer</th><th>Implantation</th></tr></thead>
            <tbody>
            <?php foreach ($snapshot['leads'] as $lead): ?>
                <tr>
                    <td><?= $e($lead['chamber_label']) ?></td>
                    <td><?= $cell($lead['manufacturer']) ?></td>
                    <td><?= $cell($lead['model_number']) ?></td>
                    <td><?= $cell($lead['serial_number']) ?></td>
                    <td><?= $cell($lead['implant_date_display']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= $icon('settings', 'app-icon app-icon--sm') ?> Parameter je Kategorie</h2>
    <ul class="chips">
        <?php foreach ($analysis->categoryCounts() as $label => $count): ?>
            <li><?= $e($label) ?> <strong><?= $e($count) ?></strong></li>
        <?php endforeach; ?>
    </ul>
</section>

<?php $issues = $analysis->issues(); ?>
<section class="card">
    <h2><?= $icon('warning', 'app-icon app-icon--sm') ?> Fehler und Warnungen (<?= $e(count($issues)) ?>)</h2>
    <?php if ($issues === []): ?>
        <p class="muted">Keine Auffälligkeiten beim Einlesen.</p>
    <?php else: ?>
        <p class="muted">Fehlerhafte Datensätze werden nicht als Parameter übernommen, aber im Importprotokoll gespeichert.</p>
        <table class="table">
            <thead><tr><th>Pos.</th><th>Art</th><th>Code</th><th>Meldung</th><th>Rohdaten</th></tr></thead>
            <tbody>
            <?php foreach ($issues as $issue): ?>
                <tr class="<?= $issue->isError() ? 'row-error' : 'row-warning' ?>">
                    <td><?= $e($issue->position ?? 'Datei') ?></td>
                    <td><?= $issue->isError() ? 'Fehler' : 'Warnung' ?></td>
                    <td><code><?= $e($issue->code) ?></code></td>
                    <td><?= $e($issue->message) ?></td>
                    <td><code class="raw"><?= $view::raw($issue->rawRecord) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="card">
    <details>
        <summary>Alle erkannten Datensätze anzeigen (<?= $e(count($result->records)) ?>)</summary>
        <table class="table compact">
            <thead><tr><th>Pos.</th><th>ID</th><th>Parameter</th><th class="num">Wert</th><th>Einheit</th><th>Kategorie</th></tr></thead>
            <tbody>
            <?php foreach ($result->records as $record): ?>
                <tr>
                    <td><?= $e($record->position) ?></td>
                    <td><?= $e($record->parameterId) ?></td>
                    <td><?= $e($record->name) ?></td>
                    <td class="num"><?= $record->value === '' ? '<span class="muted">(leer)</span>' : $view::raw($record->value) ?></td>
                    <td><?= $view::raw($record->unit) ?></td>
                    <td><?= $e($analysis->assignments[$record->position]->label ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</section>

<section class="card actions">
    <?php if ($valid): ?>
        <form method="post" action="/import/<?= $e($token) ?>/commit" class="inline">
            <?= $csrf() ?>
            <?php if ($analysis->isDuplicate()): ?>
                <label class="checkbox"><input type="checkbox" name="allow_duplicate" value="1" required> Ich bestätige den erneuten Import dieser bereits importierten Datei.</label>
            <?php endif; ?>
            <button type="submit" class="primary" data-once>Import endgültig speichern</button>
        </form>
    <?php endif; ?>
    <form method="post" action="/import/<?= $e($token) ?>/cancel" class="inline">
        <?= $csrf() ?>
        <button type="submit">Verwerfen</button>
    </form>
</section>
