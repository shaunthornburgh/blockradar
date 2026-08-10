<?php

namespace App\Services\Epc;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Models\EpcCertificate;
use App\Models\Title;

/**
 * Links a CCOD title to the EPC certificates inside that building.
 *
 * Four strategies are tried in descending order of reliability, and the first
 * that produces anything wins:
 *
 *   1. UPRN            — unambiguous, when the title has one
 *   2. Exact address   — same postcode, identical normalised building key
 *   3. Fuzzy address   — same postcode, building key above the threshold
 *   4. Postcode only   — everything in the postcode, and probably too much
 *
 * The whole point of matching a *building* rather than a dwelling is that the
 * number of certificates found becomes a unit count. That makes a
 * postcode-only match actively dangerous — it would sweep in the neighbours'
 * flats — which is why it is recorded as low confidence and excluded by
 * default.
 */
class EpcMatcher
{
    public function __construct(private readonly AddressNormaliser $normaliser) {}

    public function match(Title $title): EpcMatchSet
    {
        if (($byUprn = $this->byUprn($title))->isEmpty() === false) {
            return $byUprn;
        }

        if ($title->postcode === null) {
            return EpcMatchSet::none();
        }

        $buildingKey = $this->buildingKeyFor($title);

        if ($buildingKey !== '') {
            if (($exact = $this->byExactBuilding($title->postcode, $buildingKey))->isEmpty() === false) {
                return $exact;
            }

            if (($fuzzy = $this->byFuzzyBuilding($title->postcode, $buildingKey))->isEmpty() === false) {
                return $fuzzy;
            }
        }

        return $this->byPostcode($title->postcode);
    }

    /**
     * CCOD does not supply UPRNs, so this lies dormant today. It is wired up
     * and tested so that adding an address-to-UPRN source later needs no
     * change here.
     */
    private function byUprn(Title $title): EpcMatchSet
    {
        if ($title->uprn === null || trim($title->uprn) === '') {
            return EpcMatchSet::none();
        }

        $certificates = EpcCertificate::query()
            ->where('uprn', $title->uprn)
            ->get();

        if ($certificates->isEmpty()) {
            return EpcMatchSet::none();
        }

        return new EpcMatchSet(
            $certificates,
            EpcMatchMethod::Uprn,
            EpcMatchMethod::Uprn->confidence(),
            100.0,
        );
    }

    /**
     * Every certificate whose building key is identical — for a block, each
     * flat in it.
     *
     * Resolved in SQL against the (postcode, building_key_hash) index rather
     * than by loading the postcode and filtering in PHP. A dense postcode can
     * hold hundreds of certificates, and any cap on the loaded pool would
     * silently hide the right building behind its neighbours.
     */
    private function byExactBuilding(string $postcode, string $buildingKey): EpcMatchSet
    {
        $hash = $this->normaliser->hash($buildingKey);

        if ($hash === null) {
            return EpcMatchSet::none();
        }

        $matches = EpcCertificate::query()
            ->inPostcode($postcode)
            ->where('building_key_hash', $hash)
            ->limit($this->maxCertificates())
            ->get();

        if ($matches->isEmpty()) {
            return EpcMatchSet::none();
        }

        return new EpcMatchSet(
            $matches,
            EpcMatchMethod::ExactAddress,
            EpcMatchMethod::ExactAddress->confidence(),
            100.0,
        );
    }

    /**
     * Uses similarity to *locate* the building, then takes its members
     * exactly.
     *
     * Catching typos matters — "Hawthorne House" against "Hawthorn House" —
     * but accepting every certificate above the threshold does not work. A
     * postcode holding "Block A Mill Lane", "Block B Mill Lane" and "Block C
     * Mill Lane" scores all three well above any usable threshold, and the
     * matched set becomes three buildings' worth of flats. Since that count is
     * then used as the unit count, the error is not cosmetic.
     *
     * So: the single best-scoring certificate identifies the building, and
     * membership is decided by exact building key from there. One fuzzy
     * decision, not one per certificate.
     */
    private function byFuzzyBuilding(string $postcode, string $buildingKey): EpcMatchSet
    {
        $threshold = (float) config('blockradar.epc.match.fuzzy_threshold', 82);

        $candidates = EpcCertificate::query()
            ->inPostcode($postcode)
            ->limit($this->fuzzyPoolSize())
            ->get();

        if ($candidates->isEmpty()) {
            return EpcMatchSet::none();
        }

        $best = $candidates
            ->map(fn (EpcCertificate $certificate) => [
                'certificate' => $certificate,
                'similarity' => $this->normaliser->similarity(
                    $buildingKey,
                    $this->normaliser->buildingKey($certificate->address, [
                        $certificate->post_town,
                        $certificate->council,
                        $certificate->county,
                    ])
                ),
            ])
            ->filter(fn (array $row) => $row['similarity'] >= $threshold)
            ->sortByDesc('similarity')
            ->first();

        if ($best === null) {
            return EpcMatchSet::none();
        }

        /** @var EpcCertificate $winner */
        $winner = $best['certificate'];

        $members = $winner->building_key_hash !== null
            ? $candidates->filter(
                fn (EpcCertificate $c) => $c->building_key_hash === $winner->building_key_hash
            )->values()
            : collect([$winner]);

        return new EpcMatchSet(
            $members->take($this->maxCertificates())->values(),
            EpcMatchMethod::FuzzyAddress,
            EpcMatchMethod::FuzzyAddress->confidence(),
            (float) $best['similarity'],
        );
    }

    private function byPostcode(string $postcode): EpcMatchSet
    {
        $certificates = EpcCertificate::query()
            ->inPostcode($postcode)
            ->limit($this->maxCertificates())
            ->get();

        if ($certificates->isEmpty()) {
            return EpcMatchSet::none();
        }

        return new EpcMatchSet(
            $certificates,
            EpcMatchMethod::Postcode,
            EpcMatchMethod::Postcode->confidence(),
            null,
        );
    }

    /**
     * How many certificates fuzzy matching will compare within one postcode.
     * Ten times the match cap: enough to cover any real postcode, while still
     * bounding the work if the data is malformed.
     */
    private function fuzzyPoolSize(): int
    {
        return $this->maxCertificates() * 10;
    }

    public function buildingKeyFor(Title $title): string
    {
        return $this->normaliser->buildingKey($title->property_address, [
            $title->district,
            $title->county,
            $title->region,
        ]);
    }

    private function maxCertificates(): int
    {
        return max(1, (int) config('blockradar.epc.match.max_certificates_per_title', 300));
    }

    public function minimumConfidence(): EpcMatchConfidence
    {
        return EpcMatchConfidence::tryFrom(
            (string) config('blockradar.epc.match.min_confidence', 'medium')
        ) ?? EpcMatchConfidence::Medium;
    }
}
