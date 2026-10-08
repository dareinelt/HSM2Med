<?php
/**
 * Stammdaten eines Patienten (Neuanlage vor dem Import oder Bearbeitung).
 *
 * Die Identität ist Nachname + Vorname + Geburtsdatum. Existiert bereits ein Patient mit
 * denselben Angaben, muss die Dublette ausdrücklich bestätigt werden.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var int|null $patientId
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $duplicates
 * @var string|null $message
 */
$val = static fn (string $key): string => $values[$key] ?? '';
$err = static function (string $key) use ($errors, $e): string {
    return isset($errors[$key]) ? '<p class="field-error" role="alert">' . $e($errors[$key]) . '</p>' : '';
};
$text = static function (string $name, string $label, string $hint = '', int $max = 255, bool $required = false) use ($val, $err, $e): string {
    return '<div class="field">'
        . '<label for="f-' . $e($name) . '">' . $e($label) . '</label>'
        . '<input type="text" id="f-' . $e($name) . '" name="' . $e($name) . '" maxlength="' . $e($max) . '"'
        . ' value="' . $e($val($name)) . '"' . ($required ? ' required' : '') . '>'
        . ($hint === '' ? '' : '<small class="muted">' . $e($hint) . '</small>')
        . $err($name) . '</div>';
};
$action = $patientId === null ? '/patients' : '/patients/' . $patientId;
?>
<div class="page-head">
    <div>
        <h1><?= $icon($patientId === null ? 'user-plus' : 'edit', 'app-icon app-icon--lg') ?><span><?= $patientId === null ? 'Patient anlegen' : 'Patient bearbeiten' ?></span></h1>
        <p class="lead">Patienten können unabhängig von einem Import angelegt werden. Anamnese,
            Vormedikation und Epikrise werden anschließend als versionierte Bausteine erfasst.</p>
    </div>
    <div class="actions">
        <?php if ($patientId === null): ?>
            <a class="button" href="/patients"><?= $icon('back') ?> <span>Zur Übersicht</span></a>
        <?php else: ?>
            <a class="button" href="/patients/<?= $e($patientId) ?>"><?= $icon('patients') ?> <span>Zur Akte</span></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($message) ?></div>
<?php endif; ?>

<?php if ($duplicates !== []): ?>
    <div class="alert alert-warning" role="alert">
        <p><strong>Mögliche Dublette:</strong> <?= $e(count($duplicates)) ?> Patient(en) mit gleichem Namen und
            Geburtsdatum gefunden.</p>
        <ul>
            <?php foreach ($duplicates as $d): ?>
                <li>
                    Nr. <?= $e($d['id']) ?> – <?= $e($d['patient_name']) ?>
                    (<?= $e(\App\Http\View::dateTime($d['date_of_birth'] ?? null, true)) ?>)
                    <?php if (($d['patient_identifier'] ?? null) !== null && $d['patient_identifier'] !== ''): ?>
                        · Patienten-ID <?= $e($d['patient_identifier']) ?>
                    <?php endif; ?>
                    · <a href="/patients/<?= $e($d['id']) ?>">Akte öffnen</a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="muted">Handelt es sich um denselben Patienten, bitte stattdessen die vorhandene Akte
            verwenden. Ist es ein anderer Patient, die Bestätigung unten setzen.</p>
    </div>
<?php endif; ?>

