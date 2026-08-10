<?php

namespace Tests\Unit;

use App\Services\Candidates\UnitCountEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UnitCountEstimatorTest extends TestCase
{
    private UnitCountEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->estimator = new UnitCountEstimator;
    }

    #[Test]
    #[DataProvider('addresses')]
    public function it_estimates_units_from_an_address(?string $address, ?int $expected, string $why): void
    {
        $this->assertSame($expected, $this->estimator->estimate($address), $why);
    }

    /** @return array<string, array{0: string|null, 1: int|null, 2: string}> */
    public static function addresses(): array
    {
        return [
            'labelled range with hyphen' => [
                'Flats 1-8 Hawthorn House, Manchester', 8, 'Flats 1 through 8 inclusive',
            ],
            'labelled range with "to"' => [
                'Flats 1 to 6 Elm Lodge, Birmingham', 6, '"to" reads the same as a hyphen',
            ],
            'apartments range' => [
                'Apartments 10-21 The Mill, Leeds', 12, 'Inclusive count of 10 through 21',
            ],
            'explicit count' => [
                'Being 14 flats at Sandringham Court', 14, 'The number is stated outright',
            ],
            'leading odd-numbered terrace' => [
                '23-25 Joshua Drive, Cardiff', 2, 'Matching parity means every other number: 23 and 25',
            ],
            'leading even-numbered terrace' => [
                '12-18 Osborne Road, Liverpool', 4, '12, 14, 16, 18',
            ],
            'leading mixed-parity range' => [
                '10-13 Sea View, Hull', 4, 'Mixed parity counts every number',
            ],
            'repeated flat mentions' => [
                'Flat 1, Flat 2 And Flat 3 Rosemount', 3, 'Counted by mention',
            ],
            'single house number' => [
                '96 Cardigan Lane, Leeds', null, 'A single dwelling gives nothing to count',
            ],
            'no numbers at all' => [
                'The Old Vicarage, Church Lane', null, 'Nothing numeric to work with',
            ],
            'postcode digits are not a range' => [
                'Hawthorn House, Bury New Road, Manchester M8 8EL', null, 'Digits away from the start are ignored',
            ],
            'implausibly large range is rejected' => [
                'Flats 1-5000 Nowhere Tower', null, 'Beyond any real block, so treated as unknown',
            ],
            'reversed range is rejected' => [
                '25-23 Backwards Street', null, 'End before start is a data error',
            ],
            'null address' => [null, null, 'Nothing to estimate from'],
            'empty address' => ['   ', null, 'Nothing to estimate from'],
        ];
    }
}
