<?php
/**
 * Formular und Fassungshistorie eines Aktenbausteins.
 *
 * Freitextbausteine (Anamnese, Epikrise, Notiz) haben ein Textfeld. Die Vormedikation hat
 * zusaetzlich Arzneimittelzeilen. Die Schrittmacher-/ICD-Abfrage wird nach der Vorlage des
 * aerztlichen Dienstes (config/device_check_template.php) in Abschnitte gegliedert.
 * Speichern erzeugt eine neue Fassung; inhaltsgleiche Eingaben erzeugen keine neue Fassung.
 *
 * @var Closure $e
 * @var App\Http\View $view
 * @var array<string, mixed> $patient
 * @var App\Patient\PatientRecordType $type
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string|null $message
 * @var array<string, mixed>|null $current
 * @var list<array<string, mixed>> $history
 * @var list<App\Patient\PatientRecordType> $types
 * @var int $maxEntries
 * @var array<string, mixed>|null $deviceCheck
 */
$id = (int) $patient['id'];
$structured = $type->isStructured();
$isDeviceCheck = $deviceCheck !== null;
$entries = is_array($values['medication'] ?? null) ? $values['medication'] : [];
if ($structured && $entries === []) {
    $entries = [array_fill_keys(array_keys(\App\Patient\PatientRecordInput::ENTRY_FIELDS), '')];
}
$err = static function (string $key) use ($errors, $e): string {
    return isset($errors[$key]) ? '<p class="field-error" role="alert">' . $e($errors[$key]) . '</p>' : '';
};
$value = static fn (string $key): string => is_string($values[$key] ?? null) ? (string) $values[$key] : '';
$labels = [
    'substance' => 'Wirkstoff',
    'dose' => 'Dosis',
    'unit' => 'Einheit',
    'schedule' => 'Einnahme',
    'reason' => 'Grund',
    'from' => 'von (TT.MM.JJJJ)',
    'to' => 'bis (TT.MM.JJJJ)',
];

/** @var array<string, string> $checkValues */
$checkValues = is_array($values['values'] ?? null) ? $values['values'] : [];
/** @var list<array<string, string>> $checkLeads */
$checkLeads = is_array($values['leads'] ?? null) ? array_values($values['leads']) : [];
$deviceType = $value('device_type');
$mrtLocked = ($values['mrt_locked'] ?? false) === true;
$emptyLead = array_fill_keys(array_map(
    static fn (array $field): string => (string) $field['name'],
    is_array($deviceCheck['leadFields'] ?? null) ? $deviceCheck['leadFields'] : [],
), '');
$leadRows = $checkLeads === [] ? [$emptyLead] : $checkLeads;

/**
 * Ein Eingabefeld der Abfrage (Auswahlfeld, Datumsfeld oder Freitext mit Einheit in der
 * Beschriftung). Werte aus dem Patientenausweis werden nur lesend uebernommen.
 *
 * @param array<string, mixed> $field
 */
