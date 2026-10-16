<?php

namespace Odden\SesTenants\Identities;

/**
 * Where a sending identity stands at SES.
 */
final readonly class IdentityState
{
    /**
     * @param  list<string>  $dkimTokens  The three selectors the DKIM CNAME records are built from.
     */
    public function __construct(
        public array $dkimTokens,
        public string $dkimStatus,
        public string $mailFromStatus,
        public bool $verifiedForSending,
    ) {}

    /**
     * Verified only when SES says DKIM passes, the custom MAIL FROM domain passes, and it will send.
     */
    public function isVerified(): bool
    {
        return $this->verifiedForSending && $this->dkimStatus === 'SUCCESS' && $this->mailFromStatus === 'SUCCESS';
    }

    /**
     * A short reason for people while it is not verified yet.
     */
    public function problem(): string
    {
        if ($this->dkimStatus !== 'SUCCESS') {
            return $this->dkimStatus === 'FAILED' ? 'The DKIM records were not found or do not match.' : 'Waiting for the DKIM records.';
        }

        if ($this->mailFromStatus !== 'SUCCESS') {
            return in_array($this->mailFromStatus, ['FAILED', 'TEMPORARY_FAILURE'], true) ? 'The bounce-domain records were not found or do not match.' : 'Waiting for the bounce-domain records.';
        }

        return 'Waiting for the provider to finish verifying the domain.';
    }
}
