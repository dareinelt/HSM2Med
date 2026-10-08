<?php
/**
 * Uebersicht der erstellten Briefe zur Schrittmacher-/ICD-Abfrage.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, string> $filters
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 */
$query = static fn (array $extra): string => http_build_query(array_filter(
    $extra + $filters,
    static fn ($v): bool => $v !== '' && $v !== null,
));
$hasFilter = array_filter($filters, static fn (string $v): bool => $v !== '') !== [];
?>
<div class="page-head">
    <div>
        <h1><?= $icon('letters', 'app-icon app-icon--lg') ?><span>Briefe zur Schrittmacher-/ICD-Abfrage</span></h1>
        <p class="lead">Jeder Brief ist ein unveränderliches Dokument: Er friert die Fassungen von Anamnese,
            Vormedikation und Epikrise, den zugeordneten Bericht und die Stammdatenfassung ein. Die vollständige
            Abfragetabelle steht als mehrseitiger Anhang am Briefende.</p>
    </div>
    <div class="actions">
        <a class="button primary" href="/letters/new"><?= $icon('mail-new') ?> <span>Brief erstellen</span></a>
        <a class="button" href="/patients"><?= $icon('patients') ?> <span>Patienten</span></a>
    </div>
</div>

<section class="card">
    <form method="get" action="/letters" class="filters">
        <div class="field wide"><label for="q">Suche (Patient)</label><input type="search" id="q" name="q" value="<?= $e($filters['q']) ?>" maxlength="200"></div>
        <div class="field"><label for="patient">Patienten-ID</label><input id="patient" name="patient" value="<?= $e($filters['patient']) ?>" maxlength="200"></div>
        <div class="field"><label for="report">Bericht-Nr.</label><input id="report" name="report" value="<?= $e($filters['report']) ?>" maxlength="200"></div>
        <div class="field buttons"><button type="submit" class="primary"><?= $icon('search') ?> <span data-label>Suchen</span></button><?php if ($hasFilter): ?> <a class="button" href="/letters"><?= $icon('undo') ?> <span>Zurücksetzen</span></a><?php endif; ?></div>
    </form>
</section>

<section class="card">
    <p class="muted"><?= $e($total) ?> Brief(e) gefunden.</p>
    <?php if ($rows === []): ?>
        <p>Noch kein Brief erstellt. <a href="/letters/new">Brief erstellen</a> – Anamnese, Vormedikation und
            Epikrise werden dabei aus der Akte übernommen.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr><th>Nr.</th><th>Erstellt</th><th>Patient</th><th>Geburtsdatum</th><th>Bericht</th><th>Fassung</th><th>Anhang</th><th>PDF</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $l): ?>
                    <tr>
                        <td><?= $e($l['id']) ?></td>
                        <td><?= $e($view::dateTime($l['created_at'])) ?></td>
                        <td><a href="/letters/<?= $e($l['id']) ?>"><?= $e($l['patient_name']) ?></a></td>
                        <td><?= $e($view::dateTime($l['date_of_birth'], true)) ?></td>
                        <td>
                            <?php if (($l['report_id'] ?? null) === null): ?>
                                <span class="muted">ohne Bericht</span>
                            <?php else: ?>
                                <a href="/reports/<?= $e($l['report_id']) ?>">Nr. <?= $e($l['report_id']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($l['letter_version']) ?></td>
                        <td><?= $e(($l['appendix_sections'] ?? 0) === 0 ? 'ohne' : $l['appendix_sections'] . ' Abschnitt(e)') ?></td>
                        <td><small class="muted"><?= $e(number_format(((int) $l['pdf_size']) / 1024, 0, ',', '.')) ?> kB</small></td>
                        <td class="nowrap">
                            <a href="/letters/<?= $e($l['id']) ?>/pdf" target="_blank" rel="noopener">anzeigen</a> ·
                            <a href="/letters/<?= $e($l['id']) ?>/pdf?download=1">herunterladen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten">
                <?php if ($page > 1): ?><a href="/letters?<?= $e($query(['page' => (string) ($page - 1)])) ?>">« zurück</a><?php endif; ?>
                <span>Seite <?= $e($page) ?> von <?= $e($pages) ?></span>
                <?php if ($page < $pages): ?><a href="/letters?<?= $e($query(['page' => (string) ($page + 1)])) ?>">weiter »</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
