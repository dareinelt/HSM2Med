<?php
/**
 * Detailansicht eines Patientenausweises. Alle Angaben stammen ausschliesslich aus dem
 * gespeicherten Snapshot; Stammdatenaenderungen wirken sich nicht auf diese Ansicht aus.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, mixed> $card
 * @var array<string, mixed> $snapshot
 * @var array<string, mixed>|null $patient
 * @var list<array<string, mixed>> $previous
 */
$patient_ = is_array($snapshot['patient'] ?? null) ? $snapshot['patient'] : [];
$device = is_array($snapshot['device'] ?? null) ? $snapshot['device'] : [];
$leads = is_array($snapshot['leads'] ?? null) ? $snapshot['leads'] : [];
$emergency = is_array($snapshot['emergency_contact'] ?? null) ? $snapshot['emergency_contact'] : [];
$physician = is_array($snapshot['physician'] ?? null) ? $snapshot['physician'] : [];
$followUp = is_array($snapshot['follow_up'] ?? null) ? $snapshot['follow_up'] : [];
$source = is_array($snapshot['source'] ?? null) ? $snapshot['source'] : [];
$settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : [];
$history = is_array($snapshot['history'] ?? null) ? $snapshot['history'] : [];
$row = static fn (string $label, string $value): string => '<tr><th>'
    . $label . '</th><td>' . ($value === '' ? '<span class="muted">–</span>' : $value) . '</td></tr>';
$dobDisplay = (string) ($patient_['date_of_birth_display'] ?? '');
$dobRaw = (string) ($patient_['date_of_birth_raw'] ?? '');
if ($dobRaw !== '' && $dobRaw !== $dobDisplay) {
    $dobDisplay .= ' (' . $dobRaw . ')';
}
$address = trim(implode(' ', array_filter([
    (string) ($patient_['street'] ?? ''),
    trim((string) ($patient_['postal_code'] ?? '') . ' ' . (string) ($patient_['city'] ?? '')),
])));
$practice = trim((string) ($physician['practice'] ?? '') . ' '
    . trim((string) ($physician['postal_code'] ?? '') . ' ' . (string) ($physician['city'] ?? '')));
$mrtValue = trim((string) ($device['mrt_compatibility'] ?? ''));
$mrtNote = trim((string) ($device['mrt_compatibility_note'] ?? ''));
$mrtDisplay = $mrtValue === '' && $mrtNote === ''
    ? ''
    : trim($mrtValue . ($mrtNote === '' ? '' : ($mrtValue === '' ? $mrtNote : ' (' . $mrtNote . ')')));
?>
<div class="page-head">
    <div>
        <h1><?= $icon('cards', 'app-icon app-icon--lg') ?><span>Patientenausweis Nr. <?= $e($card['id']) ?></span></h1>
        <p class="lead">
            <?= $e($card['patient_name']) ?>,
            geboren <?= $e($view::dateTime($card['date_of_birth'], true)) ?> ·
            Fassung <?= $e($card['card_version']) ?> ·
            laufende Nummer <?= $e($card['sequence_no']) ?>
        </p>
    </div>
    <div class="actions">
        <a class="button primary" href="/patient-cards/<?= $e($card['id']) ?>/pdf" target="_blank" rel="noopener"><?= $icon('printer') ?> <span>PDF anzeigen</span></a>
        <a class="button" href="/patient-cards/<?= $e($card['id']) ?>/pdf?download=1"><?= $icon('download') ?> <span>PDF herunterladen</span></a>
        <a class="button" href="/patient-cards/patients/<?= $e($card['patient_id']) ?>"><?= $icon('patients') ?> <span>Patient</span></a>
    </div>
</div>

