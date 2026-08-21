<?php

declare(strict_types=1);

namespace WaveSpeed\Http;

use WaveSpeed\Exception\ConnectionException;

/**
 * Transport seam for the SDK.
 *
 * `Client` talks to the API only through this interface, so tests drive it
 * with a fake and applications may substitute their own HTTP stack.
 */
interface HttpClient
{
    /**
     * Send a request and return the response.
     *
     * An HTTP error status is a valid response and must be returned, not
     * thrown — only a failure to obtain any response at all is an exception.
     *
     * @throws ConnectionException If no response could be obtained.
     */
    public function send(Request $request): Response;
}
