<?php

namespace App\Modules\Rating\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Enums\RatingTag;
use App\Modules\Order\Models\OrderRating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What customers thought, made into a screen.
 *
 * Every report before this one measured speed and disputes. None of them could
 * say whether the customer was happy, which is the figure a laundry is actually
 * judged on.
 *
 * Tenant-scoped through `OrderRating`'s BelongsToLaundry, so a laundry owner
 * reads its own verdicts and nobody else's — unlike the driver and operations
 * reports, this one is safe to give them.
 */
class RatingController extends Controller
{
    public function index(Request $request)
    {
        $band = (string) $request->get('band', 'all');

        $ratings = $this->listing($band)->paginate(15);

        $view = view('admin.rating.index', [
            'ratings' => $ratings,
            'band' => $band,
            'summary' => $this->summary(),
            'tagCounts' => $this->tagCounts(),
        ]);

        return $request->ajax() ? response($view) : $view;
    }

    public function search(Request $request)
    {
        if (! $request->ajax()) {
            return response()->json([], 400);
        }

        $term = (string) $request->get('query');

        $ratings = $this->listing((string) $request->get('band', 'all'))
            ->when($term !== '', function (Builder $q) use ($term) {
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('comment', 'like', "%{$term}%")
                        ->orWhereHas('order', fn ($o) => $o->where('code', 'like', "%{$term}%"))
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"));
                });
            })
            ->paginate(15);

        return response()->json([
            'table' => view('admin.rating.partials._rating_table_body', ['ratings' => $ratings])->render(),
            'pagination' => $ratings->withQueryString()->links()->toHtml(),
        ]);
    }

    /**
     * @return Builder<OrderRating>
     */
    private function listing(string $band): Builder
    {
        return OrderRating::with(['customer:id,name,phone', 'order:id,code', 'laundry:id,name'])
            ->when($band === 'poor', fn (Builder $q) => $q->poor())
            ->when($band === 'good', fn (Builder $q) => $q->where('overall', '>=', 4))
            // A comment is what somebody can act on; a bare score is not.
            ->when($band === 'commented', fn (Builder $q) => $q->whereNotNull('comment'))
            ->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $base = OrderRating::query();

        $total = (clone $base)->count();

        return [
            'total' => $total,
            // Rounded to one place. Two decimals on a five-point scale is a
            // precision the data does not have.
            'average' => $total > 0 ? round((float) (clone $base)->avg('overall'), 1) : null,
            'poor' => (clone $base)->poor()->count(),
            'commented' => (clone $base)->whereNotNull('comment')->count(),
            // Per aspect, over the rows that actually filled it in — a skipped
            // aspect must not count as a zero.
            'aspects' => [
                'service_quality' => $this->aspectAverage('service_quality'),
                'delivery' => $this->aspectAverage('delivery'),
                'timing' => $this->aspectAverage('timing'),
            ],
        ];
    }

    private function aspectAverage(string $column): ?float
    {
        $average = OrderRating::whereNotNull($column)->avg($column);

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * How often each chip was picked.
     *
     * The whole reason the tags are a closed enum: free text could not be counted,
     * and «ما الذي أعجبك؟» is only useful as a tally.
     *
     * @return array<int, array{tag: RatingTag, count: int}>
     */
    private function tagCounts(): array
    {
        $counts = array_fill_keys(RatingTag::values(), 0);

        // Read in PHP rather than with a JSON query, because the column is a
        // short list and MySQL/SQLite disagree enough on JSON functions that a
        // clever query here would pass the tests and fail in production.
        foreach (OrderRating::whereNotNull('tags')->pluck('tags') as $tags) {
            foreach ((array) $tags as $tag) {
                if (array_key_exists($tag, $counts)) {
                    $counts[$tag]++;
                }
            }
        }

        arsort($counts);

        // array_map over two arrays already returns a list, so array_values
        // around it would do nothing.
        return array_map(
            fn ($value, $key) => ['tag' => RatingTag::from($key), 'count' => $value],
            $counts,
            array_keys($counts)
        );
    }
}
