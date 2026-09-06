<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'student_name',
        'student_email',
        'student_phone',
        'student_major',
        'anonymous_code',
        'status',
        'ip_address',
    ];

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function files()
    {
        return $this->hasMany(SubmissionFile::class);
    }
}
