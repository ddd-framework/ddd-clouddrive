<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Merges\DriveCommandOutput;
use DDD\Domain\CloudDrive\Entities\Merges\DriveTextMergeResult;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use Throwable;

/**
 * Three-way text merge with `git merge-file` (base, mine, theirs): non-overlapping changes merge, overlapping ones are
 * a conflict and nothing is merged. Binary content (a NUL byte, invalid UTF-8) and files above the size limit are
 * never merged. The runner merges in the sandbox the same way; this service serves merges on the server and tests.
 */
class DriveTextMergeService extends Service
{
    public const int MAX_MERGE_BYTES = 2 * 1024 * 1024;

    public const string GIT_BINARY = 'git';

    /** @var int Seconds one merge may take */
    public const int MERGE_TIMEOUT_SECONDS = 30;

    public function mergeTexts(string $baseText, string $mineText, string $theirsText): DriveTextMergeResult
    {
        $driveTextMergeResult = new DriveTextMergeResult();
        foreach ([$baseText, $mineText, $theirsText] as $text) {
            if (!$this->isMergeableText($text)) {
                $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_NOT_MERGEABLE;
                return $driveTextMergeResult;
            }
        }
        if ($mineText === $theirsText) {
            $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_MERGED;
            $driveTextMergeResult->mergedText = $mineText;
            return $driveTextMergeResult;
        }
        $temporaryDirectory = IsolatedProcess::createWorkingDirectory('drive-merge');
        try {
            file_put_contents($temporaryDirectory . '/mine', $mineText);
            file_put_contents($temporaryDirectory . '/base', $baseText);
            file_put_contents($temporaryDirectory . '/theirs', $theirsText);
            $driveCommandOutput = $this->runMergeCommand($temporaryDirectory);
            $mergedText = $driveCommandOutput->standardOutput;
            $exitCode = $driveCommandOutput->exitCode;
            if ($exitCode === 0) {
                $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_MERGED;
                $driveTextMergeResult->mergedText = $mergedText;
            } elseif ($exitCode > 0 && $exitCode < 124) {
                $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_CONFLICT;
                $driveTextMergeResult->conflictText = $mergedText;
                $driveTextMergeResult->conflictCount = $exitCode;
            } else {
                $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_NOT_MERGEABLE;
            }
            return $driveTextMergeResult;
        } finally {
            IsolatedProcess::removeWorkingDirectory($temporaryDirectory);
        }
    }

    /**
     * `git merge-file -p` on the three files of the working directory, as an isolated process (argument array, no
     * shell, stripped environment: no git configuration or credential of the server is read, timeout). The raw
     * standard output and the exit code (124 and above: timeout or signal).
     */
    protected function runMergeCommand(string $workingDirectory): DriveCommandOutput
    {
        $driveCommandOutput = new DriveCommandOutput();
        try {
            $process = IsolatedProcess::create(
                self::GIT_BINARY,
                ['merge-file', '-p', '-L', 'mine', '-L', 'base', '-L', 'theirs', $workingDirectory . '/mine', $workingDirectory . '/base', $workingDirectory . '/theirs'],
                $workingDirectory,
                static::MERGE_TIMEOUT_SECONDS,
                null
            );
            $process->run();
            $driveCommandOutput->standardOutput = $process->getOutput();
            $driveCommandOutput->exitCode = (int)$process->getExitCode();
        } catch (Throwable) {
            $driveCommandOutput->exitCode = 255;
        }
        return $driveCommandOutput;
    }

    public function isMergeableText(string $text): bool
    {
        return strlen($text) <= self::MAX_MERGE_BYTES && !str_contains($text, "\0") && mb_check_encoding($text, 'UTF-8');
    }
}
