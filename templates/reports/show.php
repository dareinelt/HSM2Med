<?php
/**
 * @var Closure $e
 * @var App\Http\View $view
 * @var App\Report\ReportData $data
 * @var list<array<string, mixed>> $related
 * @var bool $rawDefault
 */
$r = $data->report;
$i = $data->import;
$id = $data->id();
$cell = static function (mixed $value) use ($e): string {
    if ($value === null) {
        return '<span class="muted">nicht in den Quelldaten enthalten</span>';
    }
    return $value === '' ? '<span class="muted">(leer)</span>' : $e($value);
};
?>
<div class="page-head">
    <div>
        <h1>Bericht Nr. <?= $e($id) ?></h1>
        <p class="lead">Herzschrittmacher – Auslesebericht · automatisch erzeugter Datenbericht · keine medizinische Bewertung</p>
    </div>
    <div class="actions">
        <a class="button primary" href="/reports/<?= $e($id) ?>/pdf?raw=0" target="_blank" rel="noopener">PDF anzeigen</a>
        <a class="button" href="/reports/<?= $e($id) ?>/pdf?raw=0&amp;download=1">PDF herunterladen</a>
        <a class="button" href="/reports/<?= $e($id) ?>/pdf?raw=1&amp;download=1">PDF mit Rohdatenanhang</a>
    </div>
</div>
<p class="muted">Die PDF-Erzeugung verwendet ausschließlich den in der Datenbank gespeicherten Bericht-Snapshot.
    <?= $rawDefault ? 'Standardmäßig wird der Rohdatenanhang mit ausgegeben.' : '' ?></p>

<div class="grid-2">
    <section class="card">
        <h2>Bericht</h2>
        <table class="kv">
            <tr><th>Berichtsversion</th><td><?= $e($r['report_version']) ?> (Parser <?= $e($r['parser_version']) ?>, Mapping <?= $e($r['mapping_version']) ?>)</td></tr>
            <tr><th>Importdatei</th><td class="break"><a href="/imports/<?= $e($i['id']) ?>"><?= $e($i['filename']) ?></a></td></tr>
            <tr><th>SHA-256</th><td><code class="hash"><?= $e($i['file_hash']) ?></code></td></tr>
            <tr><th>Importiert am</th><td><?= $e($view::dateTime($i['imported_at'])) ?></td></tr>
            <tr><th>Importstatus</th><td><span class="badge status-<?= $e($i['status']) ?>"><?= $e(App\Report\PdfGenerator::statusLabel($i['status'])) ?></span></td></tr>
            <tr><th>Datensätze</th><td><?= $e($i['record_count']) ?> erkannt, <?= $e($r['parameter_count']) ?> Parameter, <?= $e($i['error_count']) ?> fehlerhaft, <?= $e($i['warning_count']) ?> Warnungen</td></tr>
        </table>
    </section>
    <section class="card">
        <?php foreach (['patient' => 'Patient', 'device' => 'Gerät'] as $key => $title): ?>
            <h2><?= $e($title) ?></h2>
            <table class="kv">
                <?php foreach ($data->summarySection($key) as $row): ?>
                    <tr><th><?= $e($row['label']) ?></th>
                        <td><?= $cell($row['value']) ?><?= $row['value'] !== null && $row['value'] !== '' && $row['unit'] !== '' ? ' ' . $e($row['unit']) : '' ?>
                            <?php if ($row['original'] !== null): ?><small class="muted">(Original: <?= $e($row['original']) ?>)</small><?php endif; ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>
    </section>
</div>

