<?php
/**
 * Fehlerprotokoll: Eintraege des Anwendungsprotokolls, neueste zuerst, durchsuchbar nach Referenz.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var array{ref: string, level: string, q: string} $filters
 * @var bool $invalidRef
 * @var bool $available
 * @var ?string $logFile
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var bool $truncated
 * @var int $page
 * @var int $pages
 */
$levels = App\Support\LogReader::LEVELS;
$query = static fn (array $extra): string => http_build_query(array_filter(
    $extra + $filters,
    static fn ($v): bool => $v !== '' && $v !== null,
));
$hasFilter = array_filter($filters, static fn (string $v): bool => $v !== '') !== [];
$openDetails = $filters['ref'] !== '' && count($rows) === 1;
$time = static function (string $value): string {
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('d.m.Y H:i:s', $timestamp);
};
?>
<div class="page-head">
    <div>
        <h1><?= $icon('warning', 'app-icon app-icon--lg') ?><span>Fehlerprotokoll</span></h1>
        <p class="lead">Technische Meldungen der Anwendung, neueste zuerst. Die Referenz von einer Fehlerseite
            führt direkt zum passenden Eintrag mit allen Details.</p>
    </div>
    <div class="actions">
        <a class="button" href="/system"><?= $icon('system') ?> <span>Systeminformationen</span></a>
    </div>
</div>

<section class="card">
    <form method="get" action="/system/logs" class="filters">
        <div class="field"><label for="ref">Referenz</label><input id="ref" name="ref" value="<?= $e($filters['ref']) ?>" maxlength="64" placeholder="z. B. 279e68e8f457" autocomplete="off"></div>
        <div class="field"><label for="level">Stufe</label>
            <select id="level" name="level">
                <option value="">alle</option>
                <?php foreach ($levels as $value => $label): ?>
                    <option value="<?= $e($value) ?>"<?= $filters['level'] === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field wide"><label for="q">Suche (Meldung, Pfad, Ausnahme)</label><input type="search" id="q" name="q" value="<?= $e($filters['q']) ?>" maxlength="200"></div>
        <div class="field buttons"><button type="submit" class="primary"><?= $icon('search') ?> <span data-label>Suchen</span></button><?php if ($hasFilter): ?> <a class="button" href="/system/logs"><?= $icon('undo') ?> <span>Zurücksetzen</span></a><?php endif; ?></div>
    </form>
    <?php if ($invalidRef): ?>
        <div class="alert alert-error" role="alert">Eine Referenz besteht aus bis zu 12 Zeichen 0–9 und a–f.</div>
    <?php endif; ?>
</section>

<section class="card">
    <?php if (!$available): ?>
        <p>Es liegt noch kein Protokoll vor<?= $logFile === null ? ' (Protokollverzeichnis fehlt)' : '' ?>.</p>
    <?php else: ?>
        <p class="muted"><?= $e($total) ?> Eintrag/Einträge gefunden.<?= $truncated ? ' Durchsucht werden die neuesten ' . $e((int) (App\Support\LogReader::MAX_BYTES / 1048576)) . ' MiB des Protokolls.' : '' ?></p>
        <?php if ($rows === [] && $filters['ref'] !== '' && !$invalidRef): ?>
            <p>Zur Referenz <code><?= $e($filters['ref']) ?></code> wurde kein Eintrag gefunden.</p>
        <?php endif; ?>
        <?php if ($rows !== []): ?>
            <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Zeitpunkt</th><th>Stufe</th><th>Referenz</th><th>Meldung</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr class="log-entry">
                        <td><?= $e($time((string) $row['time'])) ?></td>
                        <td><span class="badge level-<?= $e($row['level']) ?>"><?= $e($levels[$row['level']] ?? $row['level']) ?></span></td>
                        <td><a href="/system/logs?<?= $e(http_build_query(['ref' => $row['ref']])) ?>"><code><?= $e($row['ref']) ?></code></a></td>
                        <td class="break">
                            <?= $e($row['message']) ?>
                            <?php if (isset($row['context']['path'])): ?><br><small class="muted">Pfad: <?= $e($row['context']['path']) ?></small><?php endif; ?>
                            <?php if ($row['exception'] !== null): ?>
                                <br><strong><?= $e($row['exception']['class']) ?>:</strong> <?= $e($row['exception']['message']) ?>
                                <details<?= $openDetails ? ' open' : '' ?>>
                                    <summary>Technische Details</summary>
                                    <p><small class="muted">Ort: <?= $e($row['exception']['file']) ?></small></p>
                                    <?php if ($row['context'] !== []): ?>
                                        <p><small class="muted">Kontext: <?php foreach ($row['context'] as $key => $value): ?><?= $e($key) ?>=<?= $e($value) ?> <?php endforeach; ?></small></p>
                                    <?php endif; ?>
                                    <pre class="raw"><?= $e($row['exception']['trace']) ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="pagination" aria-label="Seiten">
                    <?php if ($page > 1): ?><a href="/system/logs?<?= $e($query(['page' => (string) ($page - 1)])) ?>">« zurück</a><?php endif; ?>
                    <span>Seite <?= $e($page) ?> von <?= $e($pages) ?></span>
                    <?php if ($page < $pages): ?><a href="/system/logs?<?= $e($query(['page' => (string) ($page + 1)])) ?>">weiter »</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
