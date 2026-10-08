<?php
/**
 * Brief erstellen – Schritte 2 bis 6: Bericht zuordnen, Bausteine pruefen, Empfaenger waehlen,
 * Zusammenfassung, bestaetigen und erzeugen. Je ausgewaehltem Empfaenger entsteht ein Brief.
 *
 * Die Berichtzuordnung erfolgt ueber Links (serverseitig, ohne JavaScript). Alle Schritte liegen
 * in einem Formular; JavaScript schaltet die Schritte um (CSP-konform, keine Inline-Skripte).
 * Ohne JavaScript sind alle Schritte untereinander sichtbar. Geprueft wird immer serverseitig.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, mixed> $patient
 * @var App\Report\ReportData|null $report
 * @var list<array<string, mixed>> $reports
 * @var array<string, array<string, mixed>> $records
 * @var array<string, mixed> $mrt
 * @var array<string, mixed> $appendix
 * @var list<string> $warnings
 * @var int $nextSequence
 * @var array<string, string> $errors
 * @var string|null $message
 * @var array{patient_id: int, report_id: ?int, confirm_data: bool, confirm_letter: bool, recipients: list<string>} $selection
 * @var array<string, array<string, mixed>> $recipients
 * @var int $startStep
 * @var array<int, string> $steps
 */
$patientId = (int) $patient['id'];
$selectedReportId = $selection['report_id'];
$textTypes = \App\Letter\LetterService::TEXT_TYPES;
$err = static function (string $key) use ($errors, $e): string {
    return isset($errors[$key]) ? '<p class="field-error" role="alert">' . $e($errors[$key]) . '</p>' : '';
};
$reportRow = [];
if ($report !== null) {
    foreach ($report->summarySection('patient') as $row) {
        $reportRow[(string) $row['label']] = (string) ($row['value'] ?? '');
    }
}
$mrtLabel = (string) ($mrt['value'] ?? '');
$appendixSections = (array) ($appendix['sections'] ?? []);
$reportDate = $report === null ? null : ($report->report['session_timestamp']
    ?? $report->report['interrogation_timestamp']
    ?? $report->report['created_at']
    ?? null);
$reportDateLabel = $reportDate === null ? '' : $view::dateTime((string) $reportDate);
$reportParameters = $report === null ? 0 : count($report->parameters);
$selectedRecipients = array_values(array_filter(
    $selection['recipients'],
    static fn (string $type): bool => ($recipients[$type]['available'] ?? false) === true,
));
?>
<div class="page-head">
    <div>
        <h1><?= $icon('mail-new', 'app-icon app-icon--lg') ?><span>Brief erstellen</span></h1>
        <p class="lead">Patient Nr. <?= $e($patientId) ?> · <?= $e($patient['patient_name']) ?>
            · geboren am <?= $e($view::dateTime($patient['date_of_birth'], true)) ?></p>
    </div>
    <div class="actions">
        <a class="button" href="/patients/<?= $e($patientId) ?>"><?= $icon('patients') ?> <span>Akte</span></a>
        <a class="button" href="/letters/new"><?= $icon('refresh') ?> <span>Anderen Patienten wählen</span></a>
    </div>
</div>

