<?php

namespace Odden\SesTenants\Identities;

/**
 * Where an SES tenant stands: whether it may send, who stopped it if not, and why. SES keeps two statuses, one it manages
 * (set by its reputation policy or by AWS Trust and Safety) and one the application manages (a pause); the aggregate is
 * what decides.
 */
final class TenantSendingStatus
{
    public function __construct(
        public readonly string $aggregate,
        public readonly string $awsStatus,
        public readonly ?string $awsCause,
        public readonly string $customerStatus,
        public readonly ?string $customerCause,
        public readonly ?string $impact,
    ) {}

    /**
     * Whether the tenant cannot send right now, whoever stopped it.
     */
    public function blocked(): bool
    {
        return strtoupper($this->aggregate) === 'DISABLED';
    }

    /**
     * Whether SES itself stopped it (a finding under the reputation policy, or Trust and Safety). The application cannot lift that.
     */
    public function awsEnforced(): bool
    {
        return strtoupper($this->awsStatus) === 'DISABLED';
    }

    /**
     * Why it is stopped, in words for a person.
     */
    public function reason(): string
    {
        if ($this->awsEnforced()) {
            return 'Amazon SES stopped sending because of reputation findings'.($this->awsCause !== null && $this->awsCause !== '' ? " ({$this->awsCause})" : '').'.';
        }

        return 'Sending was paused at Amazon SES'.($this->customerCause !== null && $this->customerCause !== '' ? " ({$this->customerCause})" : '').'.';
    }
}
