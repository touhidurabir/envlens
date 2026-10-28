# Changelog

All notable changes to `touhidurabir/envlens` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).
`--json` output carries a top-level `"schema"` number; removing or renaming a field bumps it and is listed here.

## [Unreleased]

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