$checkField = static function (array $field, string $name, string $current, bool $locked) use ($e): void {
    $options = is_array($field['options'] ?? null) ? $field['options'] : null;
    $label = (string) $field['label'];
    $id = 'f-' . str_replace(['[', ']', '.'], '-', $name);
    $locked = $locked && ($field['from_card'] ?? null) !== null;
    echo '<div class="field' . ($locked ? ' field-locked' : '') . '"' . ($locked ? ' data-locked' : '') . '>';
    echo '<label for="' . $e($id) . '">' . $e($label) . '</label>';
    if ($options !== null) {
        echo '<select id="' . $e($id) . '" name="' . $e($name) . '"' . ($locked ? ' disabled' : '') . '>';
        echo '<option value="">– keine Angabe –</option>';
        foreach ($options as $option) {
            $selected = (string) $option === $current ? ' selected' : '';
            echo '<option value="' . $e((string) $option) . '"' . $selected . '>' . $e((string) $option) . '</option>';
        }
        echo '</select>';
        if ($locked) {
            echo '<input type="hidden" name="' . $e($name) . '" value="' . $e($current) . '">';
        }
    } else {
        $placeholder = ($field['type'] ?? 'text') === 'date' ? ' placeholder="TT.MM.JJJJ"' : '';
        $maxlength = $locked ? '' : ' maxlength="' . $e((string) $field['maxlength']) . '"';
        $readonly = $locked ? ' readonly aria-readonly="true"' : '';
        echo '<input type="text" id="' . $e($id) . '" name="' . $e($name) . '" value="' . $e($current) . '"'
            . $placeholder . $maxlength . $readonly . '>';
    }
    if ($locked) {
        echo '<small class="muted">Wird aus dem Patientenausweis übernommen und dort gepflegt.</small>';
    }
    echo '</div>';
};
?>
<div class="page-head">
    <div>
        <h1><?= $e($type->label()) ?></h1>
        <p class="lead"><?= $e($patient['patient_name']) ?> · Patient Nr. <?= $e($id) ?> · <?= $e($type->hint()) ?></p>
    </div>
    <div class="actions">
        <a class="button" href="/patients/<?= $e($id) ?>">Zur Akte</a>
        <a class="button" href="/patient-cards/patients/<?= $e($id) ?>">Ausweise und Nachsorge</a>
    </div>
</div>

