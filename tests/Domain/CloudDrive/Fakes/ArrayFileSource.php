<?php

declare(strict_types=1);

namespace DDD\Tests\CloudDrive\Domain\CloudDrive\Fakes;

use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFactsSet;
use DDD\Domain\Common\Services\Files\FileSource;
use DDD\Infrastructure\Exceptions\NotFoundException;

/** A file source over facts and bytes the test sets; an id it does not hold is a file the caller may not read */
class ArrayFileSource implements FileSource
{
    /** @var FileFacts[] */
    public array $fileFactsById = [];

    /** @var string[] */
    public array $contentById = [];

    public function __construct(public string $sourceName)
    {
    }

    public function addFile(FileFacts $fileFacts, string $content): void
    {
        $this->fileFactsById[$fileFacts->fileReference->id] = $fileFacts;
        $this->contentById[$fileFacts->fileReference->id] = $content;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function findFilesFacts(array $ids): FileFactsSet
    {
        $fileFactsSet = new FileFactsSet();
        foreach ($ids as $id) {
            $fileFacts = $this->fileFactsById[$id] ?? null;
            if ($fileFacts !== null) {
                $fileFactsSet->add($fileFacts);
            }
        }
        return $fileFactsSet;
    }

    public function readFileContent(string $id, int $maxBytes): FileContent
    {
        if (!isset($this->fileFactsById[$id])) {
            throw new NotFoundException($this->sourceName . '/' . $id . ' was not found.');
        }
        return FileContent::fromFactsAndContent($this->fileFactsById[$id], $this->contentById[$id]);
    }
}
