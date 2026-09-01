<?php

namespace App\Modules\Complaint\Models;

use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photograph attached to a complaint.
 *
 * @property int $complaint_id
 * @property string $path
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 */
class ComplaintAttachment extends Model
{
    protected $fillable = ['complaint_id', 'path', 'uploaded_by'];

    /**
     * @return BelongsTo<Complaint, $this>
     */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'complaint_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return getImageassetUrl($this->path);
    }
}
