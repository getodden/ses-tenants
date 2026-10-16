<?php

namespace Odden\SesTenants\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A message that was refused before it reached SES, or that SES refused. Nothing was sent.
 */
class SendFailed extends TransportException {}
