# Deploying to the VPS

The box runs HestiaCP with another PHP site on it. Nothing here touches
Hestia: this app gets its own user, its own PHP-FPM pool, its own Postgres,
and an nginx block in `conf.d/` — the one place Hestia does not rewrite.

Do these in order. Each step says what to check before moving on.

---

## Before you start

Point the domain at the server. In whichever DNS panel holds
`smmresellershub.com`, add:

| Type | Name | Value |
|------|------|-------|
| A    | `@`  | `186.240.153.29` |
| A    | `www`| `186.240.153.29` |

DNS takes anywhere from minutes to a few hours. Check with:

```bash
dig +short smmresellershub.com
```

When that prints the server's IP, carry on. **SSL will fail if you run
certbot before DNS has propagated**, so this genuinely has to come first.

---

## Step 1 — Set up the server

SSH in as root (Hostinger's web console works too).

The repo is private, so `curl` cannot fetch the script without a token. The
simplest way in is to paste it. On the server:

```bash
nano /tmp/server-setup.sh
```

Paste the contents of `deploy/server-setup.sh` from this repo, save
(`Ctrl+O`, `Enter`, `Ctrl+X`), then:

```bash
bash /tmp/server-setup.sh
```

If you would rather copy it from your own machine than paste, run this in
WSL instead — it pushes the file over SSH:

```bash
scp deploy/server-setup.sh root@186.240.153.29:/tmp/
ssh root@186.240.153.29 'bash /tmp/server-setup.sh'
```

It installs PHP 8.3, PostgreSQL, Redis and Node, creates the `hxapp` user,
and writes the nginx block. It checks nginx's config before reloading — if
the block is bad it removes it rather than taking the other site down.

**Copy the database password it prints at the end.** It is also saved at
`/home/hxapp/smm-reseller-hub/shared/.db_password`.

Check the other site still works before continuing:

```bash
systemctl status nginx
curl -I http://localhost
```

---

## Step 2 — Put the deploy script on the server

Same idea — paste it on the server:

```bash
nano /home/hxapp/smm-reseller-hub/deploy.sh
```

Paste `deploy/deploy.sh` from this repo, save, then:

```bash
chown hxapp:hxapp /home/hxapp/smm-reseller-hub/deploy.sh
chmod +x /home/hxapp/smm-reseller-hub/deploy.sh
```

Or from WSL:

```bash
scp deploy/deploy.sh root@186.240.153.29:/home/hxapp/smm-reseller-hub/
ssh root@186.240.153.29 'chown hxapp:hxapp /home/hxapp/smm-reseller-hub/deploy.sh && chmod +x /home/hxapp/smm-reseller-hub/deploy.sh'
```

---

## Step 3 — Write the production .env

```bash
sudo -u hxapp nano /home/hxapp/smm-reseller-hub/shared/.env
```

Paste `deploy/env.production.example` from this repo and fill in every
`CHANGE_ME`. For the app key:

```bash
# On your own machine, in the project
php artisan key:generate --show
```

Then lock it down:

```bash
chmod 600 /home/hxapp/smm-reseller-hub/shared/.env
chown hxapp:hxapp /home/hxapp/smm-reseller-hub/shared/.env
```

Leave the billing keys blank for now — the app treats a blank gateway as not
configured. `MAIL_PASSWORD` matters more: without it, password resets never
arrive.

---

## Step 4 — Create the deploy key

This is what lets GitHub in. Run it **on the server**:

```bash
sudo -u hxapp ssh-keygen -t ed25519 -f /home/hxapp/.ssh/github_deploy -N ""

# Let that key log in as hxapp
sudo -u hxapp bash -c 'cat /home/hxapp/.ssh/github_deploy.pub >> /home/hxapp/.ssh/authorized_keys'
sudo -u hxapp chmod 600 /home/hxapp/.ssh/authorized_keys

# The private half — this is what goes into GitHub
cat /home/hxapp/.ssh/github_deploy
```

Copy that private key, **including** the `-----BEGIN` and `-----END` lines.

### Put it in GitHub

Repo → **Settings** → **Secrets and variables** → **Actions** → **New
repository secret**. Add three:

| Name | Value |
|------|-------|
| `SSH_PRIVATE_KEY` | the private key you just copied |
| `SSH_HOST` | `186.240.153.29` |
| `SSH_USER` | `hxapp` |

The workflow also expects an environment called `production` (Settings →
Environments → New environment). Add a required reviewer there if you want
deploys to pause for your approval.

---

## Step 5 — First deploy

The workflow runs on pushes to `master`. You are on
`onboarding-payments-and-test-bot`, so merge first:

```bash
git checkout master
git merge onboarding-payments-and-test-bot
git push origin master
```

Watch it under the repo's **Actions** tab. It runs tests, builds the assets,
uploads a tarball, and releases it.

If the deploy step fails, the site is not left broken — `deploy.sh` puts the
previous release back. On the very first deploy there is no previous release,
so a failure leaves nothing serving; the fix is to read the log and push
again.

---

## Step 6 — SSL

Only after the site answers on http:

```bash
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d smmresellershub.com -d www.smmresellershub.com
```

Certbot edits the nginx block in place and sets up renewal. Check it:

```bash
certbot renew --dry-run
```

---

## Step 7 — Start the workers

```bash
systemctl start smmhub-queue smmhub-schedule.timer
systemctl status smmhub-queue
```

The queue worker is what submits orders to panels. Without it, orders sit
unprocessed.

---

## Afterwards

### Watching it

```bash
# App log
tail -f /home/hxapp/smm-reseller-hub/shared/storage/logs/laravel.log

# Queue worker
journalctl -u smmhub-queue -f

# nginx
tail -f /var/log/nginx/smmhub-error.log
```

### Rolling back by hand

```bash
sudo -u hxapp ls -1t /home/hxapp/smm-reseller-hub/releases/
sudo -u hxapp ln -sfn /home/hxapp/smm-reseller-hub/releases/<older> \
    /home/hxapp/smm-reseller-hub/current
sudo systemctl restart php8.3-fpm smmhub-queue
```

### Meta webhook

Once SSL is live, the callback URL is:

```
https://smmresellershub.com/webhooks/whatsapp
```

The verify token is whatever you put in `META_VERIFY_TOKEN`.

---

## If something breaks

**502 Bad Gateway** — PHP-FPM is not answering.
```bash
systemctl status php8.3-fpm
ls -l /run/php/php8.3-fpm-hxapp.sock
```

**The other site went down** — check whose block is claiming the domain:
```bash
nginx -T | grep -A5 server_name
```
Our block only answers for `smmresellershub.com`. If Hestia's site broke, the
fix is to remove `/etc/nginx/conf.d/smmresellershub.conf`, reload nginx, and
tell me what the config test says.

**Deploy says "No shared/.env"** — Step 3 was skipped.

**Migrations fail** — check the database credentials in `shared/.env` match
what `server-setup.sh` printed:
```bash
sudo -u hxapp psql -h 127.0.0.1 -U smmhub -d smmhub -c '\dt'
```
