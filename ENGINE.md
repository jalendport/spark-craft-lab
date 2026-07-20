# Engine contract

This repository is the **asset bundle** consumed by the `spark lab` command group in [spark-cli](https://github.com/jalendport/spark-cli). The Go engine (`internal/lab`) owns all orchestration — minting, booting, seeding, teardown; this repo supplies what an instance is made of: Craft project skeletons, the Docker build context, compose/`.env` templates, generic site templates, and the in-Craft `lab` module.

This document is the interface between the two. If you change the assets, keep the engine in mind; if you change the engine, keep this document current. Where prose and code disagree, the engine's code is authoritative.

## 1. What the engine expects of this repo

Two directories, validated on every fetch:

| Directory | Contents |
| --- | --- |
| `skeleton/craft-4/`, `skeleton/craft-5/` | Minimal Craft project templates: `composer.json`, `.env.tpl`, `compose.yaml.tpl`, `config/craft/`, `craft`, `web/`, `storage/`, `templates/` (the generic site templates `index.twig` + `_lab/article.twig`, served from `/app/templates`), and `modules/lab/` — the per-major in-Craft `lab` module (§9), already registered in the skeleton's `composer.json` and `config/craft/app.php` |
| `docker/` | The php image build context (adds `pdo_pgsql` and a host-UID `spark` user to `spark-php`), copied into each instance as `.docker/` |

There is **no persistent cache**: `spark lab up` shallow-clones this repo into a temp dir, copies what the instance needs, and discards the clone. Every other command works from the instance's own copies and never touches the network. Setting `SPARK_LAB_ASSETS` to a local checkout skips the clone — that's how you develop these assets.

Because assets are baked in at mint, editing this repo affects **newly minted instances only**; pick up changes in an existing instance with `destroy` + `up`.

## 2. Instances

An instance is a self-contained, disposable Craft project testing **one plugin**: the repo enclosing the current working directory (first ancestor whose `composer.json` has `"type": "craft-plugin"`; `--plugin-dir` overrides). The plugin's package name comes from `name`, its handle from `extra.handle` — both required.

- Instances live under `<plugin>/.lab/<name>/`. The engine writes nothing else into the plugin repo, and keeps `.lab/` out of git via the repo's `.git/info/exclude` (never its `.gitignore`).
- **Name** = `<version-token>-<db-suffix>`: the `--craft` value verbatim, or `latest` when omitted, plus `mysql`/`pg`. Examples: `5.10-mysql`, `4.16-pg`, `latest-mysql`.
- Any number of instances run concurrently — distinct names, ports, and compose projects. The project name is `lab-<slug(handle-name)>-<hash6>`, where the trailing hash is the first six hex of `sha256(resolved plugin path)`. That path hash means two checkouts or `git worktree`s of the *same* plugin (same handle and instance name) get distinct compose projects, so they can't cross-wire each other's containers, networks, or volumes. Host ports are coordinated machine-wide (see §6), so concurrent instances across unrelated repos don't collide either.

Layout after mint:

```
.lab/<name>/
├── .docker/                # copy of docker/ (php build context); compose builds ./.docker/php
├── modules/lab/            # the lab module, from the skeleton (§9)
├── templates/              # generic site templates, from the skeleton → /app/templates
├── compose.yaml  .env      # rendered from the skeleton's .tpl files (§5)
├── composer.json           # skeleton's, rewritten (§4)
├── config/craft/…          # app.php already registers the lab module (from the skeleton)
├── craft  web/  storage/   # from the skeleton
└── lab.instance.json       # engine metadata (§7)
```

## 3. Command surface

| Command | Behavior |
| --- | --- |
| `spark lab up [--craft <v>] [--db mysql\|pg] [--php <tag>]` | Mint if new, then boot + seed. Re-running boots and reseeds the existing instance. |
| `spark lab list` | This plugin's instances: name, URL, Craft version, PHP tag, db, running/stopped. |
| `spark lab craft [name] <args…>` | Run a Craft console command in the instance's php container (tty when interactive). |
| `spark lab seed [name]` | Ensure the instance is up, then re-run the seed flow. |
| `spark lab destroy [name]` \| `--all` | `compose down -v`, delete the instance dir; `--all` removes `.lab/` entirely. |
| `spark lab prune` | Remove the shared `spark-craft-lab-composer` cache volume and any orphaned `lab-*` compose projects (containers/networks/volumes whose compose file is gone). Cross-instance cleanup that `destroy` intentionally leaves behind (§6/§12). |

- `--craft` accepts several forms, validated **before** any asset fetch (an unsupported Craft major fails fast, rather than after a clone): omitted or `latest` ⇒ token `latest`, Craft 5, skeleton's `^5.0` default; a bare major `4`/`5` ⇒ latest stable of that major (skeleton's `^major` default, token `4`/`5`); a two-segment `5.10`/`4.16` ⇒ the **latest patch of that minor** (`craftcms/cms` pinned to `5.10.*`, *not* exactly `5.10.0`); a three-segment `5.4.3` ⇒ that exact release. Only Craft majors 4 and 5 are accepted (the two shipped skeletons).
- `--db` tolerates aliases (`pg`/`pgsql`/`postgres`, `mysql`/`maria`…); default `mysql`.
- `--php` overrides the mapped tag (§11); it applies at mint, so changing it for an existing instance requires destroy + up.
- `craft`, `seed`, and `destroy` take the instance name **optionally**: omit it and the engine infers the sole instance, erroring only when zero or several exist (name one explicitly then). For `craft` a leading argument is treated as a name only when it matches an existing instance, so `spark lab craft migrate/up` runs against the single instance.
- `list` shows the exact Craft version once resolved (the version composer locked, recorded on first boot — most useful for `latest` instances), falling back to the name token before then. `seed` skips the `compose up --build` when the instance's php container is already running.

