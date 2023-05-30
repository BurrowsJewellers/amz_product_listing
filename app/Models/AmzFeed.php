<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmzFeed extends Model
{
    use HasFactory;

    protected $fillable = [
        'feed_id',
        'type',
        'file_name',
        'response_file_name',
        'processing_status',
    ];

}
