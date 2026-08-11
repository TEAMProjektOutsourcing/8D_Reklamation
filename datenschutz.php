<?php
$pageTitle = 'Datenschutzerklärung';

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
            <div class="legal-kicker">Datenschutz</div>

            <div class="legal-title-row">
                <div class="legal-title-icon" aria-hidden="true">✓</div>
                <div>
                    <h1>Datenschutzerklärung</h1>
                    <p>Informationen zur Verarbeitung personenbezogener Daten und zu Ihren Rechten.</p>
                </div>
            </div>

            <nav class="legal-tabs" aria-label="Rechtliche Seiten">
                <a href="impressum.php" class="">Impressum</a>
                <a href="datenschutz.php" class="active">Datenschutz</a>
            </nav>
        </div>

        <div class="legal-meta-grid">
            
<div class="legal-meta-card">
    <span>Verantwortlicher</span>
    <strong>TEAMProjekt Outsourcing GmbH</strong>
</div>


<div class="legal-meta-card">
    <span>Datenschutz</span>
    <strong><a href="mailto:datenschutz@teamprojekt-outsourcing.de">E-Mail schreiben</a></strong>
</div>


<div class="legal-meta-card">
    <span>Aufsichtsbehörde</span>
    <strong>Hessen</strong>
</div>


<div class="legal-meta-card">
    <span>Stand</span>
    <strong>16.02.2026</strong>
</div>

        </div>
    </section>

    <div class="legal-mobile-toc">
        <details>
            <summary>Inhaltsübersicht</summary>
            <nav><a href="#einleitung">Datenschutz auf einen Blick</a>
<a href="#1-kontaktdaten">1. Kontaktdaten</a>
<a href="#2-erhebung-und-verarbeitung-von-daten">2. Erhebung und Verarbeitung von Daten</a>
<a href="#3-zweckbestimmung-nutzung-und-weitergabe-personenbezogener-daten">3. Zweckbestimmung, Nutzung und Weitergabe personenbezogener Daten</a>
<a href="#4-daten-die-wir-erheben">4. Daten, die wir erheben</a>
<a href="#5-weitergabe-von-daten-an-dritte-oder-in-ein-drittland">5. Weitergabe von Daten an Dritte oder in ein Drittland</a>
<a href="#11-social-media-auftritte">11. Social-Media-Auftritte</a>
<a href="#12-ihre-rechte">12. Ihre Rechte</a>
<a href="#13-datensicherheit">13. Datensicherheit</a>
<a href="#14-anderung-unserer-datenschutzbestimmungen">14. Änderung unserer Datenschutzbestimmungen</a>
<a href="#15-fristen-zur-datenloschung">15. Fristen zur Datenlöschung</a>
<a href="#16-ansprechpartner-bei-fragen-zum-datenschutz">16. Ansprechpartner bei Fragen zum Datenschutz</a></nav>
        </details>
    </div>

    <div class="legal-layout">
        <aside class="legal-sidebar">
            <div class="legal-sidebar-card">
                <div class="legal-sidebar-title">Auf dieser Seite</div>
                <nav class="legal-toc"><a href="#einleitung">Datenschutz auf einen Blick</a>
<a href="#1-kontaktdaten">1. Kontaktdaten</a>
<a href="#2-erhebung-und-verarbeitung-von-daten">2. Erhebung und Verarbeitung von Daten</a>
<a href="#3-zweckbestimmung-nutzung-und-weitergabe-personenbezogener-daten">3. Zweckbestimmung, Nutzung und Weitergabe personenbezogener Daten</a>
<a href="#4-daten-die-wir-erheben">4. Daten, die wir erheben</a>
<a href="#5-weitergabe-von-daten-an-dritte-oder-in-ein-drittland">5. Weitergabe von Daten an Dritte oder in ein Drittland</a>
<a href="#11-social-media-auftritte">11. Social-Media-Auftritte</a>
<a href="#12-ihre-rechte">12. Ihre Rechte</a>
<a href="#13-datensicherheit">13. Datensicherheit</a>
<a href="#14-anderung-unserer-datenschutzbestimmungen">14. Änderung unserer Datenschutzbestimmungen</a>
<a href="#15-fristen-zur-datenloschung">15. Fristen zur Datenlöschung</a>
<a href="#16-ansprechpartner-bei-fragen-zum-datenschutz">16. Ansprechpartner bei Fragen zum Datenschutz</a></nav>
            </div>

            <div class="legal-sidebar-card legal-contact-card">
                <div class="legal-sidebar-title">Kontakt</div>
                <a href="mailto:kontakt@teamprojekt-outsourcing.de">kontakt@teamprojekt-outsourcing.de</a>
                <a href="tel:+496142837860">+49 (0) 6142 / 83786-0</a>
            </div>
        </aside>

        <article class="legal-content-stack">
            
