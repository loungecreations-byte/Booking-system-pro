# Staging deployment

## Scope

Staging code deployment is split from WordPress data.

Deploy with Git/SSH:

- `wp-content/mu-plugins`
- custom plugins
- Angie snippets

Do not deploy with Git:

- database
- uploads/media
- cache directories
- `wp-config.php`
- WooCommerce orders/users/settings

Uploads/media are runtime data. Keep them out of Git and sync them over SSH when a staging page depends on attachment files.

Required runtime assets:

- `wp-content/plugins/booking-pro-module/assets/js/day-planner/dist/`
- `wp-content/plugins/booking-pro-module/modules/product-overview/assets/js/dist/`

These bundles are loaded by WordPress enqueue fallbacks. If they are missing, the planner can fall back to source `.jsx` files and fail in the browser.

## Server paths

SSH:

```text
dagjedb123@212.227.224.239
```

WordPress root:

```text
/var/www/vhosts/dagjedenbosch.nl/site1
```

WordPress content root:

```text
/var/www/vhosts/dagjedenbosch.nl/site1/wp-content
```

The staging WordPress root is not:

```text
/var/www/vhosts/dagjedenbosch.nl/staging.dagjedenbosch.nl/site1
```

## Branches

Main working branch:

```text
fix/planner-participants-request-truth
```

Deploy branch with `wp-content` as repository root:

```text
deploy/wp-content
```

Refresh the deploy branch from the working tree:

```bash
git branch -D deploy/wp-content
git subtree split --prefix=app/public/wp-content -b deploy/wp-content
git push --force-with-lease origin deploy/wp-content
```

## SSH publish command

From the server:

```bash
export GIT_SSH_COMMAND="ssh -i /var/www/vhosts/dagjedenbosch.nl/.ssh/id_rsa -o UserKnownHostsFile=/var/www/vhosts/dagjedenbosch.nl/.ssh/git_known_hosts -o StrictHostKeyChecking=yes"
git --git-dir=/var/www/vhosts/dagjedenbosch.nl/git/Booking-system-pro.git fetch origin +refs/heads/deploy/wp-content:refs/heads/deploy/wp-content
git --git-dir=/var/www/vhosts/dagjedenbosch.nl/git/Booking-system-pro.git archive deploy/wp-content | tar -x -C /var/www/vhosts/dagjedenbosch.nl/site1/wp-content
```

## Validation

Use an explicit memory limit for WP-CLI on staging:

```bash
cd /var/www/vhosts/dagjedenbosch.nl/site1
php -d memory_limit=512M $(command -v wp) plugin list --status=active --field=name
```

Required active custom plugins:

```text
angie
booking-pro-module
ddb-content-model
ddb-core-ui
ddb-mega-menu
ddb-spinwheel
ddb-spots-0.1.0
```

After publishing planner or product-overview assets, clear cache:

```bash
cd /var/www/vhosts/dagjedenbosch.nl/site1
php -d memory_limit=512M $(command -v wp) cache flush
php -d memory_limit=512M $(command -v wp) rocket clean --confirm
```

## Uploads/media sync

Use this when staging has WordPress attachment records but missing files under `wp-content/uploads`.

Dry-run from the repository root:

```powershell
.\scripts\sync-staging-uploads.ps1 -IncludePaths 2026 -RunMediaAudit
```

Apply the upload:

```powershell
.\scripts\sync-staging-uploads.ps1 -IncludePaths 2026 -Apply -RunMediaAudit
```

Rules:

- This script copies local uploads to staging over SSH.
- It does not delete remote files.
- It does not change Git tracking for uploads.
- Keep `.gitignore` excluding `app/public/wp-content/uploads/`.
- After syncing DBSpots media, validate with:

```bash
cd /var/www/vhosts/dagjedenbosch.nl/site1
php -d memory_limit=512M $(command -v wp) ddb-spots media audit
```

## Cleanup history

On 2026-06-26, plugin-nested wrong publish artifacts were moved to:

```text
/var/www/vhosts/dagjedenbosch.nl/ddb-staging-audit-2026-06-26-ssh-deploy/
```

Do not restore those directories into:

```text
wp-content/plugins/booking-pro-module/
```

except `vendor/`, which is currently required by the plugin runtime on staging.
