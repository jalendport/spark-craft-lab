<p align="center"><img src=".github/icon.svg" alt="Spark Craft Lab" width="80" height="80"></p>

<h1 align="center">Spark Craft Lab</h1>

<p align="center"><em>A disposable Craft CMS runtime for smoke-testing plugins.</em></p>

Your plugin's CI should stay fast and unit-only — but some behavior only shows up inside a real [Craft CMS](https://craftcms.com) install: template tags rendering on a real front end, console commands against a real database, settings interacting with real project config. The lab gives any plugin a real, throwaway Craft install on demand — from a single command run inside the plugin repo.

## Features

- **One command, real Craft** — `spark lab up` in a plugin repo mints a self-contained instance with your plugin installed and sample content seeded
- **Any Craft, any database** — pin Craft 4 or 5 down to the patch, on MySQL or Postgres, with the era-appropriate PHP
- **Live working copy** — the plugin is symlinked, so edits show up on refresh
- **Plugin-owned smoke tests** — ship a `lab/test.twig` and every instance serves it at `/lab-test`
- **Fully ephemeral** — instances live under `.lab/`, coexist side by side, and destroy without a trace

## Installation

You'll need [Docker Desktop](https://www.docker.com/products/docker-desktop/) or a compatible engine, plus the [spark CLI](https://github.com/jalendport/spark-cli):

```sh
brew install jalendport/tap/spark
```

The engine lives in the `spark` binary (a single static Go binary). This repo supplies the runtime assets it consumes — Craft project skeletons, Docker templates, and the in-Craft seeding module. `spark lab up` fetches a fresh copy of these on each mint and bakes them into the instance, so instances are fully self-contained and there is nothing to cache or update.

## Usage

Stand in a plugin repo and bring up an instance:

```sh
cd ~/Developer/craft-confetti
spark lab up
```

That mints a **fully self-contained Craft instance** under the plugin's `.lab/` directory — its own nginx, PHP-FPM, database, Redis, and Mailpit via Docker Compose, nothing shared with any other project — then installs Craft (latest stable by default), installs *your plugin* symlinked to the working copy (edits are live immediately), seeds sample content, and prints the URL. There is no long-lived "main" instance and no shared services: every instance is ephemeral, and you can run several at once against different Craft versions.

```sh
spark lab up                        # .lab/5.10-mysql  →  http://localhost:8100
spark lab up --craft 4.16 --db pg   # .lab/4.16-pg     →  http://localhost:8101
spark lab list                      # both instances, ports, status
spark lab craft 4.16-pg migrate/up  # run console commands inside an instance
spark lab destroy 4.16-pg
spark lab destroy --all             # removes .lab/ entirely; repo left untouched
```

`.lab/` is ignored automatically (via the repo's `.git/info/exclude`), so none of this ever shows up in your plugin's git status.

### Reproducing version-specific bugs

Someone reports your plugin breaks on Craft 4.16 with Postgres:

```sh
spark lab up --craft 4.16 --db pg
```

The lab pins the instance to exactly Craft 4.16 on the PHP version that era expects (4.x → PHP 8.1, 5.x → 8.2, overridable with `--php`), backed by Postgres 16 instead of MySQL. Reproduce on the printed URL, fix in your working copy (symlinked, so live), refresh, verify, destroy.

### Ports and services

Each instance allocates its ports automatically starting at `8100`, skipping anything already in use — instances coexist with each other and with whatever else you're running. `spark lab list` shows every instance's URL; Mailpit (which captures all instance email) gets its own auto-assigned UI port, also shown in `list`.

Inside an instance's stack: nginx, PHP-FPM (with `pdo_pgsql` added to the base image), MySQL 8.4 *or* Postgres 16, Redis (the instance's cache driver), and Mailpit. Destroying an instance removes its containers, volumes, and database completely.

### For agents

Coding agents verify plugin changes with the same loop a human uses — up, assert, destroy:

1. `spark lab up` — note the printed URL. The first mint runs a full composer install and can take several minutes; it isn't hung.
2. `curl -sf <url>/lab-test` — the smoke page is plain HTML by design; grep it for expected output rather than driving a browser.
3. `spark lab craft <command>` — console-level assertions: migrations, the plugin's own commands. With a single instance running, no instance name is needed.
4. If the change touches email, Mailpit's API (`GET /api/v1/messages` on its UI port) returns assertable JSON.
5. `spark lab destroy --all` when done — the repo ends byte-identical to before the lab touched it.

Two ground rules: edits to the working copy are live on refresh (re-run `spark lab seed` if the change affects seeded state), and never edit files under `.lab/` — they're disposable copies, regenerated at the next mint. For control-panel checks, sign in at `/admin` as `admin` / `password`, or as `editor@lab.test` to test non-admin permissions.

Agents only use what they know exists, so tell them in your plugin repo's own `AGENTS.md` / `CLAUDE.md`:

> This repo supports [Spark Craft Lab](https://github.com/jalendport/spark-craft-lab): run `spark lab up` for a throwaway Craft install with the plugin live-mounted, smoke-test at `/lab-test`, and `spark lab destroy --all` when done.

## Configuration

The lab itself needs none — but your plugin can opt in to richer smoke-testing by committing a `lab/` directory:

```
craft-confetti/
├── src/
├── composer.json
└── lab/
    ├── test.twig     # served at /lab-test on every lab instance
    └── seed.php      # optional — plugin-specific seeding (see below)
```

### Test page

`lab/test.twig` is a plain Twig template rendered by Craft with your plugin installed and seeded content available. Make it exercise the surface a human (or agent) should check — render your tags, hit your variables, show the values plainly:

```twig
{% set entry = craft.entries.section('articles').one() %}

<h1>Confetti smoke test</h1>
<dl>
	<dt>plugin installed</dt>
	<dd>{{ craft.app.plugins.getPlugin('confetti') ? 'yes' : 'no' }}</dd>

	<dt>burst tag output</dt>
	<dd>{{ craft.confetti.burst(entry) }}</dd>
</dl>
```

Because the page is deliberately plain, it's easy to assert against with `curl` — which is exactly how agent-driven smoke checklists consume it.

### Seeding

The lab ships no project config. On `up`, after Craft installs, a seed command builds a generic content model programmatically — so the instance's project config is always native to whatever Craft version it runs:

- an **articles** section with sample entries carrying `labSummary` (plain text) and `labBody` (long-form text) fields
- an **admin** user (`admin@lab.test` / `password`) and a non-admin **editor** (`editor@lab.test`)

If your plugin needs more — settings toggled, a specific entry shape, a user in a particular state — ship a `lab/seed.php` beside your test template. It returns a closure that runs inside Craft after the generic seed, with your plugin installed:

```php
<?php

return function (): void {
	// Runs on every seed; keep it idempotent.
	$plugin = Craft::$app->plugins->getPlugin('confetti');

	Craft::$app->plugins->savePluginSettings($plugin, [
		'particleCount' => 500,
	]);
};
```

Re-run seeding on a live instance any time with `spark lab seed <name>`.

## Support

Found a bug or need help? Open an [issue](https://github.com/jalendport/spark-craft-lab/issues).

<hr>

<p align="center">Made by <a href="https://jalendport.com">Jalen Davenport</a></p>
