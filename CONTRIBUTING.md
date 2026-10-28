# Contributing

Thanks for helping improve EnvLens.

## Setup

```bash
composer install
```

## Before opening a pull request

```bash
composer test       # Pest, including the architecture tests
composer analyse    # Larastan, level 8, no baseline
composer format     # Pint (CI runs `vendor/bin/pint --test`)
```

CI also enforces at least 90% line coverage (`composer test-coverage`, needs pcov or xdebug).

## Guidelines

- Write the failing test first.
- `declare(strict_types=1);` in every PHP file.
- Layering: services (everything outside `src/Console`) never use `Illuminate\Console` or `Laravel\Prompts`.
  Commands extend `EnvLensCommand` and implement `perform()`. The architecture tests enforce this.
- Never print sensitive values. Go through the masking helpers.
- Update `README.md` and `CHANGELOG.md` for user-visible changes.
- Backups go to `storage/envlens/backups/` (a directory with its own `.gitignore`); never commit them.
- Keep the command output consistent: a header line, a table, a legend where codes need explaining and a summary.
  Write commands show what they will write first, support `--dry-run` and end with a `DONE` badge. Shared pieces
  live in `src/Console/Concerns`.
- Termwind writes to stdout until told otherwise; a command that calls `Termwind\render()` must call
  `renderUsing($this->output)` first, or its output is lost in tests and `Artisan::call()`.
- Never let a write command touch the app's own env file (`files.source`) in production, and never remove a key the
  app reads.

## Reporting bugs

Use the issue templates. Mask any real secrets in examples.
