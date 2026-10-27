"""Fill a mailbox with made-up mail for the webmail screenshots.

    IMAP_HOST=127.0.0.1 IMAP_PORT=1143 MAIL_USER=admin@example.onion \
    MAIL_PASSWORD='correct horse battery' python3 tools/demo/mail/seed_mail.py

The messages are written to the logged-in user ("Ash", the admin account the
screenshots use); twelve in the inbox, three sent, one draft. Run it once:
running it again adds the messages a second time.
"""
import imaplib, os, datetime as dt
from email.message import EmailMessage
from email.utils import format_datetime, make_msgid

ME = os.environ.get('MAIL_FROM', 'Ash <admin@example.onion>')
NOVA = 'Nova <nova@example.onion>'
now = dt.datetime.now(dt.timezone(dt.timedelta(hours=1)))

def msg(frm, to, subj, body, ago, html=None, attach=None):
    m = EmailMessage()
    m['From'], m['To'], m['Subject'] = frm, to, subj
    d = now - ago
    m['Date'] = format_datetime(d)
    m['Message-ID'] = make_msgid(domain='example.onion')
    m.set_content(body)
    if html:
        m.add_alternative(html, subtype='html')
    if attach:
        name, ctype, data = attach
        main, sub = ctype.split('/')
        m.add_attachment(data, maintype=main, subtype=sub, filename=name)
    return m, d

H = lambda **k: dt.timedelta(**k)
inbox = [
    (msg(NOVA, ME, 'Re: Moderation rota for November',
         "Hi Ash,\n\nI've put you down for the weekend shifts on the board again, and Saffi said she'd cover the chat on Tuesdays.\n\nOne thing: could we add a 'slow mode' note to the rules page? People keep asking why their posts wait 30 seconds.\n\nNova", H(minutes=14)), set()),
    (msg('Kestrel <kestrel@example.onion>', ME, 'Crawler stats for this week',
         "Weekly numbers from the onion crawler:\n\n  pages indexed      41 284  (+1 902)\n  live services       2 117\n  dead since last     63\n  blocklist hits      18\n\nThe big jump is the new seed list Otto sent. Full log attached.\n\nK.", H(hours=2, minutes=5),
         attach=('crawl-week-43.txt', 'text/plain', b"2026-10-19 crawl start\n2026-10-19 seeds 412\n...\n2026-10-25 crawl end, 41284 pages\n")), set()),
    (msg('Otto <otto@example.onion>', ME, 'Wiki: page on running your own relay',
         "I finished the draft of the relay guide on the wiki ([[Running a relay]]). It still needs a section on bandwidth limits; if you know someone who runs one at home I'd love a second pair of eyes.", H(hours=5)), {'\\Seen'}),
    (msg('Mirelle <mirelle@example.onion>', ME, 'Theme idea: "Paper"',
         "What about an off-white theme with a serif font, like a printed newsletter? I mocked it up with just CSS variables, so it should drop into the theme folder. Want me to post it on the board first so people can vote?", H(hours=9)), {'\\Seen', '\\Flagged'}),
    (msg('Riseup Lists <lists@lists.example.org>', ME, '[selfhost] Digest, Vol 214, Issue 3',
         "Today's topics:\n\n  1. Backing up MariaDB without downtime\n  2. Tor v3 client authorization in practice\n  3. Re: Moving off a VPS\n", H(hours=20),
         html="<html><body><h2 style='font-family:sans-serif'>selfhost digest</h2><p>Today's topics:</p><ol><li>Backing up MariaDB without downtime</li><li>Tor v3 client authorization in practice</li><li>Re: Moving off a VPS</li></ol><p><img src='https://lists.example.org/pixel.gif'> <a href='https://lists.example.org/unsubscribe'>Unsubscribe</a></p><script>alert(1)</script></body></html>"), {'\\Seen'}),
    (msg('Juniper <juniper@example.onion>', ME, 'Thanks for the invite!',
         "Just wanted to say thanks for the invite code. The chat is lovely and it's the first site in years that works on my old laptop without the fan spinning up.\n\n- J", H(days=1, hours=3)), {'\\Seen'}),
    (msg('Saffi <saffi@example.onion>', ME, 'Re: Spam wave on /tech/',
         "The bot trap caught 37 of them overnight. I banned the last three by hand and pruned their threads. Nothing got through to the front page.\n\nMaybe raise the captcha difficulty for new accounts for a week?", H(days=1, hours=10)), {'\\Seen', '\\Answered'}),
    (msg('Kestrel <kestrel@example.onion>', ME, 'Torrent DHT index rebuilt',
         "The torrentds index finished rebuilding after the disk swap. Search is back, magnet links verified against the infohashes.", H(days=2, hours=4)), {'\\Seen'}),
    (msg(NOVA, ME, 'Canary renewed?',
         "Reminder that the warrant canary expires on the 1st. Can you sign the new one this week?", H(days=3)), {'\\Seen'}),
    (msg('Otto <otto@example.onion>', ME, 'Seed list for the onion crawler',
         "Here's the list of 400-ish services I collected from the directories. I removed everything on our blocklist already.", H(days=4, hours=6)), {'\\Seen'}),
    (msg('noreply@example.onion', ME, 'Weekly backup completed',
         "Backup of content_manager finished: 184 MB, 0 errors. Retention pruned 2 old snapshots.", H(days=5)), {'\\Seen'}),
    (msg('Mirelle <mirelle@example.onion>', ME, 'Italian translation is complete',
         "Every catalog now has its Italian strings. The parity check passes.", H(days=6, hours=2)), {'\\Seen'}),
]
sent = [
    (msg(ME, 'Saffi <saffi@example.onion>', 'Re: Spam wave on /tech/',
         "Good call. I've raised it for accounts younger than 7 days. Thanks for jumping on it so fast.\n\nAsh", H(days=1, hours=8)), {'\\Seen'}),
    (msg(ME, 'Juniper <juniper@example.onion>', 'Welcome aboard',
         "Glad you made it in! Ping me if anything looks off.", H(days=1, hours=2)), {'\\Seen'}),
    (msg(ME, 'Kestrel <kestrel@example.onion>', 'Disk swap tonight',
         "I'll take the search box down at 23:00 for the disk swap; should be back within the hour.", H(days=2, hours=12)), {'\\Seen'}),
]
drafts = [
    (msg(ME, 'everyone@example.onion', 'November update',
         "Hi all,\n\nA few things from this month:\n\n- the onion index passed 40 000 pages\n- a new theme is on the way\n- ", H(hours=1)), {'\\Seen', '\\Draft'}),
]

c = imaplib.IMAP4(os.environ.get('IMAP_HOST', '127.0.0.1'), int(os.environ.get('IMAP_PORT', '1143')))
c.login(os.environ.get('MAIL_USER', 'admin@example.onion'), os.environ.get('MAIL_PASSWORD', 'correct horse battery'))
for box, items in (('INBOX', inbox), ('Sent', sent), ('Drafts', drafts)):
    for (m, d), flags in sorted(items, key=lambda x: x[0][1]):
        typ, data = c.append(box, '(' + ' '.join(flags) + ')', imaplib.Time2Internaldate(d.timestamp()), m.as_bytes())
        if typ != 'OK':
            raise SystemExit(f'{box}: {data}')
c.logout()
print('ok')
