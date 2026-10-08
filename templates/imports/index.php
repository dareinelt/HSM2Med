<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var ?string $status
 */
$statuses = ['completed', 'completed_with_warnings', 'completed_with_errors', 'failed'];
$link = static fn (array $params): string => '/imports' . (($q = http_build_query(array_filter($params, static fn ($v): bool => $v !== null && $v !== ''))) !== '' ? '?' . $q : '');
?>
<div class="page-head">
    <div>
        <h1><?= $icon('log', 'app-icon app-icon--lg') ?><span>Importprotokoll</span></h1>
        <p class="lead">Jede eingelesene Datei mit Ergebnis, Fehlern und Warnungen.</p>
    </div>
    <div class="actions">
        <a class="button primary" href="/import"><?= $icon('import') ?><span>Neue Datei importieren</span></a>
    </div>
</div>
<section class="card">
    <h2><?= $icon('filter', 'app-icon app-icon--sm') ?> Nach Status filtern</h2>
    <p class="filters-inline">Status:
        <a href="/imports"<?= $status === null ? ' class="active"' : '' ?>>alle</a>
        <?php foreach ($statuses as $s): ?>
            · <a href="<?= $e($link(['status' => $s])) ?>"<?= $status === $s ? ' class="active"' : '' ?>><?= $e(App\Report\PdfGenerator::statusLabel($s)) ?></a>
        <?php endforeach; ?>
    </p>
    <p class="muted"><?= $e($total) ?> Import(e).</p>
    <?php if ($rows !== []): ?>
        <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Nr.</th><th>Importiert</th><th>Datei</th><th>Größe</th><th>Datensätze</th><th>Fehler</th><th>Warnungen</th><th>Status</th><th>Bericht</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="/imports/<?= $e($row['id']) ?>"><?= $e($row['id']) ?></a></td>
                    <td><?= $e($view::dateTime($row['imported_at'])) ?></td>
                    <td class="break"><?= $e($row['filename']) ?></td>
                    <td><?= $e(App\Security\UploadValidator::formatBytes((int) $row['file_size'])) ?></td>
                    <td><?= $e($row['record_count']) ?></td>
                    <td><?= $e($row['error_count']) ?></td>
                    <td><?= $e($row['warning_count']) ?></td>
                    <td><span class="badge status-<?= $e($row['status']) ?>"><?= $e(App\Report\PdfGenerator::statusLabel($row['status'])) ?></span></td>
                    <td><?php if ($row['report_id'] !== null): ?><a href="/reports/<?= $e($row['report_id']) ?>">Nr. <?= $e($row['report_id']) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Seiten">
                <?php if ($page > 1): ?><a href="<?= $e($link(['status' => $status, 'page' => (string) ($page - 1)])) ?>">« zurück</a><?php endif; ?>
                <span>Seite <?= $e($page) ?> von <?= $e($pages) ?></span>
                <?php if ($page < $pages): ?><a href="<?= $e($link(['status' => $status, 'page' => (string) ($page + 1)])) ?>">weiter »</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
