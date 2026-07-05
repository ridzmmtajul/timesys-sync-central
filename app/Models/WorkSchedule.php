<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'schedule_id',
        'schedule_type_id',
        'timein_AM',
        'timeout_AM',
        'timein_PM',
        'timeout_PM',
        'from_date',
        'to_date',
        'is_others',
        'schedule_for',
        'days',
        'no_lunch_gap',
    ];

    protected $casts = [
        'days'         => 'array',
        'is_others'    => 'boolean',
        'no_lunch_gap' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function scheduleType()
    {
        return $this->belongsTo(ScheduleType::class);
    }
}