## 4. Mint

1. Copy `skeleton/craft-<major>/` → `.lab/<name>/`. This brings the `lab` module (`modules/lab/`) and its registration — the skeleton's `composer.json` carries the PSR-4 mapping `sparkcraftlab\lab\ → modules/lab/src/`, and its `config/craft/app.php` carries `'bootstrap' => ['lab']` plus the module class registration — so mint does no module copy or app.php patching.
2. Copy `docker/` → `.lab/<name>/.docker/` (the php build context; the generic templates already rode in with the skeleton at `templates/`).
3. Rewrite `composer.json`: `repositories` = asset-packagist (supplies the `bower-asset/*` packages Yii drags in via `yii2-redis`) + a symlinked `path` repo for `/plugin`, version-pinned to the plugin's latest git tag so inter-package constraints resolve against a branch checkout. The tag is validated against a Composer-acceptable version shape before use — a tag like `2024.05`, `release-3`, or `nightly` would produce an invalid `versions` pin and fail deep in composer install, so any non-matching tag (or a repo with no tags) falls back to `0.1.0`. Then `require[<plugin>] = "*"`, plus `require["craftcms/cms"] = <version>` when `--craft` pinned one (`5.10.*` for a two-segment value, exact for three); `config.platform.php = <php-tag>`. The `autoload` block (the module PSR-4 mapping) is left untouched. The skeleton itself carries `minimum-stability: dev`, `prefer-stable: true`, and `config.policy.advisories.block: false` (old pinned Craft releases have published advisories; a repro lab installs them on purpose).
4. Render `.env.tpl` and `compose.yaml.tpl` by string replacement (§5), removing the `.tpl` files.
5. Write `lab.instance.json` (§7).

## 5. Template placeholders

| Placeholder | Value |
| --- | --- |
| `{{PROJECT}}` | compose project name (§2) |
| `{{WEB_PORT}}` | instance web port (also the `APP_URL` port in `.env`) |
| `{{MAILPIT_PORT}}` | instance Mailpit UI port |
| `{{DB_BLOCK}}` | fully rendered YAML service block for `mysql:` or `postgres:` (2-space indent, healthcheck, `db-data` volume, `craft` database baked in). Built by the engine — the fragment contains no further placeholders. |
| `{{DB_SERVER}}` | db service name (`mysql`/`postgres`) — `depends_on` + `CRAFT_DB_SERVER` |
| `{{DB_DRIVER}}` `{{DB_PORT}}` `{{DB_USER}}` `{{DB_PASSWORD}}` `{{DB_NAME}}` | connection values (§6); `{{DB_NAME}}` is `craft` |
| `{{DB_SCHEMA_LINE}}` | the `CRAFT_DB_SCHEMA=public` line (with trailing newline) for pgsql, empty for mysql — schema is a pgsql-only concept, so mysql `.env` omits it entirely |
| `{{PHP_TAG}}` / `{{NGINX_TAG}}` | image tags (nginx is `1.26`) |
| `{{UID}}` / `{{GID}}` | host ids for the php image build |
| `{{APP_ID}}` | `spark-craft-lab--<handle>--<name>` |
| `{{SECURITY_KEY}}` | random 32-byte hex |

The php build context and the plugin mount are **relative**, not placeholders: because an instance always lives at `<plugin>/.lab/<name>/`, compose (rooted there) builds the php image from `./.docker/php` and bind-mounts the plugin at `/plugin` via `../..`, **read-only** (`:ro`) — composer only reads the plugin through the symlinked path repo and every edit happens on the host, so the container never needs write access to the working copy (see the trust boundary in §6).

## 6. Compose shape, databases, ports

