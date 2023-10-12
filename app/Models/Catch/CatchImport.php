<?php

namespace App\Models\Catch;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatchImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_type',
        'import_id',
        'product_import_id',
        'file_name',
        'response_file_name',
        'submitted',
        'processed',
    ];
}