<section class="legal-section legal-intro-section" id="einleitung">
    <div class="legal-section-head">
        <span class="legal-section-number legal-section-check">✓</span>
        <div>
            <div class="legal-section-kicker">Datenschutz und Transparenz</div>
            <h2>Datenschutz auf einen Blick</h2>
        </div>
    </div>
    <div class="legal-section-body">
        <p>TEAMProjekt Outsourcing<br/>
Der Schutz Ihrer personenbezogenen Daten bei der Erhebung, Verarbeitung und Nutzung anlässlich Ihres Besuchs auf unserem Portal ist uns ein wichtiges Anliegen. Wir haben technische und organisatorische Maßnahmen getroffen, die sicherstellen, dass die Vorschriften über den Datenschutz sowohl von uns als auch von externen Dienstleistern beachtet werden.</p>
<p>Wir möchten Sie vorliegend über Art, Umfang und Zweck der Erhebung und Verwendung Ihrer uns zur Verfügung gestellten persönlichen Daten sowie über deren Verarbeitung und Nutzung informieren.</p>
    </div>
</section>


<section class="legal-section" id="1-kontaktdaten">
    <div class="legal-section-head">
        <span class="legal-section-number">1</span>
        <h2>1. Kontaktdaten</h2>
    </div>
    <div class="legal-section-body">
        <h3>Sie erreichen uns wie folgt:</h3>
<p>TEAMProjekt Outsourcing GmbH<br/>
Am Prime Parc 17<br/>
65479 Raunheim</p>
<p>Telefon: +49 (0) 6142/83786-0<br/>
Telefax: +49 (0) 6142/83786-15<br/>
E-Mail: <a href="mailto:kontakt@teamprojekt-outsourcing.de">kontakt@teamprojekt-outsourcing.de</a></p>
    </div>
</section>


<section class="legal-section" id="2-erhebung-und-verarbeitung-von-daten">
    <div class="legal-section-head">
        <span class="legal-section-number">2</span>
        <h2>2. Erhebung und Verarbeitung von Daten</h2>
    </div>
    <div class="legal-section-body">
        <p>Personenbezogene Daten sind Informationen zu Ihrer Identität. Hierunter fallen z.B. Angaben wie Name, Adresse, Telefonnummer, E-Mail-Adresse.</p>
<p>Generell können Sie unsere Website besuchen, ohne personenbezogene Daten zu hinterlassen, z.B., wenn Sie sich nur über unsere Produkte informieren wollen und die entsprechenden Seiten aufrufen. Die hierbei getätigten Zugriffe auf unserer Homepage und jeder Abruf einer auf der Homepage hinterlegten Datei werden protokolliert. Die Speicherung dient internen systembezogenen und statistischen Zwecken. Protokolliert werden: Name der abgerufenen Datei, Datum und Uhrzeit des Abrufs, übertragene Datenmenge, Meldung über erfolgreichen Abruf, Webbrowser und anfragende Domain. Hierbei werden jedoch keine personenbezogenen Daten Ihrerseits übermittelt und diese Informationen werden von möglicherweise übermittelten personenbezogenen Daten getrennt gespeichert. Zusätzlich werden die IP-Adressen der anfragenden Rechner protokolliert.</p>
<p>In bestimmten Fällen benötigen wir jedoch Ihren Namen und Ihre Adresse sowie weitere Angaben, damit wir die gewünschten Dienstleistungen auch im Rahmen unserer sonstigen Angebote erbringen können. Diese weitergehenden personenbezogenen Daten werden nur erfasst und gespeichert, wenn Sie diese Angaben freiwillig, etwa im Rahmen einer Anfrage, einer Bewerbung als Mitarbeiter/in oder bei der Inanspruchnahme unserer sonstigen Angebote machen.</p>
    </div>
</section>


