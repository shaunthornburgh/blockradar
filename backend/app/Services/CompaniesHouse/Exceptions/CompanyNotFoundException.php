<?php

namespace App\Services\CompaniesHouse\Exceptions;

/**
 * Companies House has no record of the number. Permanent: CCOD carries
 * overseas and historic registrations that will never resolve, so the company
 * is marked and not retried.
 */
class CompanyNotFoundException extends CompaniesHouseException
{
    public function __construct(public readonly string $companyNumber)
    {
        parent::__construct("Companies House has no company numbered {$companyNumber}.");
    }
}
