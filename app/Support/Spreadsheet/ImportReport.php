<?php

namespace App\Support\Spreadsheet;

/**
 * What an import did, row by row.
 *
 * Good rows are saved and bad ones reported — the owner's choice — so the
 * report is the whole of what the operator has to act on: how many were added,
 * how many changed, and for each refused row its number in the sheet and what
 * was wrong, in the form's own words.
 */
final class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    /** @var array<int, array{row: int, messages: array<int, string>}> */
    public array $failed = [];

    /** Rows past the cap, not read at all. */
    public int $skipped = 0;

    /**
     * @param  array<int, string>  $messages
     */
    public function fail(int $row, array $messages): void
    {
        $this->failed[] = ['row' => $row, 'messages' => array_values($messages)];
    }

    /**
     * @return array{created: int, updated: int, failed: array<int, array{row: int, messages: array<int, string>}>, skipped: int}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
        ];
    }
}
