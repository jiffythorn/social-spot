# Packaging: the server as containers plus a thin installer

This project is a server (PHP + MariaDB + a web front + a background-job
worker), so it ships the way servers ship: as containers. Everything someone
needs to run it lives under `packaging/`:

| File | What it is |
|---|---|
| `Dockerfile` | Builds the app image: PHP 8.3 + Apache, pristine Friendica core fetched from upstream at build time, our addon and skin copied in, dependencies installed. |
| `docker-entrypoint.sh` | Boots the container. Waits for the database, writes configuration from the environment, provisions on first boot (schema, admin, addon, skin), migrates on later boots. |
| `compose.yaml` | The stack: `app`, `db` (MariaDB 11.4), `worker` (same image, runs the job daemon). The only volume that matters is `db-data`. |
| `.env.example` | Documents every variable. Copy to `.env` for a manual install. |
| `install.sh` / `install.ps1` | The thin installers. They install nothing themselves beyond checking Docker: they ask a few questions, write `.env`, and run compose. |
| `check.sh` | Validates everything in this directory that can be validated without Docker. |
| `assets/htaccess` | The Apache front-controller rewrite (production twin of `tools/dev/router.php`). |

## Quick start

Linux or macOS:

```sh
bash packaging/install.sh
```

Windows (PowerShell):

```powershell
powershell -ExecutionPolicy Bypass -File packaging\install.ps1
```

By hand (any platform with Docker):

```sh
cp packaging/.env.example packaging/.env
# edit .env, then:
docker compose -f packaging/compose.yaml up -d --build
```

Then open the printed address, sign in as the administrator, and open the
Officer console. Picking the kind of organization only sets the *starting*
feature set - every switch stays toggleable afterwards, which is the point of
the feature flags.

## Operating it

All commands run from `packaging/` (or pass `-f packaging/compose.yaml`):

```sh
docker compose logs -f app        # follow the server log
docker compose logs -f worker     # follow the background jobs
docker compose up -d --build      # upgrade: rebuild and restart (also what
                                  # re-running the installer does)
docker compose down               # stop; data stays in the db-data volume
```

Back up the whole organization with one command - the database *is* the state
(configuration regenerates from `.env`, uploads live in the database):

```sh
docker compose exec db mariadb-dump -u root -p"$DB_ROOT_PASSWORD" \
  --single-transaction "$DB_NAME" > backup.sql
```

Restore into a fresh stack: install, then pipe the dump back in:

```sh
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" \
  "$DB_NAME" < backup.sql
```

Uninstall everything including data: `docker compose down -v` (the `-v`
deletes the database volume - that is the point, and the danger).

## Rules that keep it boring

- `BASE_URL` must be exactly what members type (scheme, host, port).
  Behind a reverse proxy, set it to the public URL and point the proxy at
  `HTTP_PORT`; the container itself stays plain HTTP behind the proxy.
- The admin password in `.env` applies once, at account creation. Changing it
  later means the console or
  `docker compose exec app php bin/console.php user password <nick>`.
- `.env` holds passwords and is gitignored. Never commit it, never paste it.
- First boot provisions; later boots migrate (`dbstructure update` runs every
  start) but never touch stored organization settings, the theme, or the admin
  password - so upgrades cannot clobber an officer's choices.

## Limitations, stated plainly

- Docker (or Docker Desktop) is required. There is deliberately no native
  installer, AppImage, or phone build: a PHP + MariaDB server with background
  workers does not fit those shapes, and pretending otherwise produces a
  fragile appliance instead of a server.
- The first build downloads upstream core plus Composer dependencies (several
  minutes on a normal connection). `CORE_REF` defaults to the `stable` branch
  like CI; pin a tag in `.env` to freeze it deliberately.
- The image build itself is not part of the `verify` gate and no Docker daemon
  exists in this checkout's dev environment, so `packaging/check.sh` validates
  syntax and cross-references only. Build and boot the stack on a machine with
  Docker before calling any release ready.