Each instance's compose file bundles **all** of its services — no shared containers, no external networks: `nginx` (publishes the web port), `php` (built relatively from `./.docker/php`; mounts the instance at `/app` — so the skeleton's own `templates/` is served from `/app/templates` — and the plugin **read-only** at `/plugin` via the relative `../..`; sets `LAB_PLUGIN_DIR=/plugin`), the database, `redis` (password `root`), and `mailpit` (publishes the UI port). Both published ports bind **loopback only** — `127.0.0.1:<web>:80` and `127.0.0.1:<mailpit>:8025` — so a lab's `admin`/`password` dev install and Mailpit are never exposed to the local network; opening them up would be an explicit, opt-in edit. `.env` keeps the spark-craft variable names (`CRAFT_DB_*`, `REDIS_*`, `MAILPIT_HOST`), with one deliberate exception: the in-container Mailpit **SMTP** port is `MAILPIT_SMTP_PORT` (1025), renamed from `MAILPIT_PORT` so it can't be confused with the host-side Mailpit **UI** port that `{{MAILPIT_PORT}}` fills into the compose publish line.

The one cross-instance artifact is the external Docker **volume** `spark-craft-lab-composer` — a composer cache, created idempotently at boot, shared so installs stay fast while instances stay independent. It is **not** reclaimed by `destroy` (see §12); `spark lab prune` removes it (and any orphaned `lab-*` compose projects docker still knows about) once no instance is using it. Because it is written by the `spark` user running the plugin's own code, it is a shared-mutable channel between every plugin's instances — one more reason the plugin's code is trusted (§8/§10): keep it for repos you trust, `prune` it if in doubt.

| | mysql | pgsql |
| --- | --- | --- |
| image | `jalendport/spark-mysql:8.4` | `postgres:16` |
| service / server | `mysql` | `postgres` |
| port / user / password | `3306` / `root` / `root` | `5432` / `postgres` / `root` |
| database | `craft`, auto-created by the container | `craft`, auto-created, schema `public` |

**Ports** are allocated at mint by bind-testing `127.0.0.1` upward from `8100` — two per instance (web, then mailpit). Allocation is coordinated **machine-wide** through a per-user registry (`~/.spark/lab-state.json`) guarded by an advisory file lock, so two concurrent `up` runs in *different* plugin repos or worktrees can't both pick `8100`. Each allocation locks the registry, garbage-collects entries whose instance dir is gone, unions the surviving reservations with this repo's sibling `lab.instance.json` ports, picks two free ports, and records them before `compose up` runs. Stopped-but-minted instances keep their ports (their dir still exists, so their reservation survives GC). If the registry can't be locked (e.g. no home dir), allocation falls back to the sibling-only scan. As a last line of defense against the residual bind race, a `compose up` that still fails on a host-port clash triggers a reallocation-and-retry (new ports rewritten into the instance's `compose.yaml`/`.env`/metadata).

## 7. `lab.instance.json`

```json
{
  "name": "5.10-mysql", "plugin": "vendor/craft-foo", "handle": "foo",
  "craftMajor": 5, "craftVersion": "5.10", "craftResolved": "5.10.5",
  "phpTag": "8.2", "db": "mysql", "webPort": 8100, "mailpitPort": 8101
}
```

Written at mint; read back by list, boot, port allocation, and teardown. `craftVersion` holds the name token (`latest` when unpinned); `craftResolved` is the exact version composer locked, filled in on first boot and shown by `list` (omitted until then); `db` is `mysql` or `pgsql`.

## 8. Boot, install, seed

1. Ensure the composer cache volume exists, then `docker compose up -d --build --wait`. **Skipped when the instance's php container is already running** (e.g. a `seed` right after an `up`), so a reseed doesn't rebuild — boot goes straight to the steps below.
2. **Wait for vendor**: the spark-php entrypoint runs `composer install` when `vendor/` is missing; poll for `vendor/autoload.php` + `composer.lock` (15-minute timeout, failing fast with the php container's logs if it exits).
3. `composer dump-autoload`.
4. `craft install/check`; if not installed, `craft install/craft --interactive=0` with admin `admin` / `password` / `admin@lab.test`, site name `Spark Craft Lab (<name>)`, `--siteUrl=http://localhost:<webPort>`, language `en-US`.
5. `craft plugin/install <handle>` — the cwd plugin is the **only** plugin installed; "already installed" counts as success.
6. Record the exact `craftcms/cms` version from `composer.lock` into `craftResolved` (§7) so `list` can show it.
7. `craft lab/seed` (the module, below).

Craft installs as the single-user **Solo** edition and `install/craft` offers no edition flag, so the seeder upgrades the install to **Pro** before creating its second user.

**Trust boundary.** `up` and `seed` run the plugin repo's own code inside the container: `craft plugin/install` executes the plugin, and `lab/seed` `require`s and runs the plugin's `lab/seed.php` closure (§10) with a fully booted `\Craft::$app`. In other words, `spark lab up`/`seed` executes **arbitrary PHP from the plugin working copy** as the container's `spark` user. That is by design — the whole point is to exercise the plugin — but it means you should only run the lab on plugin repos you trust, exactly as you would `composer install` or running the plugin's tests. The plugin is mounted read-only (§6), so that code can't mutate the working copy, but it can do anything else the container can (network, the shared composer cache, the instance's database).

