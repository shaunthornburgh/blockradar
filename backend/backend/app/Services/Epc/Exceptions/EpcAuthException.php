<?php

namespace App\Services\Epc\Exceptions;

/**
 * The bearer token is missing or rejected. Never worth retrying.
 */
class EpcAuthException extends EpcApiException {}
