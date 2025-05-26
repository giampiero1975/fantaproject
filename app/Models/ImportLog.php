<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'original_file_name',
        'import_type',
        'status',
        'details',
        'rows_processed',
        'rows_created',
        'rows_updated',
    ];
}