<section class="legal-section" id="3-zweckbestimmung-nutzung-und-weitergabe-personenbezogener-daten">
    <div class="legal-section-head">
        <span class="legal-section-number">3</span>
        <h2>3. Zweckbestimmung, Nutzung und Weitergabe personenbezogener Daten</h2>
    </div>
    <div class="legal-section-body">
        <p>Soweit Sie uns personenbezogene Daten zur Verfügung stellen, verwenden wir diese nur zur Beantwortung Ihrer Anfragen und deren Abwicklung sowie zur Erfüllung unserer vertraglichen Pflichten im Rahmen unserer sonstigen Angebote. Wir werden die von Ihnen online zur Verfügung gestellten personenbezogenen Daten nur für die Ihnen mitgeteilten Zwecke erheben, verarbeiten und nutzen. Ihre personenbezogenen Daten werden nicht an Dritte weitergeben. Selbstverständlich respektieren wir es, wenn Sie uns Ihre personenbezogenen Daten nicht zur Unterstützung unserer Kundenbeziehung (insbesondere für Direktmarketing oder zu Marktforschungszwecken) überlassen wollen. Wir geben persönliche Daten über Kunden oder Lieferanten nur bekannt, wenn wir hierzu gesetzlich verpflichtet sind, bzw. sofern wir durch eine gerichtliche Entscheidung dazu verpflichtet sind oder wenn die Weitergabe erforderlich ist. Dies gilt entsprechend in Bezug auf die Speicherung der Daten.<br/>
Die Bekanntgabe der Daten erfolgt nicht zu wirtschaftlichen Zwecken. Unsere Mitarbeiter/inne sind von uns zur Verschwiegenheit und zur Einhaltung der Bestimmungen der aktuellen Datenschutzgesetze verpflichtet. Der Zugriff auf personenbezogene Daten durch unsere Mitarbeiter ist auf die Mitarbeiter beschränkt, die die jeweiligen Daten aufgrund ihrer beruflichen Aufgaben benötigen.</p>
    </div>
</section>


<section class="legal-section" id="4-daten-die-wir-erheben">
    <div class="legal-section-head">
        <span class="legal-section-number">4</span>
        <h2>4. Daten, die wir erheben</h2>
    </div>
    <div class="legal-section-body">
        <h3>Kontaktformular</h3>
