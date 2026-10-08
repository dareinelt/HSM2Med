<?php
/**
 * Formular und Fassungshistorie eines Aktenbausteins.
 *
 * Freitextbausteine (Anamnese, Epikrise, Notiz) haben ein Textfeld. Die Vormedikation hat
 * zusaetzlich Arzneimittelzeilen. Speichern erzeugt eine neue Fassung; inhaltsgleiche Eingaben
 * erzeugen keine neue Fassung.
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
 */
$id = (int) $patient['id'];
$structured = $type->isStructured();
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

    <div class="field">
        <label for="f-text"><?= $structured ? 'Ergänzungen zur Vormedikation' : 'Inhalt' ?></label>
        <textarea id="f-text" name="text" rows="12" maxlength="<?= $e(\App\Patient\PatientRecordInput::MAX_TEXT) ?>"><?= $e($value('text')) ?></textarea>
        <?php if (!$structured): ?>
            <small class="muted">Freitext – Zeilenumbrüche bleiben erhalten.</small>
        <?php endif; ?>
        <?= $err('text') ?>
    </div>

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
