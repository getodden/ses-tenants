<?php

namespace Odden\SesTenants\Exceptions;

use RuntimeException;

/**
 * A sending-identity change that did not work. The message is safe to show the person who asked: the provider's own
 * text is reported to the log, never put in it.
 */
class SesTenantsException extends RuntimeException {}