<section class="card">
    <h2><?= $icon('reports', 'app-icon app-icon--sm') ?> Dokument</h2>
    <table class="kv">
        <?= $row('Dateiname', $e($card['pdf_filename'])) ?>
        <?= $row('Erstellt am', $e($view::dateTime($card['created_at']))) ?>
        <?= $row('Größe', $e(number_format(((int) $card['pdf_size']) / 1024, 1, ',', '.')) . ' kB') ?>
        <?= $row('Prüfsumme (SHA-256)', '<code class="hash">' . $e($card['pdf_sha256']) . '</code>') ?>
        <?= $row('Quellbericht', '<a href="/reports/' . $e($card['report_id']) . '">Bericht Nr. ' . $e($card['report_id']) . '</a>'
            . (($source['filename'] ?? '') !== '' ? ' · ' . $e($source['filename']) : '')) ?>
        <?= $row('Stammdatenfassung', $e($card['settings_version_id'])) ?>
    </table>
    <p class="hint">Das PDF wird aus dem gespeicherten Datenstand dieses Ausweises erzeugt und ändert sich
        nicht, wenn Patienten- oder Stammdaten später angepasst werden.</p>
</section>

<section class="card">
    <h2><?= $icon('patients', 'app-icon app-icon--sm') ?> Patient</h2>
    <table class="kv">
        <?= $row('Name', $e((string) ($patient_['patient_name'] ?? ''))) ?>
        <?= $row('Geburtsdatum', $e($dobDisplay)) ?>
        <?= $row('Patienten-ID', $e((string) ($patient_['patient_identifier'] ?? ''))) ?>
        <?= $row('Anschrift', $e($address)) ?>
        <?= $row('Telefon', $e((string) ($patient_['phone'] ?? ''))) ?>
        <?= $row('Indikation', $e((string) ($patient_['indication'] ?? ''))) ?>
    </table>
</section>

<section class="card">
    <h2><?= $icon('user-plus', 'app-icon app-icon--sm') ?> Notfallkontakt und Hausarzt</h2>
    <table class="kv">
        <?= $row('Notfallkontakt', $e((string) ($emergency['name'] ?? ''))) ?>
        <?= $row('Telefon Notfallkontakt', $e((string) ($emergency['phone'] ?? ''))) ?>
        <?= $row('Hausarzt', $e((string) ($physician['name'] ?? ''))) ?>
        <?= $row('Praxis', $e($practice)) ?>
        <?= $row('Telefon Hausarzt', $e((string) ($physician['phone'] ?? ''))) ?>
    </table>
</section>

