<?php
/**
 * Stammdaten des Patientenausweises: Logo, Nachsorgezentrum und die drei Hinweistexte.
 * Aenderungen gelten nur fuer kuenftig erzeugte Ausweise (jeder Ausweis haelt seine Fassung fest).
 *
 * @var Closure $e
 * @var Closure $icon
 * @var array<string, mixed> $settings
 * @var array<string, mixed>|null $logo
 * @var int $versions
 * @var array<string, int> $limits
 * @var array<string, string> $values
 * @var array<string, array{0: string, 1: int, 2: bool}> $fields
 * @var array<string, string> $errors
 * @var string|null $message
 */
$hasLogo = is_array($logo) && ($logo['id'] ?? null) !== null;
?>
<div class="page-head">
    <div>
        <h1><?= $icon('settings', 'app-icon app-icon--lg') ?><span>Stammdaten des Patientenausweises</span></h1>
        <p class="lead">Logo, Nachsorgezentrum und Hinweistexte gelten für neu erstellte Ausweise.
            Bereits erzeugte Ausweise bleiben unverändert.</p>
    </div>
    <div class="actions">
        <a class="button" href="/patient-cards"><?= $icon('cards') ?> <span>Patientenausweise</span></a>
        <a class="button primary" href="/patient-cards/new"><?= $icon('card-plus') ?> <span>Ausweis erstellen</span></a>
    </div>
</div>

<?php if ($message !== null): ?>
    <p class="alert error" role="alert"><?= $e($message) ?></p>
<?php endif; ?>

<form method="post" action="/patient-cards/settings" enctype="multipart/form-data" class="card">
    <?= $csrf() ?>

    <h2><?= $icon('settings', 'app-icon app-icon--sm') ?> Nachsorgezentrum</h2>
    <?php foreach (['center_name', 'center_address'] as $field): ?>
        <?php [$label, $max, $multiline] = $fields[$field]; ?>
        <div class="field<?= $multiline ? ' wide' : '' ?>">
            <label for="<?= $e($field) ?>"><?= $e($label) ?></label>
            <?php if ($multiline): ?>
                <textarea id="<?= $e($field) ?>" name="<?= $e($field) ?>" rows="3" maxlength="<?= $e($max) ?>"><?= $e($values[$field]) ?></textarea>
            <?php else: ?>
                <input id="<?= $e($field) ?>" name="<?= $e($field) ?>" value="<?= $e($values[$field]) ?>" maxlength="<?= $e($max) ?>">
            <?php endif; ?>
            <?php if (isset($errors[$field])): ?><p class="field-error"><?= $e($errors[$field]) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <h2><?= $icon('info', 'app-icon app-icon--sm') ?> Hinweistexte</h2>
    <?php foreach (['notice_text', 'flight_notice_de', 'flight_notice_en'] as $field): ?>
        <?php [$label, $max, $multiline] = $fields[$field]; ?>
        <div class="field wide">
            <label for="<?= $e($field) ?>"><?= $e($label) ?> <small class="muted">(max. <?= $e($max) ?> Zeichen)</small></label>
            <textarea id="<?= $e($field) ?>" name="<?= $e($field) ?>" rows="<?= $field === 'notice_text' ? 6 : 4 ?>" maxlength="<?= $e($max) ?>"><?= $e($values[$field]) ?></textarea>
            <?php if (isset($errors[$field])): ?><p class="field-error"><?= $e($errors[$field]) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <h2><?= $icon('reports', 'app-icon app-icon--sm') ?> Logo</h2>
    <div class="logo-row">
        <div class="logo-preview">
            <?php if ($hasLogo): ?>
                <img src="/patient-cards/settings/logo" alt="Hinterlegtes Logo" width="<?= $e($logo['width']) ?>" height="<?= $e($logo['height']) ?>">
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
    <p class="hint">Das Logo wird ohne Patientendaten gespeichert. Änderungen wirken sich nicht auf bereits
        erzeugte Ausweise aus.</p>

    <div class="form-actions">
        <button type="submit" class="primary">Stammdaten speichern</button>
        <span class="muted">Bisher gespeicherte Fassungen: <?= $e($versions) ?></span>
    </div>
</form>
