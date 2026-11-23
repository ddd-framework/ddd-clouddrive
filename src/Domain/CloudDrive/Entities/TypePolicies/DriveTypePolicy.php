<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\TypePolicies;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveFileFamily;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use Normalizer;

/**
 * Which files a space takes, for one audience: an allow-list of families, extensions and content types, a largest
 * file, and whether the bytes must match the name. Built per space kind and audience by
 * {@see \DDD\Domain\CloudDrive\Services\DriveTypePoliciesService::buildTypePolicyForSpace()} from
 * `CloudDrive.typePolicies`. An empty list restricts nothing; a policy without any list restricts nothing at all
 * ({@see isRestricted()}) and the drive behaves as without a policy.
 *
 * Checked three times: the name and the announced type at the upload's reservation, the name at every write (uploads,
 * working-copy commits, copies, transfers), the bytes at the ingest (the type libmagic reads must fit the name's family;
 * executables are refused) — a refused content is BLOCKED and never released.
 */
class DriveTypePolicy extends ValueObject
{
    /** @var string[] Types libmagic names for programs; never taken by a restricted policy */
    public const array EXECUTABLE_CONTENT_TYPES = [
        'application/x-dosexec', 'application/x-executable', 'application/x-mach-binary', 'application/x-sharedlib',
        'application/x-pie-executable', 'application/x-msdownload', 'application/x-msdos-program', 'application/vnd.microsoft.portable-executable',
        'application/x-elf', 'application/java-archive', 'application/x-java-applet',
    ];

    /** @var array<string, string[]> A sniffed family that fits a name's family of another kind (containers, plain text formats) */
    public const array COMPATIBLE_SNIFFED_FAMILIES = [
        DriveFileFamily::FAMILY_ARCHIVE => [DriveFileFamily::FAMILY_SPREADSHEET, DriveFileFamily::FAMILY_DOCUMENT, DriveFileFamily::FAMILY_PRESENTATION],
        DriveFileFamily::FAMILY_TEXT => [DriveFileFamily::FAMILY_SPREADSHEET, DriveFileFamily::FAMILY_VECTOR],
        DriveFileFamily::FAMILY_DOCUMENT => [DriveFileFamily::FAMILY_SPREADSHEET, DriveFileFamily::FAMILY_PRESENTATION],
    ];

    /** @var string Characters a sanitised name never contains (path, shell and Windows-reserved characters) */
    public const string UNSAFE_NAME_CHARACTERS_PATTERN = '/[\/\\\\:*?"<>|]/u';

    /** @var string[] Families the space takes (DriveFileFamily::FAMILY_*); empty = every family */
    public array $allowedFamilies = [];

    /** @var string[] Extensions the space takes, lower case without the dot; empty = every extension */
    public array $allowedExtensions = [];

    /** @var string[] Content types the space takes (announced and stored); empty = every type */
    public array $allowedContentTypes = [];

    /** @var int|null The largest file; null = the space's own limit only */
    public ?int $maxFileSizeBytes = null;

    /** @var bool A restricted policy refuses bytes whose type does not fit the name's family */
    public bool $refusesContentMismatch = true;

    /** True when the policy restricts anything */
    public function isRestricted(): bool
    {
        return $this->allowedFamilies !== [] || $this->allowedExtensions !== [] || $this->allowedContentTypes !== [] || $this->maxFileSizeBytes !== null;
    }

    /** Why a file of this name is refused; null when it is taken */
    public function findFileNameRefusalReason(string $fileName): ?string
    {
        $extension = DriveFileFamily::findExtension($fileName);
        if ($this->allowedExtensions !== [] && !in_array($extension, $this->allowedExtensions, true)) {
            return $extension === ''
                ? 'a file without an extension is not taken here'
                : sprintf('files of type .%s are not taken here (allowed: %s)', $extension, implode(', ', $this->allowedExtensions));
        }
        if ($this->allowedFamilies !== []) {
            $family = DriveFileFamily::findFamily(null, $fileName);
            if (!in_array($family, $this->allowedFamilies, true)) {
                return sprintf('files of the kind %s are not taken here (allowed: %s)', $family, implode(', ', $this->allowedFamilies));
            }
        }
        return null;
    }

