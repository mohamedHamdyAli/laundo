<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\LaundryContext;
use App\Support\Spreadsheet\Exporter;
use App\Support\Spreadsheet\Importer;
use App\Support\Spreadsheet\SheetRegistry;
use Illuminate\Http\Request;

/**
 * Excel export and import for the panel's list screens.
 *
 * One controller for every screen, because the only thing that differs is the
 * sheet: its columns, its query, its form. The permission is the screen's own —
 * `{model}.view` to export what the list shows, `{model}.create` to add rows and
 * `{model}.update` to change them — checked here rather than in the route,
 * where one route serves every model.
 */
class SpreadsheetController extends Controller
{
    public function __construct(
        private readonly SheetRegistry $sheets,
        private readonly Exporter $exporter,
        private readonly Importer $importer,
    ) {}

    public function export(Request $request, string $sheet)
    {
        $definition = $this->sheets->find($sheet) ?? abort(404);

        abort_unless(canDo($definition->permission().'.view'), 403);

        // Exactly what the list shows: the same scoped query, narrowed by the
        // same search term and filters the screen is sending.
        $query = $definition->filter($definition->query(), $request->query());

        return $this->exporter->download($definition, $query);
    }

    /**
     * The headers and nothing else — the file to fill in for a first import.
     */
    public function template(string $sheet)
    {
        $definition = $this->sheets->find($sheet) ?? abort(404);

        abort_unless($definition->importable(), 404);
        abort_unless(canDo($definition->createPermission()) || canDo($definition->permission().'.update'), 403);

        return $this->exporter->download($definition, $definition->query(), headersOnly: true);
    }

    public function import(Request $request, string $sheet)
    {
        $definition = $this->sheets->find($sheet) ?? abort(404);

        abort_unless($definition->importable(), 404);

        $mayCreate = canDo($definition->createPermission());
        $mayUpdate = canDo($definition->permission().'.update');

        abort_unless($mayCreate || $mayUpdate, 403);

        // The importable screens are the platform's own catalogue and people.
        // Somebody inside a laundry never imports into them, whatever they hold.
        abort_if(LaundryContext::currentId() !== null, 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);

        $report = $this->importer->import($definition, $request->file('file')->getRealPath(), $mayCreate, $mayUpdate);

        return back()->with('spreadsheet_report', $report->toArray() + ['sheet' => $definition->key()]);
    }
}
