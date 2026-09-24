<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'father_name',
        'last_name',
        'email',
        'password',
        'year',
        'semester',
        'current_term_id',
        'role',
        'google_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'full_name',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'year' => 'integer',
            'semester' => 'integer',
        ];
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    /**
     * رسالة الإعادة بقالب الموقع العربي لا بقالب لارافيل الإنجليزي.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * الاسم الكامل صفة محسوبة من الأجزاء الثلاثة — لا عمود لها في القاعدة.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(function (): string {
            $parts = array_filter(
                [$this->first_name, $this->father_name, $this->last_name],
                fn ($part) => trim((string) $part) !== '',
            );

            return implode(' ', array_map('trim', $parts));
        });
    }

    /*
     * حقول التسجيل تُقرأ من الجدول الوسيط: الحالة والفصل والتقدير
     * وتاريخ الإنجاز خصائص للتسجيل لا للمساق ولا للطالب.
     */
    public function myCourses()
    {
        return $this->belongsToMany(Course::class, 'my_courses')
            ->withPivot(['term_id', 'status', 'completed_at', 'grade'])
            ->withTimestamps();
    }

    public function currentTerm()
    {
        return $this->belongsTo(Term::class, 'current_term_id');
    }

    public function progress()
    {
        return $this->hasMany(CourseProgress::class);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'supervisor'], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
