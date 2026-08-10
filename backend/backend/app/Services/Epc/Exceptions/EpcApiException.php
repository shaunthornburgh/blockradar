<?php

namespace App\Services\Epc\Exceptions;

use RuntimeException;

/** Anything that went wrong talking to the EPC developer API. */
class EpcApiException extends RuntimeException {}
