<?php
/**
 * Assistent zur Erstellung eines Patientenausweises (sechs Schritte).
 *
 * Alle Schritte liegen in einem Formular; JavaScript schaltet die Schritte um (CSP-konform, keine
 * Inline-Skripte). Ohne JavaScript sind alle Schritte untereinander sichtbar. Die Prüfung erfolgt
 * immer serverseitig.
 *
 * @var Closure $e
 * @var App\Http\View $view
 * @var App\Report\ReportData $report
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<array{field: string, label: string, stored: string, new: string}> $conflicts
 * @var list<array<string, mixed>> $candidates
 * @var array<string, string> $choices
 * @var array{patient_id: string, confirm_patient: bool, confirm_merge: bool} $selection
 * @var array<string, mixed>|null $masterData
 * @var array<string, mixed>|null $existingCard
 * @var array<string, string> $labels
 * @var int $step
 * @var string|null $message
 * @var array<string, mixed> $settings
 * @var array<int, string> $steps
 */
$id = $report->id();
$patientRow = [];
foreach ($report->summarySection('patient') as $row) {
    $patientRow[(string) $row['label']] = (string) ($row['value'] ?? '');
}
$deviceRow = [];
foreach ($report->summarySection('device') as $row) {
    $deviceRow[(string) $row['label']] = (string) ($row['value'] ?? '');
}
$val = static fn (string $key): string => $values[$key] ?? '';
$err = static function (string $key) use ($errors, $e): string {
    return isset($errors[$key]) ? '<p class="field-error" role="alert">' . $e($errors[$key]) . '</p>' : '';
};
$text = static function (string $name, string $label, string $hint = '', int $max = 255) use ($val, $err, $e): string {
    return '<div class="field">'
        . '<label for="f-' . $e($name) . '">' . $e($label) . '</label>'
        . '<input type="text" id="f-' . $e($name) . '" name="' . $e($name) . '" maxlength="' . $e($max) . '" value="' . $e($val($name)) . '">'
        . ($hint === '' ? '' : '<small class="muted">' . $e($hint) . '</small>')
        . $err($name) . '</div>';
};
$tel = static function (string $name, string $label, string $hint = '') use ($val, $err, $e): string {
    return '<div class="field"><label for="f-' . $e($name) . '">' . $e($label) . '</label>'
        . '<input type="tel" id="f-' . $e($name) . '" name="' . $e($name) . '" maxlength="64" value="' . $e($val($name)) . '">'
        . ($hint === '' ? '' : '<small class="muted">' . $e($hint) . '</small>')
        . $err($name) . '</div>';
};
$centerConfigured = trim((string) ($settings['center_name'] ?? '')) !== ''
    || trim((string) ($settings['notice_text'] ?? '')) !== '';
$date = static fn (string $key): string => \App\PatientCard\PatientCardInput::formatDate($val($key));
?>
<div class="page-head">
    <div>
        <h1>Patientenausweis erstellen</h1>
        <p class="lead">Bericht Nr. <?= $e($id) ?> · Schritt 1: Patient identifizieren – die Identität ist
            Nachname + Vorname + Geburtsdatum.</p>
    </div>
    <div class="actions">
        <a class="button" href="/reports/<?= $e($id) ?>">Bericht ansehen</a>
        <a class="button" href="/patient-cards/new">Anderen Bericht wählen</a>
    </div>
</div>

