<?php

declare(strict_types=1);

namespace WaveSpeed;

/**
 * Lifecycle states a prediction can report in `data.status`.
 *
 * The API is the authority on this vocabulary: an unknown value is treated as
 * "still running" rather than an error, so a newly introduced non-terminal
 * status cannot break polling.
 */
enum PredictionStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Timeout = 'timeout';

    /**
     * Whether polling should stop because the task will not change again.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::Timeout => true,
            default => false,
        };
    }

    /**
     * Whether this is a terminal state that did not produce outputs.
     */
    public function isFailure(): bool
    {
        return $this->isTerminal() && $this !== self::Completed;
    }
}
