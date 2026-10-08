<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, string> $filters
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 */
$query = static fn (array $extra): string => http_build_query(array_filter($extra + $filters, static fn ($v): bool => $v !== '' && $v !== null));
$hasFilter = array_filter($filters, static fn (string $v): bool => $v !== '') !== [];
?>
<div class="page-head">
    <div>
        <h1><?= $icon('reports', 'app-icon app-icon--lg') ?><span>Berichte</span></h1>
        <p class="lead">Alle importierten Merlin-Auslesungen durchsuchen und öffnen.</p>
    </div>
    <div class="actions">
        <a class="button primary" href="/import"><?= $icon('import') ?><span>Neue Datei importieren</span></a>
        <a class="button" href="/imports"><?= $icon('log') ?><span>Importprotokoll</span></a>
    </div>
</div>
<section class="card">
    <h2><?= $icon('search', 'app-icon app-icon--sm') ?> Suche und Filter</h2>
    <form method="get" action="/reports" class="filters">
        <div class="field wide"><label for="q">Suche (alle Felder)</label><input type="search" id="q" name="q" value="<?= $e($filters['q']) ?>" maxlength="200"></div>
        <div class="field"><label for="patient">Patient</label><input id="patient" name="patient" value="<?= $e($filters['patient']) ?>" maxlength="200"></div>
        <div class="field"><label for="patient_id">Patient-ID</label><input id="patient_id" name="patient_id" value="<?= $e($filters['patient_id']) ?>" maxlength="200"></div>
        <div class="field"><label for="serial">Geräte-Seriennummer</label><input id="serial" name="serial" value="<?= $e($filters['serial']) ?>" maxlength="200"></div>
        <div class="field"><label for="model">Modell</label><input id="model" name="model" value="<?= $e($filters['model']) ?>" maxlength="200"></div>
        <div class="field"><label for="filename">Importdatei</label><input id="filename" name="filename" value="<?= $e($filters['filename']) ?>" maxlength="200"></div>
        <div class="field"><label for="date_from">Datum von</label><input type="date" id="date_from" name="date_from" value="<?= $e($filters['date_from']) ?>"></div>
        <div class="field"><label for="date_to">Datum bis</label><input type="date" id="date_to" name="date_to" value="<?= $e($filters['date_to']) ?>"></div>
        <div class="field buttons"><button type="submit" class="primary"><?= $icon('search') ?> <span data-label>Suchen</span></button><?php if ($hasFilter): ?> <a class="button" href="/reports"><?= $icon('undo') ?> <span>Zurücksetzen</span></a><?php endif; ?></div>
    </form>
</section>

<section class="card">
    <h2><?= $icon('list', 'app-icon app-icon--sm') ?> Suchergebnis</h2>
    <p class="muted"><?= $e($total) ?> Bericht(e) gefunden. Sortierung: neueste zuerst (Sitzungsdatum, sonst Importdatum).</p>
    <?php if ($rows === []): ?>
        <p>Keine Berichte gefunden.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="table">
            <thead>
            <tr><th>Nr.</th><th>Datum</th><th>Patient</th><th>Gerät</th><th>Modell</th><th>Seriennummer</th><th>Importdatei</th><th>Importstatus</th><th>Version</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $e($r['id']) ?></td>
                    <td><?= $e($r['session_timestamp'] !== null ? $view::dateTime($r['session_timestamp']) : ($r['interrogation_timestamp'] !== null ? $view::dateTime($r['interrogation_timestamp']) : $view::dateTime($r['created_at']) . ' (Import)')) ?></td>
                    <td><?= $e($r['patient_name_snapshot']) ?><?php if ($r['patient_identifier_snapshot'] !== null): ?><br><small class="muted">ID <?= $e($r['patient_identifier_snapshot']) ?></small><?php endif; ?></td>
                    <td><?= $e($r['device_manufacturer_snapshot'] ?? '') ?> <?= $e($r['device_model_name_snapshot']) ?></td>
                    <td><?= $e($r['device_model_number_snapshot']) ?></td>
                    <td><?= $e($r['device_serial_snapshot']) ?></td>
                    <td class="break"><?= $e($r['filename']) ?></td>
                    <td><span class="badge status-<?= $e($r['status']) ?>"><?= $e(App\Report\PdfGenerator::statusLabel($r['status'])) ?></span></td>
                    <td><?= $e($r['report_version']) ?></td>
                    <td class="nowrap"><a href="/reports/<?= $e($r['id']) ?>">öffnen</a> · <a href="/reports/<?= $e($r['id']) ?>/pdf">PDF</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten">
                <?php if ($page > 1): ?><a href="/reports?<?= $e($query(['page' => (string) ($page - 1)])) ?>">« zurück</a><?php endif; ?>
                <span>Seite <?= $e($page) ?> von <?= $e($pages) ?></span>
                <?php if ($page < $pages): ?><a href="/reports?<?= $e($query(['page' => (string) ($page + 1)])) ?>">weiter »</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
