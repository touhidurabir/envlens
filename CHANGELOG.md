# Changelog

All notable changes to `touhidurabir/envlens` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).
`--json` output carries a top-level `"schema"` number; removing or renaming a field bumps it and is listed here.

## [Unreleased]

### Added
- `env:audit --ci`: the gate for pull requests. It checks config and code against the example file alone, with no `.env`
  or secrets, and a stock Laravel 12 or 13 app passes with no setup. New findings: `undeclared-key` (config reads a key
  with no default that the example doesn't list) and `example-secret`. A file that can't be scanned is an error, and
  finding no `env()` reads in config exits 2 whatever `--fail-on` says.
- `env:audit --ci --update-baseline`: accepts current findings in `envlens-baseline.json`, so an existing app can adopt
  the gate and only new findings block. Secrets, syntax errors and scan failures are never accepted. Adds `stale-baseline`.
- GitHub annotations from `env:audit` and `env:validate` inside GitHub Actions, errors first.
- `env:validate`: checks values against rules in `env.rules.php`, and works with no rules file by inferring checks from
  config defaults. Inferred checks warn unless `--strict`; rules in the file fail. Sensitive keys show only the rule's name.
- `env:validate --init`: generates `env.rules.php`, showing the assumption behind each rule first.
- `optional` and `ci.allow_values` config keys. `validator()` and `inferrer()` on the facade, and `EnvAnalyzer::contract()`.
- `illuminate/validation` as a dependency (Laravel apps already have it).
- `data.baselined` in `env:audit --json`.

### Changed
These affect `env:audit` without `--ci`:
- It reports `example-secret` as a warning when a sensitive key has a real-looking value in the example file.
- A key matching `optional` is no longer required, even when the example lists it. By default that covers
  `POSTMARK_API_KEY` and `RESEND_API_KEY`. This also applies to `env:keys` and `env:compare`.
- Inside GitHub Actions it prints annotation lines.

Other changes:
- The default secret names are wider: `PASSWORD`, `SECRET`, `TOKEN`, `BEARER`, `*_PASSWD`, `*APIKEY`, `*_PASSWORD_*`,
  `*_TOKEN_*`, `*_KEY_*`, `*_BEARER`, `*AUTH_HEADER*`, with exceptions such as `AUTH_PASSWORD_*`. More keys are masked and
  never copied, including `AWS_ACCESS_KEY_ID`. **If you published the config, it keeps its old lists**: add these yourself.
- `env:cleanup` refuses to write when a file couldn't be scanned, and mirror mode refuses an empty scan as `--unused` does.
- `env:cleanup` keeps a comment that starts a block and heads several keys until the last of them goes.
- With no terminal, every command is non-interactive, as `-n` would make it.
- A named `--example` that doesn't exist is exit 2 on `env:audit`.
- The header of `env:audit` says how many files read `env()`, which is what it always counted.

### Fixed
- **Secrets:** `env:generate` copied commented-out secrets behind `##`, `#;` or `#-`, or inside a comment's words, and it and
  `env:sync` wrote a config default holding credentials. The default of a runtime-built `env()` key was shown unmasked in JSON.
- **Production guard:** `./.env` and `app/../.env` when the file didn't exist yet, and `.ENV`, got past the guard on the app's
  env file. It also read `APP_ENV` only from `.env` rather than `files.source`, and an empty `production_environments`
  counted everything as production.
- **Scanner:** in Blade, `env()` in a comment or in plain text was reported, and `\\env('X')`, `env ('X')` and runtime-built keys
  were missed. `env('X') ?? 'y'` and `env('X') ?: 'y'` now count as having a default. A float default such as `1.0` stays a float.
  Keys read through `getenv()`, `$_ENV` or `$_SERVER` count as used, so cleanup no longer removes them.
- **Parsing:** a byte order mark, keys starting with a digit and a key with no equals sign are accepted, as in phpdotenv.
- `env:generate` lists every line an unterminated quote swallows, not just the first.
- `--json` printed a blank line when a value wasn't valid UTF-8.
- The 1.0.0 links in this file pointed at a `v1.0.0` tag; the tag is `1.0.0`.
- The README's CI recipe couldn't catch a forgotten key or a secret in the example. It now uses `--ci`.

## [1.0.0] - 2026-10-28

### Added
- Seven commands: `env:keys`, `env:compare`, `env:audit`, `env:explain`, `env:generate`, `env:sync` and
  `env:cleanup`. They share one look: a header line, a table, a legend where codes need explaining and a summary,
  using Termwind badges (`nunomaduro/termwind`, which Laravel already ships).
- `env:keys` lists the keys defined in the file, with ✔/✘ for Value and Sensitive, the config paths that read each
  key (Used by Config), who reads it (Used In: `app`, `app (code)`, `app (pattern)`, `vendor`) and a coloured status,
  plus a status legend, a summary (total, with a value, need a value, sensitive / not sensitive) and a footer naming
  required keys the default view leaves out. `--missing`, `--vendor` and `--only=vendor|missing|unused|sensitive`.
- `env:compare` shows one row per differing key (✔/✘/– per file, Sensitive, Difference) with a legend and a
  summary; differences against the example file are muted. `--all`, `--only=source|target|different|empty|missing`,
  `--strict`, `--fail-on-empty`.
- `env:audit` shows a `FAIL` / `WARN` / `PASS` verdict, a prioritised "Fix first" list, a legend of the codes it
  reports and the findings grouped by severity (info findings listed with `-v`). Findings: `syntax`,
  `missing-required`, `empty-value`, `env-outside-config`, `duplicate-key`, `no-example`, `example-drift` (both
  directions), `dynamic-key`, `scan-warning`, `unused-key`, `missing-optional`, `missing-with-default`. `--fail-on`.
- `env:explain` shows one key's status, what the app gets (value, `''`, config default or `null`), its usages
  (framework usages your config overrides folded away; `-v` lists them), its state in every `.env*` file and its
  findings with fixes. An unknown key suggests similar keys and exits 1. `--reveal`.
- `env:generate` builds an example file from a real one, printing the value written for each key and why, a summary
  (including commented-out values blanked and dropped line numbers) and what overwriting an existing file loses.
  `--with-values`, `--no-defaults`, `--dry-run`, `--force`.
- `env:sync` adds missing keys in either direction, with the same plan table, a value picker that shows each value,
  and hints for keys only in the target. `--with-values`, `--no-defaults`, `--dry-run`, `--force`.
- `env:cleanup` removes keys absent from a source (mirror mode) or that nothing reads (`--unused`). Keys config or
  code reads, keys matched by a runtime-built `env()` key and `cleanup.keep` keys are always kept and listed with
  the reason; the table marks keys whose removal loses a value. `--dry-run`, `--force`.
- `--vendor` (on `keys`, `compare`, `audit`, `explain`, `cleanup`) scans every vendor package's config. Laravel's
  own config is always scanned so framework keys count as used, but it is only reported in full with `--vendor`.
- Static config and code scanning (nikic/php-parser), including Blade and runtime-built keys (`"{$p}_TOKEN"` →
  `*_TOKEN`).
- Required keys: read by config with no default **and** declared in the example file or `envlens.required`.
- Sensitive-key detection by name (`sensitive.keys`, `sensitive.patterns` including `*_WEBHOOK*`,
  `sensitive.except`) and by value (connection strings with credentials); values are masked by default and secrets
  are never printed or copied.
- Lossless env file reading and writing (comments, quoting, `export`, multi-line values, CRLF, file mode, symlinks),
  with backups in `storage/envlens/backups/<file>.<YmdHis>` (a git-ignored directory).
- Production guard: `--reveal` and `--with-values` refused; writes need `--force`; the app's own env file is never
  written in production, and `env:generate` never overwrites it at all.
- JSON output (`"schema": 1`) on the read-only commands, including summaries, finding `detail` / `hint`, key
  `status` / `resolves` and suggestions.
- `EnvLens` facade for using the scanning, analysis and mutation services programmatically.

[Unreleased]: https://github.com/touhidurabir/envlens/compare/1.0.0...HEAD
[1.0.0]: https://github.com/touhidurabir/envlens/releases/tag/1.0.0
