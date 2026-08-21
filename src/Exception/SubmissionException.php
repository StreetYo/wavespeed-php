<?php

declare(strict_types=1);

namespace WaveSpeed\Exception;

/**
 * A task submission failed and must not be retried automatically.
 *
 * A submission POST is not idempotent: when it fails without a response the
 * server may already have created the task, so replaying it risks paying for
 * the same job twice. The SDK therefore sends every submission at most once
 * and surfaces the ambiguity here instead of retrying.
 */
class SubmissionException extends ApiException
{
}
