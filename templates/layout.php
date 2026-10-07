<?php
/**
 * @var Closure $e
 * @var string $content
 * @var string $title
 * @var string $active
 * @var list<array{type: string, message: string}> $flashes
 */
$nav = [
    'dashboard' => ['/', 'Dashboard'],
    'import' => ['/import', 'Import'],
    'reports' => ['/reports', 'Berichte'],
    'imports' => ['/imports', 'Importprotokoll'],
    'system' => ['/system', 'Systeminformationen'],
];
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> – HSM2Med</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<header class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="/">HSM2Med <span>Merlin-Auslesedaten</span></a>
        <nav aria-label="Hauptnavigation">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= $e($href) ?>"<?= $active === $key ? ' class="active" aria-current="page"' : '' ?>><?= $e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<main class="container">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= $e($flash['type']) ?>" role="status"><?= $e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="footer">
    <div class="container">
        Automatisch erzeugte Datendarstellung – keine medizinische Bewertung, Diagnose oder Empfehlung.
        Keine originale Abbott-/Merlin-Dokumentation. Offline-Betrieb ohne externe Ressourcen.
    </div>
</footer>
</body>
</html>
