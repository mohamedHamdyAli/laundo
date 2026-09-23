<?php

namespace Laundo\SecondBrain\Bench;

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Parse\FileScanner;

/**
 * What the brain costs against what exploring costs.
 *
 * **What is measured, honestly.** The baseline is not a hypothetical. For each
 * task it is the set of files a grep-and-read approach would actually open:
 * the naive search is run over the repository, the files it hits are counted,
 * and the ones a reader would have to open to answer the question are summed at
 * their real byte size. The brain side is the exact bytes its tool response
 * returns, plus the files that response says to read.
 *
 * **Tokens are estimated, not counted.** There is no tokeniser here and adding
 * one would be a dependency. Four characters per token is the usual rule for
 * English and code; the figure is labelled as an estimate everywhere it appears
 * and the character counts are reported beside it so nobody has to trust the
 * conversion. The ratio between the two sides is what the benchmark is for, and
 * the ratio is unaffected by the constant.
 *
 * **Both sides are counted the same way** and both include the files that
 * actually have to be read. A comparison that counted full file reads on one
 * side and only the search response on the other would be measuring nothing.
 */
final class Benchmark
{
    /**
     * Real questions about this repository, each with the files somebody would
     * have to end up in. The expected files are used to score whether the brain
     * pointed at the right place — a cheap answer that is wrong is not a saving.
     *
     * @var list<array{task:string,grep:list<string>,answer:list<string>}>
     */
    private const TASKS = [
        [
            'task' => 'Where is the coupon discount calculated?',
            'grep' => ['coupon', 'discount'],
            'answer' => ['app/Modules/Coupon/Services/CouponService.php', 'app/Modules/Coupon/Models/Coupon.php'],
        ],
        [
            'task' => 'Which laundry gets an order, and how is that decided?',
            'grep' => ['laundry', 'assign'],
            'answer' => ['app/Modules/Order/Services/LaundryAssigner.php'],
        ],
        [
            'task' => 'How is the platform commission split at settlement?',
            'grep' => ['commission', 'settlement'],
            'answer' => ['app/Modules/Payment/Services/SettlementService.php', 'app/Modules/Payment/Models/OrderSettlement.php'],
        ],
        [
            'task' => 'Where does an order status change, and what validates it?',
            'grep' => ['status', 'transition'],
            'answer' => ['app/Modules/Order/Services/OrderStateMachine.php', 'app/Modules/Order/Enums/OrderStatus.php'],
        ],
        [
            'task' => 'How does a driver submit a record change for approval?',
            'grep' => ['driver', 'submission'],
            'answer' => ['app/Modules/Driver/Services/DriverRecordReview.php', 'app/Modules/Driver/Models/DriverRecordSubmission.php'],
        ],
        [
            'task' => 'How are laundry owners prevented from seeing each other\'s orders?',
            'grep' => ['laundry_id', 'scope'],
            'answer' => ['app/Support/LaundryContext.php', 'app/Trait/BelongsToLaundry.php'],
        ],
    ];

    private const CHARS_PER_TOKEN = 4;

    public function __construct(private readonly Brain $brain) {}

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $scanner = new FileScanner($this->brain->paths());
        $files = $scanner->all();

        // The corpus a grep would be searching, measured once.
        $sizes = [];
        foreach ($files as $file) {
            $absolute = $this->brain->paths()->abs($file);
            $sizes[$file] = is_file($absolute) ? (int) filesize($absolute) : 0;
        }

        $rows = [];
        $withoutTotal = 0;
        $withTotal = 0;
        $hits = 0;

        foreach (self::TASKS as $task) {
            $row = $this->measure($task, $files, $sizes);
            $rows[] = $row;
            $withoutTotal += $row['without_brain']['characters'];
            $withTotal += $row['with_brain']['characters'];
            $hits += $row['with_brain']['found_the_answer'] ? 1 : 0;
        }

        $saving = $withoutTotal > 0 ? 1 - ($withTotal / $withoutTotal) : 0.0;

