<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        'receipt_token',
        'status',
        'ip_address',
    ];

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Submission $submission): void {
            if (empty($submission->receipt_token)) {
                $submission->receipt_token = Str::random(64);
            }
        });
    }

    public function files()
    {
        return $this->hasMany(SubmissionFile::class);
    }
}
