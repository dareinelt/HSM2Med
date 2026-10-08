<?php
/**
 * Patientensicht: alle Ausweise und alle Nachsorgeuntersuchungen eines Patienten.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, mixed> $patient
 * @var list<array<string, mixed>> $cards
 * @var list<array<string, mixed>> $past
 * @var array<int, string> $physicians
 */
?>
<div class="page-head">
    <div>
        <h1><?= $icon('patients', 'app-icon app-icon--lg') ?><span><?= $e($patient['patient_name']) ?></span></h1>
        <p class="lead">
            geboren <?= $e($view::dateTime($patient['date_of_birth'], true)) ?>
            <?php if (($patient['patient_identifier'] ?? null) !== null && $patient['patient_identifier'] !== ''): ?>
                · Patienten-ID <?= $e($patient['patient_identifier']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <a class="button primary" href="/patient-cards/new"><?= $icon('card-plus') ?> <span>Patientenausweis erstellen</span></a>
        <a class="button" href="/patient-cards"><?= $icon('cards') ?> <span>Übersicht</span></a>
    </div>
</div>

<section class="card">
    <h2><?= $icon('cards', 'app-icon app-icon--sm') ?> Ausweise</h2>
    <?php if ($cards === []): ?>
        <p class="muted">Für diesen Patienten wurde noch kein Patientenausweis erstellt.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Nr.</th><th>Erstellt</th><th>Fassung</th><th>Nachsorge</th><th>Bericht</th><th>Datei</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($cards as $card): ?>
                    <tr>
                        <td><a href="/patient-cards/<?= $e($card['id']) ?>"><?= $e($card['id']) ?></a></td>
                        <td><?= $e($view::dateTime($card['created_at'])) ?></td>
                        <td><?= $e($card['card_version']) ?></td>
                        <td><?= $e($view::dateTime($card['follow_up_date'], true)) ?></td>
                        <td><a href="/reports/<?= $e($card['report_id']) ?>">Nr. <?= $e($card['report_id']) ?></a></td>
                        <td><small class="muted"><?= $e($card['pdf_filename']) ?></small></td>
                        <td class="nowrap">
                            <a href="/patient-cards/<?= $e($card['id']) ?>/pdf" target="_blank" rel="noopener">PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= $icon('calendar', 'app-icon app-icon--sm') ?> Nachsorgeuntersuchungen</h2>
    <?php if ($past === []): ?>
        <p class="muted">Es sind keine weiteren Untersuchungen mit diesem Patienten verknüpft.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Untersuchung</th><th>Bericht</th><th>Aggregat</th><th>Nachsorgearzt</th><th>Import</th><th>Datei</th></tr></thead>
                <tbody>
                <?php foreach ($past as $report): ?>
                    <tr>
                        <td><?= $e($view::dateTime($report['session_timestamp'] ?? $report['interrogation_timestamp'] ?? $report['created_at'], true)) ?></td>
                        <td><a href="/reports/<?= $e($report['id']) ?>">Nr. <?= $e($report['id']) ?></a></td>
                        <td><?= $e(trim((string) ($report['device_model_name_snapshot'] ?? '') . ' ' . (string) ($report['device_serial_snapshot'] ?? ''))) ?></td>
                        <td><?= $e($physicians[(int) $report['id']] ?? '') ?></td>
                        <td><?= $e($view::dateTime($report['imported_at'])) ?></td>
                        <td><small class="muted"><?= $e($report['filename']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">Die Berichte sind nach Untersuchungszeitpunkt absteigend sortiert. Seite 2 eines Ausweises
            zeigt die Messwerte der aktuellen Untersuchung und der bis zu sechs letzten früheren Untersuchungen.
            Die Auswahl erfolgt zum Zeitpunkt der Erstellung und ist im Ausweis unveränderlich festgehalten.</p>
    <?php endif; ?>
</section>
