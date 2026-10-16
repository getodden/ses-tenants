<?php

namespace Odden\SesTenants\Transport;

use Aws\SesV2\SesV2Client;
use Illuminate\Mail\Transport\SesV2Transport;
use Symfony\Component\Mailer\SentMessage;

/**
 * Laravel's SES v2 transport with the owner's SES tenant fixed in the API call, instead of read from an
 * X-SES-TENANT-NAME header: SES leaves that header in the delivered message, which would show the owner's
 * internal name to every recipient.
 */
final class TenantSesTransport extends SesV2Transport
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(SesV2Client $ses, array $options, private readonly string $sesTenantName)
    {
        parent::__construct($ses, $options);
    }

    protected function tenantName(SentMessage $message): string
    {
        return $this->sesTenantName;
    }
}
