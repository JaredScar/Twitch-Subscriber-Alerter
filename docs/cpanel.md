# Deploying on cPanel

The site is a normal PHP application. cPanel has to serve the `public/` folder, and nothing above it.
Twitch only delivers subscriber events to a public `https://` address on port 443, so the domain needs a real
certificate before you log in.

## What the host needs

* PHP 8.1 or newer, with `pdo_mysql`, `curl`, `mbstring`, and `fileinfo`
* MySQL 8 or MariaDB 10.4 or newer
* A domain or subdomain
* AutoSSL, or another certificate that covers that hostname

No cron job is required. The overlay polls the site, and Twitch calls the webhook when a sub happens.

## Where the files go

Put the project **outside** `public_html`, then point the domain at its `public` folder:

```
/home/USERNAME/twitch-alerter/     .env, src/, storage/, database/
/home/USERNAME/twitch-alerter/public/    <-- document root
```

`USERNAME` is the cPanel account name. Two ways to get the files there:

* **Git.** In cPanel open **Git Version Control**, clone this repository into `/home/USERNAME/twitch-alerter`.
  A public repository does not need a key.
* **Upload.** On GitHub use **Code → Download ZIP**, upload that zip in **File Manager**, and extract it into
  `/home/USERNAME`. Rename the extracted folder to `twitch-alerter` if you want the path above.

Do not extract the project into `public_html` and leave the domain pointed at `public_html`. The project-root
`.htaccess` would refuse every request, and removing that file while the domain still points at the project folder
would publish `.env`.

An alert pack is not part of the repository. If you have one, copy it into `public/alerts/` after the code is in
place. See the repository README for the files a pack contains.

## Database

In **MySQL Databases**:

1. Create a database. When the form asks for a collation, choose `utf8mb4_unicode_ci`.
2. Create a user with a long password.
3. **Add User To Database** and grant **ALL PRIVILEGES**.

cPanel prefixes both names with the account name, so a database you call `alerter` is stored as
`USERNAME_alerter`. The database user usually cannot create databases, which is why the panel does this step.
The name may only contain letters, digits, and underscores.

## Settings

In File Manager, turn on **Show Hidden Files**, copy `.env.example` to `.env` in the project folder (the one that
contains `src/`, not `public/`), and fill it in:

```
APP_URL=https://alerts.example.com
APP_ENV=production
APP_SECRET=...at least 32 characters...
TWITCH_CLIENT_ID=...
TWITCH_CLIENT_SECRET=...
ADMIN_TWITCH_LOGINS=your_twitch_login
RESTRICT_LOGIN_TO_ADMINS=false
DB_HOST=localhost
DB_PORT=3306
DB_NAME=USERNAME_alerter
DB_USER=USERNAME_alerter
DB_PASS=...
```

`APP_URL` has no trailing slash, and it is the exact hostname people will open. Use `DB_HOST=localhost`. On cPanel,
`127.0.0.1` is a different login and the database user is often not allowed to use it.

Generate `APP_SECRET` in **Terminal** with:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Set `ADMIN_TWITCH_LOGINS` before you turn on `RESTRICT_LOGIN_TO_ADMINS`. With the restriction on and the list empty,
nobody can log in.

## Point the domain at public/

If this hostname already serves another site from `public_html`, create a subdomain such as `alerts.example.com`
instead of replacing that site.

In **Domains**, edit the domain and set the document root to:

```
/home/USERNAME/twitch-alerter/public
```

Then open **SSL/TLS Status** and run **AutoSSL**. Wait until the hostname shows a valid certificate. Twitch rejects
a self-signed or missing certificate.

In **MultiPHP Manager**, select that domain and choose PHP 8.1, 8.2, or 8.3.

`public/.user.ini` raises the upload limit to 16 MB so a picture or video alert can be submitted with its sound.
Some hosts lock that setting. If an upload fails immediately, set `upload_max_filesize` to `16M` and `post_max_size`
to `20M` in **MultiPHP INI Editor** for this domain.

## Create the tables

**Terminal**, from the project folder:

```
cd ~/twitch-alerter
php bin/migrate.php
```

It prints `Database ... ready`. Running it again is safe.

cPanel's **Terminal** `php` is sometimes older than the version you picked for the domain. If `php -v` is below 8.1,
run the matching binary instead, for example `ea-php83 bin/migrate.php`.

Without Terminal access, open **phpMyAdmin**, select the database, choose **Import**, and import `database/schema.sql`
from the project folder.

## Twitch

In the [Twitch developer console](https://dev.twitch.tv/console/apps), add this OAuth redirect URL:

```
https://alerts.example.com/callback.php
```

It must match `APP_URL` exactly, including `https://` and without a trailing slash. Leave any existing localhost
redirect in the list if you still use the Docker setup; Twitch allows more than one.

Open the site and log in with Twitch. That login creates the subscriber webhooks. The channel has to be allowed to
have subscribers (Twitch Affiliate or Partner), or Twitch will refuse the subscription and the dashboard will show
the error.

## Check that it worked

* The homepage loads over `https://`.
* Log in, and the dashboard shows an overlay URL.
* **Send to overlay** plays a test alert when that URL is open, including in OBS.
* `https://alerts.example.com/.env` is a 404.
* After a real sub, a row appears in the dashboard event log. Twitch's first call is a verification request; the
  site answers it on its own.

In OBS, add a **Browser Source** with the overlay URL, width 1920, height 1080. Untick **Shutdown source when not
visible** and **Refresh browser when scene becomes active**.

## Updating

From the project folder, `git pull` (or upload the changed files again). Leave `.env`, `storage/`, and an installed
alert pack in `public/alerts/` as they are. Pull does not delete those. Run `php bin/migrate.php` again after every
update: it adds any new tables and settings columns, and does nothing if there is nothing to add. Until you run it,
saving the dashboard settings can fail after an update that added a setting.

## When something fails

**Every page is 403.** The document root is the project folder rather than `public/`. Point it at `public/`.
If the host only allows the site to live inside `public_html` and the homepage is still 403, the project-root
`.htaccess` (the file that says `Require all denied`) is being applied. Rename that one file on the server, keep
`public/.htaccess`, and immediately open `https://your-domain/.env`. It must be a 404. If the browser shows the
file, put the `.htaccess` back and fix the document root before logging in.

**A page is 500 or blank.** `APP_ENV=production` hides the message. Read `storage/logs/php-error.log` in File
Manager, and the domain's entry in cPanel **Errors**. The first request creates `storage/logs`. If that folder
cannot be created, create `storage`, `storage/logs`, and `storage/uploads` yourself. The default permissions
(755 for folders) are enough, because PHP runs as your cPanel user.

**Login returns to Twitch and then fails.** The redirect URL in the Twitch console does not match `APP_URL`, or
the certificate is not ready yet.

**The dashboard says the webhook failed.** `APP_URL` has to be the public `https://` address Twitch can reach,
on port 443, with no extra path. A CDN in front of the site must allow `POST` to `/webhook/twitch.php`.

**Migration says Access denied or Unknown database.** The user was not added to the database, the prefixed names
in `.env` do not match the panel, or `DB_HOST` is `127.0.0.1` instead of `localhost`.

**Uploads fail straight away.** The host is ignoring `public/.user.ini`. Raise `upload_max_filesize` and
`post_max_size` in **MultiPHP INI Editor**, as above.
