<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, int> $stats
 * @var list<array<string, mixed>> $latestReports
 * @var list<array<string, mixed>> $latestImports
 */
?>
<div class="page-head">
    <div>
        <h1><?= $icon('dashboard', 'app-icon app-icon--lg') ?><span>Dashboard</span></h1>
        <p class="lead">Kennzahlen, letzte Vorgänge und die häufigsten Einstiege.</p>
    </div>
    <div class="actions">
        <a class="button primary" href="/import"><?= $icon('import') ?><span>Neue Datei importieren</span></a>
    </div>
</div>

<div class="tiles">
    <a class="tile" href="/import">
        <?= $icon('import') ?>
        <div><strong>Datei importieren</strong><span>Auslesedatei des Programmiergeräts einlesen</span></div>
    </a>
    <a class="tile" href="/reports">
        <?= $icon('reports') ?>
        <div><strong>Berichte ansehen</strong><span>Alle importierten Auslesungen durchsuchen</span></div>
    </a>
    <a class="tile" href="/patient-cards/new">
        <?= $icon('card-plus') ?>
        <div><strong>Patientenausweis erstellen</strong><span>Ausweis mit MRT-Kompatibilität und Nachsorgeplan</span></div>
    </a>
    <a class="tile" href="/letters/new">
        <?= $icon('mail-new') ?>
        <div><strong>Arztbrief erstellen</strong><span>Brief aus Anamnese, Befunden und Auslesedaten</span></div>
    </a>
    <a class="tile" href="/patients/new">
        <?= $icon('user-plus') ?>
        <div><strong>Patient anlegen</strong><span>Neue Akte mit Stammdaten erfassen</span></div>
    </a>
</div>

<div class="stats">
    <div class="stat"><span class="stat-value"><?= $e($stats['patients']) ?></span><span class="stat-label"><?= $icon('patients', 'app-icon app-icon--sm') ?> Patienten</span></div>
    <div class="stat"><span class="stat-value"><?= $e($stats['devices']) ?></span><span class="stat-label"><?= $icon('pulse', 'app-icon app-icon--sm') ?> Geräte</span></div>
    <div class="stat"><span class="stat-value"><?= $e($stats['reports']) ?></span><span class="stat-label"><?= $icon('reports', 'app-icon app-icon--sm') ?> Berichte</span></div>
    <div class="stat"><span class="stat-value"><?= $e($stats['imports']) ?></span><span class="stat-label"><?= $icon('import', 'app-icon app-icon--sm') ?> Importe<?= $stats['failed_imports'] > 0 ? ' (' . $e($stats['failed_imports']) . ' fehlgeschlagen)' : '' ?></span></div>
</div>

<div class="grid-2">
    <section class="card">
        <h2><?= $icon('reports', 'app-icon app-icon--sm') ?> Letzte Berichte</h2>
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
        <h2><?= $icon('import', 'app-icon app-icon--sm') ?> Letzte Importe</h2>
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
        <p><a class="button primary" href="/import"><?= $icon('import') ?> Neue Datei importieren</a></p>
    </section>
</div>
