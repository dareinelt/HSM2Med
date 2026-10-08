<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var int $status
 * @var string $message
 * @var ?string $details
 * @var ?string $reference
 * @var Closure(string): bool $permitted
 */
?>
<section class="card">
    <h1><?= $icon('warning', 'app-icon app-icon--lg') ?><span>Fehler <?= $e($status) ?></span></h1>
    <p><?= $e($message) ?></p>
    <?php if ($details !== null): ?>
        <details><summary>Technische Details (nur außerhalb des Produktivbetriebs)</summary><pre class="raw"><?= $e($details) ?></pre></details>
    <?php endif; ?>
    <p>
        <a class="button" href="/"><?= $icon('dashboard') ?> <span>Zur Startseite</span></a>
        <?php if (($reference ?? null) !== null && $permitted('/system/logs')): ?>
            <a class="button" href="/system/logs?<?= $e(http_build_query(['ref' => $reference])) ?>"><?= $icon('log') ?> <span>Im Fehlerprotokoll anzeigen</span></a>
        <?php endif; ?>
    </p>
</section>
