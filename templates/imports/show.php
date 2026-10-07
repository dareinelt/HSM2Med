<?php
/**
 * @var Closure $e
 * @var App\Http\View $view
 * @var array<string, mixed> $import
 * @var list<array<string, mixed>> $issues
 */
?>
<h1>Import Nr. <?= $e($import['id']) ?></h1>
<section class="card">
    <table class="kv">
        <tr><th>Dateiname</th><td class="break"><?= $e($import['filename']) ?></td></tr>
        <tr><th>Dateigröße</th><td><?= $e(App\Security\UploadValidator::formatBytes((int) $import['file_size'])) ?> (<?= $e($import['file_size']) ?> Byte)</td></tr>
        <tr><th>SHA-256</th><td><code class="hash"><?= $e($import['file_hash']) ?></code></td></tr>
        <tr><th>Kodierung</th><td><?= $e($import['encoding']) ?></td></tr>
        <tr><th>Importiert am</th><td><?= $e($view::dateTime($import['imported_at'])) ?></td></tr>
        <tr><th>Parser-Version</th><td><?= $e($import['parser_version']) ?></td></tr>
        <tr><th>Datensätze</th><td><?= $e($import['record_count']) ?> erkannt, <?= $e($import['valid_record_count']) ?> gültig</td></tr>
        <tr><th>Fehler / Warnungen</th><td><?= $e($import['error_count']) ?> / <?= $e($import['warning_count']) ?></td></tr>
        <tr><th>Status</th><td><span class="badge status-<?= $e($import['status']) ?>"><?= $e(App\Report\PdfGenerator::statusLabel($import['status'])) ?></span></td></tr>
        <?php if ($import['failure_reason'] !== null): ?>
            <tr><th>Grund</th><td class="text-error"><?= $e($import['failure_reason']) ?></td></tr>
        <?php endif; ?>
        <tr><th>Archivierte Originaldatei</th><td><?= $import['archive_filename'] !== null ? '<code>' . $e($import['archive_filename']) . '</code>' : '<span class="muted">nicht archiviert</span>' ?></td></tr>
        <tr><th>Bericht</th><td><?php if ($import['report_id'] !== null): ?><a href="/reports/<?= $e($import['report_id']) ?>">Bericht Nr. <?= $e($import['report_id']) ?> öffnen</a><?php else: ?><span class="muted">kein Bericht gespeichert</span><?php endif; ?></td></tr>
    </table>
</section>

<section class="card">
    <h2>Fehler und Warnungen (<?= $e(count($issues)) ?>)</h2>
    <?php if ($issues === []): ?>
        <p class="muted">Keine Einträge.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Pos.</th><th>Art</th><th>Code</th><th>Meldung</th><th>Rohdaten</th></tr></thead>
            <tbody>
            <?php foreach ($issues as $issue): ?>
                <tr class="<?= $issue['severity'] === 'error' ? 'row-error' : 'row-warning' ?>">
                    <td><?= $e($issue['record_position'] ?? 'Datei') ?></td>
                    <td><?= $issue['severity'] === 'error' ? 'Fehler' : 'Warnung' ?></td>
                    <td><code><?= $e($issue['error_code']) ?></code></td>
                    <td><?= $e($issue['message']) ?></td>
                    <td><code class="raw"><?= $view::raw($issue['raw_record']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
