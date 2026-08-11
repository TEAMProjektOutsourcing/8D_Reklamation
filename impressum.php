<?php
$pageTitle = 'Impressum';

// Öffentliche rechtliche Informationsseite – ohne Login-Zwang.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

function legal_page_e(string $value): string
{
    if (function_exists('e')) {
        return e($value);
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$hasSharedLayout = file_exists(__DIR__ . '/header.php')
    && file_exists(__DIR__ . '/footer.php');

$legalUser = function_exists('current_user') ? current_user() : null;
$legalBackUrl = $legalUser ? 'dashboard.php' : 'login.php';
$legalBackLabel = $legalUser ? 'Zurück zum Dashboard' : 'Zurück zum Login';

if ($hasSharedLayout) {
    require __DIR__ . '/header.php';

    $legalCssPath = __DIR__ . '/assets/css/legal-extra.css';
    if (is_file($legalCssPath)) {
        echo '<style>' . file_get_contents($legalCssPath) . '</style>';
    }
} else {
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= legal_page_e($pageTitle) ?> · Reklamation8D</title>
    <link rel="icon" type="image/png" href="assets/logo-reklamation8d-light-icon.png?v=60">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
    <link href="assets/css/8d-app.css" rel="stylesheet">
    <link href="assets/css/legal-extra.css" rel="stylesheet">
</head>
<body>
<main class="container-fluid py-4">
<?php
}
?>

<div class="legal-page-shell" id="legal-top">
    <section class="legal-hero">
        <div class="legal-hero-main">
            <div class="legal-kicker">Rechtliche Informationen</div>

            <div class="legal-title-row">
                <div class="legal-title-icon" aria-hidden="true">§</div>
                <div>
                    <h1>Impressum</h1>
                    <p>Anbieterkennzeichnung und rechtliche Hinweise der TEAMProjekt Outsourcing GmbH.</p>
                </div>
            </div>

            <nav class="legal-tabs" aria-label="Rechtliche Seiten">
                <a href="impressum.php" class="active">Impressum</a>
                <a href="datenschutz.php" class="">Datenschutz</a>
            </nav>
        </div>

        <div class="legal-meta-grid">
            
<div class="legal-meta-card">
    <span>Unternehmen</span>
    <strong>TEAMProjekt Outsourcing GmbH</strong>
</div>


<div class="legal-meta-card">
    <span>Sitz</span>
    <strong>65479 Raunheim</strong>
</div>


<div class="legal-meta-card">
    <span>Geschäftsführung</span>
    <strong>Christian Besier</strong>
</div>


<div class="legal-meta-card">
    <span>Stand</span>
    <strong>05.04.2018</strong>
</div>

        </div>
    </section>

    <div class="legal-mobile-toc">
        <details>
            <summary>Inhaltsübersicht</summary>
            <nav><a href="#anbieter">Anbieterkennzeichnung</a>
<a href="#haftungsausschluss">Haftungsausschluss</a>
<a href="#nutzungsrecht">Nutzungsrecht</a>
<a href="#cookies">Cookies</a>
<a href="#datenschutzerklarung-fur-die-nutzung-von-google-analytics">Datenschutzerklärung für die Nutzung von Google Analytics</a>
<a href="#kontaktformular">Kontaktformular</a></nav>
        </details>
    </div>

    <div class="legal-layout">
        <aside class="legal-sidebar">
            <div class="legal-sidebar-card">
                <div class="legal-sidebar-title">Auf dieser Seite</div>
                <nav class="legal-toc"><a href="#anbieter">Anbieterkennzeichnung</a>
<a href="#haftungsausschluss">Haftungsausschluss</a>
<a href="#nutzungsrecht">Nutzungsrecht</a>
<a href="#cookies">Cookies</a>
<a href="#datenschutzerklarung-fur-die-nutzung-von-google-analytics">Datenschutzerklärung für die Nutzung von Google Analytics</a>
<a href="#kontaktformular">Kontaktformular</a></nav>
            </div>

            <div class="legal-sidebar-card legal-contact-card">
                <div class="legal-sidebar-title">Kontakt</div>
                <a href="mailto:kontakt@teamprojekt-outsourcing.de">kontakt@teamprojekt-outsourcing.de</a>
                <a href="tel:+496142837860">+49 (0) 6142 / 83786-0</a>
            </div>
        </aside>

        <article class="legal-content-stack">
            
<section class="legal-section legal-company-section" id="anbieter">
    <div class="legal-section-head">
        <span class="legal-section-number">§</span>
        <div>
            <div class="legal-section-kicker">Unternehmensangaben</div>
            <h2>Anbieterkennzeichnung</h2>
        </div>
    </div>
    <div class="legal-company-grid">
        <p>TEAMProjekt Outsourcing GmbH<br/>
Am Prime Parc 17<br/>
65479 Raunheim</p>
<p>Tel.: +49 6142 590 96 48<br/>
Fax: +49 6142 83786-15</p>
<p>E-Mail: <a href="mailto:kontakt@teamprojekt-outsourcing.de">kontakt@teamprojekt-outsourcing.de</a><br/>
Website: www.teamprojekt-outsourcing.de</p>
<p>Geschäftsführer:<br/>
Christian Besier</p>
<p>Handelsregister:<br/>
Amtsgericht Darmstadt, HRB 87387</p>
<p>Umsatzsteuer Ident-Nr.:<br/>
DE 113589582</p>
    </div>
</section>


<section class="legal-section" id="haftungsausschluss">
    <div class="legal-section-head">
        <span class="legal-section-number">§</span>
        <h2>Haftungsausschluss</h2>
    </div>
    <div class="legal-section-body">
        <p>Die TEAMProjekt Outsourcing GmbH stellt auf dieser Webseite ausgewählte Informationen zu verschiedenen Themen und ihre Leistungen bereit. Dabei verfolgen wir das Ziel, der interessierten Öffentlichkeit aktuelle und exakte Informationen zur Verfügung zu stellen. Für Kritik und Anregungen sind wir jederzeit dankbar. Sollten wir von Fehlern erfahren, werden wir versuchen, diese zu korrigieren. Die TEAMProjekt Outsourcing GmbH übernimmt jedoch keinerlei Verantwortung oder Haftung für die Angaben auf dieser Website. Sie sind zum Teil nicht notwendigerweise umfassend, komplett, genau oder aktuell. Soweit eine Verbindung zu externen Webseiten besteht, kann die TEAMProjekt Outsourcing GmbH diese Seiten nicht beeinflussen und übernimmt für sie und deren Inhalt keine Verantwortung. Diese Webseite dient insbesondere nicht der spezifischen Beratung im Einzelfall.</p>
    </div>
</section>


<section class="legal-section" id="nutzungsrecht">
    <div class="legal-section-head">
        <span class="legal-section-number">§</span>
        <h2>Nutzungsrecht</h2>
    </div>
    <div class="legal-section-body">
        <p>Die von der TEAMProjekt Outsourcing GmbH zur Verfügung gestellten Informationen, Fotos, Grafiken und Animationen stehen nicht zur freien Verfügung. Sie dürfen weder kopiert oder in ähnlicher Weise im gleichen Zusammenhang geändert bzw. gesetzt werden. Für ein Nutzungsrecht bedarf es jederzeit der schriftlichen Genehmigung durch die Inhaber und Urheber dieser Website.</p>
    </div>
</section>


<section class="legal-section" id="cookies">
    <div class="legal-section-head">
        <span class="legal-section-number">§</span>
        <h2>Cookies</h2>
    </div>
    <div class="legal-section-body">
        <p>Die Internetseiten verwenden teilweise so genannte Cookies. Cookies richten auf Ihrem Rechner keinen Schaden an und enthalten keine Viren. Cookies dienen dazu, unser Angebot nutzerfreundlicher, effektiver und sicherer zu machen. Cookies sind kleine Textdateien, die auf Ihrem Rechner abgelegt werden und die Ihr Browser speichert. Die meisten der von uns verwendeten Cookies sind so genannte „Session-Cookies“. Sie werden nach Ende Ihres Besuchs automatisch gelöscht. Andere Cookies bleiben auf Ihrem Endgerät gespeichert, bis Sie diese löschen. Diese Cookies ermöglichen es uns, Ihren Browser beim nächsten Besuch wiederzuerkennen. Sie können Ihren Browser so einstellen, dass Sie über das Setzen von Cookies informiert werden und Cookies nur im Einzelfall erlauben, die Annahme von Cookies für bestimmte Fälle oder generell ausschließen sowie das automatische Löschen der Cookies beim Schließen des Browsers aktivieren. Bei der Deaktivierung von Cookies kann die Funktionalität dieser Website eingeschränkt sein.</p>
    </div>
</section>


<section class="legal-section" id="datenschutzerklarung-fur-die-nutzung-von-google-analytics">
    <div class="legal-section-head">
        <span class="legal-section-number">§</span>
        <h2>Datenschutzerklärung für die Nutzung von Google Analytics</h2>
    </div>
    <div class="legal-section-body">
        <p>Diese Website nutzt Funktionen des Webanalysedienstes Google Analytics. Anbieter ist die Google Inc. 1600 Amphitheatre Parkway Mountain View, CA 94043, USA. Google Analytics verwendet sog. „Cookies“. Das sind Textdateien, die auf Ihrem Computer gespeichert werden und die eine Analyse der Benutzung der Website durch Sie ermöglichen. Die durch den Cookies erzeugten Informationen über Ihre Benutzung dieser Website werden in der Regel an einen Server von Google in den USA übertragen und dort gespeichert.</p>
<p>Im Falle der Aktivierung der IP-Anonymisierung auf dieser Webseite wird Ihre IP-Adresse von Google jedoch innerhalb von Mitgliedstaaten der Europäischen Union oder in anderen Vertragsstaaten des Abkommens über den Europäischen Wirtschaftsraum zuvor gekürzt. Nur in Ausnahmefällen wird die volle IP-Adresse an einen Server von Google in den USA übertragen und dort gekürzt. Im Auftrag des Betreibers dieser Website wird Google diese Informationen benutzen, um Ihre Nutzung der Website auszuwerten, um Reports über die Websiteaktivitäten zusammenzustellen und um weitere mit der Websitenutzung und der Internetnutzung verbundene Dienstleistungen gegenüber dem Websitebetreiber zu erbringen. Die im Rahmen von Google Analytics von Ihrem Browser übermittelte IP-Adresse wird nicht mit anderen Daten von Google zusammengeführt.</p>
<p>Sie können die Speicherung der Cookies durch eine entsprechende Einstellung Ihrer Browser-Software verhindern; wir weisen Sie jedoch darauf hin, dass Sie in diesem Fall gegebenenfalls nicht sämtliche Funktionen dieser Website vollumfänglich werden nutzen können. Sie können darüber hinaus die Erfassung der durch das Cookie erzeugten und auf Ihre Nutzung der Website bezogenen Daten (inkl. Ihrer IP-Adresse) an Google sowie die Verarbeitung dieser Daten durch Google verhindern, indem sie das unter dem folgenden Link verfügbare Browser-Plug-In herunterladen und installieren: <a href="http://tools.google.com/dlpage/gaoptout?hl=de" rel="noopener" target="_blank">http://tools.google.com/dlpage/gaoptout?hl=de</a></p>
    </div>
</section>


<section class="legal-section" id="kontaktformular">
    <div class="legal-section-head">
        <span class="legal-section-number">§</span>
        <h2>Kontaktformular</h2>
    </div>
    <div class="legal-section-body">
        <p>Wenn Sie uns per Kontaktformular Anfragen zukommen lassen, werden Ihre Angaben aus dem Anfrageformular inklusive der von Ihnen dort angegebenen Kontaktdaten zwecks Bearbeitung der Anfrage und für den Fall von Anschlussfragen bei uns gespeichert. Diese Daten geben wir nicht ohne Ihre Einwilligung weiter.</p>
<p>Stand: 05.04.2018</p>
    </div>
</section>


            <div class="legal-bottom-actions">
                <a href="<?= legal_page_e($legalBackUrl) ?>" class="btn btn-primary">
                    <?= legal_page_e($legalBackLabel) ?>
                </a>
                <a href="#legal-top" class="btn btn-outline-secondary">Nach oben</a>
            </div>
        </article>
    </div>
</div>

<?php
if ($hasSharedLayout) {
    require __DIR__ . '/footer.php';
} else {
?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
?>
