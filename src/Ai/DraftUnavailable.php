<?php

namespace Goldnead\StatamicInbox\Ai;

use RuntimeException;

/** No draft: no API key, or the API did not answer usefully. */
class DraftUnavailable extends RuntimeException {}
