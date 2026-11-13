# Twitch Subscriber Alerter

Subscriber alerts for OBS that run as a lightweight **Browser Source** instead of video files, plus a small PHP site
where streamers link their Twitch channel, get a private overlay URL, and choose which alert plays for which subscriber
event. Streamers can submit their own alerts; the site owner reviews them before they can go live.

```
Twitch ──EventSub webhook──▶ public/webhook/twitch.php ──▶ MySQL (events)
                                                              ▲
OBS Browser Source ── polls public/api/poll.php ──────────────┘   plays the alert
Streamer ── dashboard / alerts pages  (settings, test buttons, event log, submit alerts)
Owner    ── streamers / review pages  (disable / resync / delete streamers, approve alerts)
```

## Alerts

There are two kinds:

* **Custom alerts**, submitted by streamers on the **Alerts** page and approved by the site owner (see below).
  These work out of the box.
* **Built-in alerts** from an optional, privately installed **alert pack**. The repository does not include one.
  Without a pack, the site runs with custom alerts only.

### Alert packs (optional)
A pack is a set of files you copy into `public/alerts/` yourself (it is git-ignored there):

```
public/alerts/catalog.json      which alerts exist (see catalog.example.json)
public/alerts/engine.js         renderer; must define window.GiftedAlerts.Player
public/alerts/data/<id>.js      one file per alert; registers window.GiftedAlertData['<id>'] = { duration, ... }
public/alerts/<page>.html       optional standalone preview page per alert
```

`catalog.json` lists the alerts in order, with a label, the default minimum number of gifted subs, and an optional
preview page:

```json
{ "alerts": { "small-gift": { "label": "Small gift", "min": 5, "page": "small-gift.html" } } }
```

The overlay creates `new GiftedAlerts.Player({ canvas, vignette })` and calls
`player.play(id, { volume, replayOnClick: false, onend })`, which must return a Promise. When a streamer chooses to
stack alerts, the overlay creates one `Player` per alert on screen (each with its own canvas and vignette, at most 4),
so a `Player` must not share mutable state with other instances. The canvas size is `GiftedAlerts.W` x
`GiftedAlerts.H` (1920 x 1080 if they are not defined). `ALERT_CATALOG` in `.env` can point at a catalog somewhere else.

## Local development with Docker

Docker Desktop runs PHP and MariaDB for you. The project directory is mounted into the container, so a refresh picks
up code changes, and the database schema is applied on startup.

```
cp .env.example .env          # then set APP_SECRET, the Twitch credentials, and ADMIN_TWITCH_LOGINS
docker compose up --build     # site at https://localhost/
docker compose down           # stop it; add -v to delete the database volume too
```

Caddy serves the site at `https://localhost` with a local certificate, and `http://localhost` redirects there.
`APP_URL` in `.env` must be `https://localhost`, and the Twitch OAuth redirect URL must be exactly
`https://localhost/callback.php`. On the Twitch form, type that into the existing box and do not click Add: an empty extra
box is what triggers "Redirect URLs must use HTTPS protocol."

The first time you start the stack, trust Caddy's local CA so the browser accepts the certificate (Windows):

```
docker compose cp proxy:/data/caddy/pki/authorities/local/root.crt caddy-root.crt
certutil -user -addstore Root caddy-root.crt
```

Compose overrides `DB_HOST`, `DB_PORT`, `DB_USER` and `DB_PASS` so the container uses the MariaDB service. That
database is only reachable inside the Compose network and its password is for local development only.

Twitch will not deliver live subscriber events to `https://localhost`, because webhooks must reach a public HTTPS
address. Login and the dashboard test buttons work. For live events, point `APP_URL` at an HTTPS tunnel and register
that same `/callback.php` URL.

## Running the site in production

Requirements: PHP 8.1+ (curl, pdo_mysql, mbstring, fileinfo), MySQL / MariaDB, a **public HTTPS URL** (Twitch only
delivers EventSub webhooks to HTTPS on port 443).

1. Create a Twitch application at <https://dev.twitch.tv/console/apps>. OAuth redirect URL: `${APP_URL}/callback.php`.
2. `cp .env.example .env` and fill it in (`APP_URL`, `APP_SECRET`, `TWITCH_CLIENT_ID/SECRET`, `DB_*`,
   `ADMIN_TWITCH_LOGINS` = your own Twitch login for the owner view). Keep `APP_ENV=production`.
3. Use a database user with rights on this one database only, not `root`.
4. `php bin/migrate.php` creates the tables (safe to repeat). It also creates the database when the
   database user is allowed to. On shared hosting, create the empty database in the panel first and import
   `database/install.sql` in phpMyAdmin instead.
   Run `php bin/migrate.php` again after every update: it also adds any columns that newer versions need.
   (The Docker setup runs it every time the app container starts.)
5. Point the web server's document root at `public/` (never the project folder).
   * Apache: `public/.htaccess` is included; a root `.htaccess` denies everything as a safety net.
   * nginx: `root .../public; index index.php;` plus the usual PHP-FPM `location ~ \.php$`.
   * Allow uploads of at least 16 MB (`upload_max_filesize`, `post_max_size`; see `docker/php.ini`).
