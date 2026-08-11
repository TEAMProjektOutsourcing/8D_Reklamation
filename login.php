<?php
require_once __DIR__ . '/auth.php';

$returnTo = safe_login_return_to($_POST['return_to'] ?? ($_GET['return'] ?? ($_SESSION['login_return_to'] ?? 'dashboard.php')));

if (current_user()) {
    redirect($returnTo);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = pdo()->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];

        $returnTo = safe_login_return_to($_POST['return_to'] ?? ($_SESSION['login_return_to'] ?? 'dashboard.php'));
        unset($_SESSION['login_return_to']);

        redirect($returnTo);
    }

    $error = 'E-Mail oder Passwort ist falsch.';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · <?= e(APP_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
    <link href="assets/css/8d-app.css" rel="stylesheet">

    <style>
        :root {
            --card-bg: rgba(255, 255, 255, 0.12);
            --card-border: rgba(255, 255, 255, 0.22);
            --text-light: #ffffff;
            --text-soft: rgba(255, 255, 255, 0.78);
            --dark-blue: #0b1f36;
            --accent-blue: #0d6efd;
            --shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        }

        html, body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text-light);
            background:
                linear-gradient(100deg, rgba(6, 18, 36, 0.88) 0%, rgba(6, 18, 36, 0.72) 38%, rgba(6, 18, 36, 0.35) 100%),
                url('assets/img/login-bg-8d.png') center center / cover no-repeat fixed;
            overflow-x: hidden;
        }

        .login-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 18px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 1320px;
        }

        .login-grid {
            display: grid;
            grid-template-columns: 1.15fr 470px;
            gap: 42px;
            align-items: center;
            min-height: calc(100vh - 64px);
        }

        .login-info {
            padding: 24px 10px 24px 8px;
            max-width: 720px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 20px;
            backdrop-filter: blur(8px);
        }

        .login-info h1 {
            font-size: clamp(2.4rem, 4vw, 4.5rem);
            font-weight: 800;
            line-height: 1.03;
            margin-bottom: 18px;
            text-shadow: 0 14px 40px rgba(0, 0, 0, 0.35);
        }

        .login-info p {
            font-size: 1.08rem;
            line-height: 1.65;
            color: var(--text-soft);
            max-width: 620px;
            margin-bottom: 28px;
        }
