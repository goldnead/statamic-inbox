<?php

namespace Goldnead\StatamicInbox\Fetching;

use RuntimeException;

/**
 * A fetch that failed, with the mailbox password already masked out of the
 * message. Deliberately without the original exception as `previous`: that
 * one still carries the clear text and would reach the log through report().
 */
class FetchFailed extends RuntimeException {}
