<?php
/**
 * @var Closure $e
 * @var int $status
 * @var string $message
 * @var ?string $details
 */
?>
<section class="card">
    <h1>Fehler <?= $e($status) ?></h1>
    <p><?= $e($message) ?></p>
    <?php if ($details !== null): ?>
        <details><summary>Technische Details (nur außerhalb des Produktivbetriebs)</summary><pre class="raw"><?= $e($details) ?></pre></details>
    <?php endif; ?>
    <p><a class="button" href="/">Zur Startseite</a></p>
</section>