<section class="card">
    <h2><?= $icon('pulse', 'app-icon app-icon--sm') ?> Aggregat und Sonden</h2>
    <table class="kv">
        <?= $row('Hersteller', $e((string) ($device['manufacturer'] ?? ''))) ?>
        <?= $row('Modell', $e(trim((string) ($device['model_name'] ?? '') . ' ' . (string) ($device['model_number'] ?? '')))) ?>
        <?= $row('Seriennummer', $e((string) ($device['serial_number'] ?? ''))) ?>
        <?= $row('Implantation', $e((string) ($device['implant_date_display'] ?? ''))) ?>
        <?= $row('Implantationsort', $e((string) ($device['implant_location'] ?? ''))) ?>
        <?= $row('MRT-Tauglichkeit', $e($mrtDisplay)) ?>
        <?= $row('Modus / Grundfrequenz', $e(trim((string) ($device['mode'] ?? '') . ' ' . (string) ($device['base_rate'] ?? '')))) ?>
    </table>
    <?php if ($leads === []): ?>
        <p class="muted">Zu diesem Bericht sind keine Sonden erfasst.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Kammer</th><th>Hersteller</th><th>Modell</th><th>Seriennummer</th><th>Typ</th><th>Implantation</th></tr></thead>
                <tbody>
                <?php foreach ($leads as $lead): ?>
                    <tr>
                        <td><?= $e((string) ($lead['chamber_label'] ?? '')) ?></td>
                        <td><?= $e((string) ($lead['manufacturer'] ?? '')) ?></td>
                        <td><?= $e(trim((string) ($lead['model_label'] ?? '') . ' ' . (string) ($lead['model_number'] ?? ''))) ?></td>
                        <td><?= $e((string) ($lead['serial_number'] ?? '')) ?></td>
                        <td><?= $e((string) ($lead['lead_type'] ?? '')) ?></td>
                        <td><?= $e((string) ($lead['implant_date_display'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2><?= $icon('calendar', 'app-icon app-icon--sm') ?> Nachsorge</h2>
    <table class="kv">
        <?= $row('Nachsorgezentrum', $e((string) ($settings['center_name'] ?? ''))) ?>
        <?= $row('Adresse Zentrum', nl2br($e((string) ($settings['center_address'] ?? '')))) ?>
        <?= $row('Grundlage', $e((string) ($followUp['report_label'] ?? ''))) ?>
        <?= $row('Nächste Kontrolle', $e((string) ($followUp['next_control_display'] ?? ''))) ?>
        <?= $row('Kontrollierender Arzt', $e((string) ($followUp['control_physician'] ?? ''))) ?>
    </table>
</section>

<?php if ($history !== []): ?>
<section class="card">
    <h2><?= $icon('clock', 'app-icon app-icon--sm') ?> Frühere Untersuchungen</h2>
    <p class="hint">Seite 2 des Ausweises zeigt die Messwerte der aktuellen Untersuchung und der bis zu sechs
        letzten früheren Untersuchungen. Diese Liste ist die vollständige Historie zum Zeitpunkt der
        Ausweiserstellung.</p>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Datum</th><th>Bericht</th><th>Nachsorgearzt</th><th>Zentrum</th><th>Datei</th></tr></thead>
            <tbody>
            <?php foreach ($history as $entry): ?>
                <tr>
                    <td><?= $e((string) ($entry['date_display'] ?? '')) ?></td>
                    <td><a href="/reports/<?= $e($entry['report_id']) ?>">Nr. <?= $e($entry['report_id']) ?></a></td>
                    <td><?= $e((string) ($entry['physician'] ?? '')) ?></td>
                    <td><?= $e((string) ($entry['center'] ?? '')) ?></td>
                    <td><small class="muted"><?= $e((string) ($entry['filename'] ?? '')) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <h2><?= $icon('info', 'app-icon app-icon--sm') ?> Hinweistexte des Ausweises</h2>
    <table class="kv">
        <?= $row('Hinweise', nl2br($e((string) ($settings['notice_text'] ?? '')))) ?>
        <?= $row('Achtung Flugsicherheit (DE)', nl2br($e((string) ($settings['flight_notice_de'] ?? '')))) ?>
        <?= $row('Attention Airline Security (EN)', nl2br($e((string) ($settings['flight_notice_en'] ?? '')))) ?>
        <?= $row('Logo', $e((string) ($settings['logo_filename'] ?? '')) !== ''
            ? $e((string) $settings['logo_filename']) . ' (' . $e(substr((string) $settings['logo_sha256'], 0, 12)) . '…)'
            : '') ?>
    </table>
</section>

<?php if ($previous !== []): ?>
<section class="card">
    <h2><?= $icon('cards', 'app-icon app-icon--sm') ?> Frühere Ausweise dieses Patienten</h2>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Nr.</th><th>Erstellt</th><th>Fassung</th><th>Nachsorge</th><th>Datei</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($previous as $old): ?>
                <tr>
                    <td><a href="/patient-cards/<?= $e($old['id']) ?>"><?= $e($old['id']) ?></a></td>
                    <td><?= $e($view::dateTime($old['created_at'])) ?></td>
                    <td><?= $e($old['card_version']) ?></td>
                    <td><?= $e($view::dateTime($old['follow_up_date'], true)) ?></td>
                    <td><small class="muted"><?= $e($old['pdf_filename']) ?></small></td>
                    <td class="nowrap"><a href="/patient-cards/<?= $e($old['id']) ?>/pdf" target="_blank" rel="noopener">PDF</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<p><a href="/patient-cards/new">Weiteren Ausweis erstellen</a> · <a href="/patient-cards">Zur Übersicht</a></p>
