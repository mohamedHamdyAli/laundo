<?php

namespace App\Support\Spreadsheet;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Read a sheet back in: add the rows with no id, change the rows with one.
 *
 * **Row by row, each on its own.** A good row is saved in its own transaction
 * and a bad one is reported with its sheet row number — the owner's choice over
 * all-or-nothing. Nothing is ever deleted: a row missing from the file is a row
 * the file does not mention, not one to remove.
 *
 * **An id is looked up through the screen's own scoped query**, so a row cannot
 * reach a record the screen would not show; an id that finds nothing is an
 * error, never a silent create.
 *
 * **Capped.** An import runs inside the request — this codebase keeps business
 * actions synchronous — so the rows it reads are bounded, and the report says
 * how many it did not.
 */
class Importer
{
    public const MAX_ROWS = 2000;

    public function __construct(private readonly RowValidator $validator) {}

    public function import(Sheet $sheet, string $path, bool $mayCreate, bool $mayUpdate): ImportReport
    {
        $report = new ImportReport;
        $known = array_flip($sheet->headers());

        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $worksheet) {
                $headers = null;
                $read = 0;

                foreach ($worksheet->getRowIterator() as $number => $row) {
                    $values = $row->toArray();

                    if ($headers === null) {
                        $headers = array_map(fn ($h) => trim((string) $h), $values);

                        continue;
                    }

                    $cells = $this->cells($headers, $values, $known);

                    if ($cells === null) {
                        continue; // an empty row
                    }

                    if (++$read > self::MAX_ROWS) {
                        $report->skipped++;

                        continue;
                    }

                    $this->row($sheet, (int) $number, $cells, $mayCreate, $mayUpdate, $report);
                }

                // The first worksheet is the data; anything after it is somebody's notes.
                break;
            }
        } finally {
            $reader->close();
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $cells
     */
    private function row(Sheet $sheet, int $number, array $cells, bool $mayCreate, bool $mayUpdate, ImportReport $report): void
    {
        $idCell = $cells['id'] ?? null;
        $id = ($idCell === null || $idCell === '') ? null : (int) $idCell;

        if ($id !== null && (! is_numeric($idCell) || $id < 1)) {
            $report->fail($number, [__('The id must be a number, or empty for a new row.')]);

            return;
        }

        $existing = null;

        if ($id !== null) {
            if (! $mayUpdate) {
                $report->fail($number, [__('You may add rows here but not change existing ones.')]);

                return;
            }

            $existing = $sheet->find($id);

            if (! $existing) {
                $report->fail($number, [__('No record with id :id.', ['id' => $id])]);

                return;
            }
        } elseif (! $mayCreate) {
            $report->fail($number, [__('You may change rows here but not add new ones.')]);

            return;
        }

        try {
            $validated = $this->validator->validate($sheet->request(), $sheet->input($cells, $existing), $id, $sheet->routeKey());

            DB::transaction(function () use ($sheet, $existing, $validated) {
                $existing ? $sheet->update($existing, $validated) : $sheet->create($validated);
            });

            $existing ? $report->updated++ : $report->created++;
        } catch (ValidationException $e) {
            $report->fail($number, collect($e->errors())->flatten()->unique()->values()->all());
        } catch (AuthorizationException) {
            $report->fail($number, [__('This action is unauthorized.')]);
        } catch (\Throwable $e) {
            // Reported, not thrown: one row a service refused must not undo the
            // rows before it, which is the whole point of row by row.
            Log::warning('[spreadsheet] import row failed', [
                'sheet' => $sheet->key(), 'row' => $number, 'error' => $e->getMessage(),
            ]);
            $report->fail($number, [__('This row could not be saved.')]);
        }
    }

    /**
     * One row keyed by header, only the headers the sheet knows, strings
     * trimmed and dates written the way a form would post them. Null when the
     * row holds nothing at all.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $known
     * @return array<string, mixed>|null
     */
    private function cells(array $headers, array $values, array $known): ?array
    {
        $cells = [];
        $any = false;

        foreach ($headers as $index => $header) {
            if ($header === '' || ! isset($known[$header])) {
                continue;
            }

            $value = $values[$index] ?? null;

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_string($value)) {
                $value = trim($value);
            } elseif (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
                // Excel stores every number as a float; 3.0 is the id 3.
                $value = (int) $value;
            }

            if ($value !== null && $value !== '') {
                $any = true;
            }

            $cells[$header] = $value === '' ? null : $value;
        }

        return $any ? $cells : null;
    }
}
