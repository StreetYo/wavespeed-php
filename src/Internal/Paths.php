<?php

declare(strict_types=1);

namespace WaveSpeed\Internal;

/**
 * Path helpers that behave the same on Windows and POSIX.
 *
 * @internal
 */
final class Paths
{
    private function __construct()
    {
    }

    /**
     * Last path segment, treating both `/` and the platform separator as
     * boundaries — stream URIs use forward slashes even on Windows.
     */
    public static function basename(string $path): string
    {
        $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $normalized = rtrim($normalized, '/');
        if ($normalized === '') {
            return $path;
        }

        $position = strrpos($normalized, '/');

        return $position === false ? $normalized : substr($normalized, $position + 1);
    }

    /**
     * Whether the string looks like a filesystem path or stream URI rather
     * than a bare name.
     */
    public static function hasDirectory(string $path): bool
    {
        return str_contains($path, '/') || str_contains($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Derive an upload filename from a stream's URI.
     *
     * A real path (or a `file://` URL) yields its basename. Anything else —
     * `php://temp`, `php://memory`, a `data:` URI — is not a filename at all
     * and yields null, so the caller falls back to a generic name.
     */
    public static function filenameFromStreamUri(string $uri): ?string
    {
        if ($uri === '') {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*)://(.*)$#i', $uri, $matches) === 1) {
            if (strtolower($matches[1]) !== 'file') {
                return null;
            }

            $uri = $matches[2];
            if ($uri === '') {
                return null;
            }
        }

        $basename = self::basename($uri);

        return $basename === '' ? null : $basename;
    }
}