    /** Why an announced content type is refused; null when it is taken (or nothing was announced) */
    public function findAnnouncedContentTypeRefusalReason(?string $announcedContentType): ?string
    {
        $contentType = UntrustedContentHeaders::normalizeContentType($announcedContentType);
        if ($this->allowedContentTypes === [] || $contentType === '' || in_array($contentType, DriveFileFamily::UNSPECIFIC_CONTENT_TYPES, true)) {
            return null;
        }
        return in_array($contentType, $this->allowedContentTypes, true) ? null : sprintf('the type %s is not taken here', $contentType);
    }

    /** Why a size is refused; null when it is taken */
    public function findSizeRefusalReason(int $sizeBytes): ?string
    {
        return $this->maxFileSizeBytes !== null && $sizeBytes > $this->maxFileSizeBytes
            ? sprintf('the file is larger than the %d bytes taken here', $this->maxFileSizeBytes)
            : null;
    }

    /**
     * Why stored bytes are refused (the ingest): the name, the stored type, a program, a type that does not fit the
     * name. Null when they are taken; always null for a policy that restricts nothing.
     *
     * @param string|null $sniffedContentType the type libmagic reads from the bytes
     */
    public function findContentRefusalReason(?string $fileName, ?string $storedContentType, ?string $sniffedContentType): ?string
    {
        if (!$this->isRestricted()) {
            return null;
        }
        $sniffedType = UntrustedContentHeaders::normalizeContentType($sniffedContentType);
        if (in_array($sniffedType, static::EXECUTABLE_CONTENT_TYPES, true)) {
            return sprintf('the bytes are a program (%s)', $sniffedType);
        }
        if ($fileName !== null) {
            $fileNameRefusalReason = $this->findFileNameRefusalReason($fileName);
            if ($fileNameRefusalReason !== null) {
                return $fileNameRefusalReason;
            }
        }
        if ($this->allowedContentTypes !== []) {
            $storedType = UntrustedContentHeaders::normalizeContentType($storedContentType);
            if (!in_array($storedType, DriveFileFamily::UNSPECIFIC_CONTENT_TYPES, true) && !in_array($storedType, $this->allowedContentTypes, true)) {
                return sprintf('the type %s is not taken here', $storedType);
            }
        }
        if ($this->refusesContentMismatch && $fileName !== null && $sniffedType !== '') {
            $nameFamily = DriveFileFamily::findFamily(null, $fileName);
            $sniffedFamily = in_array($sniffedType, DriveFileFamily::UNSPECIFIC_CONTENT_TYPES, true) ? DriveFileFamily::FAMILY_OTHER : DriveFileFamily::findFamily($sniffedType);
            if ($nameFamily !== DriveFileFamily::FAMILY_OTHER && $sniffedFamily !== DriveFileFamily::FAMILY_OTHER && $nameFamily !== $sniffedFamily
                && !in_array($nameFamily, static::COMPATIBLE_SNIFFED_FAMILIES[$sniffedFamily] ?? [], true)
            ) {
                return sprintf('the bytes are %s, the name says %s', strtolower($sniffedFamily), strtolower($nameFamily));
            }
        }
        return null;
    }

    /**
     * A name safe to store and to show: no path, shell or Windows-reserved characters, no control or direction
     * characters, no leading or trailing dots and spaces, NFC, at most 255 characters with the extension kept;
     * `file` when nothing is left.
     */
    public static function sanitizeFileName(string $fileName): string
    {
        $sanitizedName = (string)preg_replace('/[\p{Cc}\p{Cf}]/u', '', $fileName);
        if (class_exists(Normalizer::class)) {
            $sanitizedName = (string)(Normalizer::normalize($sanitizedName, Normalizer::FORM_C) ?: $sanitizedName);
        }
        $sanitizedName = (string)preg_replace(static::UNSAFE_NAME_CHARACTERS_PATTERN, '_', $sanitizedName);
        $sanitizedName = (string)preg_replace('/\s+/u', ' ', $sanitizedName);
        $sanitizedName = trim($sanitizedName, " .\t");
        if ($sanitizedName === '') {
            return 'file';
        }
        if (mb_strlen($sanitizedName) > 255) {
            $extension = DriveFileFamily::findExtension($sanitizedName);
            $suffix = $extension !== '' && mb_strlen($extension) < 32 ? '.' . $extension : '';
            $sanitizedName = rtrim(mb_substr($sanitizedName, 0, 255 - mb_strlen($suffix)), ' .') . $suffix;
        }
        return $sanitizedName;
    }
}
