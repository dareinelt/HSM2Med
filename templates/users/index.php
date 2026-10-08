<?php
/**
 * Benutzerverwaltung: Konten und Gruppen.
 *
 * Konten werden nie geloescht, nur deaktiviert – so bleiben Zuweisungen und Protokolle
 * nachvollziehbar. Gruppen tragen die Rechte (Berechtigungsmatrix).
 *
 * @var Closure $e
 * @var Closure $icon
 * @var list<\App\User\User> $users
 * @var list<array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}> $groups
 * @var int $currentUserId
 * @var bool $passwordIsDefault
 * @var int $idleMinutes
 * @var \App\Http\View $view
 */
$active = 0;
foreach ($users as $account) {
    if ($account->isActive) {
        $active++;
    }
}
?>
<div class="page-head">
    <div>
        <h1><?= $icon('users', 'app-icon app-icon--lg') ?><span>Benutzerverwaltung</span></h1>
        <p class="lead">Konten anlegen, Gruppen zuordnen und die Rechte der Gruppen pflegen.
            Der Zugriff auf die Bereiche der Anwendung folgt ausschließlich diesen Rechten.</p>
    </div>
    <div class="actions">
        <a class="button" href="/system/users/groups"><?= $icon('shield') ?> <span>Gruppen und Rechte</span></a>
        <a class="button primary" href="/system/users/new"><?= $icon('user-plus') ?> <span>Benutzer anlegen</span></a>
    </div>
</div>

<?php if ($passwordIsDefault): ?>
    <p class="alert alert-warning" role="alert">
        <?= $icon('warning', 'app-icon app-icon--sm') ?>
        <span>Das Kennwort des Administrators ist noch der Vorgabewert aus <code>.env</code>.
            Bitte unter <a href="/account/password">Kennwort ändern</a> ein eigenes Kennwort setzen und
            den Wert in der <code>.env</code> entfernen.</span>
    </p>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <span class="stat-value"><?= $e(count($users)) ?></span>
        <span class="stat-label">Benutzerkonten</span>
    </div>
    <div class="stat">
        <span class="stat-value"><?= $e($active) ?></span>
        <span class="stat-label">davon aktiv</span>
    </div>
    <div class="stat">
        <span class="stat-value"><?= $e(count($groups)) ?></span>
        <span class="stat-label">Gruppen</span>
    </div>
    <div class="stat">
        <span class="stat-value"><?= $e($idleMinutes) ?></span>
        <span class="stat-label">Minuten bis zur Abmeldung</span>
    </div>
</div>

<section class="card">
    <h2><?= $icon('user', 'app-icon app-icon--sm') ?> Konten</h2>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th class="col-id">ID</th>
                    <th>Benutzername</th>
                    <th>Anzeigename</th>
                    <th>Gruppen</th>
                    <th>Status</th>
                    <th>Letzte Anmeldung</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $account): ?>
                <tr<?= $account->isActive ? '' : ' class="row-warning"' ?>>
                    <td class="col-id"><?= $e($account->id) ?></td>
                    <td>
                        <strong><?= $e($account->username) ?></strong>
                        <?php if ($account->id === $currentUserId): ?><span class="badge">eigenes Konto</span><?php endif; ?>
                    </td>
                    <td><?= $account->displayName === '' ? '<span class="muted">–</span>' : $e($account->displayName) ?></td>
                    <td>
                        <?php if ($account->groups === []): ?>
                            <span class="muted">keine Gruppe</span>
                        <?php else: ?>
                            <ul class="chips">
                                <?php foreach ($account->groups as $group): ?>
                                    <li><?= $e($group['label']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($account->isActive): ?>
                            <span class="badge status-completed">aktiv</span>
                        <?php else: ?>
                            <span class="badge level-warning">deaktiviert</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $account->lastLoginAt === null ? '<span class="muted">noch nie</span>' : $e($view::dateTime($account->lastLoginAt)) ?></td>
                    <td class="nowrap">
                        <a class="button" href="/system/users/<?= $e($account->id) ?>/edit"><?= $icon('edit') ?> <span>Bearbeiten</span></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Kennwörter werden ausschließlich als Hash gespeichert und sind auch hier nicht
        lesbar. Ein vergessenes Kennwort setzt die Administration unter „Bearbeiten“ neu.</p>
</section>

<section class="card">
    <h2><?= $icon('shield', 'app-icon app-icon--sm') ?> Gruppen</h2>
    <table class="kv">
        <?php foreach ($groups as $group): ?>
            <tr>
                <th>
                    <?= $e($group['label']) ?>
                    <?php if ($group['is_system']): ?><span class="badge">Systemgruppe</span><?php endif; ?>
                </th>
                <td>
                    <?= $e($group['description']) ?>
                    <div class="muted"><?= $e($group['member_count']) ?> Mitglied(er) · Kennung <code><?= $e($group['code']) ?></code></div>
                    <div class="actions">
                        <a class="button" href="/system/users/groups/<?= $e($group['id']) ?>"><?= $icon('settings') ?> <span>Rechte bearbeiten</span></a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <div class="form-actions">
        <a class="button" href="/system/users/groups"><?= $icon('plus') ?> <span>Gruppe anlegen</span></a>
    </div>
</section>
