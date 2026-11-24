<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Public\CloudDrive;

use DDD\Domain\CloudDrive\Services\DriveContentStreamsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Public\CloudDrive\Dtos\DriveContentStreamRequestDto;
use DDD\Presentation\Base\Dtos\ObjectStorageStreamResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use Symfony\Component\HttpFoundation\Request;

/**
 * The content URLs of the drive (downloads, previews, thumbnails): every byte is streamed from the store through the
 * backend. The URL carries a signed, short-lived token instead of a session, so it works in `<img>`, `<video>` and a
 * plain link; the rights and the scan state are checked again on every fetch.
 *
 * Mount it on a path WITHOUT authentication (the token is the authorisation) and point
 * `CloudDrive.settings.transfer.contentUrlPrefix` at the full path it answers on (default `/api/public/drive/content`),
 * e.g. in the application's public controllers (prefixed `/api/public` by the host's routing):
 * `#[Route('/drive/content')] class DriveContentController extends HttpController { use CloudDriveContentControllerTrait; }`.
 */
trait CloudDriveContentControllerTrait
{
    /**
     * The content of a grant (GET and HEAD; one `Range` answers 206).
     *
     * @throws ForbiddenException an invalid or expired link, revoked rights, a file no longer released
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws InternalErrorException
     */
    #[Get('/{contentToken}', requirements: ['contentToken' => '[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+'])]
    #[Summary('DriveContent Stream')]
    public function streamDriveContent(
        DriveContentStreamRequestDto $requestDto,
        Request $request,
        DriveContentStreamsService $driveContentStreamsService
    ): ObjectStorageStreamResponseDto {
        return $driveContentStreamsService->createContentStreamResponse($requestDto->contentToken, $request);
    }
}
