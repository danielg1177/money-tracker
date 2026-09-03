<?php

namespace App\Models;

use App\Services\Closeout\CloseoutMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthHardClose extends Model
{
    protected $fillable = [
        'family_id',
        'year',
        'month',
        'closed_at',
        'closed_by_user_id',
        'closeout_mode',
        'settings_snapshot',
        'results_snapshot',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'year' => 'integer',
        'month' => 'integer',
        'settings_snapshot' => 'array',
        'results_snapshot' => 'array',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function resolvedCloseoutMode(): string
    {
        $snapshotMode = is_array($this->results_snapshot)
            ? ($this->results_snapshot['mode'] ?? null)
            : null;

        return CloseoutMode::normalize($this->closeout_mode ?? $snapshotMode);
    }
}
