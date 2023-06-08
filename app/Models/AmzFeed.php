<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AmzFeed extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'feed_id',
        'type',
        'file_name',
        'response_file_name',
        'processing_status',
    ];

}