<?php if ($message !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($message) ?></div>
<?php endif; ?>

<?php if ($warnings !== []): ?>
    <div class="alert alert-warning" role="status">
        <strong>Hinweise zu diesem Brief</strong>
        <ul><?php foreach ($warnings as $warning): ?><li><?= $e($warning) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" action="/letters" class="wizard-form" data-wizard data-step="<?= $e($startStep) ?>" novalidate>
    <?= $csrf() ?>
    <input type="hidden" name="patient_id" value="<?= $e($patientId) ?>">
    <input type="hidden" name="report_id" value="<?= $e($selectedReportId ?? '') ?>">

    <ol class="wizard-steps" aria-label="Schritte">
        <?php // Schritt 1 (Patientenauswahl) ist eine eigene Seite und daher hier kein Formularschritt. ?>
        <?php foreach ($steps as $no => $label): ?>
            <?php if ($no === 1): ?>
                <?php continue; ?>
            <?php endif; ?>
            <li><a class="wizard-step-link" href="#schritt-<?= $e($no) ?>" data-wizard-goto="<?= $e($no) ?>">
                <span class="wizard-step-no"><?= $e($no) ?></span> <?= $e($label) ?></a></li>
        <?php endforeach; ?>
    </ol>

    <section class="card wizard-step" data-step="2" id="schritt-2">
        <h2><?= $icon('reports', 'app-icon app-icon--sm') ?> 2 · Bericht zuordnen (optional)</h2>
        <p class="muted">Der Bericht liefert den Baustein „Berichte" – Geräte-, Sonden- und Messwerte des Befundteils,
            der als Anhang unter der Grußformel auf einer neuen Seite steht. Ohne Bericht entfällt dieser Baustein;
            der Brief enthält dann Anamnese, Vormedikation, Befund, Epikrise und den Anhang.</p>
        <?= $err('report_id') ?>
        <?php if ($reports === []): ?>
            <p class="muted">Für diesen Patienten ist kein Bericht importiert.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Nr.</th><th>Untersuchung</th><th>Gerät</th><th>Datei</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($reports as $r): ?>
                        <?php $isSelected = $selectedReportId !== null && (int) $selectedReportId === (int) $r['id']; ?>
                        <tr>
                            <td><?= $e($r['id']) ?></td>
                            <td><?= $e($view::dateTime($r['session_timestamp'] ?? $r['interrogation_timestamp'] ?? $r['created_at'])) ?></td>
                            <td><?= $e(trim(($r['device_model_name_snapshot'] ?? '') . ' ' . ($r['device_serial_snapshot'] ?? ''))) ?></td>
                            <td class="break"><?= $e($r['filename']) ?></td>
                            <td class="nowrap">
                                <?php if ($isSelected): ?>
                                    <span class="badge">ausgewählt</span>
                                <?php else: ?>
                                    <a href="/letters/new?patient=<?= $e($patientId) ?>&amp;report=<?= $e($r['id']) ?>">diesen Bericht verwenden</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p>
            <?php if ($selectedReportId === null): ?>
                <span class="badge">Kein Bericht ausgewählt – der Baustein „Berichte" entfällt.</span>
            <?php else: ?>
                <a href="/letters/new?patient=<?= $e($patientId) ?>">Bericht abwählen (Brief ohne „Berichte")</a>
            <?php endif; ?>
        </p>
        <?php if ($report !== null): ?>
            <h3>Angaben aus dem ausgewählten Bericht</h3>
            <table class="kv">
                <?php foreach ($reportRow as $label => $value): ?>
                    <tr><th><?= $e($label) ?></th><td><?= $e($value) ?></td></tr>
                <?php endforeach; ?>
                <tr><th>Berichtsdatum</th><td><?= $e($reportDateLabel) ?></td></tr>
                <tr><th>Parameter</th><td><?= $e($reportParameters) ?></td></tr>
            </table>
        <?php endif; ?>
    </section>

    <section class="card wizard-step" data-step="3" id="schritt-3">
        <h2><?= $icon('list', 'app-icon app-icon--sm') ?> 3 · Bausteine prüfen</h2>
        <p class="muted">Gezeigt wird der aktuelle Stand der Akte. Beim Erzeugen friert der Brief genau diese
            Fassungen ein; spätere Änderungen an der Akte verändern den Brief nicht.</p>
        <?php foreach ($textTypes as $type): ?>
            <?php $record = $records[$type->value] ?? null; ?>
            <h3><?= $e($type->label()) ?></h3>
            <?php if ($record === null || ($record['empty'] ?? true)): ?>
                <p class="field-error">Noch nicht erfasst – im Brief erscheint „nicht angegeben".
                    <a href="/patients/<?= $e($patientId) ?>/records/<?= $e($type->value) ?>">Jetzt erfassen</a></p>
            <?php else: ?>
                <p><span class="badge"><?= $e(\App\Patient\PatientRecordService::versionLabel($record)) ?></span></p>
                <?php if (($record['lines'] ?? []) !== []): ?>
                    <div class="table-scroll">
                        <table class="table compact">
                            <tbody>
                            <?php foreach ($record['lines'] as $line): ?>
                                <tr><td><?= $e($line) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <h3>Anhang: vollständige Abfrage</h3>
        <?php $deviceCheck = $records['device_check'] ?? null; ?>
        <?php if ($deviceCheck === null || ($deviceCheck['empty'] ?? true)): ?>
            <p class="field-error">Es liegt keine Schrittmacher-/ICD-Abfrage vor – der Brief erhält keinen Anhang.
                <a href="/patients/<?= $e($patientId) ?>/records/device_check">Abfrage erfassen</a></p>
        <?php else: ?>
            <p>
                <span class="badge"><?= $e(\App\Patient\PatientRecordService::versionLabel($deviceCheck)) ?></span>
                <?= $e((string) ($deviceCheck['device_check']['device_type_label'] ?? '')) ?> ·
                <?= $e((string) count($deviceCheck['device_check']['leads'] ?? [])) ?> Sonde(n) ·
                <?= $e((string) ($deviceCheck['device_check']['filled'] ?? 0)) ?> Angabe(n) ·
                <?= $e(count($appendixSections)) ?> Abschnitt(e)
            </p>
            <?php foreach ($appendixSections as $section): ?>
                <details>
                    <summary><?= $e($section['label']) ?> (<?= $e(count($section['rows'])) ?>)</summary>
                    <table class="kv">
                        <?php foreach ($section['rows'] as $row): ?>
                            <tr><th><?= $e($row['label']) ?></th><td><?= $e($row['value']) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>

        <h3>MRT-Tauglichkeit</h3>
        <p>
            <?php if (($mrt['available'] ?? false) === true): ?>
                <span class="badge"><?= $e($mrtLabel) ?></span>
                <?php if (trim((string) ($mrt['note'] ?? '')) !== ''): ?> · <?= $e($mrt['note']) ?><?php endif; ?>
                <br><small class="muted">Übernommen aus dem neuesten Patientenausweis
                    <?= $mrt['card_id'] === null ? '' : 'Nr. ' . $e($mrt['card_id']) ?>; die Angabe wird im Brief nur
                    gelesen und im Anhang mit Quelle ausgewiesen.</small>
            <?php else: ?>
                <span class="muted">Im neuesten Patientenausweis ist keine MRT-Tauglichkeit angegeben; im Anhang
                    erscheint die Angabe der Abfrage.</span>
                <a href="/patient-cards/patients/<?= $e($patientId) ?>">Ausweise des Patienten</a>
            <?php endif; ?>
        </p>
    </section>

    <section class="card wizard-step" data-step="4" id="schritt-4">
        <h2><?= $icon('patients', 'app-icon app-icon--sm') ?> 4 · Empfänger wählen</h2>
        <p class="muted">Für jeden ausgewählten Empfänger wird ein eigener Brief mit dessen Anschrift im
            Anschriftfeld erzeugt. Inhalt und Datengrundlage sind bei allen Briefen gleich. Ist keine
            Arztanschrift hinterlegt, steht der generische Arztbrief zur Wahl: Er wird an „An die
            weiterbehandelnden Ärztinnen und Ärzte“ adressiert.</p>
        <?= $err('recipients') ?>
        <fieldset class="choice-box recipient-choices" data-recipient-choices>
            <legend>Empfänger</legend>
            <?php foreach ($recipients as $type => $recipient): ?>
                <?php
                $available = ($recipient['available'] ?? false) === true;
                $generic = $type === \App\Letter\LetterRecipient::GENERIC;
                // Der generische Arztbrief erscheint nur, wenn keine Arztanschrift vorliegt.
                if ($generic && !$available) {
                    continue;
                }
                ?>
                <div class="recipient-choice<?= $available ? '' : ' is-unavailable' ?>">
                    <label class="check" for="recipient-<?= $e($type) ?>">
                        <input type="checkbox" id="recipient-<?= $e($type) ?>" name="recipients[]" value="<?= $e($type) ?>"
                            data-recipient-label="<?= $e($recipient['label']) ?>"
                            <?= in_array($type, $selectedRecipients, true) ? ' checked' : '' ?><?= $available ? '' : ' disabled' ?>>
                        <span>
                            <strong><?= $e(\App\Letter\LetterRecipient::choiceLabel($type)) ?></strong>
                            <?php if ($recipient['lines'] !== []): ?>
                                <span class="recipient-address"><?= nl2br($e(implode("\n", $recipient['lines']))) ?></span>
                            <?php endif; ?>
                            <?php if (!$available): ?>
                                <small class="field-error">Nicht wählbar – in den Stammdaten fehlt:
                                    <?= $e(implode(', ', $recipient['missing'])) ?>.
                                    <a href="/patients/<?= $e($patientId) ?>/edit">Stammdaten ergänzen</a></small>
                            <?php elseif ($generic): ?>
                                <small class="hint">Feste Anrede „<?= $e($recipient['salutation']) ?>“; die Stammdaten der
                                    Ärzte bleiben unberücksichtigt.</small>
                            <?php elseif ($recipient['street'] === ''): ?>
                                <small class="muted">Hinweis: Straße und Hausnummer fehlen in den Stammdaten.</small>
                            <?php endif; ?>
                            <?php if ($available && !$generic && ($recipient['salutation_value'] ?? '') === ''): ?>
                                <small class="hint">Hinweis: In den Stammdaten ist keine Anrede gepflegt.
                                    Im Brief erscheint „<?= $e(\App\Letter\LetterSalutation::FALLBACK) ?>“.
                                    <a href="/patients/<?= $e($patientId) ?>/edit">Stammdaten ergänzen</a></small>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <p class="muted">Anschriften werden in den <a href="/patients/<?= $e($patientId) ?>/edit">Stammdaten des
            Patienten</a> gepflegt (Kontakt, Hausarzt, Überweisender Arzt).</p>
    </section>

    <section class="card wizard-step" data-step="5" id="schritt-5">
        <h2><?= $icon('check', 'app-icon app-icon--sm') ?> 5 · Zusammenfassung</h2>
        <table class="kv">
            <tr><th>Patient</th><td><?= $e($patient['patient_name']) ?>,
                geboren am <?= $e($view::dateTime($patient['date_of_birth'], true)) ?></td></tr>
            <tr><th>Empfänger</th><td>
                <ul class="plain-list" data-recipient-summary>
                    <?php foreach ($recipients as $type => $recipient): ?>
                        <?php if ($type === \App\Letter\LetterRecipient::GENERIC && ($recipient['available'] ?? false) !== true) { continue; } ?>
                        <li data-recipient-item="<?= $e($type) ?>"<?= in_array($type, $selectedRecipients, true) ? '' : ' hidden' ?>>
                            <?= $e($recipient['label']) ?><?= \App\Letter\LetterRecipient::displayName($recipient) === '' ? '' : ': ' . $e(\App\Letter\LetterRecipient::displayName($recipient)) ?></li>
                    <?php endforeach; ?>
                    <li data-recipient-none<?= $selectedRecipients === [] ? '' : ' hidden' ?> class="field-error">Kein Empfänger ausgewählt</li>
                </ul>
            </td></tr>
            <tr><th>Briefnummer</th><td>ab Nr. <?= $e($nextSequence) ?> für diesen Patienten (je Empfänger eine Nummer)</td></tr>
            <tr><th>Befund</th><td>Aktenbaustein „Befund" (aktuelle Fassung), erscheint im Brieftext</td></tr>
            <tr><th>Berichte</th><td><?= $report === null
                ? 'ohne Bericht (entfällt)'
                : 'Bericht Nr. ' . $e($report->id()) . ($reportDateLabel === '' ? '' : ' vom ' . $e($reportDateLabel)) . ' – als Anhang auf neuer Seite' ?></td></tr>
            <tr><th>Brieftext</th><td>Anamnese, Vormedikation, Befund, Epikrise (jeweils aktuelle Fassung)</td></tr>
            <tr><th>Anhang</th><td><?= $appendixSections === []
                ? 'kein Anhang (keine Abfrage vorhanden)'
                : $e(count($appendixSections)) . ' Abschnitt(e), mehrseitig mit wiederholter Kopfzeile' ?></td></tr>
            <tr><th>MRT-Tauglichkeit</th><td><?= ($mrt['available'] ?? false) === true
                ? $e($mrtLabel) . ' (aus Patientenausweis)'
                : 'keine Angabe im Patientenausweis' ?></td></tr>
            <tr><th>Fußzeile</th><td>Dokumentnummer, Erstellungszeitpunkt, Hinweis „keine medizinische Bewertung"</td></tr>
        </table>
        <p class="muted">Der Brief ist unveränderlich: Snapshot und PDF werden gemeinsam gespeichert; das PDF ist
            ausschließlich aus dem Snapshot reproduzierbar.</p>
    </section>

    <section class="card wizard-step" data-step="6" id="schritt-6">
        <h2><?= $icon('check', 'app-icon app-icon--sm') ?> 6 · Bestätigen und erzeugen</h2>
        <?= $err('patient_id') ?>
        <fieldset class="choice-box">
            <legend>Bestätigungen</legend>
            <div class="field">
                <label for="confirm_data">
                    <input type="checkbox" id="confirm_data" name="confirm_data" value="1"
                        <?= $selection['confirm_data'] ? ' checked' : '' ?>>
                    Ja, die Angaben sind geprüft und vollständig.
                </label>
                <?= $err('confirm_data') ?>
            </div>
            <div class="field">
                <label for="confirm_letter">
                    <input type="checkbox" id="confirm_letter" name="confirm_letter" value="1"
                        <?= $selection['confirm_letter'] ? ' checked' : '' ?>>
                    Ja, der Brief darf erzeugt und unveränderlich gespeichert werden.
                </label>
                <?= $err('confirm_letter') ?>
            </div>
        </fieldset>
        <p class="muted">Die Prüfung erfolgt immer serverseitig. Ohne beide Bestätigungen wird kein Brief erzeugt.
            Je ausgewähltem Empfänger entsteht ein eigener Brief.</p>
    </section>

    <div class="wizard-controls">
        <button type="button" class="button" data-wizard-prev><?= $icon('back') ?> <span>Zurück</span></button>
        <button type="button" class="button" data-wizard-next><?= $icon('next') ?> <span>Weiter</span></button>
        <button type="submit" class="button primary" data-wizard-submit data-once><?= $icon('mail-new') ?> <span data-label data-recipient-submit><?= count($selectedRecipients) > 1 ? $e(count($selectedRecipients)) . ' Briefe erzeugen' : 'Brief erzeugen' ?></span></button>
    </div>
</form>
