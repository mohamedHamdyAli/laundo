<?php

/*
|--------------------------------------------------------------------------
| Retrieval benchmark
|--------------------------------------------------------------------------
|
|   php .second-brain/bench/run.php                   # the frozen 20
|   php .second-brain/bench/run.php --set=unseen      # the held-out set
|   php .second-brain/bench/run.php --label=phase-3   # record it in results/
|   php .second-brain/bench/run.php --detail          # per-task breakdown
|   php .second-brain/bench/run.php --json
|
| Results are written to `.second-brain/bench/results/{label}.json` so a phase
| can be compared against the one before it rather than against a memory of it.
|
*/

require __DIR__.'/../autoload.php';
require __DIR__.'/Runner.php';

use Laundo\SecondBrain\Bench\Runner;
use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Support\Paths;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $parts = explode('=', substr($argument, 2), 2);
        $options[$parts[0]] = $parts[1] ?? true;
    }
}

$set = $options['set'] ?? 'known';
$tasksFile = __DIR__.'/tasks-'.basename((string) $set).'.php';

if (! is_file($tasksFile)) {
    fwrite(STDERR, "No such task set: {$tasksFile}\n");
    exit(1);
}

$paths = Paths::discover(__DIR__);
$brain = new Brain($paths);

if (! $brain->isBuilt()) {
    fwrite(STDERR, "The brain is not built. Run: php .second-brain/bin/brain.php index\n");
    exit(1);
}

$tasks = require $tasksFile;
$started = microtime(true);
$result = (new Runner($brain))->run($tasks);
$result['summary']['set'] = $set;
$result['summary']['ran_at'] = date('c');
$result['summary']['took_seconds'] = round(microtime(true) - $started, 1);
$result['summary']['manifest_built_at'] = $brain->manifest()['built_at'] ?? null;
$result['summary']['build_seconds'] = $brain->manifest()['built_in_seconds'] ?? null;

if (isset($options['label'])) {
    $directory = __DIR__.'/results';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents(
        $directory.'/'.preg_replace('/[^a-z0-9._-]/i', '-', (string) $options['label']).'.json',
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
    );
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

$s = $result['summary'];

if (isset($options['detail'])) {
    foreach ($result['rows'] as $i => $row) {
        printf("\n%2d. [%s] %s\n", $i + 1, $row['category'], $row['query']);
        printf("    rank=%-4s truth %d/%d (+expansion %d)  via=%s\n",
            $row['rank'] ?? 'MISS', $row['truth_found'], $row['truth_total'],
            $row['truth_found_with_expansion'], $row['answered_as']);
        foreach ($row['primary'] as $k => $path) {
            printf("      %d %-9s %s\n", $k + 1, $row['classes'][$path], $path);
        }
        foreach ($row['then_read'] as $path) {
            printf("        +then_read %s\n", $path);
        }
        foreach ($row['missed_after_expansion'] as $path) {
            printf("      -- STILL MISSED %s\n", $path);
        }
    }
    echo "\n";
}

printf("SET: %s   tasks=%d   built_at=%s\n", $s['set'], $s['tasks'], (string) $s['manifest_built_at']);
printf("  top1=%.0f%%  top3=%.0f%%  top5=%.0f%%\n", $s['top1'], $s['top3'], $s['top5']);
printf("  truth recall=%.1f%% (with then_read %.1f%%)   FN=%.1f%% (with then_read %.1f%%)\n",
    $s['recall'], $s['recall_with_expansion'], $s['false_negative_rate'], $s['false_negative_rate_with_expansion']);
printf("  returned=%d (neighbour=%d noise=%d)  FP=%.1f%%  avg files=%.2f  avg then_read=%.2f\n",
    $s['returned'], $s['neighbour'], $s['noise'], $s['false_positive_rate'],
    $s['avg_files_returned'], $s['avg_then_read']);
printf("  avg response=%d chars (~%d tok)   build=%ss\n",
    $s['avg_response_chars'], $s['avg_response_tokens'], (string) $s['build_seconds']);
printf("  context: naive ~%s tok -> brain ~%s tok (%.1f%% optimistic) / ~%s tok (%.1f%% honest)\n",
    number_format($s['naive_tokens']), number_format($s['brain_tokens']), $s['reduction_optimistic'],
    number_format($s['honest_tokens']), $s['reduction_honest']);

printf("\n  %-14s %3s %6s %6s %6s %12s %8s\n", 'category', 'n', 'top1', 'top3', 'top5', 'recall(+exp)', 'noise');
foreach ($s['by_category'] as $category => $v) {
    printf("  %-14s %3d %5.0f%% %5.0f%% %5.0f%% %5d/%-2d(%2d) %6.0f%%\n",
        $category, $v['n'],
        $v['top1'] / $v['n'] * 100, $v['top3'] / $v['n'] * 100, $v['top5'] / $v['n'] * 100,
        $v['truth_found'], $v['truth_total'], $v['truth_expanded'],
        $v['noise'] / max(1, $v['returned']) * 100);
}
