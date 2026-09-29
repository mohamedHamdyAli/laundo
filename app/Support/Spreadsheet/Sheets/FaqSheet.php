<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\Faq\Models\Faq;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * The FAQ — export only.
 */
class FaqSheet extends Sheet
{
    public function key(): string
    {
        return 'faq';
    }

    public function title(): string
    {
        return 'faqs';
    }

    public function query(): Builder
    {
        return Faq::query();
    }

    public function searchColumns(): array
    {
        return ['question', 'answer', 'audience', 'order'];
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::make('question')->translatable(),
            Column::make('answer')->translatable(),
            Column::make('audience'),
            Column::make('order'),
            Column::make('status'),
        ];
    }
}
