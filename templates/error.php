<?php
/**
 * @var Closure $e
 * @var Closure $icon
 * @var int $status
 * @var string $message
 * @var ?string $details
 */
?>
<section class="card">
    <h1><?= $icon('warning', 'app-icon app-icon--lg') ?><span>Fehler <?= $e($status) ?></span></h1>
    <p><?= $e($message) ?></p>
    <?php if ($details !== null): ?>
        <details><summary>Technische Details (nur außerhalb des Produktivbetriebs)</summary><pre class="raw"><?= $e($details) ?></pre></details>
    <?php endif; ?>
    <p><a class="button" href="/"><?= $icon('dashboard') ?> <span>Zur Startseite</span></a></p>
</section>
