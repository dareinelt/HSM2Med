<?php
/**
 * Hintergrund der Anmeldung: die geladene Oberflaeche ohne Anmeldedaten.
 *
 * Diese Seite erscheint hinter dem Anmeldefenster – gedimmt und unscharf. Sie zeigt bewusst
 * keine Patientendaten und keine Kennzahlen: vor der Anmeldung soll nichts aus der Datenbank
 * sichtbar sein, auch nicht im Quelltext der Seite.
 *
 * @var Closure $e
 * @var Closure $icon
 */
use App\Config\Config;
?>
<div class="page-head">
    <div>
        <h1><?= $icon('dashboard', 'app-icon app-icon--lg') ?><span>Dashboard</span></h1>
        <p class="lead">Kennzahlen und letzte Vorgänge der Herzschrittmachernachsorge.</p>
    </div>
</div>

<div class="stats">
    <?php foreach (['Importe', 'Berichte', 'Patienten', 'Patientenausweise'] as $label): ?>
        <div class="stat">
            <span class="stat-value">–</span>
            <span class="stat-label"><?= $e($label) ?></span>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid-2">
    <section class="card">
        <h2><?= $icon('reports', 'app-icon app-icon--sm') ?> Letzte Berichte</h2>
        <table class="table">
            <thead><tr><th>Datum</th><th>Patient</th><th>Gerät</th></tr></thead>
            <tbody>
            <?php for ($row = 0; $row < 4; $row++): ?>
                <tr><td><span class="skeleton-line"></span></td><td><span class="skeleton-line skeleton-line--wide"></span></td><td><span class="skeleton-line"></span></td></tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </section>
    <section class="card">
        <h2><?= $icon('import', 'app-icon app-icon--sm') ?> Letzte Importe</h2>
        <table class="table">
            <thead><tr><th>Zeitpunkt</th><th>Datei</th><th>Status</th></tr></thead>
            <tbody>
            <?php for ($row = 0; $row < 4; $row++): ?>
                <tr><td><span class="skeleton-line"></span></td><td><span class="skeleton-line skeleton-line--wide"></span></td><td><span class="skeleton-line"></span></td></tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </section>
</div>

<section class="card">
    <h2><?= $icon('shield', 'app-icon app-icon--sm') ?> <?= $e(Config::APP_NAME) ?> ist zugangsgeschützt</h2>
    <p>Diese Anwendung enthält Gesundheitsdaten. Der Zugang ist auf die eingerichteten Benutzerkonten
        beschränkt; jede Person sieht nur die Bereiche ihrer Gruppen.</p>
    <p class="muted">Bitte im Anmeldefenster mit dem eigenen Benutzerkonto anmelden.</p>
</section>