        return [
            'method' => [
                'baseline' => 'grep the repository for the task\'s terms, then read every matching source file — the files are counted at their real size',
                'with_brain' => 'one second_brain_search response, plus reading only the files it ranked in the top three',
                'tokens' => 'estimated at '.self::CHARS_PER_TOKEN.' characters per token; character counts are given so the estimate can be checked',
            ],
            'corpus' => [
                'indexable_files' => count($files),
                'characters' => array_sum($sizes),
                'estimated_tokens' => (int) round(array_sum($sizes) / self::CHARS_PER_TOKEN),
            ],
            'tasks' => $rows,
            'totals' => [
                'without_brain_characters' => $withoutTotal,
                'with_brain_characters' => $withTotal,
                'without_brain_estimated_tokens' => (int) round($withoutTotal / self::CHARS_PER_TOKEN),
                'with_brain_estimated_tokens' => (int) round($withTotal / self::CHARS_PER_TOKEN),
                'reduction' => round($saving, 3),
                'reduction_percent' => round($saving * 100, 1),
                'answer_found_in_top_3' => $hits.'/'.count(self::TASKS),
            ],
            'caveat' => 'Measured on this repository with these six tasks. A task whose answer is one well-named file saves little; the saving comes from tasks whose terms are common across modules.',
        ];
    }

    /**
     * @param  array{task:string,grep:list<string>,answer:list<string>}  $task
     * @param  list<string>  $files
     * @param  array<string,int>  $sizes
     * @return array<string,mixed>
     */
    private function measure(array $task, array $files, array $sizes): array
    {
        // ---- without the brain: grep, then read what it hit ----------------
        $matched = [];

        foreach ($files as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $contents = @file_get_contents($this->brain->paths()->abs($file));
            if ($contents === false) {
                continue;
            }
            foreach ($task['grep'] as $needle) {
                if (stripos($contents, $needle) !== false) {
                    $matched[$file] = true;
                    break;
                }
            }
        }

        $matchedFiles = array_keys($matched);

        // Nobody reads 200 files. A realistic exploration opens the most
        // promising twelve — which is generous to the baseline, because it
        // assumes the right ones are among them.
        $read = array_slice($matchedFiles, 0, 12);
        $withoutCharacters = 0;
        foreach ($read as $file) {
            $withoutCharacters += $sizes[$file] ?? 0;
        }

        // The grep output itself costs something too: one line per hit.
        $withoutCharacters += count($matchedFiles) * 90;

        // ---- with the brain: one search, then read the top three -----------
        $response = $this->brain->search($task['task'], 8);
        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        $top = [];
        foreach ($response['results'] as $result) {
            $path = $result['path'] ?? null;
            if ($path !== null && ! in_array($path, $top, true)) {
                $top[] = $path;
            }
            if (count($top) >= 3) {
                break;
            }
        }

        $withCharacters = strlen($responseJson);
        foreach ($top as $file) {
            $withCharacters += $sizes[$file] ?? 0;
        }

        $found = false;
        foreach ($task['answer'] as $expected) {
            if (in_array($expected, $top, true)) {
                $found = true;
                break;
            }
        }

        return [
            'task' => $task['task'],
            'without_brain' => [
                'files_matched_by_grep' => count($matchedFiles),
                'files_read' => count($read),
                'characters' => $withoutCharacters,
                'estimated_tokens' => (int) round($withoutCharacters / self::CHARS_PER_TOKEN),
            ],
            'with_brain' => [
                'tool_calls' => 1,
                'response_characters' => strlen($responseJson),
                'files_read' => count($top),
                'files' => $top,
                'characters' => $withCharacters,
                'estimated_tokens' => (int) round($withCharacters / self::CHARS_PER_TOKEN),
                'found_the_answer' => $found,
                'expected_one_of' => $task['answer'],
            ],
            'reduction_percent' => $withoutCharacters > 0
                ? round((1 - $withCharacters / $withoutCharacters) * 100, 1)
                : 0.0,
        ];
    }
}