<?php if ($message !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($message) ?></div>
<?php endif; ?>

<ul class="chips">
    <?php foreach ($types as $t): ?>
        <li>
            <a href="/patients/<?= $e($id) ?>/records/<?= $e($t->value) ?>"
               <?= $t->value === $type->value ? 'aria-current="page"' : '' ?>><?= $e($t->label()) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<form method="post" action="/patients/<?= $e($id) ?>/records/<?= $e($type->value) ?>" class="card">
    <?= $csrf() ?>

    <?php if ($isDeviceCheck): ?>
        <div class="field">
            <label for="f-device_type">Art des Geräts</label>
            <select id="f-device_type" name="device_type" data-device-type>
                <option value="">– bitte wählen –</option>
                <?php foreach ($deviceCheck['deviceTypes'] as $key => $label): ?>
                    <option value="<?= $e((string) $key) ?>" <?= (string) $key === $deviceType ? 'selected' : '' ?>><?= $e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="muted">
                Die Art des Geräts bestimmt die Abschnitte der Abfrage. Ohne Auswahl kann nicht
                gespeichert werden. Vorlage <?= $e((string) $deviceCheck['version']) ?>.
            </small>
            <?= $err('device_type') ?>
        </div>

        <p>
            <button type="submit" class="button"
                    formaction="/patients/<?= $e($id) ?>/records/<?= $e($type->value) ?>/prefill"
                    formnovalidate>Werte aus dem letzten Bericht übernehmen</button>
            <span class="muted">Nur leere Felder werden ergänzt; vorhandene Eingaben bleiben erhalten.</span>
        </p>

        <?= $err('values') ?>

        <?php foreach ($deviceCheck['sections'] as $section): ?>
            <?php
            $sectionDevices = is_array($section['devices'] ?? null) ? $section['devices'] : [];
            $applies = $sectionDevices === [] || in_array($deviceType, $sectionDevices, true);
            ?>
            <section class="card" data-devices="<?= $e(implode(' ', $sectionDevices)) ?>" <?= $applies ? '' : 'hidden' ?>>
                <h2><?= $e((string) $section['label']) ?></h2>
                <?php if ($sectionDevices !== []): ?>
                    <p class="muted">Nur für <?= $e(implode(', ', array_map(
                        static fn (string $device): string => (string) $deviceCheck['deviceTypes'][$device],
                        $sectionDevices,
                    ))) ?>.</p>
                <?php endif; ?>

                <?php if (($section['repeat'] ?? null) === 'leads'): ?>
                    <div data-repeat data-repeat-name="leads" data-repeat-limit="<?= $e((string) $deviceCheck['maxLeads']) ?>">
                        <div class="table-scroll">
                            <table class="table">
                                <thead>
                                <tr>
                                    <?php foreach ($deviceCheck['leadFields'] as $field): ?>
                                        <th><?= $e((string) $field['label']) ?></th>
                                    <?php endforeach; ?>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody data-repeat-rows>
                                <?php foreach ($leadRows as $index => $lead): ?>
                                    <tr data-repeat-row>
                                        <?php foreach ($deviceCheck['leadFields'] as $field): ?>
                                            <?php
                                            $name = 'leads[' . $index . '][' . $field['name'] . ']';
                                            $leadDevices = is_array($field['devices'] ?? null) ? $field['devices'] : [];
                                            $leadApplies = $leadDevices === [] || in_array($deviceType, $leadDevices, true);
                                            ?>
                                            <td <?= $leadDevices === [] ? '' : 'data-devices="' . $e(implode(' ', $leadDevices)) . '"' ?>
                                                <?= $leadApplies ? '' : 'hidden' ?>>
                                                <?php
                                                $checkField($field, $name, (string) ($lead[$field['name']] ?? ''), false);
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td><button type="button" class="button" data-repeat-remove>Zeile entfernen</button></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <template data-repeat-template>
                            <tr data-repeat-row>
                                <?php foreach ($deviceCheck['leadFields'] as $field): ?>
                                    <?php
                                    $leadDevices = is_array($field['devices'] ?? null) ? $field['devices'] : [];
                                    $leadApplies = $leadDevices === [] || in_array($deviceType, $leadDevices, true);
                                    ?>
                                    <td <?= $leadDevices === [] ? '' : 'data-devices="' . $e(implode(' ', $leadDevices)) . '"' ?>
                                        <?= $leadApplies ? '' : 'hidden' ?>>
                                        <?php
                                        $checkField($field, 'leads[__INDEX__][' . $field['name'] . ']', '', false);
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                                <td><button type="button" class="button" data-repeat-remove>Zeile entfernen</button></td>
                            </tr>
                        </template>
                        <p><button type="button" class="button" data-repeat-add>Weitere Sonde</button></p>
                    </div>
                    <?= $err('leads') ?>
                    <p class="muted">Vollständig leere Sondenzeilen werden verworfen.</p>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($section['fields'] as $field): ?>
                            <?php
                            $checkField(
                                $field,
                                'values[' . $field['path'] . ']',
                                (string) ($checkValues[$field['path']] ?? ''),
                                $mrtLocked,
                            );
                            ?>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($section['groups'] as $group): ?>
                        <h3><?= $e((string) $group['label']) ?></h3>
                        <div class="grid">
                            <?php foreach ($group['fields'] as $field): ?>
                                <?php
                                $checkField(
                                    $field,
                                    'values[' . $field['path'] . ']',
                                    (string) ($checkValues[$field['path']] ?? ''),
                                    false,
                                );
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <div class="field">
            <label for="f-notes">Bemerkungen zur Abfrage</label>
            <textarea id="f-notes" name="notes" rows="4"
                      maxlength="<?= $e((string) $deviceCheck['maxNotes']) ?>"><?= $e($value('notes')) ?></textarea>
            <?= $err('notes') ?>
        </div>
    <?php endif; ?>

    <?php if ($structured): ?>
        <h2>Arzneimittel</h2>
        <div data-repeat data-repeat-limit="<?= $e($maxEntries) ?>">
            <div class="table-scroll">
                <table class="table">
                    <thead>
                    <tr>
                        <?php foreach ($labels as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody data-repeat-rows>
                    <?php foreach ($entries as $index => $entry): ?>
                        <tr data-repeat-row>
                            <?php foreach (array_keys($labels) as $field): ?>
                                <td>
                                    <input type="text" name="medication[<?= $e($index) ?>][<?= $e($field) ?>]"
                                           value="<?= $e((string) ($entry[$field] ?? '')) ?>"
                                           aria-label="<?= $e($labels[$field]) ?>" maxlength="<?= $e(\App\Patient\PatientRecordInput::ENTRY_FIELDS[$field]) ?>">
                                </td>
                            <?php endforeach; ?>
                            <td><button type="button" class="button" data-repeat-remove>Zeile entfernen</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <template data-repeat-template>
                <tr data-repeat-row>
                    <?php foreach (array_keys($labels) as $field): ?>
                        <td>
                            <input type="text" name="medication[__INDEX__][<?= $e($field) ?>]" value=""
                                   aria-label="<?= $e($labels[$field]) ?>" maxlength="<?= $e(\App\Patient\PatientRecordInput::ENTRY_FIELDS[$field]) ?>">
                        </td>
                    <?php endforeach; ?>
                    <td><button type="button" class="button" data-repeat-remove>Zeile entfernen</button></td>
                </tr>
            </template>
            <p><button type="button" class="button" data-repeat-add>Weitere Zeile</button></p>
        </div>
        <?= $err('medication') ?>
        <p class="muted">Vollständig leere Zeilen werden verworfen. Der Wirkstoff ist je Zeile erforderlich.</p>
    <?php endif; ?>

    <?php if (!$isDeviceCheck): ?>
    <div class="field">
        <label for="f-text"><?= $structured ? 'Ergänzungen zur Vormedikation' : 'Inhalt' ?></label>
        <textarea id="f-text" name="text" rows="12" maxlength="<?= $e(\App\Patient\PatientRecordInput::MAX_TEXT) ?>"><?= $e($value('text')) ?></textarea>
        <?php if (!$structured): ?>
            <small class="muted">Freitext – Zeilenumbrüche bleiben erhalten.</small>
        <?php endif; ?>
        <?= $err('text') ?>
    </div>
    <?php endif; ?>

    <div class="field">
        <label for="f-author_name">Erfasst von</label>
        <input type="text" id="f-author_name" name="author_name" maxlength="<?= $e(\App\Patient\PatientRecordInput::MAX_AUTHOR) ?>"
               value="<?= $e($value('author_name')) ?>">
        <small class="muted">Freie Angabe (die Anwendung führt keine Benutzerkonten).</small>
        <?= $err('author_name') ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="primary" data-once>Als neue Fassung speichern</button>
        <a class="button" href="/patients/<?= $e($id) ?>">Abbrechen</a>
        <?php if ($current !== null): ?>
            <span class="muted">Aktuell: <?= $e(\App\Patient\PatientRecordService::versionLabel($current)) ?></span>
        <?php endif; ?>
    </div>
</form>

<section class="card">
    <h2>Fassungen</h2>
    <?php if ($history === []): ?>
        <p class="muted">Noch keine Fassung vorhanden.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Fassung</th><th>Erfasst am</th><th>Erfasst von</th><th>Inhalt</th></tr></thead>
                <tbody>
                <?php foreach ($history as $index => $version): ?>
                    <tr>
                        <td>
                            Fassung <?= $e($version['version']) ?>
                            <?php if ($index === 0): ?> <span class="badge">aktuell</span><?php endif; ?>
                        </td>
                        <td><?= $e($view::dateTime($version['version_created_at'])) ?></td>
                        <td><?= $e($version['author_name'] ?? '') ?></td>
                        <td>
                            <?php if (($version['lines'] ?? []) === []): ?>
                                <span class="muted">leer</span>
                            <?php else: ?>
                                <ul>
                                    <?php foreach ($version['lines'] as $line): ?>
                                        <li><?= $e($line) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Frühere Fassungen bleiben unverändert erhalten; sie werden nicht überschrieben.</p>
    <?php endif; ?>
</section>
