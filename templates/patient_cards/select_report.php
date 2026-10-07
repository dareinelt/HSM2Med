<?php
/**
 * Berichtsauswahl: Grundlage eines Patientenausweises ist immer ein importierter Bericht.
 *
 * @var Closure $e
 * @var App\Http\View $view
 * @var array<string, string> $filters
 * @var list<array<string, mixed>> $rows
 * @var array<int, array<string, mixed>> $cards
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
        <h1>Patientenausweis erstellen</h1>
        <p class="lead">Schritt 1: den Bericht auswählen, dessen Daten in den Ausweis übernommen werden.</p>
    </div>
    <div class="actions">
        <a class="button" href="/patient-cards">Erstellte Ausweise</a>
        <a class="button" href="/patient-cards/settings">Stammdaten (Logo, Zentrum, Hinweise)</a>
    </div>
</div>

<section class="card">
    <form method="get" action="/patient-cards/new" class="filters">
        <div class="field wide"><label for="q">Suche (alle Felder)</label><input type="search" id="q" name="q" value="<?= $e($filters['q']) ?>" maxlength="200"></div>
        <div class="field"><label for="patient">Patient</label><input id="patient" name="patient" value="<?= $e($filters['patient']) ?>" maxlength="200"></div>
        <div class="field"><label for="patient_id">Patient-ID</label><input id="patient_id" name="patient_id" value="<?= $e($filters['patient_id']) ?>" maxlength="200"></div>
        <div class="field"><label for="serial">Geräte-Seriennummer</label><input id="serial" name="serial" value="<?= $e($filters['serial']) ?>" maxlength="200"></div>
        <div class="field"><label for="model">Modell</label><input id="model" name="model" value="<?= $e($filters['model']) ?>" maxlength="200"></div>
        <div class="field"><label for="filename">Importdatei</label><input id="filename" name="filename" value="<?= $e($filters['filename']) ?>" maxlength="200"></div>
        <div class="field buttons"><button type="submit" class="primary">Suchen</button><?php if ($hasFilter): ?> <a class="button" href="/patient-cards/new">Zurücksetzen</a><?php endif; ?></div>
    </form>
</section>

<section class="card">
    <p class="muted"><?= $e($total) ?> Bericht(e) gefunden. Existiert zu einem Bericht bereits ein Ausweis, wird ein
        neuer Ausweis als weitere Fassung gespeichert; ältere Ausweise bleiben unverändert.</p>
    <?php if ($rows === []): ?>
        <p>Keine Berichte gefunden. Bitte zuerst einen Merlin-Bericht <a href="/import">importieren</a>.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr><th>Nr.</th><th>Datum</th><th>Patient</th><th>Geburtsdatum</th><th>Gerät</th><th>Seriennummer</th><th>Ausweise</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): $card = $cards[(int) $r['id']] ?? null; ?>
                    <tr>
                        <td><?= $e($r['id']) ?></td>
                        <td><?= $e($r['session_timestamp'] !== null
                            ? $view::dateTime($r['session_timestamp'], true)
                            : $view::dateTime($r['created_at'], true) . ' (Import)') ?></td>
                        <td><?= $e($r['patient_name_snapshot']) ?><?php if ($r['patient_identifier_snapshot'] !== null): ?><br><small class="muted">ID <?= $e($r['patient_identifier_snapshot']) ?></small><?php endif; ?></td>
                        <td><?= $e($r['patient_dob_snapshot']) ?></td>
                        <td><?= $e($r['device_model_name_snapshot']) ?><?php if ($r['device_model_number_snapshot'] !== null): ?><br><small class="muted"><?= $e($r['device_model_number_snapshot']) ?></small><?php endif; ?></td>
                        <td><?= $e($r['device_serial_snapshot']) ?></td>
                        <td>
                            <?php if ($card === null): ?>
                                <span class="muted">keiner</span>
                            <?php else: ?>
                                <a href="/patient-cards/<?= $e($card['id']) ?>">Nr. <?= $e($card['id']) ?></a>
                                <small class="muted">(Fassung <?= $e($card['card_version']) ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><a class="button primary" href="/patient-cards/reports/<?= $e($r['id']) ?>">Ausweis erstellen</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten">
                <?php if ($page > 1): ?><a href="/patient-cards/new?<?= $e($query(['page' => (string) ($page - 1)])) ?>">« zurück</a><?php endif; ?>
                <span>Seite <?= $e($page) ?> von <?= $e($pages) ?></span>
                <?php if ($page < $pages): ?><a href="/patient-cards/new?<?= $e($query(['page' => (string) ($page + 1)])) ?>">weiter »</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
