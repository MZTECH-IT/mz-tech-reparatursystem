<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Portale und Support | MZ Tech</title>
  <style>
    :root{--blue:#0057b8;--dark:#18212f;--muted:#607086;--line:#dce4ed;--bg:#f5f8fb}
    *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--dark);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
    main{max-width:1040px;margin:auto;padding:40px 20px 64px}header{text-align:center;margin-bottom:34px}
    h1{font-size:clamp(1.9rem,5vw,3rem);margin:0 0 10px}header p{color:var(--muted);margin:auto;max-width:680px}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px}
    .card{background:white;border:1px solid var(--line);border-radius:14px;padding:24px;box-shadow:0 7px 24px rgba(24,33,47,.06)}
    .card h2{font-size:1.18rem;margin:0 0 8px}.card p{color:var(--muted);min-height:76px}
    .button{display:inline-flex;align-items:center;justify-content:center;min-height:46px;width:100%;border-radius:9px;padding:10px 16px;background:var(--blue);color:white;text-decoration:none;font-weight:700}
    .button.secondary{background:white;color:var(--blue);border:2px solid var(--blue)}
    .links{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:28px}.links a{color:var(--blue)}
    .internal{margin-top:34px;padding-top:22px;border-top:1px solid var(--line);text-align:center;color:var(--muted);font-size:.9rem}
    .internal a{color:var(--muted)}@media(max-width:520px){main{padding-top:24px}.card p{min-height:auto}}
  </style>
</head>
<body>
<main>
  <header>
    <h1>Wie können wir Ihnen helfen?</h1>
    <p>Wählen Sie den passenden sicheren Zugang für Reparaturen, Firmenprojekte und Support-Tickets.</p>
  </header>
  <section class="grid" aria-label="Portalzugänge">
    <article class="card">
      <h2>Privatkunde</h2>
      <p>Reparaturstatus, Dokumente, Nachrichten und persönliche Vorgänge sicher einsehen.</p>
      <a class="button" href="portal.php?view=account_login">Kundenportal öffnen</a>
    </article>
    <article class="card">
      <h2>Firmenkunde</h2>
      <p>Projekte, Geräte, Reparaturen und Tickets Ihres Unternehmens verwalten.</p>
      <a class="button" href="portal_business.php">Firmenkundenportal öffnen</a>
    </article>
    <article class="card">
      <h2>Support-Ticket</h2>
      <p>Als Firmenkunde ein Ticket mit Projektbezug, Terminwunsch und Anhang erstellen.</p>
      <a class="button secondary" href="portal_tickets.php">Support-Ticket erstellen</a>
    </article>
    <article class="card">
      <h2>Gastzugang</h2>
      <p>Öffnen Sie den persönlichen, zeitlich begrenzten Link aus Ihrer Freigabe.</p>
      <a class="button secondary" href="portal_guest.php">Gastzugang öffnen</a>
    </article>
  </section>
  <nav class="links" aria-label="Weitere Zugänge">
    <a href="portal.php">Reparaturstatus ansehen</a>
    <a href="portal.php?view=forgot">Passwort vergessen</a>
    <a href="portal_business.php?forgot=1">Firmenpasswort vergessen</a>
  </nav>
  <div class="internal">Interner Mitarbeiter? <a href="index.php">Zum Mitarbeiterlogin</a></div>
</main>
</body>
</html>
