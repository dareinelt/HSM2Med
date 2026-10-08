<?php
/**
 * Hinweistexte des Patientenausweises. Praxis-Informationen, Ruecksendeangaben und Logo werden im
 * Bereich "System" gepflegt und hier nur angezeigt.
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
 * @var Closure(string): bool $permitted
 */
$hasLogo = is_array($logo) && ($logo['id'] ?? null) !== null;
$practiceName = trim((string) ($settings['center_name'] ?? ''));
$practiceAddress = trim((string) ($settings['center_address'] ?? ''));
?>
<div class="page-head">
    <div>
        <h1><?= $icon('settings', 'app-icon app-icon--lg') ?><span>Stammdaten des Patientenausweises</span></h1>
        <p class="lead">Hinweistexte gelten für neu erstellte Ausweise.
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

<section class="card">
    <h2><?= $icon('settings', 'app-icon app-icon--sm') ?> Praxis-Informationen</h2>
    <table class="kv">
        <tr><th>Praxis</th><td><?= $practiceName === '' ? '<span class="muted">nicht hinterlegt</span>' : $e($practiceName) ?></td></tr>
        <tr><th>Anschrift</th><td><?= $practiceAddress === '' ? '<span class="muted">nicht hinterlegt</span>' : nl2br($e($practiceAddress)) ?></td></tr>
        <tr><th>Logo</th><td>
            <?php if ($hasLogo): ?>
                <img src="/patient-cards/settings/logo" alt="Hinterlegtes Logo" width="<?= $e($logo['width']) ?>" height="<?= $e($logo['height']) ?>">
            <?php else: ?>
                <span class="muted">Kein Logo hinterlegt.</span>
            <?php endif; ?>
        </td></tr>
    </table>
    <p class="hint">Praxis, Anschrift, Kontaktangaben, Logo und Rücksendeangaben werden im Bereich
        „System“ gepflegt und gelten gemeinsam für Briefe und Ausweise.</p>
    <div class="form-actions">
        <?php if ($permitted('/system/settings')): ?>
            <a class="button" href="/system/settings"><?= $icon('settings') ?> <span>Praxis-Informationen bearbeiten</span></a>
        <?php endif; ?>
    </div>
</section>

<form method="post" action="/patient-cards/settings" class="card">
    <?= $csrf() ?>

    <h2><?= $icon('info', 'app-icon app-icon--sm') ?> Hinweistexte</h2>
    <?php foreach (['notice_text', 'flight_notice_de', 'flight_notice_en'] as $field): ?>
        <?php [$label, $max, $multiline] = $fields[$field]; ?>
        <div class="field wide">
            <label for="<?= $e($field) ?>"><?= $e($label) ?> <small class="muted">(max. <?= $e($max) ?> Zeichen)</small></label>
            <textarea id="<?= $e($field) ?>" name="<?= $e($field) ?>" rows="<?= $field === 'notice_text' ? 6 : 4 ?>" maxlength="<?= $e($max) ?>"><?= $e($values[$field]) ?></textarea>
            <?php if (isset($errors[$field])): ?><p class="field-error"><?= $e($errors[$field]) ?></p><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="primary">Hinweistexte speichern</button>
        <span class="muted">Bisher gespeicherte Fassungen: <?= $e($versions) ?></span>
    </div>
</form>
