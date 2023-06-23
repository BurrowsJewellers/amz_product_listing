<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AmzRequestedReport extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'report_id',
        'report_type',
        'file_name',
        'amz_marketplace_id',
        'downloaded',
        'processed',
        'api_response',
    ];

    public function marketplace() {
        return $this->belongsTo(AmzMarketplace::class, 'amz_marketplace_id', 'id');
    }

}
