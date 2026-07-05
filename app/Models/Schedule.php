<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'default_timein_AM',
        'default_timeout_AM',
        'default_timein_PM',
        'default_timeout_PM',
        'schedule_type_id',
        'no_lunch_gap',
    ];

    public function scheduleType()
    {
        return $this->belongsTo(ScheduleType::class);
    }
}
