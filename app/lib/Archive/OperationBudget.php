<?php
declare(strict_types=1);

namespace OCA\ArchiveTools\Archive;

/** One monotonic deadline, explicitly shared by the work belonging to one job. */
final class OperationBudget {
    public const DEFAULT_SECONDS = 300;
    private readonly float $deadline;
    private readonly \Closure $clock;

    public function __construct(float $seconds = self::DEFAULT_SECONDS, ?\Closure $clock = null) {
        if (!is_finite($seconds) || $seconds < 0 || $seconds > self::DEFAULT_SECONDS) {
            throw new \InvalidArgumentException('Invalid archive operation time limit.');
        }
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->deadline = ($this->clock)() + $seconds;
    }

    public function remainingSeconds(): float {
        $remaining = $this->deadline - ($this->clock)();
        if ($remaining <= 0) { throw new OperationBudgetExceededException(); }
        return $remaining;
    }

    public function check(): void { $this->remainingSeconds(); }
}
