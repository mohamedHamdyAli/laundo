<?php

namespace App\Modules\Payment\Models;

use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Not a table — a permission subject.
 *
 * The same device `Report` uses, and for the same reason: `PermissionGenerator`
 * walks `config/dashboard.php` and derives every slug in this project from a
 * model class name, so a screen that owns no table of its own would otherwise
 * need its permissions hand-inserted somewhere nobody would find them.
 *
 * The screen reads `orders`, `order_settlements` and `laundry_deductions` — all
 * of which have their own models — and adds up what they say per laundry. What
 * it needs is a name to be granted by, which is this.
 *
 * `laundry_revenue.view` gates reading the screen, searching it and exporting
 * it. The two write actions are deliberately **not** gated on
 * `laundry_revenue.update`: they take money off a laundry, so they sit behind
 * `setting.update` alongside the commission terms, which is the boundary
 * `MoneyBoundaryTest` guards — the party that pays does not hold the dial.
 */
class LaundryRevenue extends Model
{
    use DashboardModel;

    /**
     * Nothing is ever read or written. Named only so a stray query fails loudly
     * rather than inventing a `laundry_revenues` table.
     */
    protected $table = 'laundry_revenues_do_not_exist';
}
