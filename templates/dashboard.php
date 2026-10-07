<?php
/**
 * @var Closure $e
 * @var App\Http\View $view
 * @var array<string, int> $stats
 * @var list<array<string, mixed>> $latestReports
 * @var list<array<string, mixed>> $latestImports
 */
?>
<h1>Dashboard</h1>
<div class="stats">
    <div class="stat"><span class="stat-value"><?= $e($stats['patients']) ?></span><span class="stat-label">Patienten</span></div>
    <div class="stat"><span class="stat-value"><?= $e($stats['devices']) ?></span><span class="stat-label">Geräte</span></div>
    <div class="stat"><span class="stat-value"><?= $e($stats['reports']) ?></span><span class="stat-label">Berichte</span></div>
    <div class="stat"><span class="stat-value"><?= $e($stats['imports']) ?></span><span class="stat-label">Importe<?= $stats['failed_imports'] > 0 ? ' (' . $e($stats['failed_imports']) . ' fehlgeschlagen)' : '' ?></span></div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Letzte Berichte</h2>
        <?php if ($latestReports === []): ?>
            <p class="muted">Noch keine Berichte vorhanden. <a href="/import">Jetzt eine Datei importieren.</a></p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Datum</th><th>Patient</th><th>Gerät</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($latestReports as $r): ?>
                    <tr>
                        <td><?= $e($r['session_timestamp'] !== null ? $view::dateTime($r['session_timestamp']) : ($r['session_timestamp_raw'] ?? $view::dateTime($r['created_at']))) ?></td>
                        <td><?= $e($r['patient_name_snapshot']) ?></td>
                        <td><?= $e(trim(($r['device_model_name_snapshot'] ?? '') . ' ' . ($r['device_serial_snapshot'] ?? ''))) ?></td>
                        <td><a href="/reports/<?= $e($r['id']) ?>">öffnen</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <section class="card">
        <h2>Letzte Importe</h2>
        <?php if ($latestImports === []): ?>
            <p class="muted">Noch keine Importe.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Importiert</th><th>Datei</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($latestImports as $i): ?>
                    <tr>
                        <td><?= $e($view::dateTime($i['imported_at'])) ?></td>
                        <td><a href="/imports/<?= $e($i['id']) ?>"><?= $e($i['filename']) ?></a></td>
                        <td><span class="badge status-<?= $e($i['status']) ?>"><?= $e(App\Report\PdfGenerator::statusLabel($i['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p><a class="button primary" href="/import">Neue Datei importieren</a></p>
    </section>
</div>
