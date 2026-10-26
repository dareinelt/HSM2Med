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
 * @var array{username:string,display_name:string,title:string,first_name:string,last_name:string,is_active:bool,must_change_password:bool,groups:list<int>} $values
 * @var list<array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}> $groups
 * @var array{id:int,sha256:string,mime_type:string,filename:string,width:int,height:int,created_at:string}|null $signature
 * @var string|null $signerName
 * @var array<string, string> $errors
 * @var string|null $message
 * @var int $minPasswordLength
 * @var int $currentUserId
 */
use App\User\Permission;

$isNew = $user === null;
$isSelf = !$isNew && $user->id === $currentUserId;
$protectsAdminRole = $isSelf && $user->hasPermission(Permission::USERS);
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

    <div class="field-row">
        <div class="field">
            <label for="title">Titel <small class="muted">(optional)</small></label>
            <input type="text" id="title" name="title" value="<?= $e($values['title']) ?>" maxlength="64" placeholder="z. B. Dr. med.">
            <?php if (isset($errors['title'])): ?><p class="field-error"><?= $e($errors['title']) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="first_name">Vorname</label>
            <input type="text" id="first_name" name="first_name" value="<?= $e($values['first_name']) ?>" maxlength="128">
            <?php if (isset($errors['first_name'])): ?><p class="field-error"><?= $e($errors['first_name']) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="last_name">Nachname</label>
            <input type="text" id="last_name" name="last_name" value="<?= $e($values['last_name']) ?>" maxlength="128">
            <?php if (isset($errors['last_name'])): ?><p class="field-error"><?= $e($errors['last_name']) ?></p><?php endif; ?>
        </div>
    </div>
    <p class="muted">Titel, Vorname und Nachname stehen im Brief immer unter der Unterschrift.
        Fehlen Vor- und Nachname, wird der Anzeigename verwendet.</p>

    <fieldset class="choice-box">
        <legend>Status</legend>
        <label class="check">
            <input type="checkbox" name="is_active" value="1"<?= $values['is_active'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
            <span>Konto ist aktiv
                <?php if ($isSelf): ?><span class="muted">(das eigene Konto lässt sich nicht deaktivieren)</span><?php endif; ?>
            </span>
        </label>
        <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
        <label class="check">
            <input type="checkbox" name="must_change_password" value="1"<?= $values['must_change_password'] ? ' checked' : '' ?>>
            <span>Muss Kennwort beim nächsten Login ändern
                <span class="muted">(empfohlen, wenn das Kennwort hier vergeben wurde)</span>
            </span>
        </label>
        <p class="muted">Ist die Pflicht gesetzt, führt die nächste Anmeldung zuerst zu „Kennwort ändern“;
            bis dahin bleiben alle anderen Bereiche gesperrt. Mit dem eigenen Kennwort entfällt die Pflicht.</p>
        <p class="muted">Deaktivierte Konten können sich nicht anmelden; die Zuordnung früherer
            Vorgänge bleibt erhalten. Konten werden nie gelöscht.</p>
    </fieldset>

    <fieldset class="choice-box">
        <legend>Gruppen</legend>
        <?php if ($groups === []): ?>
            <p class="muted">Es sind noch keine Gruppen eingerichtet.</p>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <?php $locksAdminRole = $protectsAdminRole && in_array(Permission::USERS, $group['permissions'], true); ?>
                <label class="check">
                    <input type="checkbox" name="groups[]" value="<?= $e($group['id']) ?>"
                        <?= ($locksAdminRole || in_array((string) $group['id'], $selected, true)) ? 'checked' : '' ?><?= $locksAdminRole ? ' disabled' : '' ?>>
                    <?php if ($locksAdminRole): ?><input type="hidden" name="groups[]" value="<?= $e($group['id']) ?>"><?php endif; ?>
                    <span>
                        <strong><?= $e($group['label']) ?></strong>
                        <span class="recipient-address muted"><?= $e($group['description']) ?></span>
                        <small class="muted"><?= $e($group['member_count']) ?> Mitglied(er)</small>
                        <?php if ($locksAdminRole): ?>
                            <small class="muted">Die eigene Admin-Rolle lässt sich nicht entfernen.</small>
                        <?php endif; ?>
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
                <?= $view->renderPartial('partials/password_strength', ['for' => 'password', 'minimum' => $minPasswordLength]) ?>
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
    <form method="post" action="/system/users/<?= $e($user->id) ?>/signature" class="card" enctype="multipart/form-data">
        <?= $csrf() ?>
        <h2><?= $icon('edit', 'app-icon app-icon--sm') ?> Unterschrift</h2>
        <p class="muted">JPG, PNG oder BMP. Die Anpassung an die Größe im Brief erfolgt automatisch;
            die Datei wird unverändert aufbewahrt. Bereits erstellte Briefe behalten ihre Unterschrift.</p>
        <?php if (isset($errors['signature'])): ?><p class="field-error"><?= $e($errors['signature']) ?></p><?php endif; ?>
        <?php if ($signature !== null): ?>
            <p class="signature-preview">
                <img src="/system/users/<?= $e($user->id) ?>/signature?v=<?= $e(substr((string) $signature['sha256'], 0, 12)) ?>"
                     alt="Hinterlegte Unterschrift" class="signature-image">
            </p>
            <p class="muted"><?= $e($signature['filename']) ?> ·
                <?= $e($signature['width']) ?> × <?= $e($signature['height']) ?> Pixel ·
                hinterlegt am <?= $e(substr((string) $signature['created_at'], 0, 10)) ?></p>
        <?php else: ?>
            <p class="muted">Es ist noch keine Unterschrift hinterlegt.</p>
        <?php endif; ?>
        <div class="field-row">
            <div class="field">
                <label for="signature"><?= $signature === null ? 'Unterschrift auswählen' : 'Neue Unterschrift auswählen' ?></label>
                <input type="file" id="signature" name="signature" accept="image/png,image/jpeg,image/bmp">
            </div>
        </div>
        <?php if ($signature !== null): ?>
            <label class="check">
                <input type="checkbox" name="remove_signature" value="1">
                <span>Hinterlegte Unterschrift entfernen</span>
            </label>
        <?php endif; ?>
        <p class="muted">Unter der Unterschrift erscheint im Brief immer:
            <strong><?= $e($signerName ?? '') ?></strong>. Der Name stammt aus Titel, Vorname und Nachname.</p>
        <div class="form-actions">
            <button type="submit" data-once>
                <?= $icon('check', 'app-icon app-icon--sm') ?> <span data-label>Unterschrift speichern</span>
            </button>
        </div>
    </form>

    <form method="post" action="/system/users/<?= $e($user->id) ?>/password" class="card" autocomplete="off">
        <?= $csrf() ?>
        <h2><?= $icon('key', 'app-icon app-icon--sm') ?> Kennwort neu setzen</h2>
        <p class="muted">Für den Fall eines vergessenen Kennworts. Die Person muss das Kennwort bei der
            nächsten Anmeldung selbst ersetzen; die Pflicht wird dabei automatisch aufgehoben.</p>
        <div class="field-row">
            <div class="field">
                <label for="new_password">Neues Kennwort <small class="muted">(mind. <?= $e($minPasswordLength) ?> Zeichen)</small></label>
                <input type="password" id="new_password" name="password" autocomplete="new-password" minlength="<?= $e($minPasswordLength) ?>" required>
                <?php if (isset($errors['password'])): ?><p class="field-error"><?= $e($errors['password']) ?></p><?php endif; ?>
                <?= $view->renderPartial('partials/password_strength', ['for' => 'new_password', 'minimum' => $minPasswordLength]) ?>
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