<section class="card">
    <h2>Sonden</h2>
    <?php if ($data->leads() === []): ?>
        <p class="muted">Keine Sondenangaben in den Quelldaten.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Kammer</th><th>Hersteller</th><th>Modell</th><th>Seriennummer</th><th>Typ</th><th>Implantation</th></tr></thead>
            <tbody>
            <?php foreach ($data->leads() as $lead): ?>
                <tr>
                    <td><?= $e($lead['chamber_label']) ?></td>
                    <td><?= $cell($lead['manufacturer']) ?></td>
                    <td><?= $cell($lead['model_number']) ?><?php if (!empty($lead['model_label'])): ?><br><small class="muted"><?= $e($lead['model_label']) ?></small><?php endif; ?></td>
                    <td><?= $cell($lead['serial_number']) ?></td>
                    <td><?= $cell($lead['lead_type']) ?></td>
                    <td><?= $cell($lead['implant_date_display'] ?? $lead['implant_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<nav class="card toc" aria-label="Kategorien">
    <strong>Kategorien:</strong>
    <?php foreach ($data->categories() as $category): ?>
        <a href="#cat-<?= $e($category['key']) ?>"><?= $e($category['label']) ?> (<?= $e(count($category['parameters'])) ?>)</a>
    <?php endforeach; ?>
    <a href="#issues">Importprotokoll</a>
    <a href="#raw">Originaldaten</a>
</nav>

<?php foreach ($data->categories() as $category): ?>
    <section class="card" id="cat-<?= $e($category['key']) ?>">
        <h2><?= $e($category['label']) ?> <small class="muted"><?= $e(count($category['parameters'])) ?> Parameter</small></h2>
        <table class="table params">
            <thead><tr><th class="col-id">ID</th><th>Parameter</th><th class="num">Wert</th><th>Einheit</th></tr></thead>
            <tbody>
            <?php foreach ($category['parameters'] as $p): ?>
                <tr>
                    <td class="muted col-id"><?= $e($p['parameter_id']) ?></td>
                    <td><?= $e($p['display_name']) ?><?php if ($p['display_name'] !== $p['parameter_name']): ?><br><small class="muted">Original: <?= $e($p['parameter_name']) ?></small><?php endif; ?></td>
                    <td class="num"><?= $p['value'] === null ? '<span class="muted">nicht vorhanden</span>' : ($p['value'] === '' ? '<span class="muted">(leer)</span>' : $view::raw($p['value'])) ?></td>
                    <td><?= $view::raw($p['unit']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endforeach; ?>

<section class="card" id="issues">
    <h2>Importprotokoll <small class="muted"><?= $e(count($data->issues)) ?> Einträge</small></h2>
    <?php if ($data->issues === []): ?>
        <p class="muted">Keine Fehler oder Warnungen protokolliert.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Pos.</th><th>Art</th><th>Code</th><th>Meldung</th><th>Rohdaten</th></tr></thead>
            <tbody>
            <?php foreach ($data->issues as $issue): ?>
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

<section class="card" id="raw">
    <details>
        <summary>Originaldaten / Importdaten (alle Datensätze in Originalreihenfolge)</summary>
        <p class="muted">Format: ID | Parameter | Wert | Einheit. Feldtrennzeichen 0x1C wird als ␜ dargestellt.</p>
        <pre class="raw-block"><?php foreach ($data->recordsInOriginalOrder() as $record):
            if ($record['valid']) {
                $p = $record['parameter'];
                echo $view::raw(sprintf('%s | %s | %s | %s', $p['parameter_id'], $p['parameter_name'], (string) $p['value'], (string) $p['unit'])), "\n";
            } else {
                echo '<span class="text-error">[FEHLERHAFT] ', $view::raw($record['raw']), "</span>\n";
            }
        endforeach; ?></pre>
    </details>
</section>

<?php if ($related !== []): ?>
    <section class="card">
        <h2>Weitere Berichte dieses Geräts</h2>
        <ul>
            <?php foreach ($related as $rel): ?>
                <li><a href="/reports/<?= $e($rel['id']) ?>">Bericht Nr. <?= $e($rel['id']) ?></a> –
                    <?= $e($rel['session_timestamp'] !== null ? $view::dateTime($rel['session_timestamp']) : $view::dateTime($rel['created_at'])) ?> (<?= $e($rel['filename']) ?>)</li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