<p>Mit unserem Kontaktformular werden die dort aufgeführten Informationen von Ihnen erfragt (<a href="https://www.teamprojekt-outsourcing.de/kontakt/)." rel="noopener" target="_blank">https://www.teamprojekt-outsourcing.de/kontakt/).</a> Diese Daten erheben und nutzen wir nur, um Ihnen entsprechend den dort angegebenen Wünschen mit Ihnen Kontakt aufzunehmen oder Ihnen Informationsmaterial zu Verfügung zu stellen.</p>
<h3>Typeform</h3>
<p>Wir verwenden Typeform, einen Onlineformularanbieter, den wir verwenden um Informationen von Ihnen zu erfassen die für ein Beratungsgespräch notwendig sind.</p>
<p>TYPEFORM SL<br/>
C/Bac de Roda, 163 (Local), 08018 – Barcelona (Spain)<br/>
Contact email: <a href="mailto:support@typeform.com">support@typeform.com</a><br/>
Contact details for our Data Protection Officer: <a href="mailto:gdpr@typeform.com">gdpr@typeform.com</a></p>
<p>Mit unserem Kontaktformular werden die dort aufgeführten Informationen von Ihnen erfragt (<a href="https://teamprojekt.typeform.com/to/IOIKs2)." rel="noopener" target="_blank">https://teamprojekt.typeform.com/to/IOIKs2).</a> Diese Daten erheben und nutzen wir nur, um Ihnen entsprechend den dort angegebenen Wünschen mit Ihnen Kontakt aufzunehmen oder Ihnen Informationsmaterial zu Verfügung zu stellen.</p>
<p>Weitere Informationen zu Typeform und dem Datenschutz bei Typeform können Sie hier einsehen:  <a href="https://admin.typeform.com/to/dwk6gt" rel="noopener" target="_blank">https://admin.typeform.com/to/dwk6gt</a></p>
<h3>Calendly</h3>
<p>Sie haben die Möglichkeit auf meiner Webseite einen Termin zu buchen.  Ich verwende zur Anfrage und Auswahl eines Termins den Online-Kalender „Calendly“. „Calendly“ ist ein Angebot der Calendly, LLC, 3423 Piedmont Road NE, Atlanta, GA 30305-1754, United States.</p>
<p>Wenn Sie auf den entsprechenden Buchungsbutton drücken, werden Sie automatisch mit meinem Terminaccount bei Calendly verbunden. Nach der Wahl Ihres Termins, der Bestätigung und der Eintragung Ihrer Kontaktdaten und Anliegen erhalten Sie von Calendly eine Email mit der Bestätigung Ihres Termins.</p>
<p>Ihre Angaben aus dem Calendly-Formular inklusive der von Ihnen dort angegebenen Daten werden zwecks Bearbeitung der Anfrage und für den Fall von Anschlussfragen bei mir gespeichert. Diese Daten verbleiben bei mir, bis Sie uns zur Löschung auffordern, Ihre Einwilligung zur Speicherung widerrufen oder der Zweck für die Datenspeicherung entfällt (z.B. erfolgter Termin). Zwingende gesetzliche Bestimmungen – insbesondere Aufbewahrungsfristen – bleiben unberührt.</p>
<p>Weitere Informationen zu Calendly und dem Datenschutz bei Calendly können Sie hier einsehen: <a href="https://calendly.com/pages/privacy" rel="noopener" target="_blank">https://calendly.com/pages/privacy</a> .</p>
<h3>Prismic</h3>
<p>Für unseren Internetauftritt setzen wir Prismic als Content-Management System ein. Es handlet sich hierbei um einen Dienst der Prismic Networks, Inc. 185 Alewife Brook Parkway, #410 Cambridge, MA 02138 nachfolgend nur „Prismic“ genannt.</p>
<p>Um die Darstellung des Inhalts unseres Internetauftritts zu ermöglichen, wird bei Aufruf unseres Internetauftritts eine Verbindung zu den Prismic-Servern aufgebaut.</p>
<p>Rechtsgrundlage ist Art. 6 Abs. 1 lit. f) DSGVO. Unser berechtigtes Interesse liegt in der Optimierung und dem wirtschaftlichen Betrieb unseres Internetauftritts.</p>
<p>Durch die bei Aufruf unseres Internetauftritts hergestellte Verbindung zu Prismic kann Prismic ermitteln, von welcher Website Ihre Anfrage gesendet worden ist und an welche IP-Adresse die Inhalte zu übermitteln sind.</p>
<h3>Prismic bietet unter:</h3>
<p><a href="https://prismic.io/legal/privacy" rel="noopener" target="_blank">https://prismic.io/legal/privacy</a></p>
<p><a href="https://prismic.io/security" rel="noopener" target="_blank">https://prismic.io/security</a></p>
<p>weitere Informationen an und weist darauf hin, dass die Datenschutzrichtlinie von Prismic den EU-Datenschutzgesetzen (DSGVO) entspricht.</p>
<p>​​App „Reclaimer“</p>
<p>Die von Ihnen im Rahmen der Verwendung der App „Reclaimer“ angegebenen Informationen, einschließlich Fotos und Schadensbeschreibungen, werden entsprechend dem Sinn und Zweck der App „Reclaimer“ ausschließlich zur Erstellung eines 8D-Reports und damit zur Erfüllung des Vertrags zwischen Ihnen und der TEAMProjekt Outsourcing GmbH gemäß Art. 6 Abs. 1 Satz 1b Datenschutz-Grundverordnung (DS-GVO).</p>
<h3>Bewerbung</h3>
<p>Das Bewerbersystem wird von der TEAMProjekt Outsourcing GmbH, Am Prime Parc 17, 65479 Raunheim betrieben. Die TEAMProjekt Outsourcing GmbH ist die datenschutzrechtlich verantwortliche Stelle im Sinne des § 11 Bundesdatenschutzgesetz bzw. Art. 28 DS-GVO sowie § 62 BDSG (neu). Wir stellen Ihnen diese Datenschutzerklärung, welche sich ausschließlich auf die im Rahmen des Bewerbungsprozesses erhobenen Daten bezieht, zur Verfügung, um Sie darüber zu informieren, wie wir mit Ihren im Rahmen des Bewerbungsprozesses erhobenen persönlichen Daten bei der TEAMProjekt Outsourcing GmbH umgehen. Durch Absenden Ihrer Bewerbung stimmen Sie diesen Datenschutzbestimmungen zu.</p>
<p>Personenbezogene Daten im Rahmen des Bewerberprozesses</p>
<p>Personenbezogene Daten sind Angaben über persönliche oder sachliche Verhältnisse einer bestimmten oder bestimmbaren natürlichen Person. Darunter fallen Informationen wie beispielsweise Name, Ihre Anschrift, Ihre Telefonnummer, Ihr Geburtsdatum, aber auch Daten über Ihren konkreten Werdegang etc., welche mit vertretbarem Aufwand einer bestimmten Person zugeordnet werden kann. Informationen, die nicht direkt mit Ihrer wirklichen Identität in Verbindung gebracht werden, sind hingegen keine personenbezogenen Daten.</p>
<p>Erhebung und Verarbeitung personenbezogener Daten im Rahmen des Bewerberprozesses</p>
<p>Unsere Datenschutzbestimmungen stehen im Einklang mit der DS-GVO und BDSG (neu) sowie dem Telemediengesetz (TMG). Wenn Sie für eine offene Stelle oder initiativ über das Bewerbungssystem unserer Webseite bewerben, übermitteln Sie freiwillig personenbezogene Daten und Informationen (Vorname, Name, E-Mail-Adresse, Telefonnummer, sowie etwaige Anhänge wie Lebenslauf, Anschreiben etc.). Sofern Sie uns eine Bewerbung auf eine offene Stelle oder initiativ per E-Mail (<a href="mailto:bewerbung@tp-o.de">bewerbung@tp-o.de</a>, <a href="mailto:bewerbung@teamprojekt-outsourcing.de">bewerbung@teamprojekt-outsourcing.de</a>, <a href="mailto:jobs@tp-o.de">jobs@tp-o.de</a>) versenden, hängt die Verschlüsselung von Ihrem E-Mail Dienstleister ab. Gleiches gilt für die Übermittelung über unserem Bewerberportal auf www.teamprojekt-outsourcing.de. Verantwortliche Mitarbeiter unseres Unternehmens können auf die Bewerberdatenbank zugreifen, um vakante Positionen mit geeigneten Kandidaten besetzten zu können.</p>
<p>Speicherung personenbezogener Daten im Rahmen des Bewerberprozesses</p>
<p>Die Speicherung der personenbezogenen Daten erfolgt grundsätzlich ausschließlich für den Zweck, der Besetzung der vakanten Stelle, für die Sie sich beworben haben. Darüber hinaus behalten wir uns vor, Ihre Daten zur Aufnahme in unserem „Talent Pool“ für 3 Monate nach Beendigung des Bewerbungsverfahrens zu speichern, um etwaige weitere interessante Stellen für Sie zu identifizieren. Durch absenden Ihrer Bewerbung stimmen sie einer über die gesetzlichen Anforderungen hinausgehenden Speicherung zu. Sie können diese Zustimmung jederzeit widerrufen und der Speicherung Ihrer Daten widersprechen.</p>
<p>Löschung personenbezogener Daten im Rahmen des Bewerberprozesses</p>
<p>Sie können uns jederzeit via E-Mail (<a href="mailto:datenschutz@teamprojekt-outsourcing.de">datenschutz@teamprojekt-outsourcing.de</a>) oder telefonisch (06142/83786-0) kontaktieren, um die Löschung Ihrer Daten zu veranlassen. Personenbezogene Daten werden nach Abschluss des Bewerbungsverfahrens und unter Beachtung gesetzlicher Verpflichtungen routinemäßig gelöscht.</p>
<p>Weitergabe personenbezogener Daten im Rahmen des Bewerberprozesses</p>
<p>Eine Weitergabe Ihrer personenbezogenen Daten erfolgt nur dann, wenn die TEAMProjekt Outsourcing GmbH gesetzlich verpflichtet ist oder wenn es im Falle eines Missbrauchs oder der Aufklärung erforderlich ist. Hierzu bedarf es jedoch konkreter Anhaltspunkte für ein rechtswidriges oder missbräuchliches Verhalten. Auf Anordnung einer zuständigen Stelle dürfen wir im Einzelfall Auskunft über diese Daten (Bestandsdaten) erteilen, insbesondere für die Zwecke der Strafverfolgung.</p>
<p>Sicherheit der personenbezogenen Daten im Rahmen des Bewerberprozesses</p>
<p>Sowohl wir als TEAMProjekt Outsourcing GmbH, welche im Bewerbungsprozess involviert sind, sind dazu angewiesen, geeignete organisatorische und technische Maßnahmen zu treffen, um persönliche Daten vor Missbrauch, unberechtigter Veröffentlichung und Verlust zu schützen. Diese Maßnahmen genügen mindestens den Anforderungen des § 64 BDSG (neu).</p>
<p>Auskunftsrecht</p>
<p>Sie haben das Recht, von uns Auskunft über Ihre hinterlegten Daten zu erlangen gemäß Art. 15 DS-GVO und § 57 BDSG (neu). Daneben haben Sie im Umfang des Art. 16 ff. DS-GVO bzw. § 58 BDSG (neu) das Recht, von uns Berichtigung, Löschung und Sperrung Ihrer personenbezogenen Daten zu verlangen. Bitte wenden Sie sich zur Ausübung dieser Rechte an TEAMProjekt Outsourcing GmbH, Datenschutzbeauftragter, Am Prime Parc 17, 65479 Raunheim oder per E-Mail an <a href="mailto:datenschutz@teamprojekt-outsourcing.de">datenschutz@teamprojekt-outsourcing.de</a></p>
    </div>
</section>


<section class="legal-section" id="5-weitergabe-von-daten-an-dritte-oder-in-ein-drittland">
    <div class="legal-section-head">
        <span class="legal-section-number">5</span>
        <h2>5. Weitergabe von Daten an Dritte oder in ein Drittland</h2>
    </div>
    <div class="legal-section-body">
        <p>Wir geben Ihre Daten ohne eine gesetzliche Grundlage nicht an Dritte weiter. Auch geben wir Ihre Daten nicht in ein Drittland weiter, außer Sie selbst befinden sich in einem Drittland oder die Abwicklung von Verträgen erfordert die Weitergabe Ihrer Daten in ein Drittland.</p>
<p>Web-Analyse mit etracker<br/>
Der Anbieter dieser Website nutzt Dienste der etracker GmbH aus Hamburg, Deutschland (www.etracker.com) zur Analyse von Nutzungsdaten. Wir verwenden standardmäßig keine Cookies für die Web-Analyse. Soweit wir Analyse- und Optimierungs-Cookies einsetzen, holen wir Ihre explizite Einwilligung gesondert im Vorfeld ein. Ist das der Fall und Sie stimmen zu, werden Cookies eingesetzt, die eine statistische Reichweiten-Analyse dieser Website, eine Erfolgsmessung unserer Online-Marketing-Maßnahmen sowie Testverfahren ermöglichen, um z.B. unterschiedliche Versionen unseres Online-Angebotes oder seiner Bestandteile zu testen und zu optimieren. Cookies sind kleine Textdateien, die vom Internet Browser auf dem Endgerät des Nutzers gespeichert werden. etracker Cookies enthalten keine Informationen, die eine Identifikation eines Nutzers ermöglichen.</p>
<p>Die mit etracker erzeugten Daten werden im Auftrag des Anbieters dieser Website von etracker ausschließlich in Deutschland verarbeitet und gespeichert und unterliegen damit den strengen deutschen und europäischen Datenschutzgesetzen und -standards. etracker wurde diesbezüglich unabhängig geprüft, zertifiziert und mit dem Datenschutz-Gütesiegel ePrivacyseal ausgezeichnet.</p>
<p>Die Datenverarbeitung erfolgt auf Basis der gesetzlichen Bestimmungen des Art. 6 Abs. 1 lit. f (berechtigtes Interesse) der Datenschutzgrundverordnung (DSGVO). Unser Anliegen im Sinne der DSGVO (berechtigtes Interesse) ist die Optimierung unseres Online-Angebotes und unseres Webauftritts. Da uns die Privatsphäre unserer Besucher wichtig ist, werden die Daten, die möglicherweise einen Bezug zu einer einzelnen Person zulassen, wie die IP-Adresse, Anmelde- oder Gerätekennungen, frühestmöglich anonymisiert oder pseudonymisiert. Eine andere Verwendung, Zusammenführung mit anderen Daten oder eine Weitergabe an Dritte erfolgt nicht.</p>
<p>Sie können der vorbeschriebenen Datenverarbeitung jederzeit widersprechen. Der Widerspruch hat keine nachteiligen Folgen.</p>
<p>Meine Besuchsdaten fließen in die Web-Analyse ein<br/>
Weitere Informationen zum Datenschutz bei etracker finden Sie hier.</p>
    </div>
</section>


<section class="legal-section" id="11-social-media-auftritte">
    <div class="legal-section-head">
        <span class="legal-section-number">11</span>
        <h2>11. Social-Media-Auftritte</h2>
    </div>
    <div class="legal-section-body">
        <p>Wir unterhalten öffentlich zugängliche Profile in sozialen Netzwerken. Die im Einzelnen von uns genutzten sozialen Netzwerke finden Sie nachfolgend.</p>
<p>Allgemeines<br/>
Soziale Netzwerke wie Facebook, Instagram, LinkedIn oder XING können Ihr Nutzerverhalten in der Regel umfassend analysieren, wenn Sie deren Webseiten oder eine Webseite mit integrierten Social-Media-Inhalten (z. B. Like-Buttons oder Werbebannern) besuchen.<br/>
Wenn Sie in Ihrem Social-Media-Account eingeloggt sind und unser Social-Media-Profil besuchen, kann der Betreiber des Social-Media-Portals diesen Besuch Ihrem Benutzerkonto zuordnen.</p>
<p>Rechtsgrundlage für die Verarbeitung ist in der Regel unser berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO) an einer möglichst umfassenden Präsenz im Internet. Soweit Sie um eine Einwilligung in die Datenverarbeitung gebeten werden, erfolgt die Verarbeitung auf Grundlage Ihrer Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die Sie jederzeit widerrufen können.<br/>
Eine Übermittlung in Drittländer (z. B. USA) kann erfolgen, wenn der jeweilige Anbieter seinen Sitz dort hat.</p>
<h3>Facebook</h3>
<p>Anbieter: Meta Platforms Ireland Limited<br/>
4 Grand Canal Square, Grand Canal Harbour, Dublin 2, Irland<br/>
Datenschutzerklärung: <a href="https://www.facebook.com/about/privacy/" rel="noopener" target="_blank">https://www.facebook.com/about/privacy/</a></p>
<h3>Instagram</h3>
<p>Anbieter: Meta Platforms Ireland Limited<br/>
4 Grand Canal Square, Grand Canal Harbour, Dublin 2, Irland<br/>
Datenschutzerklärung: <a href="https://help.instagram.com/519522125107875" rel="noopener" target="_blank">https://help.instagram.com/519522125107875</a></p>
<h3>LinkedIn</h3>
<p>Anbieter: LinkedIn Ireland Unlimited Company<br/>
Wilton Place, Dublin 2, Irland<br/>
Datenschutzerklärung: <a href="https://www.linkedin.com/legal/privacy-policy" rel="noopener" target="_blank">https://www.linkedin.com/legal/privacy-policy</a></p>
<h3>XING</h3>
<p>Anbieter: New Work SE<br/>
Dammtorstraße 30, 20354 Hamburg, Deutschland<br/>
Datenschutzerklärung: <a href="https://privacy.xing.com/de/datenschutzerklaerung" rel="noopener" target="_blank">https://privacy.xing.com/de/datenschutzerklaerung</a></p>
    </div>
