<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmzMarketplace extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'marketplace_id',
        'country',
        'country_code',
        'region',
        'status',
    ];


    public function scopeActive($query){
        return $query->where('status', 1);
    }

}