## 9. The in-Craft `lab` module

Bootstrapped Craft module `lab`, namespace `sparkcraftlab\lab`, autoloaded from `modules/lab/src/`. It ships **per Craft major** — one copy under each `skeleton/craft-<major>/modules/lab/` — because the seeder is version-specific (§11); each skeleton's `composer.json` (PSR-4) and `config/craft/app.php` (`bootstrap` + `modules`) register its copy statically, so the module is live the moment the skeleton is copied. `Module.php` is identical across majors (its template-root serving and alias registration use APIs common to Craft 4 and 5); only `console/controllers/SeedController.php` differs. On init the module registers the `@sparkcraftlab/lab` alias so the Craft console can resolve its controllers.

**Console — `craft lab/seed`** builds a generic content model **idempotently** through Craft's APIs, so every instance's project config is generated by the Craft version it actually runs (this repo ships no project-config YAML):

- fields `labSummary` (plain text) and `labBody` (plain text, multiline);
- entry type `labArticle` with a title/summary/body field layout;
- section `labArticles` (channel, URLs `lab/articles/{slug}`, template `_lab/article`);
- two sample entries;
- Pro edition + an activated `editor` user alongside the installer's `admin`.

Then it runs the plugin's seed hook, if any (§10).

**Web** — the module looks for `lab/test.twig` in the plugin mounted at `LAB_PLUGIN_DIR` (default `/plugin`). When present, it registers a site template root and a URL rule so **`/lab-test` serves the plugin's own test template**.

## 10. What plugin repos ship

A plugin opts in by committing a `lab/` directory:

- **`lab/test.twig`** — the smoke-test page served at `/lab-test`. Keep it plain HTML that renders the plugin's surface; it's meant to be asserted against with `curl`.
- **`lab/seed.php`** — optional; returns a closure run by `lab/seed` after the generic model exists and only with the plugin installed. It is executed verbatim inside the container (`require` + call), so `spark lab up`/`seed` runs whatever PHP this file contains — see the trust boundary in §8:

  ```php
  return function (\yii\console\Controller $seed): void {
      // \Craft::$app is booted: set plugin settings, add plugin-specific
      // entries/users on top of the generic model. Must be idempotent.
      // Report progress with $seed->stdout().
  };
  ```

  Don't `use Craft;` at the top of this file — `Craft` is a root-namespace class, and the no-effect-import warning is thrown as an error under the instance's dev mode. Reference `\Craft::$app` directly.

## 11. Version → PHP mapping

Craft 4 → PHP `8.1`, Craft 5 → PHP `8.2`, overridable per instance with `--php`. Both majors are first-class: `skeleton/craft-4/` and `skeleton/craft-5/` each ship a `SeedController` written against their own major's APIs, so generic seeding is fully supported on 4.x as well as 5.x. The two seeders produce the identical generic model (fields `labSummary`/`labBody`, entry type `labArticle`, section `labArticles` with `lab/articles/{slug}` URLs and the `_lab/article` template, two sample entries, Pro edition, an activated `editor` user); they differ only where the APIs do — Craft 4 uses the `\Craft::Pro` int edition constant (not the `CmsEdition` enum), `Section::PROPAGATION_METHOD_ALL` string constants (not `PropagationMethod`), the `getSections()` service, and entry types that belong to a section (the seeder reconfigures the section's auto-created type rather than constructing a standalone one), and it omits Craft 5-only entry-type settings like `icon`/`color`.

## 12. Teardown guarantee

`destroy` runs `compose down -v --remove-orphans` and deletes the instance directory (removing `.lab/` once empty); `destroy --all` removes `.lab/` outright. Either way the plugin repo ends **byte-identical** to before the lab touched it — the only permitted residue is the `.lab/` line in `.git/info/exclude`.

Two machine-global artifacts sit **outside** that byte-identical guarantee, because they are shared across every plugin and every instance rather than living in the repo: the persistent `spark-craft-lab-composer` cache volume (§6), and the per-user port registry `~/.spark/lab-state.json` (§6, which self-heals — a destroyed instance's reservation is garbage-collected on the next allocation). `destroy` deliberately leaves the composer volume in place so the next `up` stays fast; `spark lab prune` removes it (and any orphaned `lab-*` compose projects) when you want a clean slate.
