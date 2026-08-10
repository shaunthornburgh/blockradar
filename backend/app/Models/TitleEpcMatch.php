<?php

namespace App\Models;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One title-to-certificate link, with the evidence behind it.
 */
#[Fillable([
    'title_id',
    'epc_certificate_id',
    'method',
    'confidence',
    'similarity',
    'is_primary',
])]
class TitleEpcMatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'method' => EpcMatchMethod::class,
            'confidence' => EpcMatchConfidence::class,
            'similarity' => 'decimal:2',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Title, $this> */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    /** @return BelongsTo<EpcCertificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(EpcCertificate::class, 'epc_certificate_id');
    }
}
