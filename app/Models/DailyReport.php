<?php

namespace App\Models;

use Database\Factories\DailyReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class DailyReport extends Model
{
    /** @use HasFactory<DailyReportFactory> */
    use HasFactory;

    public const STATUS_PENDING_INPUT = 'pending_input';

    public const STATUS_GENERATING_DRAFT = 'generating_draft';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_FILLING = 'filling';

    public const STATUS_AWAITING_FINAL_REVIEW = 'awaiting_final_review';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'report_date',
        'sender_phone',
        'activity_notes',
        'activity_description',
        'lessons_learned',
        'challenges',
        'status',
        'waha_last_message_id',
        'screenshot_path',
        'error_message',
        'approved_at',
        'filled_at',
        'submitted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'approved_at' => 'datetime',
            'filled_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AutomationLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class);
    }

    /**
     * @return BelongsTo<InternUser, $this>
     */
    public function internUser(): BelongsTo
    {
        return $this->belongsTo(InternUser::class, 'sender_phone', 'phone_number');
    }

    /**
     * Scope query to find report for specific date and sender.
     *
     * @param  Builder<DailyReport>  $query
     */
    public function scopeForDateAndPhone(Builder $query, Carbon|string $date, string $phone): Builder
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query->where('report_date', $dateString)->where('sender_phone', $phone);
    }
}
