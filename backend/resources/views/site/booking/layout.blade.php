<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title','Book your event') | Zargul Silver Marquee</title>
<style>
:root{--forest:#123d2d;--gold:#b99542;--cream:#f6f4ee;--line:#e3e4db;--ink:#172b24;--muted:#68776e}
*{box-sizing:border-box}body{margin:0;background:var(--cream);color:var(--ink);font:14px/1.55 system-ui,-apple-system,'Segoe UI',sans-serif}
a{color:inherit;text-decoration:none}.publichead{display:flex;align-items:center;justify-content:space-between;padding:14px 5vw;background:#fffdf7;border-bottom:1px solid #e4ddca;gap:16px}
.public-brand{display:flex;align-items:center;gap:11px;font:700 16px Georgia,serif;letter-spacing:.06em;color:var(--forest)}.brandmark{display:grid;place-items:center;height:38px;width:38px;border:1px solid var(--gold);color:var(--gold);font-size:22px}.public-brand small{display:block;font:600 8px system-ui;letter-spacing:.2em;color:#7a6947}.publicnav{display:flex;gap:16px;align-items:center;font-size:12px;font-weight:650;color:#46564a}.publicnav a.active{color:#a37a2b}
.bookwrap{max-width:1060px;margin:auto;padding:38px 5vw}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.17em;font-weight:750;color:var(--gold)}h1{font:600 clamp(2.2rem,4vw,3.2rem)/1.1 Georgia,serif;color:var(--forest);margin:8px 0 6px}h2{font:600 1.55rem Georgia,serif;color:var(--forest);margin:0 0 8px}h3{margin:0 0 8px}.steps{display:flex;gap:6px;flex-wrap:wrap;margin:18px 0 22px}.step{padding:7px 10px;border-radius:7px;background:#e4e7dd;color:#69776d;font-size:12px;font-weight:650}.step.active{background:var(--forest);color:#fff}.step.done{background:#d4e9d6;color:#185236}
.booking-layout{display:grid;grid-template-columns:1.4fr 1fr;gap:18px}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 3px 15px #173b2910;min-width:0}
.fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:grid;gap:5px;font-size:12px;font-weight:700;color:#385048}.field input,.field select,.field textarea{border:1px solid #cfd8cd;border-radius:7px;padding:10px;width:100%;font:inherit;min-height:40px;background:#fff}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 14px;border:1px solid transparent;border-radius:7px;font-weight:700;font-size:13px;cursor:pointer}.btn.primary{background:var(--forest);color:#fff}.btn.gold{background:linear-gradient(120deg,#c0a14b,#a57d2c);color:#fff}.btn.outline{background:#fff;color:var(--forest);border-color:#d5dcd1}.btn.danger{background:#fff;color:#a42f45;border-color:#e1bcc1}
.notice{background:#f5eacb;color:#68511f;padding:10px 12px;border-radius:7px;font-size:12px;margin-bottom:14px}.error{color:#a01818;border:1px solid #e1bcc1;background:#fff5f5;padding:10px;border-radius:7px;margin-bottom:12px}
.price{font:700 1.6rem Georgia,serif;color:var(--forest)}.meta{color:var(--muted);font-size:12px}.summary-lines>div{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13px}.summary-lines .total{font-weight:750;color:var(--forest)}
.hall-list{display:grid;gap:14px}.hall-list .card{display:grid;grid-template-columns:1.2fr 1fr;gap:14px;align-items:center}.hall-list ul{margin:6px 0;padding-left:18px;color:var(--muted);font-size:12px}
code{word-break:break-all;background:#f2f0e8;padding:2px 5px;border-radius:4px}
.pubfoot{background:#153a2d;color:#e1ece1;padding:26px 6vw;margin-top:34px;font-size:12px}.pubfoot strong{color:#e7c87b}
@media(max-width:800px){.booking-layout{grid-template-columns:1fr}.fields{grid-template-columns:1fr}.hall-list .card{grid-template-columns:1fr}.publicnav{display:none}.bookwrap{padding:24px 16px}}
</style></head><body>
<header class="publichead"><a class="public-brand" href="/"><span class="brandmark">✦</span><span>ZARGUL SILVER MARQUEE<small>WEDDINGS &amp; CELEBRATIONS</small></span></a>
<nav class="publicnav" aria-label="Booking navigation"><a href="/">Home</a><a href="/#halls">Our Halls</a><a href="/#packages">Packages</a><a class="active" href="/book">Book now</a><a href="/book/status">Booking status</a></nav></header>
@yield('content')
<footer class="pubfoot"><strong>ZARGUL SILVER MARQUEE</strong> · Celebrating life's memorable moments. <span style="float:right">Secure booking · No live payment provider</span></footer>
</body></html>
