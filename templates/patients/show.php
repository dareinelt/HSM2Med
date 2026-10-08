<?php
/**
 * Patientenakte: Stammdaten, versionierte Bausteine und verknuepfte Berichte.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, mixed> $patient
 * @var array<string, mixed>|null $master
 * @var array<string, array<string, mixed>> $records
 * @var list<App\Patient\PatientRecordType> $types
 * @var list<array<string, mixed>> $reports
 */
$id = (int) $patient['id'];
$masterRows = [
    'Straße und Hausnummer' => 'street',
    'Postleitzahl' => 'postal_code',
    'Ort' => 'city',
    'Telefon' => 'phone',
];
?>
<div class="page-head">
    <div>
        <h1><?= $icon('patients', 'app-icon app-icon--lg') ?><span><?= $e($patient['patient_name']) ?></span></h1>
        <p class="lead">
            Patient Nr. <?= $e($id) ?>
            · geboren am <?= $e($view::dateTime($patient['date_of_birth'], true)) ?>
            <?php if (($patient['patient_identifier'] ?? null) !== null && $patient['patient_identifier'] !== ''): ?>
                · Patienten-ID <?= $e($patient['patient_identifier']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <a class="button" href="/patients/<?= $e($id) ?>/edit"><?= $icon('edit') ?> <span>Stammdaten bearbeiten</span></a>
        <a class="button" href="/patient-cards/patients/<?= $e($id) ?>"><?= $icon('cards') ?> <span>Ausweise und Nachsorge</span></a>
        <a class="button" href="/letters/patients/<?= $e($id) ?>"><?= $icon('letters') ?> <span>Briefe</span></a>
        <a class="button" href="/patients"><?= $icon('back') ?> <span>Zur Übersicht</span></a>
    </div>
</div>

<div class="grid-2">
    <section class="card">
        <h2><?= $icon('info', 'app-icon app-icon--sm') ?> Stammdaten</h2>
        <table class="kv">
            <tr><th>Patienten-ID</th><td><?= $e($patient['patient_identifier'] ?? '') ?></td></tr>
            <?php foreach ($masterRows as $label => $field): ?>
                <tr><th><?= $e($label) ?></th><td><?= $e($master[$field] ?? '') ?></td></tr>
            <?php endforeach; ?>
            <tr><th>Angelegt</th><td><?= $e($view::dateTime($patient['created_at'])) ?></td></tr>
            <tr><th>Geändert</th><td><?= $e($view::dateTime($patient['updated_at'])) ?></td></tr>
        </table>
        <?php if (trim((string) ($master['indication'] ?? '')) !== ''): ?>
            <h3>Indikation / Anlass</h3>
            <p><?= nl2br($e($master['indication'])) ?></p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2><?= $icon('reports', 'app-icon app-icon--sm') ?> Berichte</h2>
        <?php if ($reports === []): ?>
            <p class="muted">Noch kein Bericht mit diesem Patienten verknüpft. Nach dem Import wird der Bericht
                automatisch zugeordnet.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Nr.</th><th>Untersuchung</th><th>Gerät</th><th>Datei</th></tr></thead>
                    <tbody>
                    <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><a href="/reports/<?= $e($r['id']) ?>"><?= $e($r['id']) ?></a></td>
                            <td><?= $e($view::dateTime($r['session_timestamp'] ?? $r['interrogation_timestamp'] ?? $r['created_at'])) ?></td>
                            <td><?= $e(trim(($r['device_model_name_snapshot'] ?? '') . ' ' . ($r['device_serial_snapshot'] ?? ''))) ?></td>
                            <td class="break"><?= $e($r['filename']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="card">
    <h2><?= $icon('list', 'app-icon app-icon--sm') ?> Bausteine der Akte</h2>
    <p class="muted">Jeder Baustein ist eine Kette unveränderlicher Fassungen. Speichern erzeugt eine neue
        Fassung; frühere Fassungen bleiben erhalten.</p>
    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr><th>Baustein</th><th>Stand</th><th>Umfang</th><th>Inhalt</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($types as $type): ?>
                <?php $record = $records[$type->value] ?? null; ?>
                <tr>
                    <td>
                        <a href="/patients/<?= $e($id) ?>/records/<?= $e($type->value) ?>"><?= $e($type->label()) ?></a>
                        <br><small class="muted"><?= $e($type->hint()) ?></small>
                    </td>
                    <?php if ($record === null || ($record['empty'] ?? true)): ?>
                        <td colspan="3" class="muted">Noch nicht erfasst.</td>
                    <?php else: ?>
                        <td>
                            <span class="badge"><?= $e(\App\Patient\PatientRecordService::versionLabel($record)) ?></span>
                        </td>
                        <td>
                            <?php $check = $record['device_check'] ?? null; ?>
                            <?php if (is_array($check)): ?>
                                <?= $e((string) $check['device_type_label']) ?>,
                                <?= $e((string) count($check['leads'] ?? [])) ?> Sonde(n),
                                <?= $e((string) $check['filled']) ?> Angabe(n)
                            <?php else: ?>
                                <?= $e(count($record['entries'] ?? [])) ?> Zeile(n) Medikation,
                                <?= $e(mb_strlen((string) ($record['text'] ?? ''))) ?> Zeichen Freitext
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($record['lines'] ?? []) !== []): ?>
                                <small class="muted"><?= nl2br($e(mb_strimwidth((string) $record['lines'][0], 0, 160, ' …'))) ?></small>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="nowrap">
                        <a href="/patients/<?= $e($id) ?>/records/<?= $e($type->value) ?>">
                            <?= $record === null || ($record['empty'] ?? true) ? 'erfassen' : 'bearbeiten' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
