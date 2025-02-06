<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadLog extends Model
{
    protected $fillable = ['type', 'last_download'];

    protected $casts = [
        'last_download' => 'datetime',
    ];
}
