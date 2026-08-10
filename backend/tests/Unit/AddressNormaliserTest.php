<?php

namespace Tests\Unit;

use App\Services\Epc\AddressNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AddressNormaliserTest extends TestCase
{
    private AddressNormaliser $normaliser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normaliser = new AddressNormaliser;
    }

    #[Test]
    #[DataProvider('buildingKeys')]
    public function it_reduces_an_address_to_its_building(string $address, array $localities, string $expected): void
    {
        $this->assertSame($expected, $this->normaliser->buildingKey($address, $localities));
    }

    /** @return array<string, array{0: string, 1: array<int, string>, 2: string}> */
    public static function buildingKeys(): array
    {
        return [
            'ccod block with range and town' => [
                'Flats 1-8 Hawthorn House, 23 Bury New Road, Manchester',
                ['Manchester'],
                'hawthorn house 23 bury new rd',
            ],
            'epc single flat in the same block' => [
                'Flat 3, Hawthorn House, 23 Bury New Road',
                [],
                'hawthorn house 23 bury new rd',
            ],
            'range written with "to"' => [
                'Flats 1 to 12 Elm Lodge, 5 Clifton Drive',
                [],
                'elm lodge 5 clifton dr',
            ],
            'floor designator' => [
                'Ground Floor Flat, 44 Regent Road',
                [],
                '44 regent rd',
            ],
            'numbered floor designator' => [
                'Second Floor Flat 2, 44 Regent Road',
                [],
                '44 regent rd',
            ],
            'apartment abbreviation' => [
                'Apt 7 The Mill, Water Street',
                [],
                'mill water st',
            ],
            'bare flat with no number' => [
                'Flat, 96 Cardigan Lane',
                [],
                '96 cardigan ln',
            ],
            'embedded postcode is removed' => [
                '23 Bury New Road, Manchester M8 8EL',
                ['Manchester'],
                '23 bury new rd',
            ],
            'letter suffix unit' => [
                '1a Acacia Avenue',
                [],
                'acacia ave',
            ],
            'house number is not mistaken for a unit' => [
                '23 Bury New Road',
                [],
                '23 bury new rd',
            ],
            'locality only stripped from the end' => [
                'Manchester House, 4 Manchester Road, Leeds',
                ['Leeds'],
                'manchester house 4 manchester rd',
            ],
            'multiple localities' => [
                'Elm Lodge, 5 Clifton Drive, Birmingham, West Midlands',
                ['West Midlands', 'Birmingham'],
                'elm lodge 5 clifton dr',
            ],
            'empty address' => ['', [], ''],
        ];
    }

    #[Test]
    public function a_ccod_block_and_its_flats_share_a_building_key(): void
    {
        $title = $this->normaliser->buildingKey(
            'Flats 1-8 Hawthorn House, 23 Bury New Road, Manchester',
            ['Manchester', 'Greater Manchester']
        );

        foreach (['Flat 1, Hawthorn House, 23 Bury New Road', 'Flat 8, Hawthorn House, 23 Bury New Road'] as $flat) {
            $this->assertSame($title, $this->normaliser->buildingKey($flat, ['Manchester']));
        }
    }

    #[Test]
    public function different_buildings_in_one_postcode_do_not_collide(): void
    {
        $a = $this->normaliser->buildingKey('Flat 1, Hawthorn House, 23 Bury New Road', []);
        $b = $this->normaliser->buildingKey('Flat 1, Oakwood Court, 91 Cheetham Hill Road', []);

        $this->assertNotSame($a, $b);
        $this->assertLessThan(70.0, $this->normaliser->similarity($a, $b));
    }

    #[Test]
    public function near_identical_addresses_score_high_similarity(): void
    {
        $a = $this->normaliser->buildingKey('Hawthorn House, 23 Bury New Road', []);
        $b = $this->normaliser->buildingKey('Hawthorne House, 23 Bury New Rd', []);

        $this->assertGreaterThan(90.0, $this->normaliser->similarity($a, $b));
    }

    #[Test]
    public function normalise_keeps_the_unit_but_drops_the_postcode(): void
    {
        $this->assertSame(
            'flat 3 hawthorn house 23 bury new rd',
            $this->normaliser->normalise('Flat 3, Hawthorn House, 23 Bury New Road, M8 8EL')
        );
    }

    #[Test]
    public function hashing_is_stable_and_null_for_empty_input(): void
    {
        $key = $this->normaliser->buildingKey('Flat 3, Hawthorn House, 23 Bury New Road');

        $this->assertSame($this->normaliser->hash($key), $this->normaliser->hash($key));
        $this->assertNull($this->normaliser->hash(''));
    }
}
