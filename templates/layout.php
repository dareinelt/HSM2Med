<?php
/**
 * Rahmen der Oberflaeche im Office-Stil: Titelleiste, Funktionsband (Ribbon),
 * Arbeitsbereich und Statusleiste. Reiter und Schaltflaechen kommen aus
 * App\Http\Ribbon, damit Struktur und Markup an einer Stelle gepflegt werden.
 *
 * @var Closure $e
 * @var Closure $icon
 * @var string $content
 * @var string $title
 * @var string $active Kennung des Bereichs aus dem Controller
 * @var list<array{type: string, message: string}> $flashes
 */

use App\Config\Config;
use App\Http\Ribbon;

$activeTab = Ribbon::tabIdForSection($active);
$sectionLabel = Ribbon::sectionLabel($active);
// Kontextzeile nur zeigen, wenn sie mehr Information als der Seitentitel bietet.
$contextLine = $sectionLabel !== '' && $sectionLabel !== $title ? $sectionLabel : '';
$flashIcons = ['success' => 'check', 'error' => 'warning', 'warning' => 'warning', 'info' => 'info'];
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($title) ?> – <?= $e(Config::APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/office.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body class="office">
<a class="skip-link" href="#inhalt">Direkt zum Inhalt</a>
<div class="app-chrome">
    <header class="titlebar">
        <a class="titlebar__brand" href="/" title="Zum Dashboard">
            <span class="titlebar__mark"><?= $icon('pulse') ?></span>
            <span class="titlebar__brand-text">
                <strong><?= $e(Config::APP_NAME) ?></strong>
                <small>Merlin-Auslesedaten</small>
            </span>
        </a>
        <div class="titlebar__quick" role="group" aria-label="Schnellzugriff">
            <?php foreach (Ribbon::quickAccess() as $item): ?>
                <a class="titlebar__qb" href="<?= $e($item['href']) ?>" title="<?= $e($item['title']) ?>">
                    <?= $icon($item['icon'], 'app-icon app-icon--sm') ?><span><?= $e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="titlebar__doc">
            <span class="titlebar__doc-title"><?= $e($title) ?></span>
            <?php if ($contextLine !== ''): ?>
                <span class="titlebar__doc-area"><?= $e($contextLine) ?></span>
            <?php endif; ?>
        </p>
    </header>
    <nav class="ribbon" aria-label="Funktionsband">
        <ul class="ribbon__tabs">
            <?php foreach (Ribbon::tabs() as $tab): ?>
                <li>
                    <a class="ribbon__tab" href="<?= $e($tab['href']) ?>"<?= $tab['id'] === $activeTab ? ' aria-current="page"' : '' ?>>
                        <?= $icon($tab['icon']) ?><span><?= $e($tab['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="ribbon__panel">
            <?php foreach (Ribbon::tab($activeTab)['groups'] as $group): ?>
                <section class="rgroup">
                    <div class="rgroup__items">
                        <?php foreach ($group['items'] as $item): ?>
                            <a class="rbtn<?= in_array($active, $item['match'], true) ? ' is-current' : '' ?>"
                               href="<?= $e($item['href']) ?>" title="<?= $e($item['title']) ?>">
                                <?= $icon($item['icon'], 'app-icon app-icon--lg') ?>
                                <span class="rbtn__label"><?= $e($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <p class="rgroup__label"><?= $e($group['label']) ?></p>
                </section>
            <?php endforeach; ?>
        </div>
    </nav>
</div>
<main class="workspace container" id="inhalt">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= $e($flash['type']) ?>" role="status">
            <?= $icon($flashIcons[$flash['type']] ?? 'info') ?>
            <span><?= $e($flash['message']) ?></span>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="statusbar">
    <span class="statusbar__item"><?= $icon('pulse', 'app-icon app-icon--sm') ?> <?= $e(Config::APP_NAME) ?> <?= $e(Config::APP_VERSION) ?></span>
    <?php if ($contextLine !== ''): ?>
        <span class="statusbar__item">Bereich: <?= $e($contextLine) ?></span>
    <?php endif; ?>
    <span class="statusbar__grow"></span>
    <span class="statusbar__item statusbar__note">Automatisch erzeugte Datendarstellung – keine medizinische Bewertung, Diagnose oder Empfehlung. Keine originale Abbott-/Merlin-Dokumentation.</span>
</footer>
</body>
</html>
