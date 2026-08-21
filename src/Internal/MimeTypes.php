<?php

declare(strict_types=1);

namespace WaveSpeed\Internal;

/**
 * Filename-based content-type guessing for uploads.
 *
 * Guessing from the extension (rather than sniffing bytes) matches the Python
 * SDK and works for stream uploads, where there may be no file to sniff.
 *
 * @internal
 */
final class MimeTypes
{
    /** @var array<string, string> */
    private const MAP = [
        // Images
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpe' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'svg' => 'image/svg+xml',
        'ico' => 'image/vnd.microsoft.icon',
        'avif' => 'image/avif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        // Video
        'mp4' => 'video/mp4',
        'm4v' => 'video/x-m4v',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
        'ogv' => 'video/ogg',
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'aac' => 'audio/aac',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/opus',
        'weba' => 'audio/webm',
        // Documents and misc
        'json' => 'application/json',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'safetensors' => 'application/octet-stream',
        'bin' => 'application/octet-stream',
    ];

    private function __construct()
    {
    }

    /**
     * Guess a content type from a filename, or null when unknown.
     */
    public static function guess(string $filename): ?string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        return self::MAP[$extension] ?? null;
    }
}
