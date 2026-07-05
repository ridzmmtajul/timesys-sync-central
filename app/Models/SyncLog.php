<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'module',
        'direction',
        'status',
        'total_records',
        'synced_count',
        'existing_count',
        'skipped_count',
        'errors',
        'message',
    ];

    protected $casts = [
        'errors' => 'array',
    ];
}