<form method="post" action="<?= $e($action) ?>" class="card">
    <?= $csrf() ?>
    <h2><?= $icon('user-plus', 'app-icon app-icon--sm') ?> Identität</h2>
    <div class="field-row">
        <?= $text('last_name', 'Nachname', 'Pflichtfeld', 255, true) ?>
        <?= $text('first_name', 'Vorname', 'Pflichtfeld', 255, true) ?>
        <div class="field">
            <label for="f-date_of_birth">Geburtsdatum (TT.MM.JJJJ)</label>
            <input type="text" id="f-date_of_birth" name="date_of_birth" maxlength="32"
                   value="<?= $e($val('date_of_birth')) ?>" required>
            <small class="muted">Pflichtfeld – Identitätsmerkmal</small>
            <?= $err('date_of_birth') ?>
        </div>
        <?= $text('patient_identifier', 'Patienten-ID', 'Freie Angabe, z. B. Fallnummer', 191) ?>
    </div>

    <h2><?= $icon('info', 'app-icon app-icon--sm') ?> Kontakt</h2>
    <div class="field-row">
        <?= $text('street', 'Straße und Hausnummer') ?>
        <?= $text('postal_code', 'Postleitzahl', '', 32) ?>
        <?= $text('city', 'Ort') ?>
        <?= $text('phone', 'Telefon', 'Ziffern sowie + ( ) / - . und Leerzeichen', 64) ?>
    </div>

    <h2><?= $icon('patients', 'app-icon app-icon--sm') ?> Hausarzt</h2>
    <p class="muted">Anschrift für Briefe an den Hausarzt. Für einen Brief sind Name oder Praxis sowie
        Postleitzahl und Ort erforderlich.</p>
    <div class="field-row">
        <?= $text('physician_name', 'Name', 'z. B. Dr. med. Anna Weber') ?>
        <?= $text('physician_practice', 'Praxis') ?>
        <?= $text('physician_street', 'Straße und Hausnummer') ?>
        <?= $text('physician_postal_code', 'Postleitzahl', '', 32) ?>
        <?= $text('physician_city', 'Ort') ?>
        <?= $text('physician_phone', 'Telefon', 'Ziffern sowie + ( ) / - . und Leerzeichen', 64) ?>
    </div>

    <h2><?= $icon('patients', 'app-icon app-icon--sm') ?> Überweisender Arzt</h2>
    <p class="muted">Anschrift für Briefe an den überweisenden Arzt. Für einen Brief sind Name oder Praxis
        sowie Postleitzahl und Ort erforderlich.</p>
    <div class="field-row">
        <?= $text('referrer_name', 'Name', 'z. B. Dr. med. Jonas Klein') ?>
        <?= $text('referrer_practice', 'Praxis') ?>
        <?= $text('referrer_street', 'Straße und Hausnummer') ?>
        <?= $text('referrer_postal_code', 'Postleitzahl', '', 32) ?>
        <?= $text('referrer_city', 'Ort') ?>
        <?= $text('referrer_phone', 'Telefon', 'Ziffern sowie + ( ) / - . und Leerzeichen', 64) ?>
    </div>

    <h2><?= $icon('pulse', 'app-icon app-icon--sm') ?> Indikation</h2>
    <div class="field">
        <label for="f-indication">Indikation / Anlass</label>
        <textarea id="f-indication" name="indication" rows="4" maxlength="2000"><?= $e($val('indication')) ?></textarea>
        <?= $err('indication') ?>
    </div>

    <?php if ($duplicates !== []): ?>
        <fieldset class="choice-box">
            <legend>Dublette bestätigen</legend>
            <label class="check">
                <input type="checkbox" name="confirm_duplicate" value="1" <?= $val('confirm_duplicate') === '1' ? 'checked' : '' ?>>
                Es handelt sich um einen anderen Patienten als die oben genannten Treffer.
            </label>
            <?= $err('confirm_duplicate') ?>
        </fieldset>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="primary" data-once><?= $icon('check') ?> <span data-label><?= $patientId === null ? 'Patient anlegen' : 'Stammdaten speichern' ?></span></button>
        <?php if ($patientId === null): ?>
            <a class="button" href="/patients"><?= $icon('close') ?> <span>Abbrechen</span></a>
        <?php else: ?>
            <a class="button" href="/patients/<?= $e($patientId) ?>"><?= $icon('close') ?> <span>Abbrechen</span></a>
        <?php endif; ?>
    </div>
</form>
