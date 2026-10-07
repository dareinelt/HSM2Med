<?php
/**
 * Uebersicht der erstellten Patientenausweise.
 *
 * @var Closure $e
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
        <h1>Patientenausweise</h1>
        <p class="lead">Erstellte Ausweise sind unveränderliche Dokumente mit eigenem Datenstand und PDF.</p>
    </div>
    <div class="actions">
        <a class="button primary" href="/patient-cards/new">Patientenausweis erstellen</a>
        <a class="button" href="/patient-cards/settings">Stammdaten</a>
    </div>
</div>

<section class="card">
    <form method="get" action="/patient-cards" class="filters">
        <div class="field wide"><label for="q">Suche (Patient, Dateiname)</label><input type="search" id="q" name="q" value="<?= $e($filters['q']) ?>" maxlength="200"></div>
        <div class="field"><label for="patient">Patient</label><input id="patient" name="patient" value="<?= $e($filters['patient']) ?>" maxlength="200"></div>
        <div class="field"><label for="serial">Geräte-Seriennummer</label><input id="serial" name="serial" value="<?= $e($filters['serial']) ?>" maxlength="200"></div>
        <div class="field buttons"><button type="submit" class="primary">Suchen</button><?php if ($hasFilter): ?> <a class="button" href="/patient-cards">Zurücksetzen</a><?php endif; ?></div>
    </form>
</section>

<section class="card">
    <p class="muted"><?= $e($total) ?> Ausweis(e) gefunden.</p>
    <?php if ($rows === []): ?>
        <p>Noch keine Patientenausweise erstellt.
            <a href="/patient-cards/new">Ausweis aus einem Bericht erstellen</a>.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr><th>Nr.</th><th>Erstellt</th><th>Patient</th><th>Geburtsdatum</th><th>Nachsorge</th><th>Bericht</th><th>Fassung</th><th>PDF</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $c): ?>
                    <tr>
                        <td><?= $e($c['id']) ?></td>
                        <td><?= $e($view::dateTime($c['created_at'])) ?></td>
                        <td><a href="/patient-cards/<?= $e($c['id']) ?>"><?= $e($c['patient_name']) ?></a></td>
                        <td><?= $e($view::dateTime($c['date_of_birth'], true)) ?></td>
                        <td><?= $e($view::dateTime($c['follow_up_date'], true)) ?></td>
                        <td><a href="/reports/<?= $e($c['report_id']) ?>">Nr. <?= $e($c['report_id']) ?></a>
                            <?php if (($c['device_model_name_snapshot'] ?? null) !== null): ?>
                                <br><small class="muted"><?= $e($c['device_model_name_snapshot']) ?> <?= $e($c['device_serial_snapshot'] ?? '') ?></small>
                            <?php endif; ?></td>
                        <td><?= $e($c['card_version']) ?></td>
                        <td><small class="muted"><?= $e(number_format(((int) $c['pdf_size']) / 1024, 0, ',', '.')) ?> kB</small></td>
                        <td class="nowrap">
                            <a href="/patient-cards/<?= $e($c['id']) ?>/pdf" target="_blank" rel="noopener">anzeigen</a> ·
                            <a href="/patient-cards/<?= $e($c['id']) ?>/pdf?download=1">herunterladen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten">
                <?php if ($page > 1): ?><a href="/patient-cards?<?= $e($query(['page' => (string) ($page - 1)])) ?>">« zurück</a><?php endif; ?>
                <span>Seite <?= $e($page) ?> von <?= $e($pages) ?></span>
                <?php if ($page < $pages): ?><a href="/patient-cards?<?= $e($query(['page' => (string) ($page + 1)])) ?>">weiter »</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