</section>


<section class="legal-section" id="12-ihre-rechte">
    <div class="legal-section-head">
        <span class="legal-section-number">12</span>
        <h2>12. Ihre Rechte</h2>
    </div>
    <div class="legal-section-body">
        <p>Sie haben das Recht, von uns jederzeit Auskunft zu verlangen über die zu Ihnen bei uns gespeicherten Daten, sowie zu deren Herkunft, Empfängern oder Kategorien von Empfängern, an die diese Daten weitergegeben werden und den Zweck der Speicherung. Sofern Ihre Daten bei uns nicht richtig sein sollten, können Sie natürlich auch eine Berichtigung Ihrer Daten verlangen. Auch können Sie eine Löschung Ihrer Daten verlangen. Diesem Wunsch auf Löschung werden wir unverzüglich nachkommen. Ausgenommen davon sind Daten, die aufgrund gesetzlicher Vorschriften aufbewahrt oder zur ordnungsgemäßen Geschäftsabwicklung benötigt werden. Damit eine Datensperre jederzeit realisiert werden kann, werden Daten zu Kontrollzwecken in einer Sperrdatei vorgehalten. Werden Daten nicht von einer gesetzlichen Archivierungspflicht erfasst, löschen wir Ihre Daten auf Ihren Wunsch. Greift die Archivierungspflicht, sperren wir Ihre Daten. Wenn Sie eine Einwilligung zur Nutzung von Daten erteilt haben, können Sie diese jederzeit mit Wirkung für die Zukunft widerrufen. Sie haben auch ein Recht auf Datenübertragbarkeit. Wir werden Ihnen, bei einem entsprechenden Antrag von Ihrer Seite, Ihre Daten in einem maschinenlesbaren Format zur Verfügung stellen. Alle Informationswünsche, Auskunftsanfragen, Anträge auf Löschung, etc. oder Widersprüche zur Datenverarbeitung richten Sie bitte an die unten angegebenen Kontaktdaten unseres Datenschutzbeauftragten.</p>
