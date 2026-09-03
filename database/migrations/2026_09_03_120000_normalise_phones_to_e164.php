<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Puts a country code on every stored phone number.
 *
 * `phoneRegex()` accepted Egypt only and allowed a bare `01…`, so that is how
 * every number in the database is stored. It now requires E.164 with the
 * country code present, because the market is not settled and a number that
 * can be read as two different countries is not an identity.
 *
 * Without this migration the existing accounts become unreachable: sign-in
 * looks the user up by the exact string the app sends, and the app will now be
 * sending `+2010…` against a column holding `010…`.
 *
 * Three shapes are converted, and the rules are ordered so each one only ever
 * matches what it is for:
 *
 *   `+…`   already done — left exactly as it is, which is what makes this safe
 *          to run twice
 *   `0…`   an Egyptian national number: the trunk `0` is dropped and `+20`
 *          added, which is the actual rule, not a string concatenation
 *   `20…`  the country code without its plus
 *
 * Anything else is left alone and reported, rather than guessed at: a number
 * this cannot read is one somebody has to look at.
 */
return new class extends Migration
{
    /**
     * Every column holding a phone somebody signs in or is called on.
     * `countries.phone_code` is reference data and already carries its plus.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $columns = [
        ['users', 'phone'],
        ['laundries', 'phone'],
        ['addresses', 'contact_phone'],
    ];

    public function up(): void
    {
        $unreadable = [];

        foreach ($this->columns as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderBy('id')
                ->each(function ($row) use ($table, $column, &$unreadable) {
                    $current = trim((string) $row->{$column});
                    $converted = $this->toE164($current);

                    if ($converted === null) {
                        $unreadable[] = "$table#{$row->id}: $current";

                        return;
                    }

                    if ($converted !== $current) {
                        DB::table($table)->where('id', $row->id)->update([$column => $converted]);
                    }
                });
        }

        if ($unreadable !== []) {
            // Not an exception: failing the migration would leave the schema
            // half-done over a data problem an operator has to resolve. Said
            // out loud instead, so it is not silent either.
            echo PHP_EOL.'  Phone numbers left unchanged because they could not be read:'.PHP_EOL;
            foreach ($unreadable as $line) {
                echo '    '.$line.PHP_EOL;
            }
        }
    }

    /**
     * Back to the national format for the Egyptian numbers, which is every
     * number this migration created. A `+` on any other country is left in
     * place — there is no national format to return it to.
     */
    public function down(): void
    {
        foreach ($this->columns as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->where($column, 'LIKE', '+20%')
                ->update([$column => DB::raw("CONCAT('0', SUBSTRING(`$column`, 4))")]);
        }
    }

    /**
     * Null when the shape is not one this knows how to convert.
     */
    private function toE164(string $phone): ?string
    {
        // Spaces, dashes and brackets are how people type a number; they are
        // not part of it.
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($digits, '+')) {
            return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : null;
        }

        // National: drop the trunk prefix, add the country code.
        if (str_starts_with($digits, '0')) {
            $national = ltrim($digits, '0');

            return $national === '' ? null : '+20'.$national;
        }

        // The country code, plus missing.
        if (str_starts_with($digits, '20')) {
            return '+'.$digits;
        }

        return null;
    }
};
