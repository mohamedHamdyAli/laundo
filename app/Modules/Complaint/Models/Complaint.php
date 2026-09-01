<?php

namespace App\Modules\Complaint\Models;

use App\Modules\Complaint\Enums\ComplaintCategory;
use App\Modules\Complaint\Enums\ComplaintStatus;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use App\Trait\DashboardModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One complaint.
 *
 * Note what this model deliberately does NOT use: `BelongsToLaundry`. It carries
 * a `laundry_id` so operations can ask which laundry generates the most
 * complaints, but the owner decided complaints are the platform's to handle — a
 * laundry never reads them. Scoping the model would be the wrong tool here, and
 * the protection instead comes from `complaint.*` being granted to the super admin
 * only. If that permission is ever handed to a laundry role, this comment is the
 * warning that it opens every customer's complaint to them.
 *
 * @property int $id
 * @property string $reference
 * @property int $user_id
 * @property int|null $order_id
 * @property int|null $laundry_id
 * @property string $category
 * @property string $body
 * @property string $status
 * @property string|null $internal_note
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 * @property-read User|null $complainant
 * @property-read Order|null $order
 * @property-read Laundry|null $laundry
 * @property-read User|null $handler
 *
 * @method static Builder<static>|Complaint open()
 */
class Complaint extends Model
{
    // For PermissionGenerator, so `complaint.*` exists to gate the screen on.
    use DashboardModel;

    protected $fillable = [
        'reference', 'user_id', 'order_id', 'laundry_id',
        'category', 'body', 'status', 'internal_note',
        'handled_by', 'handled_at',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function complainant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Laundry, $this>
     */
    public function laundry(): BelongsTo
    {
        return $this->belongsTo(Laundry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    /**
     * «المرفقات» — the photographs filed with the complaint.
     *
     * @return HasMany<ComplaintAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class, 'complaint_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Still on somebody's desk.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ComplaintStatus::New->value,
            ComplaintStatus::InProgress->value,
        ]);
    }

    /**
     * The status as the enum, defaulting to New.
     *
     * A value that is no longer a case — a renamed status — must not throw on a
     * queue page; the row is real and the rest of it is still readable.
     */
    public function statusCase(): ComplaintStatus
    {
        return ComplaintStatus::tryFrom($this->status) ?? ComplaintStatus::New;
    }

    /** Same reasoning for the category. */
    public function categoryCase(): ComplaintCategory
    {
        return ComplaintCategory::tryFrom($this->category) ?? ComplaintCategory::Other;
    }

    /**
     * How long this has been waiting, in hours — null once it is finished.
     *
     * The figure the queue sorts on. A complaint open for three days is a
     * different problem from one open for three minutes, and a status column
     * cannot say which is which.
     */
    public function waitingHours(): ?float
    {
        if (! $this->statusCase()->isOpen() || $this->created_at === null) {
            return null;
        }

        return round($this->created_at->diffInMinutes(now()) / 60, 1);
    }
}
