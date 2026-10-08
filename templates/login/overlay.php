<?php
/**
 * Anmeldefenster als Overlay ueber der geladenen Oberflaeche.
 *
 * Wird nur gerendert, wenn niemand angemeldet ist. Das Fenster ist das einzige bedienbare
 * Element; die Oberflaeche dahinter ist gesperrt (inert) und wird vom Stylesheet gedimmt und
 * unscharf gestellt. Kein Inline-Stylesheet und kein Inline-Skript (CSP).
 *
 * @var Closure $e
 * @var Closure $icon
 * @var ?string $error Meldung der letzten Anmeldung (null = keine)
 * @var string $username Zuletzt angegebener Anmeldename
 * @var string $target Ziel nach erfolgreicher Anmeldung
 */
$error = $error ?? null;
$username = $username ?? '';
$target = $target ?? '/';
?>
<div class="login-overlay">
    <form class="login-card" method="post" action="/login">
        <?= $csrf() ?>
        <input type="hidden" name="target" value="<?= $e($target) ?>">

        <div class="login-card__head">
            <span class="login-card__mark"><?= $icon('pulse') ?></span>
            <div>
                <h1 id="login-title" class="login-card__title">Anmeldung</h1>
                <p class="login-card__subtitle"><?= $e(\App\Config\Config::APP_NAME) ?> – Merlin-Auslesedaten</p>
            </div>
        </div>

        <p class="login-card__hint">Diese Anwendung enthält Gesundheitsdaten. Bitte mit dem eigenen
            Benutzerkonto anmelden.</p>

        <?php if ($error !== null && $error !== ''): ?>
            <p class="alert alert-error" role="alert">
                <?= $icon('warning', 'app-icon app-icon--sm') ?> <span><?= $e($error) ?></span>
            </p>
        <?php endif; ?>

        <div class="field">
            <label for="login-username">Benutzername</label>
            <input type="text" id="login-username" name="username" value="<?= $e($username) ?>"
                   autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>

        <div class="field">
            <label for="login-password">Kennwort</label>
            <input type="password" id="login-password" name="password" autocomplete="current-password" required>
        </div>

        <div class="form-actions login-card__actions">
            <button type="submit" class="primary" data-once>
                <?= $icon('lock', 'app-icon app-icon--sm') ?> <span data-label>Anmelden</span>
            </button>
        </div>

        <p class="login-card__note">
            Nach mehreren Fehlanmeldungen wird das Konto vorübergehend gesperrt. Ohne Benutzerkonto
            bitte an die Administration wenden.
        </p>
    </form>
</div>
