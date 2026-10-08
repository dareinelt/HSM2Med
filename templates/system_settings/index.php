<?php
/**
 * Praxis-Informationen und Ruecksendeangaben (Bereich "System").
 *
 * Die Angaben gelten fuer alle Briefe und Ausweise; bereits erzeugte Briefe und Ausweise halten
 * ihre beim Erstellen gueltige Stammdaten-Fassung fest und bleiben unveraendert.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var Closure $csrf
 * @var array<string, mixed>|null $logo
 * @var int $versions
 * @var array<string, int> $limits
 * @var array<string, string> $values
 * @var array<string, array{0: string, 1: int, 2: bool}> $fields
 * @var array<string, list<string>> $sections
 * @var array<string, string> $errors
 * @var string|null $message
 * @var Closure(string): bool $permitted
 */
$hasLogo = is_array($logo) && ($logo['id'] ?? null) !== null;
?>
<div class="page-head">
    <div>
        <h1><?= $icon('settings', 'app-icon app-icon--lg') ?><span>Praxis-Informationen</span></h1>
        <p class="lead">Praxis, Kontaktangaben, Logo und Rücksendeangaben. Diese Angaben werden in die
            Briefe übernommen und gelten auch für neu erstellte Patientenausweise.</p>
    </div>
    <div class="actions">
        <a class="button" href="/system"><?= $icon('system') ?> <span>Systeminformationen</span></a>
        <?php if ($permitted('/system/letter-templates')): ?>
            <a class="button" href="/system/letter-templates" target="_blank" rel="noopener"
               title="Feste Texte und Aufbau der Briefe bearbeiten – öffnet in einem neuen Tab"><?= $icon('edit') ?><span>Briefvorlage bearbeiten</span></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message !== null): ?>
    <p class="alert error" role="alert"><?= $e($message) ?></p>
<?php endif; ?>

<form method="post" action="/system/settings" enctype="multipart/form-data" class="card">
    <?= $csrf() ?>

    <?php foreach ($sections as $heading => $sectionFields): ?>
        <h2><?= $icon($heading === 'Rücksendeangaben' ? 'mail-new' : 'settings', 'app-icon app-icon--sm') ?> <?= $e($heading) ?></h2>
        <?php if ($heading === 'Rücksendeangaben'): ?>
            <p class="hint">Name, Straße, Postleitzahl und Ort der Rücksendeangabe über der Empfängeranschrift.
                Bleiben alle Felder leer, verwenden die Briefe automatisch die Anschrift der Praxis.</p>
        <?php endif; ?>
        <?php foreach ($sectionFields as $field): ?>
            <?php [$label, $max, $multiline] = $fields[$field]; ?>
            <div class="field<?= $multiline ? ' wide' : '' ?>">
                <label for="<?= $e($field) ?>"><?= $e($label) ?> <small class="muted">(max. <?= $e($max) ?> Zeichen)</small></label>
                <?php if ($multiline): ?>
                    <textarea id="<?= $e($field) ?>" name="<?= $e($field) ?>" rows="3" maxlength="<?= $e($max) ?>"><?= $e($values[$field]) ?></textarea>
                <?php else: ?>
                    <input id="<?= $e($field) ?>" name="<?= $e($field) ?>" value="<?= $e($values[$field]) ?>" maxlength="<?= $e($max) ?>">
                <?php endif; ?>
                <?php if (isset($errors[$field])): ?><p class="field-error"><?= $e($errors[$field]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <h2><?= $icon('reports', 'app-icon app-icon--sm') ?> Logo</h2>
    <div class="logo-row">
        <div class="logo-preview">
            <?php if ($hasLogo): ?>
                <img src="/system/settings/logo" alt="Hinterlegtes Logo" width="<?= $e($logo['width']) ?>" height="<?= $e($logo['height']) ?>">
            <?php else: ?>
                <p class="muted">Kein Logo hinterlegt.</p>
            <?php endif; ?>
        </div>
        <div class="logo-meta">
            <?php if ($hasLogo): ?>
                <p><strong><?= $e($logo['filename']) ?></strong><br>
                    <small class="muted"><?= $e($logo['mime_type']) ?> ·
                        <?= $e($logo['width']) ?>×<?= $e($logo['height']) ?> px</small></p>
                <p class="check"><input type="checkbox" id="remove_logo" name="remove_logo" value="1">
                    <label for="remove_logo">Logo entfernen</label></p>
            <?php endif; ?>
            <div class="field">
                <label for="logo">Neues Logo (PNG oder JPEG, max. <?= $e(number_format($limits['logo_bytes'] / 1024, 0, ',', '.')) ?> kB)</label>
                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg">
                <?php if (isset($errors['logo'])): ?><p class="field-error"><?= $e($errors['logo']) ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <p class="hint">Das Logo wird ohne Patientendaten gespeichert und erscheint im Briefkopf der Briefe
        und auf den Patientenausweisen.</p>

    <div class="form-actions">
        <button type="submit" class="primary">Praxis-Informationen speichern</button>
        <span class="muted">Bisher gespeicherte Fassungen: <?= $e($versions) ?></span>
    </div>
</form>