<?php if ($message !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($message) ?></div>
<?php endif; ?>

<?php if ($existingCard !== null): ?>
    <div class="alert alert-info" role="status">
        Zu diesem Bericht existiert bereits Patientenausweis Nr. <?= $e($existingCard['id']) ?>
        (Fassung <?= $e($existingCard['card_version']) ?>). Ein neuer Ausweis wird als weitere Fassung gespeichert;
        der bestehende Ausweis bleibt unverändert.
        <a href="/patient-cards/<?= $e($existingCard['id']) ?>">Bestehenden Ausweis öffnen</a>
    </div>
<?php endif; ?>

<?php if (!$centerConfigured): ?>
    <div class="alert alert-warning" role="status">
        Die globalen Stammdaten (Logo, Nachsorgezentrum, Hinweise, Flugsicherheitshinweise) sind noch nicht gepflegt.
        Der Ausweis wird ohne diese Angaben erzeugt. <a href="/patient-cards/settings">Stammdaten pflegen</a>
    </div>
<?php endif; ?>

<section class="card">
    <h2>Quellbericht</h2>
    <div class="grid-2">
        <table class="kv">
            <tr><th>Bericht</th><td>Nr. <?= $e($id) ?> vom
                <?= $e($view::dateTime($report->report['session_timestamp'] ?? $report->report['created_at'], true)) ?></td></tr>
            <tr><th>Importdatei</th><td class="break"><?= $e($report->import['filename'] ?? '') ?></td></tr>
            <tr><th>Patient (Export)</th><td><?= $e($report->report['patient_name_snapshot'] ?? '') ?></td></tr>
            <tr><th>Patient-ID (Export)</th><td><?= $e($report->report['patient_identifier_snapshot'] ?? '') ?></td></tr>
            <tr><th>Geburtsdatum (Export)</th><td><?= $e($report->report['patient_dob_snapshot'] ?? '') ?></td></tr>
        </table>
        <table class="kv">
            <tr><th>Gerät</th><td><?= $e($deviceRow['Hersteller'] ?? '') ?> <?= $e($deviceRow['Modell'] ?? '') ?></td></tr>
            <tr><th>Modellnummer</th><td><?= $e($deviceRow['Modellnummer'] ?? '') ?></td></tr>
            <tr><th>Seriennummer</th><td><?= $e($deviceRow['Seriennummer'] ?? '') ?></td></tr>
            <tr><th>Sonden</th><td><?= $e(count($report->leads())) ?> Sonde(n) im Bericht</td></tr>
        </table>
    </div>
    <p class="muted">Patient-ID und Seriennummer sind Zusatzangaben. Die Zuordnung zum Patienten erfolgt
        ausschließlich über Nachname, Vorname und Geburtsdatum.</p>
</section>

<form class="card wizard-form" method="post" action="/patient-cards/reports/<?= $e($id) ?>" data-wizard="off" data-step="<?= $e($step) ?>">
    <?= $csrf() ?>
    <input type="hidden" name="step" value="6">

    <ol class="wizard-steps" aria-label="Assistentenschritte">
        <?php foreach ($steps as $number => $label): ?>
            <li>
                <button type="button" class="wizard-step-link" data-wizard-goto="<?= $e($number) ?>"
                        <?= $number === $step ? 'aria-current="step"' : '' ?>>
                    <span class="wizard-step-no"><?= $e($number) ?></span><?= $e($label) ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ol>

    <section class="wizard-step" data-step="1">
        <h2>1. Patient identifizieren</h2>
        <p class="muted">Nachname, Vorname und Geburtsdatum bestimmen den Patienten. Vorbelegt sind die Werte aus
            dem Bericht; sie können korrigiert werden.</p>
        <div class="field-row">
            <?= $text('last_name', 'Nachname', 'Pflichtfeld', 255) ?>
            <?= $text('first_name', 'Vorname', 'Pflichtfeld', 255) ?>
            <div class="field">
                <label for="f-date_of_birth">Geburtsdatum (TT.MM.JJJJ)</label>
                <input type="text" id="f-date_of_birth" name="date_of_birth" maxlength="32" value="<?= $e($date('date_of_birth')) ?>" required>
                <small class="muted">Pflichtfeld – Identitätsmerkmal</small>
                <?= $err('date_of_birth') ?>
            </div>
        </div>
        <?= $err('patient_id') ?>
        <?php if (count($candidates) > 1): ?>
            <fieldset class="choice-box">
                <legend>Es existieren mehrere Patienten mit diesen Angaben. Bitte den richtigen Patienten auswählen.</legend>
                <?php $selected = $selection['patient_id']; ?>
                <?php foreach ($candidates as $candidate): ?>
                    <label class="check">
                        <input type="radio" name="patient_id" value="<?= $e($candidate['id']) ?>"
                            <?= $selected === (string) $candidate['id'] ? 'checked' : '' ?>>
                        Patient Nr. <?= $e($candidate['id']) ?>
                        <?php if (($candidate['patient_identifier'] ?? null) !== null): ?>
                            (Patient-ID <?= $e($candidate['patient_identifier']) ?>)
                        <?php endif; ?>
                        – <?= $e($candidate['patient_name']) ?>
                    </label>
                <?php endforeach; ?>
                <p class="muted">Ohne Auswahl wird kein Ausweis erzeugt.</p>
            </fieldset>
        <?php elseif (count($candidates) === 1): ?>
            <p class="muted">Vorhandener Patient erkannt: Nr. <?= $e($candidates[0]['id']) ?> –
                <?= $e($candidates[0]['patient_name']) ?>. Die Angaben werden ergänzt, nicht ersetzt.</p>
        <?php else: ?>
            <p class="muted">Kein vorhandener Patient mit diesen Angaben – beim Erzeugen wird ein neuer Patient angelegt.</p>
        <?php endif; ?>
    </section>

    <section class="wizard-step" data-step="2">
        <h2>2. Patientendaten ergänzen</h2>
        <p class="muted">Diese Angaben sind im Merlin-Export nicht enthalten und werden ausschließlich hier gepflegt.</p>
        <div class="field-row">
            <?= $text('street', 'Straße und Hausnummer') ?>
            <?= $text('postal_code', 'Postleitzahl', '', 32) ?>
            <?= $text('city', 'Wohnort') ?>
            <?= $tel('phone', 'Telefon') ?>
            <?= $text('device_implant_location', 'Implantationsort des Geräts', 'z. B. links pectoral') ?>
        </div>
        <div class="field">
            <label for="f-indication">Indikation</label>
            <textarea id="f-indication" name="indication" rows="3" maxlength="2000"><?= $e($val('indication')) ?></textarea>
            <?php if ($val('indication') !== ''): ?>
                <small class="muted">Aus dem Bericht übernommen (Parameter „Indikation"), sofern nicht geändert.</small>
            <?php endif; ?>
            <?= $err('indication') ?>
        </div>
    </section>

    <section class="wizard-step" data-step="3">
        <h2>3. Notfallkontakt</h2>
        <p class="muted">Erscheint auf dem Ausweis unter „Notfallkontakt".</p>
        <div class="field-row">
            <?= $text('emergency_contact_name', 'Name des Notfallkontakts') ?>
            <?= $tel('emergency_contact_phone', 'Telefon des Notfallkontakts') ?>
        </div>
    </section>

    <section class="wizard-step" data-step="4">
        <h2>4. Hausarzt</h2>
        <p class="muted">Hausärztliche Anschrift für Rückfragen.</p>
        <div class="field-row">
            <?= $text('physician_name', 'Name') ?>
            <?= $text('physician_practice', 'Praxis') ?>
            <?= $text('physician_postal_code', 'Postleitzahl', '', 32) ?>
            <?= $text('physician_city', 'Ort') ?>
            <?= $tel('physician_phone', 'Telefon') ?>
        </div>
    </section>

    <section class="wizard-step" data-step="5">
        <h2>5. Nachsorge und Kontrolle</h2>
        <p class="muted">Das Nachsorgezentrum stammt aus den globalen Stammdaten.</p>
        <div class="field-row">
            <?= $text('control_physician', 'Arzt zur Kontrolle', 'Vorbelegt aus dem Bericht (Parameter „Nachsorgearzt"), sofern vorhanden') ?>
            <div class="field">
                <label for="f-next_control_date">Nächste Kontrolle (TT.MM.JJJJ)</label>
                <input type="text" id="f-next_control_date" name="next_control_date" maxlength="32" value="<?= $e($date('next_control_date')) ?>">
                <?= $err('next_control_date') ?>
            </div>
        </div>
        <table class="kv">
            <tr><th>Nachsorgezentrum</th><td><?= trim((string) ($settings['center_name'] ?? '')) !== ''
                ? $e($settings['center_name'])
                : '<span class="muted">nicht hinterlegt</span>' ?></td></tr>
        </table>
    </section>

    <section class="wizard-step" data-step="6">
        <h2>6. Zusammenfassung und Bestätigung</h2>

        <?php if ($conflicts !== []): ?>
            <div class="alert alert-warning" role="alert">
                <strong>Abweichungen zu bereits bestätigten Angaben.</strong>
                Bitte je Feld entscheiden, welcher Wert übernommen wird. Es wird nichts automatisch überschrieben.
            </div>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Angabe</th><th>Neuer Wert (Eingabe)</th><th>Bisheriger Wert (bestätigt)</th></tr></thead>
                    <tbody>
                    <?php foreach ($conflicts as $conflict): $field = $conflict['field']; ?>
                        <tr>
                            <td><?= $e($conflict['label']) ?></td>
                            <td>
                                <label class="check">
                                    <input type="radio" name="conflict[<?= $e($field) ?>]" value="new"
                                        <?= ($choices[$field] ?? '') === 'new' ? 'checked' : '' ?>>
                                    <?= $conflict['new'] === '' ? '<span class="muted">(leer)</span>' : $e($conflict['new']) ?>
                                </label>
                            </td>
                            <td>
                                <label class="check">
                                    <input type="radio" name="conflict[<?= $e($field) ?>]" value="stored"
                                        <?= ($choices[$field] ?? '') === 'stored' ? 'checked' : '' ?>>
                                    <?= $e($conflict['stored']) ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $err('conflicts') ?>
        <?php elseif ($masterData !== null): ?>
            <p class="muted">Keine Abweichungen zu den bereits bestätigten Angaben dieses Patienten.</p>
        <?php endif; ?>

        <table class="kv">
            <tr><th>Patient</th><td><?= $e($val('last_name')) ?>, <?= $e($val('first_name')) ?> ·
                geboren am <?= $e($date('date_of_birth')) ?></td></tr>
            <tr><th>Anschrift</th><td><?= $e($val('street')) ?> <?= $e($val('postal_code')) ?> <?= $e($val('city')) ?></td></tr>
            <tr><th>Telefon</th><td><?= $e($val('phone')) ?></td></tr>
            <tr><th>Notfallkontakt</th><td><?= $e($val('emergency_contact_name')) ?> <?= $e($val('emergency_contact_phone')) ?></td></tr>
            <tr><th>Hausarzt</th><td><?= $e($val('physician_name')) ?> <?= $e($val('physician_practice')) ?>
                <?= $e($val('physician_postal_code')) ?> <?= $e($val('physician_city')) ?></td></tr>
            <tr><th>Nächste Kontrolle</th><td><?= $e($date('next_control_date')) ?></td></tr>
            <tr><th>Arzt zur Kontrolle</th><td><?= $e($val('control_physician')) ?></td></tr>
            <tr><th>Implantationsort</th><td><?= $e($val('device_implant_location')) ?></td></tr>
        </table>

        <fieldset class="choice-box">
            <legend>Bestätigungen (beide erforderlich)</legend>
            <label class="check">
                <input type="checkbox" name="confirm_patient" value="1" <?= $selection['confirm_patient'] ? 'checked' : '' ?>>
                Ja, dies ist der richtige Patient.
            </label>
            <label class="check">
                <input type="checkbox" name="confirm_merge" value="1" <?= $selection['confirm_merge'] ? 'checked' : '' ?>>
                Ich bestätige, dass die angezeigten Daten zum richtigen Patienten gehören und zusammengeführt werden dürfen.
            </label>
            <?= $err('confirm_patient') ?>
            <?= $err('confirm_merge') ?>
        </fieldset>

        <p class="muted">Der Ausweis wird als unveränderliches PDF gespeichert (zwei Seiten DIN A4).
            Spätere Änderungen an Stammdaten erzeugen keine Änderung an bereits erstellten Ausweisen.</p>
    </section>

    <div class="wizard-controls">
        <button type="button" class="button" data-wizard-prev>Zurück</button>
        <button type="button" class="button primary" data-wizard-next>Weiter</button>
        <button type="submit" class="button primary" data-once data-wizard-submit>Patientenausweis erzeugen</button>
    </div>
</form>