<h3>Recht auf Beschwerde bei einer Aufsichtsbehörde</h3>
<p>Sie haben zudem das Recht, sich bei einer Datenschutz-Aufsichtsbehörde über die Verarbeitung Ihrer personenbezogenen Daten durch uns zu beschweren. Die für uns zuständige Datenschutzaufsichtsbehörde, bei der eine Beschwerde über eine Verletzung von Datenschutzrecht eingereicht werden kann, ist:</p>
<p>Der Hessische Beauftragte für Datenschutz und Informationsfreiheit<br/>
Postfach 3163<br/>
65021 Wiesbaden</p>
<p>Telefon: +49 611 1408 – 0</p>
<p>Telefax: +49 611 1408 – 900 / 901</p>
<p>E-Mail: <a href="mailto:poststelle@datenschutz.hessen.de">poststelle@datenschutz.hessen.de</a></p>
<p>Wir würden uns aber freuen, wenn Sie zuerst mit uns sprechen würden, damit wir mögliche Unklarheiten oder Unsicherheiten gemeinsam klären können.</p>
    </div>
</section>


<section class="legal-section" id="13-datensicherheit">
    <div class="legal-section-head">
        <span class="legal-section-number">13</span>
        <h2>13. Datensicherheit</h2>
    </div>
    <div class="legal-section-body">
        <p>Wir unterhalten aktuelle technische Maßnahmen zur Gewährleistung der Datensicherheit, insbesondere zum Schutz Ihrer personenbezogenen Daten vor Gefahren bei Datenübertragungen sowie vor Kenntniserlangung durch Dritte. Diese werden dem aktuellen Stand der Technik entsprechend jeweils angepasst.</p>
    </div>
