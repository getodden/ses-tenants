<?php

namespace Odden\SesTenants\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A domain an owner sends from. It can send only while it is verified, which means SES confirmed the DNS records (DKIM
 * and the custom MAIL FROM domain) the owner added.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $domain
 * @property string $status
 * @property list<array{type: string, name: string, value: string, purpose: string, required: bool}>|null $records
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $first_sent_on
 * @property int $warmup_start_step
 * @property Carbon|null $warmup_skipped_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 */
class SendingDomain extends Model
{
    public const string PENDING = 'pending';

    public const string VERIFIED = 'verified';

    public const string FAILED = 'failed';

    protected $table = 'ses_sending_domains';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['records' => 'array', 'verified_at' => 'datetime', 'last_checked_at' => 'datetime', 'first_sent_on' => 'date', 'warmup_skipped_at' => 'datetime'];
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /**
     * The subdomain SES uses for bounces (the custom MAIL FROM domain).
     */
    public function mailFromDomain(): string
    {
        return config('ses-tenants.mail_from_label', 'bounce').'.'.$this->domain;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<SendingDomain>  $query
     * @return Builder<SendingDomain>
     */
    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }
}
