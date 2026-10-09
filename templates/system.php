<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var App\Http\View $view
 * @var array<string, string> $app
 * @var array<string, array{0: string, 1: bool}> $storage
 * @var list<array{0: string, 1: bool}> $extensions
 * @var array<string, mixed> $database
 * @var array<string, int>|null $stats
 * @var Closure(string): bool $permitted
 */
?>
<div class="page-head">
    <div>
        <h1><?= $icon('system', 'app-icon app-icon--lg') ?><span>Systeminformationen</span></h1>
        <p class="lead">Technischer Zustand der Anwendung, Datenbank und Speicherorte.</p>
    </div>
    <div class="actions">
        <?php if ($permitted('/system/settings')): ?>
            <a class="button primary" href="/system/settings"><?= $icon('settings') ?><span>Praxis-Informationen</span></a>
        <?php endif; ?>
        <?php if ($permitted('/system/letter-templates')): ?>
            <a class="button" href="/system/letter-templates" target="_blank" rel="noopener"
               title="Feste Texte und Aufbau der Briefe bearbeiten – öffnet in einem neuen Tab"><?= $icon('edit') ?><span>Briefvorlage bearbeiten</span></a>
        <?php endif; ?>
        <?php if ($permitted('/system/patient-card-templates')): ?>
            <a class="button" href="/system/patient-card-templates" target="_blank" rel="noopener"
               title="Feste Texte und Aufbau der Patientenausweise bearbeiten – öffnet in einem neuen Tab"><?= $icon('edit') ?><span>Ausweisvorlage bearbeiten</span></a>
        <?php endif; ?>
        <a class="button" href="#datenschutz"><?= $icon('shield') ?><span>Datenschutz und Betrieb</span></a>
    </div>
</div>
<div class="grid-2">
    <section class="card">
        <h2><?= $icon('info', 'app-icon app-icon--sm') ?> Anwendung</h2>
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
        <h2><?= $icon('database', 'app-icon app-icon--sm') ?> Datenbank</h2>
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
        <h2><?= $icon('database', 'app-icon app-icon--sm') ?> Speicher</h2>
        <table class="kv">
            <?php foreach ($storage as $label => [$path, $writable]): ?>
                <tr><th><?= $e($label) ?></th><td><code><?= $e($path) ?></code> <span class="<?= $writable ? 'text-ok' : 'text-error' ?>"><?= $writable ? 'beschreibbar' : 'nicht beschreibbar' ?></span></td></tr>
            <?php endforeach; ?>
        </table>
    </section>
</div>
<section class="card" id="datenschutz">
    <h2><?= $icon('shield', 'app-icon app-icon--sm') ?> Datenschutz und Betrieb</h2>
    <ul>
        <li>Alle Daten verbleiben in den lokalen Docker-Volumes (Datenbank, Anwendungsdaten, Import-Archiv).</li>
        <li>Parameter-IDs sind Quellformat-IDs des Merlin-Exports und keine standardisierten Kodierungen.</li>
        <li>Es erfolgt keine medizinische Bewertung der Daten.</li>
    </ul>
</section>
