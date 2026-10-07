<?php
/**
 * @var Closure $e
 * @var Closure $csrf
 * @var int $maxBytes
 * @var ?string $error
 */
?>
<h1>Import</h1>
<section class="card">
    <h2>Merlin-Exportdatei auswählen</h2>
    <p>Unterstützt werden Textexporte eines Abbott/St. Jude Merlin-Programmiergeräts (Felder getrennt durch das Steuerzeichen 0x1C).
        Erlaubte Endungen: <code>.txt</code>, <code>.log</code>. Maximale Größe: <?= $e(App\Security\UploadValidator::formatBytes($maxBytes)) ?>.</p>
    <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/import" enctype="multipart/form-data" class="upload-form" data-max-bytes="<?= $e($maxBytes) ?>">
        <?= $csrf() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= $e($maxBytes) ?>">
        <label for="file">TXT-Datei auswählen</label>
        <input type="file" id="file" name="file" accept=".txt,.log,text/plain" required>
        <p class="hint" data-upload-hint hidden></p>
        <button type="submit" class="primary">Datei prüfen</button>
    </form>
    <p class="muted">Die Datei wird zunächst nur analysiert. Gespeichert wird erst nach Bestätigung in der Importübersicht.</p>
</section>
