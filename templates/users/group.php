<?php
/**
 * Rechtematrix einer Gruppe.
 *
 * Ein Haken je Bereich. Fehlt der Haken, ist der Bereich fuer alle Personen dieser Gruppe
 * gesperrt – auch wenn die Adresse direkt aufgerufen wird (die Pruefung erfolgt im Kernel,
 * nicht in der Oberflaeche).
 *
 * @var Closure $e
 * @var Closure $icon
 * @var array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>} $group
 * @var array<string, string> $catalog
 * @var array<string, string> $errors
 * @var string|null $message
 * @var array{label:string,description:string,permissions:list<string>} $values
 */
use App\User\Permission;

$selected = $values['permissions'];
$missingUsersRight = !in_array(Permission::USERS, $selected, true);
?>
<div class="page-head">
    <div>
        <h1><?= $icon('shield', 'app-icon app-icon--lg') ?><span>Gruppe: <?= $e($values['label']) ?></span></h1>
        <p class="lead">Rechte dieser Gruppe festlegen. Änderungen gelten sofort für alle
            <?= $e($group['member_count']) ?> Mitglied(er) – spätestens bei deren nächstem Aufruf.</p>
    </div>
    <div class="actions">
        <a class="button" href="/system/users/groups"><?= $icon('back') ?> <span>Zu den Gruppen</span></a>
        <a class="button" href="/system/users"><?= $icon('users') ?> <span>Benutzerverwaltung</span></a>
    </div>
</div>

<?php if ($message !== null): ?>
    <p class="alert alert-error" role="alert"><?= $e($message) ?></p>
<?php endif; ?>

<form method="post" action="/system/users/groups/<?= $e($group['id']) ?>" class="card" autocomplete="off">
    <?= $csrf() ?>

    <h2><?= $icon('edit', 'app-icon app-icon--sm') ?> Bezeichnung</h2>
    <div class="field-row">
        <div class="field">
            <label for="label">Bezeichnung</label>
            <input type="text" id="label" name="label" value="<?= $e($values['label']) ?>" maxlength="64" required>
            <?php if (isset($errors['label'])): ?><p class="field-error"><?= $e($errors['label']) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label>Kennung</label>
            <input type="text" value="<?= $e($group['code']) ?>" disabled>
            <p class="muted"><?= $group['is_system']
                ? 'Systemgruppe – die Kennung ist fest und die Gruppe lässt sich nicht löschen.'
                : 'Kennung der Gruppe in der Datenbank.' ?></p>
        </div>
    </div>
    <div class="field wide">
        <label for="description">Beschreibung</label>
        <textarea id="description" name="description" rows="2" maxlength="255"><?= $e($values['description']) ?></textarea>
        <?php if (isset($errors['description'])): ?><p class="field-error"><?= $e($errors['description']) ?></p><?php endif; ?>
    </div>

    <h2><?= $icon('shield', 'app-icon app-icon--sm') ?> Rechte</h2>
    <p class="muted">Nicht angehakte Bereiche sind für diese Gruppe gesperrt.</p>
    <div class="actions matrix-actions">
        <button type="button" data-matrix-all><?= $icon('check') ?> <span>Alle auswählen</span></button>
        <button type="button" data-matrix-none><?= $icon('close') ?> <span>Auswahl aufheben</span></button>
    </div>
    <div class="table-scroll">
        <table class="table matrix" data-matrix>
            <thead>
                <tr><th>Bereich</th><th>Zugriff</th><th>Beschreibung</th></tr>
            </thead>
            <tbody>
            <?php foreach ($catalog as $permission => $label): ?>
                <tr>
                    <td><label class="checkbox" for="perm-<?= $e($permission) ?>"><strong><?= $e($label) ?></strong></label></td>
                    <td>
                        <label class="checkbox" for="perm-<?= $e($permission) ?>">
                            <input type="checkbox" id="perm-<?= $e($permission) ?>" name="permissions[]"
                                   value="<?= $e($permission) ?>"<?= in_array($permission, $selected, true) ? ' checked' : '' ?>>
                            <span>erlaubt</span>
                        </label>
                    </td>
                    <td class="muted"><?= $e(Permission::description($permission)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (isset($errors['groups'])): ?><p class="field-error"><?= $e($errors['groups']) ?></p><?php endif; ?>
    <?php if (isset($errors['permissions'])): ?><p class="field-error"><?= $e($errors['permissions']) ?></p><?php endif; ?>

    <?php if ($missingUsersRight): ?>
        <p class="alert alert-warning" role="alert">
            <?= $icon('warning', 'app-icon app-icon--sm') ?>
            <span>Achtung: Ohne das Recht „Benutzerverwaltung“ kann niemand mehr Konten oder Gruppen
                pflegen. Die Anwendung weigert sich, das letzte Konto mit diesem Recht zu entziehen.</span>
        </p>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="primary" data-once>
            <?= $icon('check', 'app-icon app-icon--sm') ?> <span data-label>Rechte speichern</span>
        </button>
        <a class="button" href="/system/users/groups">Abbrechen</a>
    </div>
</form>
