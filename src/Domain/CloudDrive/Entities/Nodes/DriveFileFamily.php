<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\Nodes;

/**
 * The family of a file (PDF, spreadsheet, image …) for lists, icons and the family filter: from the content type the
 * ingest stored, and from the name's extension when the type tells nothing (`application/octet-stream`). The one
 * place the mapping lives; {@see \DDD\Domain\CloudDrive\Services\DriveNodesService::findNodesByListQuery()} filters by
 * the same tables.
 */
class DriveFileFamily
{
    public const string FAMILY_PDF = 'PDF';
    public const string FAMILY_SPREADSHEET = 'SPREADSHEET';
    public const string FAMILY_DOCUMENT = 'DOCUMENT';
    public const string FAMILY_PRESENTATION = 'PRESENTATION';
    public const string FAMILY_TEXT = 'TEXT';
    public const string FAMILY_IMAGE = 'IMAGE';
    public const string FAMILY_VECTOR = 'VECTOR';
    public const string FAMILY_VIDEO = 'VIDEO';
    public const string FAMILY_ARCHIVE = 'ARCHIVE';
    public const string FAMILY_OTHER = 'OTHER';

    /** @var string[] Every family a filter accepts */
    public const array FAMILIES = [
        self::FAMILY_PDF, self::FAMILY_SPREADSHEET, self::FAMILY_DOCUMENT, self::FAMILY_PRESENTATION, self::FAMILY_TEXT,
        self::FAMILY_IMAGE, self::FAMILY_VECTOR, self::FAMILY_VIDEO, self::FAMILY_ARCHIVE, self::FAMILY_OTHER,
    ];

    /** @var array<string, string[]> Exact content types per family (checked before the prefixes) */
    public const array CONTENT_TYPES_BY_FAMILY = [
        self::FAMILY_PDF => ['application/pdf'],
        self::FAMILY_SPREADSHEET => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel',
            'application/vnd.oasis.opendocument.spreadsheet', 'text/csv', 'text/tab-separated-values',
        ],
        self::FAMILY_DOCUMENT => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword',
            'application/vnd.oasis.opendocument.text', 'application/rtf',
        ],
        self::FAMILY_PRESENTATION => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-powerpoint',
            'application/vnd.oasis.opendocument.presentation',
        ],
        self::FAMILY_VECTOR => ['image/svg+xml'],
        self::FAMILY_ARCHIVE => [
            'application/zip', 'application/x-zip-compressed', 'application/gzip', 'application/x-tar',
            'application/x-7z-compressed', 'application/vnd.rar',
        ],
        self::FAMILY_TEXT => ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml'],
    ];

    /** @var array<string, string[]> Content type prefixes per family (after the exact types of every family) */
    public const array CONTENT_TYPE_PREFIXES_BY_FAMILY = [
        self::FAMILY_IMAGE => ['image/'],
        self::FAMILY_VIDEO => ['video/'],
        self::FAMILY_TEXT => ['text/'],
    ];

    /** @var array<string, string[]> Extensions per family, used when the content type tells nothing */
    public const array EXTENSIONS_BY_FAMILY = [
        self::FAMILY_PDF => ['pdf'],
        self::FAMILY_SPREADSHEET => ['xlsx', 'xls', 'ods', 'csv', 'tsv'],
        self::FAMILY_DOCUMENT => ['docx', 'doc', 'odt', 'rtf'],
        self::FAMILY_PRESENTATION => ['pptx', 'ppt', 'odp'],
        self::FAMILY_TEXT => ['txt', 'md', 'json', 'xml', 'yaml', 'yml', 'log'],
        self::FAMILY_IMAGE => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif', 'bmp', 'tif', 'tiff'],
        self::FAMILY_VECTOR => ['svg'],
        self::FAMILY_VIDEO => ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'],
        self::FAMILY_ARCHIVE => ['zip', 'gz', 'tgz', 'tar', '7z', 'rar'],
    ];

    /** @var string[] Content types that tell nothing about the file: the extension decides */
    public const array UNSPECIFIC_CONTENT_TYPES = ['application/octet-stream', 'binary/octet-stream', ''];

    /**
     * The family of a file: by its content type, else by its extension; OTHER when neither tells.
     */
    public static function findFamily(?string $contentType, ?string $fileName = null): string
    {
        $normalizedContentType = strtolower(trim(explode(';', (string)$contentType)[0]));
        if (!in_array($normalizedContentType, static::UNSPECIFIC_CONTENT_TYPES, true)) {
            foreach (static::CONTENT_TYPES_BY_FAMILY as $family => $contentTypes) {
                if (in_array($normalizedContentType, $contentTypes, true)) {
                    return $family;
                }
            }
            foreach (static::CONTENT_TYPE_PREFIXES_BY_FAMILY as $family => $contentTypePrefixes) {
                foreach ($contentTypePrefixes as $contentTypePrefix) {
                    if (str_starts_with($normalizedContentType, $contentTypePrefix)) {
                        return $family;
                    }
                }
            }
        }
        $extension = static::findExtension((string)$fileName);
        foreach (static::EXTENSIONS_BY_FAMILY as $family => $extensions) {
            if ($extension !== '' && in_array($extension, $extensions, true)) {
                return $family;
            }
        }
        return static::FAMILY_OTHER;
    }

    /** Lower case, without the dot; '' without one */
    public static function findExtension(string $fileName): string
    {
        $dotPosition = strrpos($fileName, '.');
        return $dotPosition !== false && $dotPosition > 0 ? strtolower(substr($fileName, $dotPosition + 1)) : '';
    }

    /** @return string[] The exact content types of every family but `$family` */
    public static function findContentTypesOfOtherFamilies(string $family): array
    {
        $contentTypes = [];
        foreach (static::CONTENT_TYPES_BY_FAMILY as $otherFamily => $familyContentTypes) {
            if ($otherFamily !== $family) {
                array_push($contentTypes, ...$familyContentTypes);
            }
        }
        return $contentTypes;
    }
}
