<?php

declare(strict_types=1);

namespace WaveSpeed;

/**
 * SDK version, reported through the X-Client-Version request header.
 *
 * When the package is installed through Composer the installed version wins,
 * so a consumer's lock file is the source of truth; the constant below is the
 * fallback for a plain checkout (and the value bumped when tagging a release).
 */
final class Version
{
    /** Fallback version used when Composer runtime metadata is unavailable. */
    public const VERSION = '1.0.0';

    private static ?string $resolved = null;

    /**
     * Get the SDK version string.
     */
    public static function get(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $version = self::VERSION;

        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $installed = \Composer\InstalledVersions::getPrettyVersion('wavespeedai/wavespeed-php');
                if (is_string($installed) && $installed !== '') {
                    // Tags are published as "v1.2.3"; the header carries the
                    // bare version.
                    $version = ltrim($installed, 'vV');
                }
            } catch (\OutOfBoundsException) {
                // Package not installed through Composer (e.g. a git checkout):
                // keep the compiled-in fallback.
            }
        }

        return self::$resolved = $version;
    }

    /**
     * Reset the memoized version. Intended for tests.
     */
    public static function reset(): void
    {
        self::$resolved = null;
    }
}
