<?php
/**
 * Benutzer anlegen und bearbeiten.
 *
 * Beim Anlegen wird das Kennwort gesetzt; beim Bearbeiten gibt es dafuer einen eigenen
 * Abschnitt, damit ein versehentliches Speichern das Kennwort nicht ueberschreibt.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var ?\App\User\User $user
 * @var array{username:string,display_name:string,is_active:bool,groups:list<int>} $values
 * @var list<array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}> $groups
 * @var array<string, string> $errors
 * @var string|null $message
 * @var int $minPasswordLength
 * @var int $currentUserId
 */
$isNew = $user === null;
$isSelf = !$isNew && $user->id === $currentUserId;
$selected = array_map(static fn (int $id): string => (string) $id, $values['groups']);
?>
<div class="page-head">
    <div>
        <h1><?= $icon($isNew ? 'user-plus' : 'user', 'app-icon app-icon--lg') ?>
            <span><?= $isNew ? 'Benutzer anlegen' : 'Benutzer bearbeiten' ?></span></h1>
        <p class="lead"><?= $isNew
            ? 'Der Anmeldename ist dauerhaft; die Rechte ergeben sich aus den Gruppen.'
            : 'Änderungen an Gruppen wirken sofort bei der nächsten Anmeldung dieser Person.' ?></p>
    </div>
    <div class="actions">
        <a class="button" href="/system/users"><?= $icon('back') ?> <span>Zur Benutzerverwaltung</span></a>
    </div>
</div>

<?php if ($message !== null): ?>
    <p class="alert alert-error" role="alert"><?= $e($message) ?></p>
<?php endif; ?>

<form method="post" action="<?= $isNew ? '/system/users' : '/system/users/' . $e($user->id) ?>" class="card" autocomplete="off">
    <?= $csrf() ?>

    <h2><?= $icon('user', 'app-icon app-icon--sm') ?> Konto</h2>

    <div class="field-row">
        <div class="field">
            <label for="username">Anmeldename</label>
            <input type="text" id="username" name="username" value="<?= $e($values['username']) ?>"
                   maxlength="64" autocapitalize="none" spellcheck="false" required>
            <?php if (isset($errors['username'])): ?><p class="field-error"><?= $e($errors['username']) ?></p><?php endif; ?>
            <p class="muted">Kleinbuchstaben, Ziffern, Punkt, Bindestrich und Unterstrich; 3 bis 64 Zeichen.</p>
        </div>
        <div class="field">
            <label for="display_name">Anzeigename</label>
            <input type="text" id="display_name" name="display_name" value="<?= $e($values['display_name']) ?>" maxlength="128">
            <?php if (isset($errors['display_name'])): ?><p class="field-error"><?= $e($errors['display_name']) ?></p><?php endif; ?>
            <p class="muted">Erscheint in der Statusleiste; ohne Angabe wird der Anmeldename gezeigt.</p>
        </div>
    </div>

    <fieldset class="choice-box">
        <legend>Status</legend>
        <label class="check">
            <input type="checkbox" name="is_active" value="1"<?= $values['is_active'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
            <span>Konto ist aktiv
                <?php if ($isSelf): ?><span class="muted">(das eigene Konto lässt sich nicht deaktivieren)</span><?php endif; ?>
            </span>
        </label>
        <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
        <p class="muted">Deaktivierte Konten können sich nicht anmelden; die Zuordnung früherer
            Vorgänge bleibt erhalten. Konten werden nie gelöscht.</p>
    </fieldset>

    <fieldset class="choice-box">
        <legend>Gruppen</legend>
        <?php if ($groups === []): ?>
            <p class="muted">Es sind noch keine Gruppen eingerichtet.</p>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <label class="check">
                    <input type="checkbox" name="groups[]" value="<?= $e($group['id']) ?>"
                        <?= in_array((string) $group['id'], $selected, true) ? 'checked' : '' ?>>
                    <span>
                        <strong><?= $e($group['label']) ?></strong>
                        <span class="recipient-address muted"><?= $e($group['description']) ?></span>
                        <small class="muted"><?= $e($group['member_count']) ?> Mitglied(er)</small>
                    </span>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (isset($errors['groups'])): ?><p class="field-error"><?= $e($errors['groups']) ?></p><?php endif; ?>
        <p class="muted">Die Rechte einer Person sind die Vereinigung der Rechte ihrer Gruppen.
            Ohne Gruppe hat das Konto keine Rechte.</p>
    </fieldset>

    <?php if ($isNew): ?>
        <div class="field-row">
            <div class="field">
                <label for="password">Kennwort <small class="muted">(mind. <?= $e($minPasswordLength) ?> Zeichen)</small></label>
                <input type="password" id="password" name="password" autocomplete="new-password" minlength="<?= $e($minPasswordLength) ?>" required>
                <?php if (isset($errors['password'])): ?><p class="field-error"><?= $e($errors['password']) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="repeat_password">Kennwort wiederholen</label>
                <input type="password" id="repeat_password" name="repeat_password" autocomplete="new-password" minlength="<?= $e($minPasswordLength) ?>" required>
                <?php if (isset($errors['repeat_password'])): ?><p class="field-error"><?= $e($errors['repeat_password']) ?></p><?php endif; ?>
            </div>
        </div>
        <p class="muted">Das Kennwort wird verschlüsselt (als Hash) gespeichert und kann später nicht
            ausgelesen werden – es lässt sich nur neu setzen.</p>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="primary" data-once>
            <?= $icon('check', 'app-icon app-icon--sm') ?> <span data-label><?= $isNew ? 'Benutzer anlegen' : 'Änderungen speichern' ?></span>
        </button>
        <a class="button" href="/system/users">Abbrechen</a>
    </div>
</form>

<?php if (!$isNew): ?>
    <form method="post" action="/system/users/<?= $e($user->id) ?>/password" class="card" autocomplete="off">
        <?= $csrf() ?>
        <h2><?= $icon('key', 'app-icon app-icon--sm') ?> Kennwort neu setzen</h2>
        <p class="muted">Für den Fall eines vergessenen Kennworts. Die betroffene Person sollte das
            Kennwort danach unter „Kennwort ändern“ selbst ersetzen.</p>
        <div class="field-row">
            <div class="field">
                <label for="new_password">Neues Kennwort <small class="muted">(mind. <?= $e($minPasswordLength) ?> Zeichen)</small></label>
                <input type="password" id="new_password" name="password" autocomplete="new-password" minlength="<?= $e($minPasswordLength) ?>" required>
                <?php if (isset($errors['password'])): ?><p class="field-error"><?= $e($errors['password']) ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label for="new_repeat_password">Kennwort wiederholen</label>
                <input type="password" id="new_repeat_password" name="repeat_password" autocomplete="new-password" minlength="<?= $e($minPasswordLength) ?>" required>
                <?php if (isset($errors['repeat_password'])): ?><p class="field-error"><?= $e($errors['repeat_password']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" data-once>
                <?= $icon('key', 'app-icon app-icon--sm') ?> <span data-label>Kennwort setzen</span>
            </button>
        </div>
    </form>
<?php endif; ?>
