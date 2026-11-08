<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Analysis;

use JsonSerializable;
use Touhidurabir\EnvLens\Scanning\EnvUsage;

final readonly class AuditReport implements JsonSerializable
{
    /**
     * @param  array<string, list<EnvUsage>>  $usagesByFile  default values of sensitive keys are masked
     * @param  list<Finding>  $findings
     * @param  int  $baselined  findings left out because the baseline accepts them
     */
    public function __construct(
        public array $usagesByFile,
        public array $findings,
        public int $baselined = 0,
    ) {}

    public function worst(): ?Severity
    {
        $worst = null;

        foreach ($this->findings as $finding) {
            $severity = $finding->severity();

            if ($worst === null || $severity->atLeast($worst)) {
                $worst = $severity;
            }
        }

        return $worst;
    }

    public function has(Severity $atLeast): bool
    {
        foreach ($this->findings as $finding) {
            // A stale baseline entry is housekeeping: it is reported, but never fails anything.
            if ($finding->code !== FindingCode::StaleBaseline && $finding->severity()->atLeast($atLeast)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Findings per severity.
     *
     * @return array{error: int, warning: int, info: int}
     */
    public function summary(): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];

        foreach ($this->findings as $finding) {
            $counts[$finding->severity()->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'usages' => $this->usagesByFile,
            'findings' => $this->findings,
            'summary' => $this->summary(),
            'baselined' => $this->baselined,
        ];
    }
}
