<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var Closure $csrf
 * @var int $maxBytes
 * @var ?string $error
 */
?>
<div class="page-head">
    <div>
        <h1><?= $icon('import', 'app-icon app-icon--lg') ?><span>Import</span></h1>
        <p class="lead">Auslesedatei eines Programmiergeräts prüfen und übernehmen.</p>
    </div>
    <div class="actions">
        <a class="button" href="/imports"><?= $icon('log') ?><span>Importprotokoll</span></a>
    </div>
</div>
<section class="card">
    <h2><?= $icon('import', 'app-icon app-icon--sm') ?> Exportdatei auswählen</h2>
    <p>Unterstützt werden Textexporte eines Abbott/St. Jude Merlin-Programmiergeräts (Felder getrennt durch das Steuerzeichen 0x1C)
        sowie XML-Exporte nach IEEE 11073-10103 (Biotronik, BioICSConverter).
        Erlaubte Endungen: <code>.txt</code>, <code>.log</code>, <code>.xml</code>. Maximale Größe: <?= $e(App\Security\UploadValidator::formatBytes($maxBytes)) ?>.</p>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/import" enctype="multipart/form-data" class="upload-form" data-max-bytes="<?= $e($maxBytes) ?>">
        <?= $csrf() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $e($maxBytes) ?>">
        <label for="file">Exportdatei auswählen</label>
        <input type="file" id="file" name="file" accept=".txt,.log,.xml,text/plain,application/xml,text/xml" required>
        <p class="hint" data-upload-hint hidden></p>
        <button type="submit" class="primary">Datei prüfen</button>
    </form>
    <p class="muted">Die Datei wird zunächst nur analysiert. Gespeichert wird erst nach Bestätigung in der Importübersicht.</p>
</section>