6. Log in with Twitch on the site. That subscribes your channel to the sub events automatically.
7. Copy the overlay URL from the dashboard into an OBS **Browser Source** (1920 x 1080, untick "Shutdown source when not
   visible" and "Refresh browser when scene becomes active").

### cPanel
Build one zip and drag it into File Manager:

```
docker compose exec app php bin/package-cpanel.php
```

The zip is `dist/twitch-alerter-cpanel.zip`. It includes `database/install.sql` for phpMyAdmin and leaves `.env` out.
The click-by-click steps are in [docs/cpanel.md](docs/cpanel.md).

### Private site (only you can log in)
Set `ADMIN_TWITCH_LOGINS` to your Twitch login (or numeric ID; comma-separated for several) and
`RESTRICT_LOGIN_TO_ADMINS=true` in `.env`, then `docker compose up -d` (the app container reads `.env` when it starts).
Anyone else who completes the Twitch login is refused before anything is stored or linked for them, and sessions that
already exist for other accounts are signed out. With the list empty, nobody can log in. Streamers who were already
registered keep working overlays (their data stays until you delete them on the Streamers page).

### What triggers an alert
* **Gifted subs** (`channel.subscription.gift`): the enabled alert with the highest minimum that is still <= the number
  gifted plays; fewer than every minimum plays nothing. Minimums and on/off are editable per streamer.
* **New subs** and **resubs**: play the alert the streamer picked; each can be switched off. Subs that are gift
  *recipients* are ignored, so a gift is never announced twice. Twitch only reports a resub when the viewer chooses to
  share it.

### Alerts that arrive close together
Each streamer chooses on the dashboard (**Overlay**, *When alerts arrive close together*) how the overlay handles
alerts that overlap:

* **One after the other** (default): each alert plays in full with a short pause before the next. Up to 10 can wait;
  anything beyond that is dropped.
* **Stack them**: up to 4 alerts play at the same time and any more wait their turn. Alerts that use the same position
  on screen draw on top of each other, so streamers who stack should give their custom alerts different positions.

The choice is read by the overlay on every poll, so it applies within a few seconds without reloading OBS.

### Custom alerts (submitted by streamers, vetted by the site owner)
Streamers can submit their own alerts on the **Alerts** page:

* **Picture / video**: PNG, JPG, GIF, WebP, WebM or MP4 (8 MB), an optional sound (OGG/MP3/WAV, 3 MB), text with
  `{name}` / `{count}` placeholders, position, size, animation and duration.
* **Custom HTML**: one self-contained `.html` file (2 MB). It runs in an iframe with `sandbox="allow-scripts"` and a CSP
  that allows no network access, so assets must be embedded as `data:` URIs. The overlay sends it
  `postMessage({type:'alert', name, count, months, kind, volume})`; it can reply `{type:'done'}` to finish early.
  A starter is at `public/alerts/custom-template.html`.

**Nothing goes live until the site owner approves it** (the **Review** tab, admins only). The reviewer sees the file
details, a static scan of HTML alerts (iframes, `eval`, network calls, navigation, external URLs), the source, and a live
preview, then approves it as **private** (only the submitter can use it) or **public** (every streamer can add it), or
rejects it with a reason. An approved alert can be taken offline again at any time. Approved alerts are locked: to change
one, delete it and submit again. Approved alerts show up beside the built-in ones in the gift-count rules and in the
new-sub / resub pickers.

How it is enforced: uploads are stored outside the web root (`storage/uploads`, or `UPLOAD_DIR`) and only served through
`media.php`, which gives an overlay key access to approved alerts it may use and nothing else. Files are validated by
content, not by name. Revoking or deleting an alert stops it on the overlay immediately, including events already
queued. Limits: 25 alerts and 5 pending submissions per streamer.

### Security notes
* Only the `channel:read:subscriptions` scope is requested. The user's token is used once for reading the profile and is
  **not stored**; webhooks use an app token that is cached in the `app_state` table.
* The overlay URL contains a secret key (treat it like a stream key); "Generate a new URL" revokes the old one.
* Webhooks are verified with HMAC-SHA256 (secret derived from `APP_SECRET`, at least 32 characters), a 10-minute replay
  window and a constant-time compare; duplicate message ids are ignored.
* Sessions are HttpOnly + SameSite=Lax (+ Secure over HTTPS), every form has a CSRF token, pages send a strict
  Content-Security-Policy, and HSTS is sent in production. Admin rights follow `ADMIN_TWITCH_LOGINS` on every request.
* Errors are logged to `storage/logs/`, never shown to visitors in production.
* `TWITCH_AUTH_URL` / `TWITCH_API_URL` (used by the tests to fake Twitch) are ignored unless `APP_ENV=development`.

## Tests

With Docker, from the project folder:

```
docker compose exec app php tests/run.php                                          # unit tests
docker compose run --rm --entrypoint "" app sh tests/docker-integration.sh         # end-to-end
```

The end-to-end test (login, EventSub sync, webhooks, overlay feed, admin, restricted logins, custom alert upload /
review / revoke / delete, account deletion) runs in a one-off container against a **throw-away** database (`tsa_test`),
upload folder, a fake Twitch and a test alert catalog (`tests/fixtures/alert-catalog.json`), so your real data is
untouched. `tests/integration.php` refuses to run unless `DB_NAME` contains `test`, because it empties the tables.

After changing `Dockerfile` or `docker/php.ini` (upload limits), run `docker compose up -d --build`.

`tools/cdp.mjs` is a tiny headless-Chrome driver used for screenshots and timing tests during development.

## License

This project is released under the [MIT License](LICENSE). The license covers the code in this repository. An alert pack
you install yourself (artwork, sounds, animation data) stays yours and is not part of this license.
