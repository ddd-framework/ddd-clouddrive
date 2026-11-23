<?php

declare(strict_types=1);

namespace DDD\Domain\CloudDrive\Services;

use DDD\Domain\CloudDrive\Entities\Merges\DriveCommandOutput;
use DDD\Domain\CloudDrive\Entities\Merges\DriveTextMergeResult;
use DDD\Infrastructure\Services\Service;

/**
 * Three-way text merge with `git merge-file` (base, mine, theirs): non-overlapping changes merge, overlapping ones are
 * a conflict and nothing is merged. Binary content (a NUL byte, invalid UTF-8) and files above the size limit are
 * never merged. The runner merges in the sandbox the same way; this service serves merges on the server and tests.
 */
class DriveTextMergeService extends Service
{
    public const int MAX_MERGE_BYTES = 2 * 1024 * 1024;

    public const string GIT_BINARY = 'git';

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
        $temporaryDirectory = sys_get_temp_dir() . '/drive-merge-' . bin2hex(random_bytes(6));
        mkdir($temporaryDirectory, 0700);
        try {
            file_put_contents($temporaryDirectory . '/mine', $mineText);
            file_put_contents($temporaryDirectory . '/base', $baseText);
            file_put_contents($temporaryDirectory . '/theirs', $theirsText);
            $command = escapeshellcmd(self::GIT_BINARY) . ' merge-file -p -L mine -L base -L theirs '
                . escapeshellarg($temporaryDirectory . '/mine') . ' '
                . escapeshellarg($temporaryDirectory . '/base') . ' '
                . escapeshellarg($temporaryDirectory . '/theirs');
            $driveCommandOutput = $this->runMergeCommand($command);
            $mergedText = $driveCommandOutput->standardOutput;
            $exitCode = $driveCommandOutput->exitCode;
            if ($exitCode === 0) {
                $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_MERGED;
                $driveTextMergeResult->mergedText = $mergedText;
            } elseif ($exitCode > 0 && $exitCode < 128) {
                $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_CONFLICT;
                $driveTextMergeResult->conflictText = $mergedText;
                $driveTextMergeResult->conflictCount = $exitCode;
            } else {
                $driveTextMergeResult->outcome = DriveTextMergeResult::OUTCOME_NOT_MERGEABLE;
            }
            return $driveTextMergeResult;
        } finally {
            foreach (['mine', 'base', 'theirs'] as $fileName) {
                @unlink($temporaryDirectory . '/' . $fileName);
            }
            @rmdir($temporaryDirectory);
        }
    }

    /**
     * The command's raw standard output and its exit code (`exec()` would strip trailing whitespace of every line).
     */
    protected function runMergeCommand(string $command): DriveCommandOutput
    {
        $driveCommandOutput = new DriveCommandOutput();
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $driveCommandOutput->exitCode = 255;
            return $driveCommandOutput;
        }
        $driveCommandOutput->standardOutput = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $driveCommandOutput->exitCode = proc_close($process);
        return $driveCommandOutput;
    }

    public function isMergeableText(string $text): bool
    {
        return strlen($text) <= self::MAX_MERGE_BYTES && !str_contains($text, "\0") && mb_check_encoding($text, 'UTF-8');
    }
}
