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
 * @var array{id:int,name:string,birth:string,identifier:string}|null $activePatient
 * @var list<array{type: string, message: string}> $flashes
 * @var array{error: ?string, username: string, target: string}|null $login Anmeldedaten im Sperrmodus
 * @var \App\User\User|null $user Angemeldete Person (null im Sperrmodus)
 * @var Closure(string): bool $permitted Rechteprüfung aus App\Http\View
 */

use App\Config\Config;
use App\Http\Ribbon;
use App\Http\View;

$activePatient = $activePatient ?? null;
$login = $login ?? null;
$user = $user ?? null;
// Sperrmodus: die Oberflaeche ist geladen, aber gesperrt; das Anmeldefenster liegt darueber.
// Die Inhalte dahinter werden fuer Hilfsmittel ausgeblendet und sind nicht bedienbar.
$locked = $login !== null;
$inert = $locked ? ' inert' : '';
$activeTab = Ribbon::tabIdForSection($active);
$sectionLabel = Ribbon::sectionLabel($active);
// Kontextzeile nur zeigen, wenn sie mehr Information als der Seitentitel bietet.
$contextLine = $sectionLabel !== '' && $sectionLabel !== $title ? $sectionLabel : '';
$flashIcons = ['success' => 'check', 'error' => 'warning', 'warning' => 'warning', 'info' => 'info'];
// Der Patientenvorgang ist fuehrend: ohne aktiven Patienten sind patientenbezogene
// Schaltflaechen gesperrt. Listen und Nachschlagewerke bleiben erreichbar.
$gateTitle = 'Zuerst einen Patienten auswählen oder anlegen – der Patientenvorgang ist führend.';
$gated = static fn (string $href): bool => $activePatient === null && Ribbon::requiresPatient($href);
// Rechte: Die Oberflaeche zeigt nur, was die Person auch aufrufen darf. Grundlage ist
// dieselbe Zuordnung, die der Kernel durchsetzt (Permission::forPath).
$permitted = $permitted ?? View::permitted($user);
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
<body class="office<?= $locked ? ' is-locked' : '' ?>">
<a class="skip-link" href="#inhalt"<?= $inert ?>>Direkt zum Inhalt</a>
<div class="app-chrome"<?= $inert ?>>
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
                <?php if (!$permitted($item['href'])): ?>
                    <?php continue; ?>
                <?php elseif ($gated($item['href'])): ?>
                    <span class="titlebar__qb is-disabled" aria-disabled="true" title="<?= $e($gateTitle) ?>">
                        <?= $icon($item['icon'], 'app-icon app-icon--sm') ?><span><?= $e($item['label']) ?></span>
                    </span>
                <?php else: ?>
                    <a class="titlebar__qb" href="<?= $e($item['href']) ?>" title="<?= $e($item['title']) ?>">
                        <?= $icon($item['icon'], 'app-icon app-icon--sm') ?><span><?= $e($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <p class="titlebar__doc">
            <span class="titlebar__doc-title"><?= $e($title) ?></span>
            <?php if ($contextLine !== ''): ?>
                <span class="titlebar__doc-area"><?= $e($contextLine) ?></span>
            <?php endif; ?>
        </p>
        <?php if ($activePatient === null): ?>
            <a class="titlebar__patient" href="/patients" title="<?= $e($gateTitle) ?>">
                <?= $icon('warning', 'app-icon app-icon--sm') ?>
                <span class="titlebar__patient-text"><strong>Kein Patient gewählt</strong><small>Patient wählen oder anlegen</small></span>
            </a>
        <?php else: ?>
            <a class="titlebar__patient is-active" href="/patients/<?= $e($activePatient['id']) ?>"
               title="Aktiver Patient – patientenbezogene Vorgänge beziehen sich auf diesen Patienten">
                <?= $icon('patients', 'app-icon app-icon--sm') ?>
                <span class="titlebar__patient-text">
                    <strong><?= $e($activePatient['name']) ?></strong>
                    <small>Aktiver Patient<?= $activePatient['birth'] !== '' ? ' · geb. ' . $e($view::dateTime($activePatient['birth'], true)) : '' ?></small>
                </span>
            </a>
        <?php endif; ?>
    </header>
    <nav class="ribbon" aria-label="Funktionsband">
        <ul class="ribbon__tabs">
            <?php foreach (Ribbon::tabs() as $tab): ?>
                <?php if (!$permitted($tab['href'])): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <li>
                    <?php if ($gated($tab['href'])): ?>
                        <span class="ribbon__tab is-disabled" aria-disabled="true" title="<?= $e($gateTitle) ?>">
                            <?= $icon($tab['icon']) ?><span><?= $e($tab['label']) ?></span>
                        </span>
                    <?php else: ?>
                        <a class="ribbon__tab" href="<?= $e($tab['href']) ?>"<?= $tab['id'] === $activeTab ? ' aria-current="page"' : '' ?>>
                            <?= $icon($tab['icon']) ?><span><?= $e($tab['label']) ?></span>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="ribbon__panel">
            <?php foreach (Ribbon::tab($activeTab)['groups'] as $group): ?>
                <?php
                // Gesperrte Ziele erscheinen gar nicht erst; bleibt nichts uebrig, entfaellt die Gruppe.
                $items = array_values(array_filter($group['items'], static fn (array $item): bool => $permitted($item['href'])));
                if ($items === []) {
                    continue;
                }
                ?>
                <section class="rgroup">
                    <div class="rgroup__items">
                        <?php foreach ($items as $item): ?>
                            <?php if ($gated($item['href'])): ?>
                                <span class="rbtn is-disabled" aria-disabled="true" title="<?= $e($gateTitle) ?>">
                                    <?= $icon($item['icon'], 'app-icon app-icon--lg') ?>
                                    <span class="rbtn__label"><?= $e($item['label']) ?></span>
                                </span>
                            <?php else: ?>
                                <a class="rbtn<?= in_array($active, $item['match'], true) ? ' is-current' : '' ?>"
                                   href="<?= $e($item['href']) ?>" title="<?= $e($item['title']) ?>"<?= ($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener"' : '' ?>>
                                    <?= $icon($item['icon'], 'app-icon app-icon--lg') ?>
                                    <span class="rbtn__label"><?= $e($item['label']) ?></span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <p class="rgroup__label"><?= $e($group['label']) ?></p>
                </section>
            <?php endforeach; ?>
        </div>
    </nav>
</div>
<main class="workspace container" id="inhalt"<?= $inert ?>>
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= $e($flash['type']) ?>" role="status">
            <?= $icon($flashIcons[$flash['type']] ?? 'info') ?>
            <span><?= $e($flash['message']) ?></span>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="statusbar"<?= $inert ?>>
    <span class="statusbar__item"><?= $icon('pulse', 'app-icon app-icon--sm') ?> <?= $e(Config::APP_NAME) ?> <?= $e(Config::APP_VERSION) ?></span>
    <span class="statusbar__item">
        <?= $icon('patients', 'app-icon app-icon--sm') ?>
        <?= $activePatient === null
            ? 'Kein Patient gewählt'
            : 'Patient: ' . $e($activePatient['name']) . ' (Nr. ' . $e($activePatient['id']) . ')' ?>
    </span>
    <?php if ($user !== null): ?>
        <span class="statusbar__item statusbar__user">
            <?= $icon('user', 'app-icon app-icon--sm') ?>
            <span><strong><?= $e($user->displayName !== '' ? $user->displayName : $user->username) ?></strong><?= $user->groupText() !== '' ? ' · ' . $e($user->groupText()) : '' ?></span>
        </span>
        <a class="statusbar__item statusbar__link" href="/account/password"
           title="Eigenes Kennwort ändern"><?= $icon('key', 'app-icon app-icon--sm') ?><span>Kennwort ändern</span></a>
        <?php if ($user->mayManageUsers()): ?>
            <a class="statusbar__item statusbar__link" href="/system/users"
               title="Benutzerkonten und Gruppen verwalten"><?= $icon('users', 'app-icon app-icon--sm') ?><span>Benutzerverwaltung</span></a>
        <?php endif; ?>
        <form method="post" action="/logout" class="statusbar__form">
            <?= $csrf() ?>
            <button type="submit" class="statusbar__item statusbar__link" title="Abmelden">
                <?= $icon('logout', 'app-icon app-icon--sm') ?><span>Abmelden</span>
            </button>
        </form>
    <?php else: ?>
        <span class="statusbar__item statusbar__user">
            <?= $icon('lock', 'app-icon app-icon--sm') ?><span>Nicht angemeldet</span>
        </span>
    <?php endif; ?>
    <button type="button" class="statusbar__item statusbar__author" data-author-info
            title="Autoren-Info zu <?= $e(Config::APP_NAME) ?> anzeigen">
        <?= $icon('info', 'app-icon app-icon--sm') ?>
        <span>HSM2Med by Daniel-André Reinelt</span>
    </button>
    <?php if ($contextLine !== ''): ?>
        <span class="statusbar__item">Bereich: <?= $e($contextLine) ?></span>
    <?php endif; ?>
    <span class="statusbar__grow"></span>
    <span class="statusbar__item statusbar__note">Automatisch erzeugte Datendarstellung – keine medizinische Bewertung, Diagnose oder Empfehlung. Keine originale Abbott-/Merlin-Dokumentation.</span>
</footer>
<dialog class="author-info" aria-label="Autoren-Info" data-author-info-dialog<?= $inert ?>>
    <form method="dialog" class="author-info__head">
        <button type="submit" class="author-info__close" title="Schließen" aria-label="Schließen">
            <?= $icon('close', 'app-icon app-icon--sm') ?>
        </button>
    </form>
    <div class="author-info__body">
        <p><strong>HSM2Med</strong> ist eine unterstützende Anwendung zur Vereinfachung und Strukturierung der Herzschrittmachernachsorge. Die Anwendung ist <strong>kein Medizinprodukt</strong>. Für die Therapieentscheidung und medizinische Beurteilung sind ausschließlich die Daten des Auslesegeräts und des Primärsystems (PVS) maßgeblich.</p>
        <p>Teile des Programmcodes wurden unter Einsatz von <strong>künstlicher Intelligenz</strong> erstellt (Anthropic Claude Opus 5.5, DeepSeek 4.1 Flash, Qwen3.8).</p>
    </div>
</dialog>
<?php if ($locked): ?>
    <?= $view->renderPartial('login/overlay', [
        'error' => $login['error'] ?? null,
        'username' => $login['username'] ?? '',
        'target' => $login['target'] ?? '/',
    ]) ?>
<?php endif; ?>
</body>
</html>
