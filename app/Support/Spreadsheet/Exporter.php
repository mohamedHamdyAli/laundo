<?php

namespace App\Support\Spreadsheet;

use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A sheet, as a real .xlsx.
 *
 * Written row by row to a temporary file and sent from there, so an export of
 * forty thousand orders never holds forty thousand rows in memory. The query is
 * walked by id in chunks for the same reason.
 *
 * Every cell is written as a value, never as a formula: a customer name that
 * begins with `=` is text in this file, not something Excel runs when the
 * operator opens it. That has to be done here, by hand — OpenSpout's own
 * `Row::fromValues()` turns any string starting with `=` into a live formula,
 * and names, notes, complaints and a public driver application all reach these
 * sheets as typed. A `=WEBSERVICE(…)` in an applicant's name would otherwise
 * send the other rows' phone numbers to whoever wrote it.
 */
class Exporter
{
    private const CHUNK = 500;

    /**
     * @param  bool  $headersOnly  a blank template: the headers and nothing else
     */
    public function download(Sheet $sheet, Builder $query, bool $headersOnly = false): BinaryFileResponse
    {
        $path = $this->write($sheet, $query, $headersOnly);

        $name = $sheet->title().($headersOnly ? '-template' : '').'-'.now(displayTimezone())->format('Y-m-d').'.xlsx';

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * The file, on disk. Separate so a test can read what was written.
     */
    public function write(Sheet $sheet, Builder $query, bool $headersOnly = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        $languages = $sheet->languages();

        $writer = new Writer;
        $writer->openToFile($path);

        // Right to left when the panel is, so an Arabic sheet reads the way the
        // screen it came from does.
        if (panelIsRtl()) {
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setRightToLeft(true));
        }

        $writer->addRow($this->row($sheet->headers(), (new Style)->setFontBold()));

        if (! $headersOnly) {
            // Built once: a sheet's columns can check permissions and hold
            // lookups, and neither should run again for every row.
            $columns = $sheet->columns();

            $query->lazyById(self::CHUNK, $query->getModel()->getQualifiedKeyName(), $query->getModel()->getKeyName())
                ->each(function ($row) use ($writer, $columns, $languages) {
                    $cells = [];

                    foreach ($columns as $column) {
                        array_push($cells, ...$column->cells($row, $languages));
                    }

                    $writer->addRow($this->row($cells));
                });
        }

        $writer->close();

        return $path;
    }

    /**
     * A row of plain values: every string a text cell, whatever it starts with.
     *
     * @param  array<int, mixed>  $values
     */
    private function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(
            fn ($value) => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value),
            array_values($values)
        ), $style);
    }
}
