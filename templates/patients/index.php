<?php
/**
 * Patientenuebersicht der Patientenakte.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, string> $filters
 * @var string $birth
 * @var string|null $filterError
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var int|null $activePatientId
 * @var Closure $query
 */
$hasFilter = $filters['q'] !== '' || $filters['identifier'] !== '' || $birth !== '';
$activePatientId = $activePatientId ?? null;
?>
<div class="page-head">
    <div>
        <h1><?= $icon('patients', 'app-icon app-icon--lg') ?><span>Patienten</span></h1>
        <p class="lead">Patienten können vor dem Import angelegt werden. Anamnese, Vormedikation, Epikrise
            und Notizen werden als versionierte Bausteine am Patienten geführt. Der Patientenvorgang ist
            führend: Import, Patientenausweis und Brief setzen einen aktiven Patienten voraus.</p>
    </div>
    <div class="actions">
        <a class="button primary" href="/patients/new"><?= $icon('user-plus') ?> <span>Patient anlegen</span></a>
        <a class="button" href="/patient-cards"><?= $icon('cards') ?> <span>Patientenausweise</span></a>
    </div>
</div>

<?php if ($activePatientId === null): ?>
    <div class="alert alert-info" role="status">
        <?= $icon('info') ?>
        <span>Kein Patient gewählt. Import, Patientenausweis und Brief sind erst nach Auswahl eines Patienten
            möglich – hier auswählen oder <a href="/patients/new">einen Patienten anlegen</a>.</span>
    </div>
<?php else: ?>
    <div class="alert alert-success" role="status">
        <?= $icon('check') ?>
        <span>Aktiver Patient: Nr. <?= $e($activePatientId) ?>. Import, Patientenausweis und Brief beziehen sich
            auf diesen Patienten.</span>
    </div>
<?php endif; ?>

<section class="card">
    <form method="get" action="/patients" class="filters">
        <div class="field wide">
            <label for="q">Suche (Name)</label>
            <input type="search" id="q" name="q" value="<?= $e($filters['q']) ?>" maxlength="200">
        </div>
        <div class="field">
            <label for="identifier">Patienten-ID</label>
            <input id="identifier" name="identifier" value="<?= $e($filters['identifier']) ?>" maxlength="200">
        </div>
        <div class="field">
            <label for="birth">Geburtsdatum (TT.MM.JJJJ)</label>
            <input id="birth" name="birth" value="<?= $e($birth) ?>" maxlength="32">
        </div>
        <div class="field buttons">
            <button type="submit" class="primary"><?= $icon('search') ?> <span data-label>Suchen</span></button>
            <?php if ($hasFilter): ?> <a class="button" href="/patients"><?= $icon('undo') ?> <span>Zurücksetzen</span></a><?php endif; ?>
        </div>
    </form>
    <?php if ($filterError !== null): ?>
        <p class="field-error" role="alert"><?= $e($filterError) ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <p class="muted"><?= $e($total) ?> Patient(en) gefunden.</p>
    <?php if ($rows === []): ?>
        <p>Kein Patient gefunden. <a href="/patients/new">Patient anlegen</a> – auch ohne Import möglich.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr>
                    <th>Nr.</th><th>Name</th><th>Geburtsdatum</th><th>Patienten-ID</th>
                    <th>Bausteine</th><th>Berichte</th><th>Bausteine zuletzt</th><th>Geändert</th>
                    <th>Aktiver Patient</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $p): $isActive = $activePatientId === (int) $p['id']; ?>
                    <tr<?= $isActive ? ' class="is-active-patient"' : '' ?>>
                        <td><?= $e($p['id']) ?></td>
                        <td><a href="/patients/<?= $e($p['id']) ?>"><?= $e($p['patient_name']) ?></a></td>
                        <td><?= $e($view::dateTime($p['date_of_birth'], true)) ?></td>
                        <td><?= $e($p['patient_identifier'] ?? '') ?></td>
                        <td><?= $e($p['record_count']) ?></td>
                        <td><?= $e($p['report_count']) ?></td>
                        <td><?= $e($p['records_updated_at'] === null ? '' : $view::dateTime((string) $p['records_updated_at'])) ?></td>
                        <td><?= $e($view::dateTime($p['updated_at'])) ?></td>
                        <td class="nowrap">
                            <?php if ($isActive): ?>
                                <span class="badge badge-ok"><?= $icon('check', 'app-icon app-icon--sm') ?> aktiv</span>
                            <?php else: ?>
                                <form method="post" action="/patients/<?= $e($p['id']) ?>/select" class="inline">
                                    <?= $csrf() ?>
                                    <button type="submit" data-once><?= $icon('patients') ?> <span data-label>Auswählen</span></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <a href="/patients/<?= $e($p['id']) ?>">Akte</a> ·
                            <a href="/patients/<?= $e($p['id']) ?>/edit">bearbeiten</a> ·
                            <a href="/patient-cards/patients/<?= $e($p['id']) ?>">Ausweise</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten">
                <?php if ($page > 1): ?><a href="/patients?<?= $e($query(['page' => (string) ($page - 1)])) ?>">« zurück</a><?php endif; ?>
                <span>Seite <?= $e($page) ?> von <?= $e($pages) ?></span>
                <?php if ($page < $pages): ?><a href="/patients?<?= $e($query(['page' => (string) ($page + 1)])) ?>">weiter »</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
