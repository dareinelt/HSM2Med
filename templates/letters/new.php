<?php
/**
 * Brief erstellen – Schritt 1: Patient waehlen.
 *
 * Bewusst serverseitig und ohne JavaScript nutzbar (CSP-konform): Die Auswahl ist eine Suche,
 * die Zuordnung erfolgt ueber Links. Schritt 2 folgt auf derselben Route mit ?patient={id}.
 *
 * @var Closure $e
 * @var App\Http\View $view
 * @var string $query
 * @var list<array<string, mixed>> $rows
 * @var array<int, string> $steps
 * @var array<string, string> $errors
 * @var string|null $message
 */
$errors = $errors ?? [];
$message = $message ?? null;
?>
<div class="page-head">
    <div>
        <h1>Brief erstellen</h1>
        <p class="lead">Der Brief entsteht ausschließlich aus vorhandenen Daten. Zuerst wird der Patient
            gewählt; der Bericht als Befundteil ist optional.</p>
    </div>
    <div class="actions">
        <a class="button" href="/letters">Zur Übersicht</a>
    </div>
</div>

<ol class="wizard-steps" aria-label="Schritte">
    <?php foreach ($steps as $no => $label): ?>
        <li><span class="wizard-step-link"<?= $no === 1 ? ' aria-current="step"' : '' ?>>
            <span class="wizard-step-no"><?= $e($no) ?></span> <?= $e($label) ?></span></li>
    <?php endforeach; ?>
</ol>

<?php if ($message !== null): ?>
    <div class="alert alert-error" role="alert"><?= $e($message) ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <p class="field-error" role="alert"><?= $e($error) ?></p>
<?php endforeach; ?>

<section class="card">
    <h2>Patient wählen</h2>
    <form method="get" action="/letters/new" class="filters">
        <div class="field wide">
            <label for="q">Suche (Name)</label>
            <input type="search" id="q" name="q" value="<?= $e($query) ?>" maxlength="200" autofocus>
        </div>
        <div class="field buttons"><button type="submit" class="primary">Suchen</button>
            <?php if ($query !== ''): ?> <a class="button" href="/letters/new">Zurücksetzen</a><?php endif; ?></div>
    </form>
</section>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="muted">Kein Patient gefunden.
            <a href="/patients/new">Patient anlegen</a> – Anamnese, Vormedikation und Epikrise können schon vor
            dem Import erfasst werden.</p>
    <?php else: ?>
        <p class="muted"><?= $e(count($rows)) ?> Patient(en). Für den Brief den Patienten auswählen.</p>
        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr><th>Nr.</th><th>Name</th><th>Geburtsdatum</th><th>Patienten-ID</th><th>Berichte</th><th>Bausteine</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td><?= $e($p['id']) ?></td>
                        <td><?= $e($p['patient_name']) ?></td>
                        <td><?= $e($view::dateTime($p['date_of_birth'], true)) ?></td>
                        <td><?= $e($p['patient_identifier'] ?? '') ?></td>
                        <td><?= $e($p['report_count']) ?></td>
                        <td><?= $e($p['record_count']) ?></td>
                        <td class="nowrap">
                            <a class="button primary" href="/letters/new?patient=<?= $e($p['id']) ?>">Brief erstellen</a>
                            <a href="/patients/<?= $e($p['id']) ?>">Akte</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
