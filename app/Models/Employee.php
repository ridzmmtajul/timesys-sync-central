<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'employee_no',
        'first_name',
        'middle_name',
        'last_name',
        'name_ext',
        'gender',
        'contact_no',
        'job_title',
        'is_active',
        'office_id',
        'employment_type_id',
        'position_id',
        'office_division_id',
        'image',
        'title_id',
        'signature',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function employmentType()
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function officeDivision()
    {
        return $this->belongsTo(OfficeDivision::class);
    }
}
