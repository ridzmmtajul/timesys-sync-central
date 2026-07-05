<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'check_time',
        'employee_id',
        'serial_no',
        'post_no',
        'void',
    ];

    protected $casts = [
        'check_time' => 'datetime',
        'void' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
