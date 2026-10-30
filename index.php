<?php
session_start();

$dashboardPath = [
    'student' => 'student_dashboard.php',
    'teacher' => 'teacher_dashboard.php',
    'admin' => 'dashboard.php'
][$_SESSION['role'] ?? ''] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ResProg helps students and research teachers organize research progress, documents, feedback, and milestones.">
    <title>ResProg | Research, clearly in progress</title>
    <style>
        html { scroll-behavior: smooth; scroll-padding-top: 24px; }
        @view-transition { navigation: auto; }
        .landing { margin: 0; overflow-x: hidden; background: #041334; color: #e8f4ff; }
        .landing-nav, .nav-links, .hero-actions, .feature-grid, .steps { display: flex; align-items: center; }
        .landing-nav { position: absolute; z-index: 1; top: 0; left: 0; width: 100%; justify-content: space-between; gap: 24px; padding: 22px 7%; }
        .brand { display: inline-flex; align-items: center; gap: 11px; color: #f1f8ff; font-size: 20px; font-weight: 800; text-decoration: none; }
        .brand-mark { display: grid; width: 40px; height: 40px; place-items: center; border: 1px solid #40e9f4a8; border-radius: 13px; background: #0c7fb18a; color: #77f6ff; box-shadow: 0 0 24px #00d9ff30; }
        .nav-links { gap: 26px; }
        .nav-links a { color: #d7eaff; font-weight: 600; text-decoration: none; transition: color .2s ease; }
        .nav-links a:hover { color: #55efff; }
        .nav-links .nav-cta { padding: 11px 17px; border: 1px solid #36dff1a6; border-radius: 10px; background: #057fba8c; color: white; box-shadow: 0 0 24px #0bdcff30; }
        .hero { position: relative; display: flex; min-height: min(760px, 100svh); align-items: center; max-width: none; margin: 0; padding: 130px max(7%, calc((100% - 1220px) / 2)); background: linear-gradient(90deg, #041334 0%, rgb(4 19 52 / 94%) 32%, rgb(4 19 52 / 30%) 64%, transparent), url("assets/resprog-dashboard.png") right center / 78% auto no-repeat, linear-gradient(135deg, #041334, #083969 58%, #041334); }
        .hero-copy-block { position: relative; z-index: 0; width: min(520px, 46%); }
        .landing .eyebrow { color: #51eaf6; font-size: 12px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        .hero h1 { max-width: 680px; margin: 17px 0; color: #f3f8ff; font-size: clamp(42px, 5.3vw, 70px); letter-spacing: -.055em; line-height: 1.04; text-shadow: 0 3px 28px #00102e; }
        .hero h1 span { color: #54efff; }
        .hero-copy { max-width: 550px; color: #d0e3fa; font-size: 18px; line-height: 1.75; text-shadow: 0 2px 18px #00102e; }
        .hero-actions { flex-wrap: wrap; gap: 12px; margin-top: 28px; }
        .hero-actions a { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 19px; border: 1px solid transparent; border-radius: 11px; font-weight: 700; text-decoration: none; transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease; }
        .hero-actions a:hover { transform: translateY(-3px); }
        .primary-link { background: linear-gradient(110deg, #0879e9, #02c5d6); color: white; box-shadow: 0 8px 30px #00c8fa50; }
        .primary-link:hover { color: white; box-shadow: 0 12px 35px #00d9ff75; }
        .secondary-link { border-color: #59dff277; background: #0a326499; color: #effaff; backdrop-filter: blur(8px); }
        .secondary-link:hover { border-color: #55efff; background: #0d4779c9; color: white; }
        .section { max-width: 1160px; margin: 0 auto; padding: 88px 28px; }
        .landing main { max-width: none; }
        .landing .section-heading h2 { color: #f1f7ff; }
        .landing .section-heading p:not(.eyebrow) { color: #a9c2e0; }
        .section-heading { max-width: 650px; margin-bottom: 30px; }
        .section-heading h2 { margin: 9px 0; font-size: clamp(30px, 4vw, 42px); letter-spacing: -.035em; }
        .section-heading p { line-height: 1.7; }
        .feature-grid { align-items: stretch; gap: 17px; }
        .landing .feature { flex: 1; min-width: 0; padding: 25px; border: 1px solid #3b76ae80; border-radius: 17px; background: linear-gradient(145deg, #142f59e8, #0b2045ed); box-shadow: 0 14px 35px #00072645; transition: transform .24s ease, border-color .24s ease, box-shadow .24s ease, background-color .24s ease; }
        .landing .feature:hover { transform: translateY(-7px) scale(1.015); border-color: #43e7ff; background: linear-gradient(145deg, #183d70, #0d2852); box-shadow: 0 20px 46px #00cfff2b, 0 0 0 1px #46e9ff35; }
        .feature-icon { display: grid; width: 42px; height: 42px; place-items: center; border: 1px solid #4be7f74d; border-radius: 13px; background: #0977a966; color: #67efff; font-weight: 800; }
        .landing .feature h3 { margin: 18px 0 8px; color: #edf6ff; }
        .landing .feature p { margin: 0; color: #aac3e0; font-size: 14px; line-height: 1.7; }
        .steps { justify-content: space-between; align-items: stretch; gap: 18px; }
        .step { flex: 1; padding: 20px; border-top: 2px solid #20d9f075; border-radius: 3px; transition: transform .22s ease, border-color .22s ease; }
        .step:hover { transform: translateY(-4px); border-color: #54efff; }
        .step b { color: #53eaf7; font-size: 12px; letter-spacing: .08em; }
        .landing .step h3 { margin: 10px 0 7px; color: #eaf5ff; }
        .landing .step p { margin: 0; color: #a9c2e0; font-size: 14px; line-height: 1.6; }
        .landing-footer { padding: 24px; border-top: 1px solid #2e558180; color: #8eacce; text-align: center; font-size: 13px; }
        @media (max-width: 760px) {
            .landing-nav { padding: 17px 6%; }
            .nav-links { gap: 13px; }
            .nav-links a:not(.nav-cta) { display: none; }
            .hero { min-height: 720px; align-items: flex-end; padding: 110px 7% 76px; background-image: linear-gradient(0deg, #041334 0%, #041334ed 37%, #04133436 100%), url("assets/resprog-dashboard.png"), linear-gradient(135deg, #041334, #083969 58%, #041334); background-position: center, center top, center; background-size: auto, 100% auto, cover; background-repeat: no-repeat; }
            .hero::before { position: absolute; inset: 0; background: linear-gradient(0deg, #041334 0%, #041334ed 10%, #041334b0 48%, #04133430 100%); content: ""; }
            .hero-copy-block { width: min(100%, 590px); }
            .section { padding: 66px 22px; }
            .feature-grid, .steps { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 420px) {
            .hero { min-height: 680px; padding-right: 22px; padding-left: 22px; background-size: auto, 112% auto, cover; }
            .hero h1 { font-size: clamp(39px, 12vw, 52px); }
            .hero-copy { font-size: 16px; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { transition-duration: .01ms !important; }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body class="landing">
    <nav class="landing-nav" aria-label="Main navigation">
        <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">R</span> ResProg</a>
        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#how-it-works">How it works</a>
            <?php if ($dashboardPath !== null): ?>
                <a class="nav-cta" href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
            <?php else: ?>
                <a class="nav-cta" href="login.php">Sign in</a>
            <?php endif; ?>
        </div>
    </nav>

    <main>
        <section class="hero">
            <div class="hero-copy-block">
                <p class="eyebrow">Research Progress Tracking and Document Management</p>
                <h1>Research, clearly <span>in progress.</span></h1>
                <p class="hero-copy">A shared space for students and research teachers to keep every milestone, manuscript, and piece of feedback moving forward.</p>
                <div class="hero-actions">
                    <a class="primary-link" href="<?= $dashboardPath !== null ? htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') : 'login.php' ?>"><?= $dashboardPath !== null ? 'Open Dashboard' : 'Get started' ?> <span aria-hidden="true">&nbsp;→</span></a>
                    <a class="secondary-link" href="login.php">Explore the Platform</a>
                </div>
            </div>
        </section>

        <section class="section" id="features">
            <div class="section-heading">
                <p class="eyebrow">One organized workspace</p>
                <h2>Explore the Platform</h2>
                <p>ResProg connects student work with teacher guidance and clear progress visibility—from the first milestone to the final manuscript.</p>
            </div>
            <div class="feature-grid">
                <article class="feature"><span class="feature-icon" aria-hidden="true">01</span><h3>Track progress</h3><p>Share research updates, see review status, and keep group progress visible.</p></article>
                <article class="feature"><span class="feature-icon" aria-hidden="true">02</span><h3>Submit manuscripts</h3><p>Upload PDF research documents and follow them through teacher review.</p></article>
                <article class="feature"><span class="feature-icon" aria-hidden="true">03</span><h3>Stay in sync</h3><p>Keep milestones, requirements, timetables, and adviser feedback in one place.</p></article>
            </div>
        </section>

        <section class="section" id="how-it-works">
            <div class="section-heading"><p class="eyebrow">Simple by design</p><h2>A clearer path from draft to done.</h2></div>
            <div class="steps">
                <article class="step"><b>STEP 01</b><h3>Join your group</h3><p>Your administrator connects students and teachers to the right research group.</p></article>
                <article class="step"><b>STEP 02</b><h3>Share your work</h3><p>Post progress updates and securely submit your research manuscript as a PDF.</p></article>
                <article class="step"><b>STEP 03</b><h3>Review and improve</h3><p>Your assigned teacher reviews submissions and provides guidance as work advances.</p></article>
            </div>
        </section>
    </main>
    <footer class="landing-footer">ResProg · Research Progress Tracking and Document Management System</footer>
</body>
</html>
