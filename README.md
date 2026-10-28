# EnvLens

<p align="center"><img src="art/banner.svg" alt="EnvLens: see what your .env is really doing" width="100%"></p>

<!-- Badges: adjust the GitHub owner/repo below if the repository lives elsewhere. -->
[![tests](https://github.com/touhidurabir/envlens/actions/workflows/tests.yml/badge.svg)](https://github.com/touhidurabir/envlens/actions/workflows/tests.yml)
[![static analysis](https://github.com/touhidurabir/envlens/actions/workflows/static.yml/badge.svg)](https://github.com/touhidurabir/envlens/actions/workflows/static.yml)
[![pint](https://github.com/touhidurabir/envlens/actions/workflows/pint.yml/badge.svg)](https://github.com/touhidurabir/envlens/actions/workflows/pint.yml)

Inspect, compare, audit and safely maintain the `.env` files of a Laravel application.

## Why

`.env` files drift. Keys get added in one environment and forgotten in `.env.example`, old keys linger after the
code that read them is gone, `env()` calls creep into application code (where they break once config is cached),
and secrets leak into terminals and CI logs. EnvLens reads your `config/` and application code **statically**
(it never executes them), lines that up against your env files, and tells you what is used, unused, missing or
risky. Its write commands edit files losslessly and make backups, and every value of a sensitive key is masked in
output.

## Installation

```bash
composer require --dev touhidurabir/envlens
```

Requires PHP 8.2+ and Laravel 12 or 13. The service provider is auto-discovered. To customise behaviour, publish
the config:

```bash
php artisan vendor:publish --provider="Touhidurabir\EnvLens\EnvLensServiceProvider"
```

Install it as a regular dependency instead if you want to run the commands in production-like environments.

## Configuration

`config/envlens.php`:

| Key | Default | Meaning |
|---|---|---|
| `files.source` | `.env` | Default "real" env file for every command (`source` argument, `--file`, `--source`). |
| `files.example` | `.env.example` | Default example file for every command (`target` argument, `--example`). |
| `required` | `[]` | Globs of keys you declare required, in addition to the keys listed in the example file (see [Required keys](#required-keys)). |
| `sensitive.keys` | `['APP_KEY']` | Exact key names treated as sensitive. |
| `sensitive.patterns` | `*_KEY`, `*_SECRET`, `*_SECRET_*`, `*_PASSWORD`, `*_PASS`, `*_TOKEN`, `*_PRIVATE*`, `*_DSN`, `*_CREDENTIALS*`, `*_SALT`, `*_PASSPHRASE`, `*_PWD`, `*_AUTH`, `*_WEBHOOK*` | Case-insensitive globs for sensitive keys. A value that is a connection string with credentials (`scheme://user:password@host`) also makes its key sensitive. |
| `sensitive.except` | `*_PUBLIC_KEY`, `VITE_*_KEY` | Globs exempted from the patterns above. |
| `scan.config_paths` | `['config']` | Config directories to scan, relative to the project base path or absolute. |
| `scan.code_paths` | `app`, `routes`, `database`, `resources/views`, `bootstrap` | Code directories scanned for `env()` calls, relative or absolute. |
| `scan.exclude` | `vendor`, `storage`, `node_modules`, `bootstrap/cache` | Paths skipped while scanning. |
| `scan.allow_env_outside_config` | `[]` | Globs of relative file paths that may call `env()` without being flagged. |
| `cleanup.keep` | `VITE_*`, `SAIL_*`, `WWWUSER`, `WWWGROUP`, `FORWARD_*`, `PHP_CLI_SERVER_WORKERS`, `APP_RUNNING_IN_CONSOLE`, `APP_*_CACHE`, `APP_BASE_PATH`, `LARAVEL_*` | Globs of keys that `env:cleanup` never removes (read by Laravel internals outside config). |
| `write.backup` | `true` | Back up a file before modifying it (see [Safety model](#safety-model)). |
| `write.backup_path` | `storage/envlens/backups` | Backup directory, relative to the project base path or absolute. |
| `write.group_by_prefix` | `true` | Insert new keys after the last key sharing their prefix (`MAIL_*` next to `MAIL_*`); otherwise append at the end. |
| `write.header_comment` | `true` | Put a `# Added by envlens ...` comment above appended keys. |
| `write.remove_attached_comments` | `true` | When removing a key, also remove the comment lines directly above it (no blank line between). |
| `generate.skip_defaults` | `APP_ENV`, `APP_DEBUG` | Keys that `env:generate` and `env:sync` never fill with a config default (so an example never says `APP_ENV=production`). |
| `production_environments` | `['production']` | Environment names treated as production by the guard. |

All commands accept `--path=` to point at another project base path; file arguments resolve relative to it.
Exit codes: `0` ok, `1` problems found (`--strict`, `--fail-on`, an unknown key in `env:explain`) or an action was
refused or declined, `2` usage error (missing file, bad option). Read-only commands accept `--json`; every payload has a top-level `"schema": 1`
(removing or renaming a field bumps it, and the change is recorded in the CHANGELOG).

Laravel's own unpublished config (`vendor/laravel/framework/config`) is always scanned, so keys such as
`BCRYPT_ROUNDS` that only the framework reads count as used. The scan is only used for that: keys read solely by
the framework are not listed by `env:keys` or reported as missing by `env:audit`.
`--vendor` (on `keys`, `compare`, `audit`, `explain`, `cleanup`) reports framework usages in full and also scans
the config of every other vendor package. Vendor usages never make a key required.

### Required keys

Laravel's config files use `env('X')` with no default to mean "optional", so EnvLens does not treat every such
key as required. A key is **required** only when all of these hold:

1. at least one of your `config/` files reads it with no default (or a `null` default),
2. no usage anywhere gives it a default, and
3. your team declared it: it is listed in the example file (`.env.example`) or matches a glob in `required`.

A required key that is absent is a `missing-required` error. Without the declaration it is optional, and an
absent optional key is only a `missing-optional` info finding. A key read only from application code is also
optional.

## Commands

| Command | Use it to | Writes files |
|---|---|---|
| [`env:keys`](#envkeys) | list the keys in an env file: set or empty, sensitive, who reads them, status | no |
| [`env:compare`](#envcompare) | compare two env files key by key | no |
| [`env:audit`](#envaudit) | check env files and `env()` calls, with a prioritised fix list (CI gate) | no |
| [`env:explain`](#envexplain) | see everything about one key, including what value the app gets | no |
| [`env:generate`](#envgenerate) | build an example file from a real one | yes |
| [`env:sync`](#envsync) | add missing keys to a file, in either direction | yes |
| [`env:cleanup`](#envcleanup) | remove keys a file shouldn't have (never ones the app reads) | yes |

All commands share one look: a header line saying what is being done, a table, a legend where codes need
explaining, and a summary. Write commands show exactly what they will write before writing, support `--dry-run`,
and end with a `DONE` badge, the backup path and a `git diff` reminder. Sample output below comes from a demo app
and is abridged.

### `env:keys`

```bash
php artisan env:keys [--file=] [--missing] [--vendor] [--only=vendor|missing|unused|sensitive] [--reveal] [--json] [--path=]
```

By default `env:keys` lists only the keys **defined in the file**. Above the table it prints a legend for the
statuses shown; below it, a summary. For each key:

| Column | Shows |
|---|---|
| Value | ✔ the key has a value, ✘ it is empty (`KEY=`) or not in the file. `--reveal` shows non-sensitive values instead (refused in production). |
| Sensitive | ✔ when the key or its value looks like a secret (see `envlens.sensitive`), `–` otherwise. |
| Used by Config | The config paths that read the key, one per line. |
| Used In | `app` (your config), `app (code)` (`env()` in app code), `app (config, code)`, `app (pattern)` (only matched by a runtime-built key such as `"{$provider}_TOKEN"`), `vendor` (a vendor package's config). `env:explain KEY` and `--json` name the package. |
| Status | See below. |

| Command | Lists |
|---|---|
| `env:keys` | keys defined in the file |
| `env:keys --missing` | plus keys your app config or code reads that the file doesn't define |
| `env:keys --vendor` | plus vendor keys the file doesn't define (scans every vendor package) |
| `env:keys --missing --vendor` | everything |
| `env:keys --only=vendor` | only keys read solely by vendor config, in the file or not (implies `--vendor`) |
| `env:keys --only=missing` | only app keys the file doesn't define (implies `--missing`) |
| `env:keys --only=unused` | only keys in the file that nothing reads |
| `env:keys --only=sensitive` | only sensitive keys |

| Status | Meaning |
|---|---|
| `used` | In the file and read by config or code. |
| `unused` | In the file, but nothing reads it. A cleanup candidate. |
| `external` | In the file and matched by `cleanup.keep` (e.g. `VITE_*`, read by tooling, not PHP). |
| `missing` | Not in the file, but required: config reads it with no default **and** it is declared in `.env.example` or `envlens.required`. Fix this. |
| `missing-with-default` | Not in the file, but the config call has a default (`env('DB_PORT', '3306')`), so Laravel uses the default. Informational. |
| `missing-optional` | Not in the file, and config reads it with no default but nobody declared it required (Laravel's `env('X')` usually means "optional"), or only code reads it. Informational. |

Laravel's own unpublished config is always scanned, so a key such as `BCRYPT_ROUNDS` shows as `used` with Used In
`vendor` and is never a cleanup candidate. Framework keys you never set (e.g. `ARGON_TIME`) only appear with
`--vendor`.

```
  Status .....................................................................
  used ................................................ read by config or code
  unused ......................................... in the file, nothing reads it

+---------------+-------+-----------+-----------------------+--------------------+--------+
| Key           | Value | Sensitive | Used by Config        | Used In            | Status |
+---------------+-------+-----------+-----------------------+--------------------+--------+
| APP_NAME      |   ✔   |     –     | app.name              | app, vendor        | used   |
|               |       |           | mail.from.name        |                    |        |
+---------------+-------+-----------+-----------------------+--------------------+--------+
| APP_KEY       |   ✔   |     ✔     | app.key               | app, vendor        | used   |
+---------------+-------+-----------+-----------------------+--------------------+--------+
| BCRYPT_ROUNDS |   ✔   |     –     | hashing.bcrypt.rounds | vendor             | used   |
+---------------+-------+-----------+-----------------------+--------------------+--------+
| SLACK_URL     |   ✘   |     –     | logging.slack.url     | app                | used   |
+---------------+-------+-----------+-----------------------+--------------------+--------+
| ORPHAN        |   ✔   |     –     |                       |                    | unused |
+---------------+-------+-----------+-----------------------+--------------------+--------+
| PAYMENT_URL   |   ✔   |     –     | services.payment.url  | app (config, code) | used   |
+---------------+-------+-----------+-----------------------+--------------------+--------+

  Summary ....................................................................
  Total keys ............................................................... 6
  With a value ............................................................. 5
  Need a value ............................................................. 1
  Sensitive / not sensitive ............................................ 1 / 5

Not listed: 1 required key missing from .env (STRIPE_WEBHOOK_SECRET), 88 optional keys read by app config or code. Use --missing to list them.
```

The summary counts the rows listed. **Need a value** counts keys with no value that something reads without a
fallback (empty in the file, since `env()` then returns `''` and the config default is not used, or absent and
`missing` / `missing-optional`); `(N required)` is added when some are `missing`. The footer appears when the default
view leaves app keys out, and names any required ones. With `--json`, each row has `has_value`, `in_file` and `source`
(with package names), and `data.summary` holds the counts.

### `env:compare`

```bash
php artisan env:compare [source] [target] [--all] [--only=source|target|different|empty|missing] [--reveal] [--strict] [--fail-on-empty] [--vendor] [--json] [--path=]
```

`source` and `target` default to `files.source` and `files.example`. One row per key that differs, with a legend
of the differences shown above the table and a summary below it:

```
  Comparing .env → .env.example

  Difference .................................................................
  only in .env .......................... defined in .env, not in .env.example
  different ..................... different values (placeholders are expected)
  empty in .env.example ................... empty there (expected for secrets)

+-------------+------+--------------+-----------+-----------------------+
| Key         | .env | .env.example | Sensitive | Difference            |
+-------------+------+--------------+-----------+-----------------------+
| ORPHAN      |  ✔   |      –       |     –     | only in .env          |
| PAYMENT_URL |  ✔   |      –       |     –     | only in .env          |
| APP_NAME    |  ✔   |      ✔       |     –     | different             |
| APP_KEY     |  ✔   |      ✘       |     ✔     | empty in .env.example |
+-------------+------+--------------+-----------+-----------------------+

  Summary ....................................................................
  Keys in .env / .env.example ........................................ 10 / 8
  Same ..................................................................... 5
  Different / only in .env / only in .env.example .................. 1 / 2 / 0
  Empty in .env.example .................................................... 1
  Required, missing from both .............................................. 0
```

The file columns show ✔ (has a value), ✘ (empty) or `–` (not in the file); `--reveal` shows non-sensitive values
there instead (refused in production). The differences are disjoint, so a key appears once:

| Difference | `--only=` | Meaning |
|---|---|---|
| `only in <source>` | `source` | Defined in the source, not in the target. |
| `only in <target>` | `target` | Defined in the target, not in the source. |
| `different` | `different` | Set in both, with different values. |
| `empty in <target>` | `empty` | Set in the source, empty in the target. |
| `missing from both` | `missing` | A [required](#required-keys) key neither file defines. |

When the target is the example file, `different` and `empty` are shown muted, since an example is expected to hold
placeholders and blank secrets. `--all` also lists keys with the same value (`same`). When nothing differs the
command prints `<source> and <target> are in sync.`

`--strict` exits 1 when the target lacks a key that the source has. `--fail-on-empty` also exits 1 for keys that
are empty in the target but not in the source. Differing values never fail. Exit codes ignore `--only`. With
`--json`, `data` holds the five lists, `same`, and a `summary` of the counts.

### `env:audit`

```bash
php artisan env:audit [--file=] [--example=] [--vendor] [--fail-on=error] [--json] [--path=] [-v]
```

Checks your env files and every `env()` call, then tells you what to fix first. The output, top to bottom:

1. **Header and verdict:** what was audited, and a `FAIL` / `WARN` / `PASS` badge with the counts and the exit code.
2. **Fix first:** up to five steps, most important first, each with the fix (and the EnvLens command, when one does it).
3. **What the codes mean:** one line per code that appears.
4. **Findings:** grouped under an `ERROR` / `WARN` / `INFO` badge, one line per finding. Info findings are counted, not
   listed, unless you pass `-v`.

```
  Auditing .env against .env.example · 12 config files, 2 code files scanned

   FAIL  3 errors · 15 warnings · 89 info (exit 1, --fail-on=error)

  Fix first
  1. Add STRIPE_WEBHOOK_SECRET to .env: required by billing.stripe.webhook.secret, no default.
  2. Set values for 3 empty keys in .env (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET), or delete them so config defaults apply.
  3. Move env() out of 2 places (app/Services/PaymentGateway.php:10, …) into config and use config(): env() returns null once php artisan config:cache runs.
  4. Remove the duplicate FEATURE_BETA (.env lines 80, 81; the last one wins).
  5. Add 8 keys from .env to the example file: php artisan env:sync.
  +3 more steps: see the findings below.

  What the codes mean
  missing-required      config reads it with no default, it is declared required, and the file lacks it
  empty-value           set but empty: env() returns '' and the config default is not used
  …

   ERROR  missing-required (1)
  STRIPE_WEBHOOK_SECRET ........................ billing.stripe.webhook.secret

   WARN  example-drift (10)
  GITHUB_TOKEN .................................. in .env, not in .env.example
  LEGACY_API_URL ................................ in .env.example, not in .env

   INFO  missing-with-default (60)
  60 findings (run with -v to list)
```

Findings, in fix order:

| Code | Severity | Meaning |
|---|---|---|
| `syntax` | error | A line phpdotenv cannot parse (the message never includes the value). |
| `missing-required` | error | A [required](#required-keys) key absent from the file. |
| `empty-value` | warning | Set but empty and read by config or code: `env()` returns `''`, so the config default is **not** used. |
| `env-outside-config` | error | `env()` called outside `config/`; it returns `null` once `php artisan config:cache` runs. |
| `duplicate-key` | warning | Defined twice in the file (the last one wins). |
| `no-example` | warning | No example file: teammates can't tell which keys to set, and only `envlens.required` marks keys as required. |
| `example-drift` | warning | In `.env` but not in the example, or in the example but not in `.env` (keys the app reads are reported as `missing-*` instead). |
| `dynamic-key` | warning | A key built at runtime (`env("{$p}_TOKEN")`), which can't be checked statically. |
| `scan-warning` | warning | A config or code file could not be read or parsed, or no config files were scanned. |
| `unused-key` | info | In the file, nothing reads it. |
| `missing-optional` | info | Absent, read without a default but not declared required, or read only from code. |
| `missing-with-default` | info | Absent, but config provides a default (including non-literal defaults). Usually fine. |

The audit no longer prints a usage table per config file; `env:keys` and `env:explain KEY` show where each key is
read. With `--json`, `data` holds `usages`, `findings` (each with `detail` and a `hint` saying how to fix it) and a
`summary` of the counts per severity.

`--fail-on=error|warning|info|none` picks the severity that produces exit code 1 (default `error`).

### `env:explain`

```bash
php artisan env:explain [key] [--reveal] [--vendor] [--json] [--path=] [-v]
```

Everything about one key. Without `key` you are prompted with a searchable list.

```
  STRIPE_WEBHOOK_SECRET  sensitive  missing
  Read by config with no default and declared required, but not set in .env.

  The app gets ........................................ null (not set in .env)

  Read by
  billing.stripe.webhook.secret ............ config/billing.php:9 · no default

  Env files
  .env ......................................................... – not in file
  .env.example ....................................................... ✘ empty

   ERROR  missing-required
  STRIPE_WEBHOOK_SECRET is required by config (billing.stripe.webhook.secret) but missing from .env
  Fix: Add STRIPE_WEBHOOK_SECRET to .env.
```

- **Headline:** the key, a `sensitive` badge, and its [status](#envkeys) with one sentence on what it means.
- **The app gets:** what `env()` hands your config:
  - a masked preview of the value (shown in full with `--reveal`, refused in production; secrets stay masked)
  - `''` when the key is empty (config defaults are **not** used)
  - the config default when the key is absent and config gives one
  - otherwise `null`
- **Read by:** one row per usage (config path, file:line, default), your config first, then code, then vendor.
  Framework usages your own config replaces at the same path are folded into `+N framework defaults overridden by
  your config`; `-v` lists them. Keys reached only through a runtime-built key (`env("{$p}_TOKEN")`) show that
  call.
- **Env files:** ✔ set (with the preview), ✘ empty or `–` not in file, for `.env`, the example and any other
  `.env.*` file.
- **Findings:** the same codes as `env:audit`, each with a fix.

For a key that nothing reads and no env file defines, it says so, suggests similarly spelled keys
(`Did you mean: STRIPE_SECRET?`) and exits 1. With `--json`, `data` has `status`, `resolves` (`value`, `empty`,
`default` or `null`), `usages` (each with `overridden`), `dynamic`, `presence`, `findings` and `suggestions`.

### `env:generate`

```bash
php artisan env:generate [target] [--source=] [--with-values] [--no-defaults] [--dry-run] [--force] [--path=]
```

Creates `target` (default `files.example`) from the structure of `source` (default `files.source`) with values
blanked: comments, order and blank lines are kept, but a commented-out assignment such as `#MAIL_PASSWORD=...`
keeps only `#MAIL_PASSWORD=`. Blank keys get the literal default from config unless `--no-defaults` (never for
keys in `generate.skip_defaults`); sensitive keys are always blank.
`--with-values` keeps non-sensitive values (refused in production). Unparseable source lines are dropped.
If the target exists you are asked to confirm (default No); non-interactively it is refused without `--force`.
`--dry-run` shows everything and writes nothing (allowed in production). It never overwrites the app's own env file
(`files.source`), even with `--force`, since a generated file has no real values; it can create one that doesn't
exist yet (`env:generate .env --source=.env.example`), except in production.

The output uses the same table as `env:sync`: each key, the exact value written, and why. When the target exists, a
`WARN` lists what overwriting loses: keys only in the current file (marked when the app reads them) and values the
team set there, with `env:sync` as the alternative.

```
  Generating .env.example from .env · 55 keys · overwrites .env.example · dry run, nothing written

+----------+--------------------+----------------------------------+
| Key      | Will be written as | Why                              |
+----------+--------------------+----------------------------------+
| APP_NAME | Laravel            | config default                   |
| APP_ENV  | (blank)            | skipped (generate.skip_defaults) |
| APP_KEY  | (blank)            | secret: values never copied      |
  …
  Summary ....................................................................
  Keys written ............................................................ 55
  Value copied / config default / blank .......................... 0 / 30 / 25
  Secrets (always blank) .................................................. 11
  Commented-out values blanked ............................................. 7
  Unparseable lines dropped ................................................ 0

   WARN  Overwriting .env.example loses:
  3 keys only in it (STRIPE_WEBHOOK_SECRET (read by the app), LEGACY_API_URL, OLD_SERVICE_KEY); 12 values set there.
  To add only missing keys instead: php artisan env:sync
```

The generated file:

```
# Application
APP_NAME=Laravel
APP_ENV=
APP_KEY=
APP_DEBUG=

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_PASSWORD=
```

### `env:sync`

```bash
php artisan env:sync [source] [target] [--with-values] [--no-defaults] [--dry-run] [--force] [--path=]
```

Adds keys that are in the source (default `files.source`) but missing from the target (default `files.example`).
Existing target values are never changed, and new keys land next to keys with the same prefix. Interactively you
pick which non-sensitive keys get their source value copied (the picker shows each value; sensitive keys are never
offered and are always written blank), see the plan, then confirm. Non-interactively values stay blank or take the
config default unless `--with-values`. Declining the confirmation prints `Aborted.` and exits 1.

```
  Syncing .env → .env.example · 8 keys to add · dry run, nothing written

+---------------------+--------------------------+-----------------------------+
| Key                 | Will be written as       | Why                         |
+---------------------+--------------------------+-----------------------------+
| GITHUB_TOKEN        | (blank)                  | secret: values never copied |
| PAYMENT_GATEWAY_URL | https://pay.example.test | value copied                |
| APP_URL             | http://localhost         | config default              |
| BCRYPT_ROUNDS       | (blank)                  | no literal config default   |
| APP_DEBUG           | (blank)                  | skipped (generate.skip_defaults) |
+---------------------+--------------------------+-----------------------------+

  Summary ....................................................................
  To add ................................................................... 8
  Value copied / config default / blank ............................ 3 / 0 / 5
  Secrets (always blank) ................................................... 5

  Not touched: 3 keys only in .env.example.
  - STRIPE_WEBHOOK_SECRET: read by the app but missing from .env; add it with php artisan env:sync .env.example .env.
  - LEGACY_API_URL, OLD_SERVICE_KEY: nothing in the app reads them; remove with php artisan env:cleanup .env .env.example.
```

It works in either direction: `php artisan env:sync .env.example .env` adds the keys a teammate added to the
example to your own `.env`. When the target is the app's env file (`files.source`), the hints never suggest removing
keys from it, and in production it is **never written**, even with `--force` (`--dry-run` still works).

**Will be written as** is the exact value that goes into the target (cut at 40 characters): a copied value, a
literal config default, or `(blank)`. Secret values are never shown or written. The header says when the target
will be created, and `--dry-run` writes nothing. After a write it prints `DONE Added N keys to <target>.`, the
backup path relative to the project, and a reminder to review the file (`git diff <target>`).

### `env:cleanup`

```bash
php artisan env:cleanup [source] [target] [--unused] [--file=] [--vendor] [--dry-run] [--force] [--path=]
```

Default (mirror) mode removes from `target` the keys that are absent from `source`. With `--unused` it removes keys
that nothing in config or code reads (the `source` argument is ignored; name the file with `--file`, for example
`env:cleanup --unused --file=.env`). In **both** modes a key is always kept when:

- config or code reads it (in mirror mode such a key belongs in the source; cleanup points at `env:sync`),
- a runtime-built `env("PREFIX_{$x}")` call matches it, or
- it matches `cleanup.keep`.

Cleanup warns about fully dynamic `env()` calls (any key could be read there). If no config file was scanned,
`--unused` refuses (exit 2) rather than treat every key as unused. Interactively you get a multiselect (all ticked,
labels say which keys have a value) and a confirmation that defaults to No; declining prints `Aborted.` and exits 1.
**Non-interactive runs without `--force` are a dry run.** In production the app's own env file (`files.source`) is
never written, even with `--force`.

```
  Cleaning .env.example · keys not in .env · 2 to remove · dry run, nothing removed

+-----------------+-------+------------------------------------------+
| Key             | Value | Why it can go                            |
+-----------------+-------+------------------------------------------+
| LEGACY_API_URL  |   ✘   | not in .env; nothing in the app reads it |
| OLD_SERVICE_KEY |   ✘   | not in .env; nothing in the app reads it |
+-----------------+-------+------------------------------------------+

  Kept
  STRIPE_WEBHOOK_SECRET  read by billing.stripe.webhook.secret; missing from .env
  Add it to .env with php artisan env:sync .env.example .env

  Summary ....................................................................
  To remove / kept ..................................................... 2 / 1
  Values lost .............................................................. 0
```

The Value column shows ✔ when the line has a value that removal loses; cleaning the app's own `.env` adds a
warning with the count. `--unused` lists under Kept only keys saved by a runtime-built key or `cleanup.keep`
(read keys are never candidates there). A removal ends with `DONE Removed N keys from <file>.`, the backup path
relative to the project, and `git diff <file>` unless the file is your own `.env`.

## CI usage

`env:audit --fail-on=error` is the primary check. It fails the build on `missing-required`, `env-outside-config`
and `syntax` findings, and reports `example-drift`, `empty-value` and `duplicate-key` as warnings (use
`--fail-on=warning` to fail on those too). CI usually has no real `.env`, so the audit step below copies
`.env.example` to `.env` so that `--file=.env` exists. That means the audit checks your config and code against the
keys the example declares. A copied example has blank values, so expect `empty-value` warnings there; keep
`--fail-on=error` in that setup.

`env:compare --strict` is only meaningful with a real, explicit source. Commit a file that lists the keys your
application needs (for example `.env.ci`) and compare it with the example. The command exits 1 when the target
lacks any key the source has. Do not compare a file with a copy of itself, since that always passes.

```yaml
# .github/workflows/env.yml
name: env
on: [push, pull_request]

jobs:
  env:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env
      - name: Audit env usage
        run: php artisan env:audit --fail-on=error
      - name: Example file has every key in .env.ci
        run: php artisan env:compare .env.ci .env.example --strict
```

Use `--json` for machine-readable output.

## Using the services programmatically

```php
use Touhidurabir\EnvLens\Facades\EnvLens;

$usages = EnvLens::usages()->for('DB_HOST');   // list of EnvUsage (file, line, config path, default)
$isUsed = EnvLens::usages()->isUsed('DB_HOST');

$file = EnvLens::file(base_path('.env'));
$detector = EnvLens::sensitivity();             // SensitivityDetector
```

The facade exposes `file()`, `usages()`, `sensitivity()`, `writer()`, `guard()`, `analyzer()`, `generator()` and
`synchronizer()`. The services contain no console code, so they can be used from your own commands or tests. The
analyzer returns the same objects the commands render:

```php
$env = EnvLens::file(base_path('.env'));
$example = EnvLens::file(base_path('.env.example'));
$index = EnvLens::usages();

$report = EnvLens::analyzer()->audit($env, $example, $index);   // AuditReport: findings (with detail and hint), summary()
$keys = EnvLens::analyzer()->keys($env, $index);                // KeyReport: rows with status, sources, hasValue
$explanation = EnvLens::analyzer()->explain('DB_HOST', ['.env' => $env, '.env.example' => $example], $index, $example);
// Explanation: status, resolves (value|empty|default|null), usages, overridden, findings, suggestions
```

## Safety model

- **Masking.** Values are masked by default: the first two characters then `••••`, or just `••••` for values of
  four characters or fewer; empty values show as `(empty)`. `--reveal` shows non-sensitive values only;
  sensitive values (including connection strings with credentials) are never printed or copied.
- **Production guard.** It trips if the running app's environment **or** the target project's `.env` `APP_ENV` is
  listed in `production_environments`. In production `--reveal` and `--with-values` are refused, and write commands
  (`sync`, `cleanup`, `generate`) refuse without `--force`; even with `--force` no values are copied and the app's
  own env file (`files.source`) is never written. `--dry-run` always works.
- **The app's own `.env` is protected everywhere.** `env:generate` never overwrites it (a generated file has no real
  values), `env:cleanup` never removes a key that config or code reads, and the hints of `env:sync` never suggest
  deleting keys from it.
- **Values you would lose are shown first.** `env:cleanup` marks keys whose removal loses a value, and
  `env:generate` lists what overwriting an existing file throws away before asking.
- **Lossless writes.** Files are parsed into lines and untouched lines are re-emitted byte for byte (comments,
  quoting, `export` prefixes, multi-line values, CRLF). File mode and symlinks are preserved.
- **Backups.** Before modifying a file EnvLens copies it to `storage/envlens/backups/<file>.YmdHis` (a `-N`
  suffix is added if that name is taken), keeping the file's mode. The directory (`write.backup_path`) is created
  `0700` with its own `.gitignore` (`*` and `!.gitignore`), so backups are never committed. Turn backups off with
  `write.backup`.
- **Write failures.** If a write fails, the command prints the reason and exits 1; the original file is untouched.

## Output in AI coding agents

Laravel 13 apps ship `laravel/pao`, which detects AI agents (Claude Code, Cursor, …) and compacts command output for
them. That strips the table padding and the ✔/✘ icons, so EnvLens output looks squashed when an agent runs it. Your
own terminal is unaffected. To see the real output from an agent session, prefix the command with `PAO_DISABLE=1`,
or use `--json`.

## Testing

```bash
composer test            # Pest
composer test-coverage   # requires pcov or xdebug; fails under 90%
composer analyse         # Larastan, level 8
composer format          # Pint
```

## License

MIT. See [LICENSE.md](LICENSE.md).
