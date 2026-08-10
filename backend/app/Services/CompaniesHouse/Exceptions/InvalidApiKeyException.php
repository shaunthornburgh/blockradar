<?php

namespace App\Services\CompaniesHouse\Exceptions;

/**
 * The API key is missing, wrong, or not authorised. Never worth retrying, and
 * an enrichment run should stop immediately rather than march through every
 * company collecting the same 401.
 */
class InvalidApiKeyException extends CompaniesHouseException {}
