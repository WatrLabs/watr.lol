# watr.lol
one account for every watr thing, plus a forum.

built on [watrfiles](https://github.com/watrabi/watrfiles) (the newest watrkit) and styled with [the watr style](https://github.com/WatrLabs/watr-style) v0.5.

## what's in it
- **accounts**: sign up, log in (username or email), profiles, settings, see and log out your devices. the first account made becomes admin
- **email**: confirm an address, reset a forgotten password, and get a heads up when your password or email changes. sends through any smtp login (zoho, see below)
- **sign in with discord, github, google, or any oauth2 provider**: log in with them, sign up with them (you just pick a username), or link them to an account you already have. each one turns on when you put its client id and secret in `.env`
- **sign in with watr.lol**: other sites send people here to log in, like "sign in with discord" but it's us. plain oauth 2.0 authorization code flow, with optional pkce. anyone can register an app at `/developers`, docs are at `/developers/docs`
- **forum**: categories, threads, replies, light formatting (`**bold**`, quotes, code, links, @mentions), pin, lock, move, edit, delete
- **admin**: people (roles, bans), forum categories, apps (mark watrlabs' own sites as trusted so they skip the "allow?" screen)

## setup
configure `.env.example` and rename it to `.env`. you need an `APP_KEY`:
```
php -r "echo bin2hex(random_bytes(32));"
```

install composer and run
`composer install`

then run
`vendor/bin/phinx migrate` (this puts all the migrations in)\
and if you want some starter forum categories
`vendor/bin/phinx seed:run`

there's no `phinx init` step anymore, `phinx.php` reads the same `.env` as the site.

point your web server at `public/`. apache uses `public/.htaccess`. for nginx:
```
location / { try_files $uri /index.php?$query_string; }
```

for messing around locally you don't need mysql: set `DB_DRIVER=sqlite`, migrate, and
```
php -S localhost:8080 -t public public/index.php
```

## mail (zoho)
fill in the `MAIL_` settings in `.env`. it logs in to zoho's smtp server and sends from there, so your own server never touches port 25 and an isp that blocks it doesn't matter.

```
MAIL_HOST="smtp.zoho.com"          # smtp.zoho.eu / .in / .com.au for other regions,
                                   # smtppro.zoho.com if it's paid zoho mail on your own domain
MAIL_PORT=465
MAIL_ENCRYPTION=ssl                # or 587 + tls
MAIL_USERNAME="noreply@watr.lol"   # the zoho mailbox
MAIL_PASSWORD="..."                # an app-specific password if the mailbox has 2fa
MAIL_FROM=""                       # empty = MAIL_USERNAME. has to be that mailbox or one of its aliases
MAIL_FROM_NAME="watr.lol"
```

then go to admin → overview → **Send a test email** (it goes to your own confirmed email, so add one in settings first). if it fails, the reason zoho gave is at the bottom of `storage/errorlog.log`. "Could not authenticate" usually means it wants an app-specific password.

so the mail doesn't land in spam, add zoho's spf, dkim and dmarc records to your domain's dns (zoho mail admin → domains → email configuration).

until `MAIL_HOST` is set, emails get written to `storage/mail.log` instead, so you can click the links while testing.

## signing in with other sites
make an app on each provider and give it the callback url `APP_URL/auth/callback/<provider>`:

| provider | where | callback |
| --- | --- | --- |
| discord | discord.com/developers/applications → OAuth2 | `/auth/callback/discord` |
| github | github.com/settings/developers → OAuth Apps | `/auth/callback/github` |
| google | console.cloud.google.com → Credentials → OAuth client | `/auth/callback/google` |
| anything else | set the `OAUTH_*` urls in `.env` | `/auth/callback/oauth` |

the "anything else" slot works with authentik, keycloak, gitea, or another copy of watr.lol (see the bottom of `/developers/docs`).

nobody gets matched up by email, so nobody can take over an account by making a provider account with the same name. people without a password can't unlink their last linked account.

## how it's laid out
same as watrkit:
- `routes/`: one file per area (`auth`, `connections`, `account`, `forum`, `oauth`, `developers`, `admin`, `web`). returning an array from a route still makes it a json response
- `classes/watrlabs/`: the logic. `oauth/` is watr.lol being the login for other sites, `connections/` is other sites being a login for watr.lol, `mail.php` + `accountmail.php` send email
- `views/emails/`: the emails. each has an html version and a `text` block for plain text
- `views/`: twig. `models/app.twig` is the bar + sidebar layout, `models/plain.twig` is the centered one
- `public/assets/watr/`: the watr style kit, copied in at v0.5 so the site doesn't change when the kit does. `site.css` sets the knobs (hue 237, the logo blue) and lays out the pages

## changed from watrfiles
- sessions are random tokens stored hashed, in an httponly samesite cookie. the cookie name mismatch (`files_auth` vs `AuthCookieName`) is gone
- a wrong password says so instead of falling through to "Invalid Request"
- every form has a csrf token
- errors only show the stack trace when `APP_DEBUG=true`
- php 8.2+ deprecation notices from pixie don't crash the site anymore
- `roles.php` and `encryption.php` were broken (missing `global $db`, a missing `;`, `construct__`), they're rewritten
- the router does `PUT` properly and passes url params like `/forum/t/{id}`

## not done yet
- deleting your own account
- refresh tokens. access tokens last 30 days, then the site sends you through login again (instant if you're still logged in here)
