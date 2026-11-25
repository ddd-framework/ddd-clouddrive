<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Entities\AI\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/**
 * A drive folder the owner browses beside the chat: the renderer shows a card and opens the folder in the canvas (a
 * small file manager: walk into folders, preview and download files, and — by the viewer's own rights on the space —
 * upload, rename, move and trash). The block names the folder's node; everything the canvas shows is read under the
 * viewer's rights, so a viewer without the read right sees "not available". Written by `showDriveFolder`.
 */
class DriveFolderConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'DriveFolder';

    public const string SUMMARY = 'A drive folder the owner browses in the canvas beside the chat (files with preview, download and, by their rights, upload, rename, move, trash).';

    public const string WHEN_TO_USE = 'The owner should look through a folder, pick files from it or tidy it themselves: call showDriveFolder; never list a folder as text when the owner wants to work in it.';

    public const string OWNER_CONTROL = 'one card that opens the folder in the canvas';

    public const bool READS_WIDGET_SELF_HYDRATION_KEY = true;

    /** @var int The drive space of the folder */
    public int $driveSpaceId = 0;

    /** @var int The folder's node (a space's root folder is a node too) */
    public int $driveNodeId = 0;

    /** @var string|null The folder's name; null for a space's root folder */
    public ?string $name = null;

    /** @var string|null The folder's path in its space (`/` for the root), for the card's second line */
    public ?string $path = null;

    /** @var string|null The space's title, for the card's second line */
    public ?string $spaceTitle = null;

    /** @var string|null The card's headline instead of the folder's name (at most 160 characters) */
    public ?string $title = null;

    /** @var string|null The host's deep link to the folder (`CloudDrive.settings.adminLinkTemplate`); null without a template */
    public ?string $adminLink = null;

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'driveSpaceId' => $this->driveSpaceId,
            'driveNodeId' => $this->driveNodeId,
            'name' => $this->name,
            'path' => $this->path,
            'spaceTitle' => $this->spaceTitle,
            'title' => $this->title,
            'adminLink' => $this->adminLink,
        ]);
    }
}