.login-panel {
            width: 100%;
        }

        .login-card {
            border-radius: 28px;
            padding: 34px 30px 28px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .login-card h2 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 6px;
            color: #fff;
        }

        .login-card .subline {
            color: var(--text-soft);
            margin-bottom: 26px;
        }

        .form-label {
            color: #fff;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control {
            min-height: 50px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            padding: 12px 14px;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.55);
        }

        .form-control:focus {
            color: #fff;
            background: rgba(255, 255, 255, 0.16);
            border-color: rgba(13, 110, 253, 0.7);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.18);
        }

        .btn-login {
            min-height: 52px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 1rem;
            border: 0;
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            box-shadow: 0 14px 30px rgba(13, 110, 253, 0.32);
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 34px rgba(13, 110, 253, 0.38);
        }

        .alert {
            border-radius: 14px;
        }

        .login-footer-note {
            margin-top: 18px;
            text-align: center;
            color: rgba(255, 255, 255, 0.65);
            font-size: 0.92rem;
        }

        @media (max-width: 1100px) {
            .login-grid {
                grid-template-columns: 1fr 430px;
                gap: 28px;
            }
}

        @media (max-width: 991.98px) {
            .login-grid {
                grid-template-columns: 1fr;
                min-height: auto;
                gap: 26px;
            }

            .login-info {
                padding: 0;
                max-width: 100%;
                text-align: center;
            }

            .login-info p {
                margin-left: auto;
                margin-right: auto;
            }
.login-panel {
                max-width: 520px;
                margin: 0 auto;
            }
        }

        /* Smartphone: komplette Login-Ansicht ohne vertikales Scrollen */
        @media (max-width: 575.98px) {
            html,
            body {
                width: 100%;
                height: 100%;
                min-height: 100%;
                overflow: hidden;
            }

            body {
                background-attachment: scroll;
            }

            .login-shell {
                width: 100%;
                height: 100vh;
                height: 100svh;
                height: 100dvh;
                min-height: 100vh;
                min-height: 100svh;
                min-height: 100dvh;
                height: 100dvh;
                padding: 8px 12px;
                overflow: hidden;
                align-items: center;
            }

            .login-wrapper {
                height: 100%;
                display: flex;
                align-items: center;
            }

            .login-grid {
                width: 100%;
                min-height: 0;
                max-height: 100%;
                display: grid;
                grid-template-columns: 1fr;
                grid-template-rows: auto auto;
                align-content: center;
                gap: 10px;
            }

            .login-info {
                padding: 0;
                text-align: center;
            }

            .eyebrow {
                margin-bottom: 7px;
                padding: 6px 11px;
                font-size: .76rem;
            }

            .login-info h1 {
                margin-bottom: 5px;
                font-size: clamp(1.28rem, 6vw, 1.65rem);
                line-height: 1.05;
            }

            .login-info p {
                max-width: 340px;
                margin: 0 auto;
                font-size: .79rem;
                line-height: 1.3;
            }

            .login-panel {
                width: 100%;
                max-width: none;
                margin: 0;
            }

            .login-card {
                padding: 14px 14px 12px;
                border-radius: 18px;
            }

            .login-card h2 {
                margin-bottom: 1px;
                font-size: 1.35rem;
            }

            .login-card .subline {
                margin-bottom: 10px;
                font-size: .82rem;
            }

            .login-card .mb-3 {
                margin-bottom: .58rem !important;
            }

            .form-label {
                margin-bottom: 3px;
                font-size: .81rem;
            }

            .form-control {
                min-height: 40px;
                padding: 8px 10px;
                border-radius: 11px;
                font-size: .9rem;
            }

            .btn-login {
                min-height: 42px;
                margin-top: 3px !important;
                border-radius: 11px;
                font-size: .92rem;
            }

            .login-footer-note {
                margin-top: 7px;
                font-size: .74rem;
            }

            .alert {
                margin-bottom: .55rem;
                padding: .45rem .65rem;
                font-size: .8rem;
                line-height: 1.25;
            }
        }

        /* Sehr kleine bzw. kurze Smartphones */
        @media (max-width: 575.98px) and (max-height: 680px) {
            .login-grid {
                gap: 7px;
            }

            .login-info p {
                display: none;
            }

            .eyebrow {
                margin-bottom: 5px;
                padding: 5px 10px;
            }

            .login-info h1 {
                margin-bottom: 0;
                font-size: 1.22rem;
            }

            .login-card {
                padding: 11px 13px 10px;
            }

            .login-card .subline {
                margin-bottom: 7px;
            }

            .form-control {
                min-height: 38px;
            }

            .btn-login {
                min-height: 40px;
            }

            .login-footer-note {
                margin-top: 5px;
            }
        }

        /* Smartphone im Querformat */
        @media (max-height: 520px) and (orientation: landscape) {
            html,
            body {
                overflow: hidden;
            }

            .login-shell {
                height: 100vh;
                height: 100svh;
                height: 100dvh;
                min-height: 100vh;
                min-height: 100svh;
                min-height: 100dvh;
                height: 100dvh;
                padding: 6px 10px;
            }

            .login-grid {
                grid-template-columns: minmax(0, .85fr) minmax(300px, 1fr);
                grid-template-rows: 1fr;
                gap: 14px;
                align-items: center;
            }

            .login-info p {
                display: none;
            }

            .login-info h1 {
                font-size: 1.25rem;
            }

            .login-card {
                padding: 10px 12px 9px;
            }

            .login-card h2 {
                font-size: 1.2rem;
            }

            .login-card .subline {
                margin-bottom: 6px;
            }

            .login-card .mb-3 {
                margin-bottom: .4rem !important;
            }

            .form-control {
                min-height: 36px;
                padding: 6px 9px;
            }

            .btn-login {
                min-height: 38px;
            }

            .login-footer-note {
                margin-top: 4px;
            }
        }

    
        .typewriter-heading {
            min-height: 2.15em;
        }

        .typewriter-cursor {
            display: inline-block;
            width: .08em;
            height: .9em;
            margin-left: .08em;
            vertical-align: -.08em;
            background: currentColor;
            animation: typewriterCursorBlink .75s steps(1, end) infinite;
        }

        @keyframes typewriterCursorBlink {
            0%, 48% { opacity: 1; }
            49%, 100% { opacity: 0; }
        }

        .login-intro-block {
            width: 100%;
            max-width: none !important;
            margin: 0 0 28px !important;
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(255, 255, 255, .09);
            border: 1px solid rgba(255, 255, 255, .14);
            backdrop-filter: blur(7px);
            -webkit-backdrop-filter: blur(7px);
            box-sizing: border-box;
        }

        @media (max-width: 991.98px) {
            .login-intro-block {
                max-width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                text-align: left;
            }
        }

        @media (max-width: 575.98px) {
            .typewriter-heading {
                min-height: 2.15em;
            }

            .login-intro-block {
                margin-bottom: 8px !important;
                padding: 9px 11px;
                border-radius: 13px;
                font-size: .78rem !important;
                line-height: 1.28 !important;
            }
        }

        @media (max-width: 575.98px) and (max-height: 680px) {
            .login-intro-block {
                display: none;
            }
        }

    
        /* Mobile Fix: kein automatisches Hineinzoomen bei E-Mail/Passwort */
        @media (max-width: 767.98px) {
            html,
            body {
                width: 100%;
                max-width: 100%;
                overflow-x: hidden !important;
            }

            .login-shell,
            .login-wrapper,
            .login-grid,
            .login-panel,
            .login-card,
            .login-card form {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            .form-control,
            input,
            select,
            textarea {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                box-sizing: border-box;
                font-size: 16px !important;
            }

            .form-control:focus {
                transform: none;
            }
        }

    
        /* Helles Login-Design passend zum Dashboard */
        :root {
            --login-page-bg: #f4f7fb;
            --login-surface: rgba(255, 255, 255, .94);
            --login-border: rgba(148, 163, 184, .22);
            --login-text: #0f172a;
            --login-muted: #64748b;
            --login-primary: #0d6efd;
            --login-shadow: 0 24px 70px rgba(15, 23, 42, .12);
        }

        body {
            color: var(--login-text);
            background:
                radial-gradient(circle at 12% 12%, rgba(13, 110, 253, .11), transparent 30%),
                radial-gradient(circle at 88% 18%, rgba(99, 102, 241, .09), transparent 28%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 45%, #eef4fb 100%);
        }

        .login-shell {
            position: relative;
        }

        .login-shell::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(rgba(255, 255, 255, .55), rgba(255, 255, 255, .55)),
                url('assets/img/login-bg-8d.png') center center / cover no-repeat;
            opacity: .08;
            z-index: -1;
        }

        .login-info {
            color: var(--login-text);
        }

        .login-brand {
            display: flex;
            align-items: center;
            margin-bottom: 1.15rem;
        }

        .login-brand-logo {
            display: block;
            /* width: min(360px, 78%); */
            max-height: 96px;
            object-fit: contain;
            object-position: left center;
        }

        .login-brand-fallback {
            display: none;
            color: var(--login-text);
            font-size: 2rem;
            font-weight: 950;
            letter-spacing: -.03em;
        }

        .eyebrow {
            background: rgba(13, 110, 253, .08);
            border-color: rgba(13, 110, 253, .16);
            color: var(--login-primary);
            box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
        }

        .login-info h1 {
            color: var(--login-text);
            text-shadow: none;
        }

        .login-info p,
        .login-intro-block {
            color: var(--login-muted);
        }

        .login-intro-block {
            background: rgba(255, 255, 255, .78);
            border-color: var(--login-border);
            box-shadow: 0 12px 34px rgba(15, 23, 42, .06);
        }

        .login-card {
            background: var(--login-surface);
            border-color: var(--login-border);
            box-shadow: var(--login-shadow);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .login-card h2 {
            color: var(--login-text);
        }

        .login-card .subline,
        .login-footer-note {
            color: var(--login-muted);
        }

        .form-label {
            color: #334155;
        }

        .form-control {
            color: var(--login-text);
            background: #fff;
            border-color: rgba(148, 163, 184, .34);
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, .02);
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        .form-control:focus {
            color: var(--login-text);
            background: #fff;
            border-color: rgba(13, 110, 253, .62);
            box-shadow: 0 0 0 .24rem rgba(13, 110, 253, .12);
        }

        .typewriter-cursor {
            background: var(--login-primary);
        }

        @media (max-width: 991.98px) {
            .login-brand {
                justify-content: center;
            }

            .login-brand-logo {
                /* width: min(320px, 78%); */
                object-position: center;
            }
        }

        @media (max-width: 575.98px) {
            body {
                background:
                    radial-gradient(circle at top, rgba(13, 110, 253, .10), transparent 34%),
                    linear-gradient(180deg, #ffffff 0%, #f3f7fc 100%);
            }

            .login-brand {
                /* margin-bottom: .45rem; */
            }

            .login-brand-logo {
                /* width: min(250px, 72%); */
                /* max-height: 58px; */
            }

            .login-card {
                background: rgba(255, 255, 255, .96);
            }
        }

        @media (max-width: 575.98px) and (max-height: 680px) {
            .login-brand {
                margin-bottom: .2rem;
            }

            .login-brand-logo {
                width: min(210px, 64%);
                max-height: 46px;
            }
        }

        @media (max-height: 520px) and (orientation: landscape) {
            .login-brand {
                margin-bottom: .25rem;
            }

            .login-brand-logo {
                width: min(210px, 70%);
                max-height: 44px;
            }
        }

    
        /* Login-Hintergrund identisch zur Navigation */
        body {
            background: var(--app-nav-bg, #0b1f36) !important;
        }

        .login-shell::before {
            display: none !important;
        }

        .login-info,
        .login-info h1 {
            color: #ffffff;
        }

        .login-info p,
        .login-intro-block {
            color: rgba(255, 255, 255, .82);
        }

        .login-intro-block {
            background: rgba(255, 255, 255, .08);
            border-color: rgba(255, 255, 255, .14);
            box-shadow: none;
        }

        .eyebrow {
            background: rgba(255, 255, 255, .08);
            border-color: rgba(255, 255, 255, .14);
            color: #ffffff;
            box-shadow: none;
        }

        .typewriter-cursor {
            background: #ffffff;
        }

        .login-card {
            background: rgba(255, 255, 255, .96);
            border-color: rgba(255, 255, 255, .20);
        }

        @media (max-width: 575.98px) {
            body {
                background: var(--app-nav-bg, #0b1f36) !important;
            }
        }

    
        /* Finale Farbwelt passend zur dunklen Navigation */
        body {
            color: #ffffff;
            background: var(--app-nav-bg, #0b1f36) !important;
        }

        .login-info {
            color: #ffffff;
        }

        .login-brand-fallback,
        .login-info h1,
        .login-card h2,
        .form-label {
            color: #ffffff !important;
        }

        .login-info p,
        .login-card .subline,
        .login-footer-note {
            color: rgba(255, 255, 255, .76) !important;
        }

        .eyebrow {
            color: #ffffff !important;
            background: rgba(255, 255, 255, .08) !important;
            border: 1px solid rgba(255, 255, 255, .14) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .05);
        }

        .login-intro-block {
            color: rgba(255, 255, 255, .84) !important;
            background:
                linear-gradient(135deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .055)) !important;
            border: 1px solid rgba(255, 255, 255, .14) !important;
            box-shadow: 0 16px 34px rgba(0, 0, 0, .16) !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .login-card {
            background:
                linear-gradient(145deg, rgba(255, 255, 255, .115), rgba(255, 255, 255, .065)) !important;
            border: 1px solid rgba(255, 255, 255, .16) !important;
            box-shadow:
                0 26px 70px rgba(0, 0, 0, .28),
                inset 0 1px 0 rgba(255, 255, 255, .07) !important;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .form-control {
            color: #ffffff !important;
            background: rgba(255, 255, 255, .09) !important;
            border: 1px solid rgba(255, 255, 255, .18) !important;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, .12);
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, .48) !important;
        }

        .form-control:hover {
            background: rgba(255, 255, 255, .115) !important;
            border-color: rgba(255, 255, 255, .26) !important;
        }

        .form-control:focus {
            color: #ffffff !important;
            background: rgba(255, 255, 255, .14) !important;
            border-color: rgba(105, 167, 255, .9) !important;
            box-shadow:
                0 0 0 .24rem rgba(13, 110, 253, .20),
                inset 0 1px 2px rgba(0, 0, 0, .08) !important;
        }

        .btn-login {
            color: #ffffff !important;
            background: linear-gradient(135deg, #2f80ed, #0d6efd) !important;
            box-shadow: 0 16px 34px rgba(13, 110, 253, .30) !important;
        }

        .btn-login:hover,
        .btn-login:focus {
            color: #ffffff !important;
            background: linear-gradient(135deg, #3b8cf5, #0b5ed7) !important;
            box-shadow: 0 20px 42px rgba(13, 110, 253, .38) !important;
        }

        .typewriter-cursor {
            background: #ffffff !important;
        }

        .alert {
            border: 1px solid rgba(255, 255, 255, .12);
            box-shadow: 0 10px 24px rgba(0, 0, 0, .12);
        }

        .alert-danger {
            color: #ffe4e6;
            background: rgba(190, 24, 93, .28);
            border-color: rgba(251, 113, 133, .36);
        }

        .alert-info {
            color: #e0f2fe;
            background: rgba(2, 132, 199, .24);
            border-color: rgba(56, 189, 248, .34);
        }

        @media (max-width: 575.98px) {
            .login-card,
            .login-intro-block {
                background:
                    linear-gradient(145deg, rgba(255, 255, 255, .12), rgba(255, 255, 255, .07)) !important;
            }
        }

    
        /* Fix: helle NAV-/Login-Fläche braucht dunkle Schriftfarben */
        body {
            color: #0f172a !important;
            background: var(--app-nav-bg, #f4f7fb) !important;
        }

        .login-info,
        .login-brand-fallback,
        .login-info h1,
        .login-card h2,
        .form-label {
            color: #0f172a !important;
        }

        .login-info p,
        .login-card .subline,
        .login-footer-note {
            color: #64748b !important;
        }

        .eyebrow {
            color: #0d6efd !important;
            background: rgba(13, 110, 253, .08) !important;
            border: 1px solid rgba(13, 110, 253, .16) !important;
            box-shadow: none !important;
        }

        .login-intro-block {
            color: #334155 !important;
            background: rgba(255, 255, 255, .82) !important;
            border: 1px solid rgba(148, 163, 184, .24) !important;
            box-shadow: 0 14px 34px rgba(15, 23, 42, .07) !important;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .login-card {
            background: rgba(255, 255, 255, .94) !important;
            border: 1px solid rgba(148, 163, 184, .24) !important;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .12) !important;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .form-control {
            color: #0f172a !important;
            background: #ffffff !important;
            border: 1px solid rgba(148, 163, 184, .36) !important;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, .03) !important;
        }

        .form-control::placeholder {
            color: #94a3b8 !important;
        }

        .form-control:hover {
            background: #ffffff !important;
            border-color: rgba(100, 116, 139, .42) !important;
        }

        .form-control:focus {
            color: #0f172a !important;
            background: #ffffff !important;
            border-color: rgba(13, 110, 253, .68) !important;
            box-shadow: 0 0 0 .24rem rgba(13, 110, 253, .12) !important;
        }

        .typewriter-cursor {
            background: #0d6efd !important;
        }

        .alert-danger {
            color: #842029 !important;
            background: #f8d7da !important;
            border-color: #f5c2c7 !important;
        }

        .alert-info {
            color: #055160 !important;
            background: #cff4fc !important;
            border-color: #b6effb !important;
        }

        @media (max-width: 575.98px) {
            body {
                background: var(--app-nav-bg, #f4f7fb) !important;
            }

            .login-card,
            .login-intro-block {
                background: rgba(255, 255, 255, .96) !important;
            }
        }

    </style>
</head>
<body>
    <main class="login-shell">
        <div class="login-wrapper">
            <div class="login-grid">

                <div class="login-info">
                    <div class="login-brand">
                        <img
                            src="assets/logo-reklamation8d-light.png?v=60"
                            alt="Reklamation8D"
                            class="login-brand-logo"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                        >
                        <div class="login-brand-fallback">Reklamation8D</div>
                    </div>

                    <h1 class="typewriter-heading" aria-label="Qualität sichern. Probleme nachhaltig lösen.">
                        <span id="typewriterText" aria-hidden="true"></span><span class="typewriter-cursor" aria-hidden="true"></span>
                    </h1>

                    <p class="login-intro-block">
                        Verwalte Reklamationen, Maßnahmen, Nachweise und 8D-Schritte
                        zentral in einem modernen System. Melde dich an und arbeite
                        strukturiert an Ursachen, Lösungen und Verbesserungen.
                    </p>

                    
                </div>

                <div class="login-panel">
                    <div class="login-card">
                        <h2>Login</h2>
                        
                        <?php if ($returnTo !== 'dashboard.php'): ?>
                            <div class="alert alert-info py-2">
                                Du wirst nach dem Login direkt weitergeleitet.
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= e($error) ?></div>
                        <?php endif; ?>

                        <form method="post" autocomplete="off">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

                            <div class="mb-3">
                                <label class="form-label">E-Mail</label>
                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    required
                                    value="<?= e($_POST['email'] ?? '') ?>"
                                    placeholder="deine@email.de"
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Passwort</label>
                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    required
                                    placeholder="Passwort eingeben"
                                >
                            </div>

                            <button class="btn btn-primary btn-login w-100 mt-2">Einloggen</button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const target = document.getElementById('typewriterText');

    if (!target) {
        return;
    }

    const text = 'Qualität sichern.\nProbleme nachhaltig lösen.';
    const typingSpeed = 78;
    const deletingSpeed = 42;
    const pauseAfterTyping = 1700;
    const pauseAfterDeleting = 500;

    let index = 0;
    let deleting = false;

    function renderText(value) {
        target.innerHTML = value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\n/g, '<br>');
    }

    function runTypewriter() {
        if (!deleting) {
            index++;
            renderText(text.slice(0, index));

            if (index >= text.length) {
                deleting = true;
                window.setTimeout(runTypewriter, pauseAfterTyping);
                return;
            }

            window.setTimeout(runTypewriter, typingSpeed);
            return;
        }

        index--;
        renderText(text.slice(0, index));

        if (index <= 0) {
            deleting = false;
            window.setTimeout(runTypewriter, pauseAfterDeleting);
            return;
        }

        window.setTimeout(runTypewriter, deletingSpeed);
    }

    renderText('');
    window.setTimeout(runTypewriter, 350);
});
</script>

</body>
</html>