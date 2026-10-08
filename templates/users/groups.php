<?php
/**
 * Gruppen und ihre Rechte: Uebersicht und Anlegen einer neuen Gruppe.
 *
 * Gruppen tragen die Rechte; Benutzer werden Gruppen zugeordnet. Systemgruppen (Admin, MFA,
 * Arzt) lassen sich umbenennen und in ihren Rechten aendern, aber nicht loeschen – sie sind
 * Teil der Aufbauorganisation der Praxis.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var list<array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}> $groups
 * @var array<string, string> $catalog
 * @var array<string, string> $errors
 * @var string|null $message
 * @var array{code:string,label:string,description:string} $values
 */
?>
<div class="page-head">
    <div>
        <h1><?= $icon('shield', 'app-icon app-icon--lg') ?><span>Gruppen und Rechte</span></h1>
        <p class="lead">Jede Gruppe beschreibt eine Aufgabe in der Praxis. Die Rechte einer Person
            sind die Vereinigung der Rechte ihrer Gruppen; fehlt ein Recht, ist der Bereich gesperrt.</p>
    </div>
    <div class="actions">
        <a class="button" href="/system/users"><?= $icon('users') ?> <span>Benutzerverwaltung</span></a>
    </div>
</div>

<?php if ($message !== null): ?>
    <p class="alert alert-error" role="alert"><?= $e($message) ?></p>
<?php endif; ?>

<section class="card">
    <h2><?= $icon('list', 'app-icon app-icon--sm') ?> Vorhandene Gruppen</h2>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th class="col-id">ID</th>
                    <th>Gruppe</th>
                    <th>Beschreibung</th>
                    <th class="num">Rechte</th>
                    <th class="num">Mitglieder</th>
                    <th>Art</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $group): ?>
                <tr>
                    <td class="col-id"><?= $e($group['id']) ?></td>
                    <td>
                        <strong><?= $e($group['label']) ?></strong>
                        <div class="muted"><code><?= $e($group['code']) ?></code></div>
                    </td>
                    <td><?= $e($group['description']) ?></td>
                    <td class="num"><?= $e(count($group['permissions'])) ?> von <?= $e(count($catalog)) ?></td>
                    <td class="num"><?= $e($group['member_count']) ?></td>
                    <td><?= $group['is_system'] ? '<span class="badge">Systemgruppe</span>' : '<span class="badge">eigene Gruppe</span>' ?></td>
                    <td class="nowrap">
                        <a class="button" href="/system/users/groups/<?= $e($group['id']) ?>"><?= $icon('edit') ?> <span>Rechte</span></a>
                        <?php if (!$group['is_system']): ?>
                            <form method="post" action="/system/users/groups/<?= $e($group['id']) ?>/delete" class="inline">
                                <?= $csrf() ?>
                                <button type="submit" data-confirm="Gruppe „<?= $e($group['label']) ?>“ wirklich löschen? Personen in dieser Gruppe verlieren die zugehörigen Rechte.">
                                    <?= $icon('close') ?> <span>Löschen</span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<form method="post" action="/system/users/groups" class="card" autocomplete="off">
    <?= $csrf() ?>
    <h2><?= $icon('plus', 'app-icon app-icon--sm') ?> Gruppe anlegen</h2>
    <p class="muted">Für zusätzliche Aufgaben, zum Beispiel eine Ausbildungsgruppe mit eingeschränktem
        Zugriff. Die Rechte werden nach dem Anlegen vergeben.</p>

    <div class="field-row">
        <div class="field">
            <label for="label">Bezeichnung</label>
            <input type="text" id="label" name="label" value="<?= $e($values['label']) ?>" maxlength="64" required>
            <?php if (isset($errors['label'])): ?><p class="field-error"><?= $e($errors['label']) ?></p><?php endif; ?>
            <p class="muted">Erscheint bei der Zuordnung und in der Statusleiste.</p>
        </div>
        <div class="field">
            <label for="code">Kennung <small class="muted">(optional)</small></label>
            <input type="text" id="code" name="code" value="<?= $e($values['code']) ?>" maxlength="32" autocapitalize="none" spellcheck="false">
            <?php if (isset($errors['code'])): ?><p class="field-error"><?= $e($errors['code']) ?></p><?php endif; ?>
            <p class="muted">Kurzname für die Datenbank (Kleinbuchstaben). Leer lassen genügt.</p>
        </div>
    </div>

    <div class="field wide">
        <label for="description">Beschreibung</label>
        <textarea id="description" name="description" rows="2" maxlength="255"><?= $e($values['description']) ?></textarea>
        <?php if (isset($errors['description'])): ?><p class="field-error"><?= $e($errors['description']) ?></p><?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="primary" data-once>
            <?= $icon('plus', 'app-icon app-icon--sm') ?> <span data-label>Gruppe anlegen</span>
        </button>
        <a class="button" href="/system/users">Abbrechen</a>
    </div>
</form>
