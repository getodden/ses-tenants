<?php

namespace Odden\SesTenants\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An owner's standing for sending.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property Carbon|null $paused_at
 * @property string|null $pause_reason
 * @property string|null $pause_source rates, manual or ses
 * @property string|null $ses_policy
 * @property Carbon|null $warned_at
 * @property Carbon|null $rates_from
 */
class OwnerStatus extends Model
{
    protected $table = 'ses_owner_status';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['paused_at' => 'datetime', 'warned_at' => 'datetime', 'rates_from' => 'date'];
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<OwnerStatus>  $query
     * @return Builder<OwnerStatus>
     */
    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }
}
