<?php
/**
 * Eigenes Kennwort aendern. Steht jeder angemeldeten Person offen.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var Closure(string): bool $permitted
 * @var array<string, string> $errors
 * @var string|null $message
 */
$minimum = \App\Http\Controller\AccountController::minimumLength();
?>
<div class="page-head">
    <div>
        <h1><?= $icon('key', 'app-icon app-icon--lg') ?><span>Kennwort ändern</span></h1>
        <p class="lead">Das Kennwort schützt den Zugang zu Gesundheitsdaten. Es wird ausschließlich
            verschlüsselt (als Hash) gespeichert.</p>
    </div>
    <div class="actions">
        <a class="button" href="/"><?= $icon('back') ?> <span>Zum Dashboard</span></a>
        <?php if ($permitted('/system/users')): ?>
            <a class="button" href="/system/users"><?= $icon('users') ?> <span>Benutzerverwaltung</span></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message !== null): ?>
    <p class="alert alert-error" role="alert"><?= $e($message) ?></p>
<?php endif; ?>

<form method="post" action="/account/password" class="card" autocomplete="off">
    <?= $csrf() ?>

    <h2><?= $icon('lock', 'app-icon app-icon--sm') ?> Neues Kennwort festlegen</h2>
    <div class="field">
        <label for="current_password">Bisheriges Kennwort</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        <?php if (isset($errors['current_password'])): ?><p class="field-error"><?= $e($errors['current_password']) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="password">Neues Kennwort <small class="muted">(mind. <?= $e($minimum) ?> Zeichen)</small></label>
            <input type="password" id="password" name="password" autocomplete="new-password" minlength="<?= $e($minimum) ?>" required>
            <?php if (isset($errors['password'])): ?><p class="field-error"><?= $e($errors['password']) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="repeat_password">Neues Kennwort wiederholen</label>
            <input type="password" id="repeat_password" name="repeat_password" autocomplete="new-password" minlength="<?= $e($minimum) ?>" required>
            <?php if (isset($errors['repeat_password'])): ?><p class="field-error"><?= $e($errors['repeat_password']) ?></p><?php endif; ?>
        </div>
    </div>

    <p class="muted">Das Kennwort ist mindestens <?= $e($minimum) ?> Zeichen lang und darf nicht dem
        Anmeldenamen entsprechen.</p>

    <div class="form-actions">
        <button type="submit" class="primary" data-once>
            <?= $icon('check', 'app-icon app-icon--sm') ?> <span data-label>Kennwort speichern</span>
        </button>
    </div>
</form>
