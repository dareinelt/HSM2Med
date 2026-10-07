<?php
/**
 * @var Closure $e
 * @var App\Http\View $view
 * @var array<string, string> $app
 * @var array<string, array{0: string, 1: bool}> $storage
 * @var list<array{0: string, 1: bool}> $extensions
 * @var array<string, mixed> $database
 * @var array<string, int>|null $stats
 */
?>
<h1>Systeminformationen</h1>
<div class="grid-2">
    <section class="card">
        <h2>Anwendung</h2>
        <table class="kv">
            <?php foreach ($app as $label => $value): ?>
                <tr><th><?= $e($label) ?></th><td><?= $e($value) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($extensions as [$ext, $loaded]): ?>
                <tr><th>PHP-Erweiterung <?= $e($ext) ?></th><td class="<?= $loaded ? 'text-ok' : 'text-error' ?>"><?= $loaded ? 'geladen' : 'fehlt' ?></td></tr>
            <?php endforeach; ?>
        </table>
    </section>
    <section class="card">
        <h2>Datenbank</h2>
        <table class="kv">
            <tr><th>Verbindung</th><td class="<?= $database['ok'] ? 'text-ok' : 'text-error' ?>"><?= $database['ok'] ? 'verbunden' : 'nicht erreichbar' . (isset($database['reference']) ? ' (Ref. ' . $e($database['reference']) . ')' : '') ?></td></tr>
            <tr><th>MySQL-Version</th><td><?= $e($database['version'] ?? '–') ?></td></tr>
            <tr><th>Migrationen</th><td>
                <?php foreach ($database['migrations'] as $migration): ?>
                    <?= $e($migration['version']) ?> <small class="muted">(<?= $e($view::dateTime($migration['applied_at'])) ?>)</small><br>
                <?php endforeach; ?>
                <?php if ($database['pending'] !== []): ?><span class="text-error">Ausstehend: <?= $e(implode(', ', $database['pending'])) ?></span><?php endif; ?>
            </td></tr>
            <?php if ($stats !== null): ?>
                <tr><th>Patienten / Geräte</th><td><?= $e($stats['patients']) ?> / <?= $e($stats['devices']) ?></td></tr>
                <tr><th>Berichte / Parameter</th><td><?= $e($stats['reports']) ?> / <?= $e($stats['parameters']) ?></td></tr>
                <tr><th>Importe (fehlgeschlagen)</th><td><?= $e($stats['imports']) ?> (<?= $e($stats['failed_imports']) ?>)</td></tr>
            <?php endif; ?>
        </table>
        <h2>Speicher</h2>
        <table class="kv">
            <?php foreach ($storage as $label => [$path, $writable]): ?>
                <tr><th><?= $e($label) ?></th><td><code><?= $e($path) ?></code> <span class="<?= $writable ? 'text-ok' : 'text-error' ?>"><?= $writable ? 'beschreibbar' : 'nicht beschreibbar' ?></span></td></tr>
            <?php endforeach; ?>
        </table>
    </section>
</div>
<section class="card">
    <h2>Datenschutz und Betrieb</h2>
    <ul>
        <li>Die Anwendung arbeitet vollständig offline; es werden keine externen Ressourcen (CDN, Schriften, Telemetrie) geladen.</li>
        <li>Alle Daten verbleiben in den lokalen Docker-Volumes (Datenbank, Anwendungsdaten, Import-Archiv).</li>
        <li>Parameter-IDs sind Quellformat-IDs des Merlin-Exports und keine standardisierten Kodierungen.</li>
        <li>Es erfolgt keine medizinische Bewertung der Daten.</li>
    </ul>
</section>
