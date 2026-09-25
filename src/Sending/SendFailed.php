<?php

namespace Goldnead\StatamicInbox\Sending;

use RuntimeException;

/**
 * SMTP refused a reply. The message is already masked; the original
 * exception is left out on purpose because it may carry the password.
 */
class SendFailed extends RuntimeException {}
