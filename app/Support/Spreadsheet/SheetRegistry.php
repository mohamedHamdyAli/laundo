<?php

namespace App\Support\Spreadsheet;

/**
 * Every sheet, by key.
 *
 * Discovered from `app/Support/Spreadsheet/Sheets/`, one class per screen, so
 * adding a screen to the export is adding one file — there is no list here to
 * forget to update, and nothing else to edit that two people could both be
 * editing at once.
 */
class SheetRegistry
{
    /** @var array<string, class-string<Sheet>>|null */
    private ?array $sheets = null;

    public function find(string $key): ?Sheet
    {
        $class = $this->all()[$key] ?? null;

        return $class ? app($class) : null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * @return array<string, class-string<Sheet>>
     */
    public function all(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }

        $this->sheets = [];

        foreach (glob(__DIR__.'/Sheets/*.php') ?: [] as $file) {
            $class = __NAMESPACE__.'\\Sheets\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Sheet::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                /** @var Sheet $sheet */
                $sheet = app($class);
                $this->sheets[$sheet->key()] = $class;
            }
        }

        ksort($this->sheets);

        return $this->sheets;
    }
}