</section>


<section class="legal-section" id="14-anderung-unserer-datenschutzbestimmungen">
    <div class="legal-section-head">
        <span class="legal-section-number">14</span>
        <h2>14. Änderung unserer Datenschutzbestimmungen</h2>
    </div>
    <div class="legal-section-body">
        <p>Wir behalten uns das Recht vor, unsere Sicherheits- und Datenschutzmaßnahmen zu verändern, soweit dies wegen der technischen Entwicklung erforderlich wird. In diesen Fällen werden wir auch unsere Hinweise zum Datenschutz entsprechend anpassen. Bitte beachten Sie daher die jeweils aktuelle Version unserer Datenschutzerklärung.</p>
    </div>
</section>


<section class="legal-section" id="15-fristen-zur-datenloschung">
    <div class="legal-section-head">
        <span class="legal-section-number">15</span>
        <h2>15. Fristen zur Datenlöschung</h2>
    </div>
    <div class="legal-section-body">
        <p>Personenbezogene Daten erheben, verarbeiten und nutzen wir im Rahmen von Datenvermeidung und Datensparsamkeit nur in dem Ausmaß und so lange, wie es zur Nutzung unserer Webseite notwendig ist, beziehungsweise vom Gesetzgeber vorgeschrieben wird. Im Hinblick der Löschung von Bewerberdaten beachten Sie bitte den Abschnitt „Bewerbung“.</p>
    </div>
</section>


<section class="legal-section" id="16-ansprechpartner-bei-fragen-zum-datenschutz">
    <div class="legal-section-head">
        <span class="legal-section-number">16</span>
        <h2>16. Ansprechpartner bei Fragen zum Datenschutz</h2>
    </div>
    <div class="legal-section-body">
        <p>Sollten Sie weitere Fragen zur Erhebung, Verarbeitung und Nutzung Ihrer persönlichen Daten haben, dann wenden Sie sich bitte an unseren Datenschutzbeauftragten</p>
<p>TEAMProjekt Outsourcing GmbH<br/>
Datenschutzbeauftragter<br/>
Am Prime Parc 17<br/>
65479 Raunheim<br/>
E-Mail: <a href="mailto:datenschutz@teamprojekt-outsourcing.de">datenschutz@teamprojekt-outsourcing.de</a></p>
<p>Externer Datenschutzbeauftragter:<br/>
CTM-COM GmbH<br/>
Marienburgstraße 27<br/>
64297 Darmstadt<br/>
E-Mail: <a href="mailto:datenschutz@ctm-com.de">datenschutz@ctm-com.de</a></p>
<p>Stand: 16.02.2026</p>
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
