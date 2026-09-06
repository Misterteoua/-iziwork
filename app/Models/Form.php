<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'token',
        'open_date',
        'close_date',
        'max_submissions',
        'is_anonymous',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'open_date' => 'datetime',
            'close_date' => 'datetime',
            'is_anonymous' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($form) {
            if (empty($form->token)) {
                $form->token = Str::random(32);
            }
        });
    }

    public function fields()
    {
        return $this->hasMany(FormField::class)->orderBy('order');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function isOpen(): bool
    {
        $now = now();
        
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->open_date && $now->lt($this->open_date)) {
            return false;
        }

        if ($this->close_date && $now->gt($this->close_date)) {
            return false;
        }

        if ($this->max_submissions && $this->submissions()->count() >= $this->max_submissions) {
            return false;
        }

        return true;
    }

    public function getSubmissionCount(): int
    {
        return $this->submissions()->count();
    }
}
