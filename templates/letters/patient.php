<?php
/**
 * Alle Briefe eines Patienten (Einstieg aus der Patientenakte).
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, mixed> $patient
 * @var list<array<string, mixed>> $rows
 */
$patientId = (int) $patient['id'];
?>
<div class="page-head">
    <div>
        <h1><?= $icon('letters', 'app-icon app-icon--lg') ?><span>Briefe – <?= $e($patient['patient_name']) ?></span></h1>
        <p class="lead">
            geboren am <?= $e($view::dateTime($patient['date_of_birth'], true)) ?>
            · <?= $e(count($rows)) ?> Brief<?= count($rows) === 1 ? '' : 'e' ?> gespeichert
        </p>
    </div>
    <div class="actions">
        <a class="button primary" href="/letters/new?patient=<?= $e($patientId) ?>"><?= $icon('mail-new') ?> <span>Neuen Brief erstellen</span></a>
        <a class="button" href="/patients/<?= $e($patientId) ?>"><?= $icon('patients') ?> <span>Zur Patientenakte</span></a>
        <a class="button" href="/letters"><?= $icon('letters') ?> <span>Alle Briefe</span></a>
    </div>
</div>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="muted">Für diesen Patienten wurde noch kein Brief erstellt.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr>
                    <th>Nr.</th>
                    <th>Briefnummer</th>
                    <th>Erstellt</th>
                    <th>Briefdatum</th>
                    <th>Bericht</th>
                    <th>Fassung</th>
                    <th>Anhang</th>
                    <th>PDF</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="/letters/<?= $e($row['id']) ?>"><?= $e($row['id']) ?></a></td>
                        <td><?= $e($row['sequence_no']) ?></td>
                        <td><?= $e($view::dateTime($row['created_at'])) ?></td>
                        <td><?= $e($view::dateTime($row['letter_date'], true)) ?></td>
                        <td>
                            <?php if ($row['report_id'] === null): ?>
                                <span class="muted">ohne Bericht</span>
                            <?php else: ?>
                                <a href="/reports/<?= $e($row['report_id']) ?>">Nr. <?= $e($row['report_id']) ?></a>
                                <?php if (($row['session_timestamp'] ?? null) !== null): ?>
                                    <br><small class="muted"><?= $e($view::dateTime($row['session_timestamp'])) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($row['letter_version']) ?></td>
                        <td><?= $e($row['appendix_sections']) ?></td>
                        <td>
                            <a href="/letters/<?= $e($row['id']) ?>/pdf" target="_blank" rel="noopener">anzeigen</a>
                            · <a href="/letters/<?= $e($row['id']) ?>/pdf?download=1">laden</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
