<?php

namespace Tests\Fixtures;

/**
 * Companies House profile payloads, shaped like the real API responses.
 */
class CompaniesHouseFixtures
{
    /**
     * A healthy, up-to-date trading company.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function profile(array $overrides = []): array
    {
        return array_replace_recursive([
            'company_number' => '04512378',
            'company_name' => 'NORTHERN BLOCKS LIMITED',
            'company_status' => 'active',
            'type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'date_of_creation' => '2002-08-14',
            'sic_codes' => ['68209', '68100'],
            'has_charges' => false,
            'has_insolvency_history' => false,
            'registered_office_address' => [
                'address_line_1' => '1 King Street',
                'locality' => 'Manchester',
                'postal_code' => 'M2 6AG',
                'country' => 'England',
            ],
            'accounts' => [
                'last_accounts' => ['made_up_to' => '2025-08-31', 'type' => 'small'],
                'next_accounts' => ['due_on' => '2027-05-31', 'overdue' => false],
                'next_due' => '2027-05-31',
                'overdue' => false,
            ],
            'confirmation_statement' => [
                'last_made_up_to' => '2026-01-10',
                'next_due' => '2027-01-24',
                'overdue' => false,
            ],
        ], $overrides);
    }

    /**
     * Overdue on everything, secured, and long-established — the strongest
     * motivation profile the scorer can see.
     *
     * @return array<string, mixed>
     */
    public static function distressedProfile(): array
    {
        return self::profile([
            'company_status' => 'active',
            'date_of_creation' => '1998-03-02',
            'has_charges' => true,
            'accounts' => [
                'last_accounts' => ['made_up_to' => '2022-03-31'],
                'next_accounts' => ['due_on' => '2023-12-31', 'overdue' => true],
                'next_due' => '2023-12-31',
                'overdue' => true,
            ],
            'confirmation_statement' => [
                'last_made_up_to' => '2022-04-01',
                'next_due' => '2023-04-15',
                'overdue' => true,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function liquidationProfile(): array
    {
        return self::profile([
            'company_status' => 'liquidation',
            'has_insolvency_history' => true,
        ]);
    }

    /** @return array<string, mixed> */
    public static function dissolvedProfile(): array
    {
        return self::profile([
            'company_status' => 'dissolved',
            'date_of_cessation' => '2024-11-05',
            'has_insolvency_history' => true,
        ]);
    }

    /** @return array<string, mixed> */
    public static function charges(int $total = 3): array
    {
        return [
            'total_count' => $total,
            'unfiltered_count' => $total,
            'items' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function officers(int $active = 2): array
    {
        return [
            'active_count' => $active,
            'resigned_count' => 1,
            'total_results' => $active + 1,
            'items' => [],
        ];
    }
}
