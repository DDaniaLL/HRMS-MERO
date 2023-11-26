<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable
{
    use CausesActivity;
    use HasApiTokens, HasFactory, Notifiable;
    use SoftDeletes;
    use LogsActivity;

   

    protected static $recordEvents = ['created', 'updated'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name',
                'birth_date',
                'unit',
                'position',
                'employee_number',
                'joined_date',
                'grade',
                'department',
                'email',
                'linemanager',
                'usertype_id',
                'hradmin'])->logOnlyDirty();

        // Chain fluent methods for configuration options
    }

    protected $fillable = [
        'name',
        'birth_date',
        'department',
        'grade',
        'position',
        'employee_number',
        'contract',
        'joined_date',
        'email',
        // 'office',
        'password',
        'linemanager',
        'usertype_id',
        'hradmin',
        'token',
    ];


    protected $hidden = [
        'password',
        'remember_token',
    ];

  
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function leaves()
    {
        return $this->hasMany(Leave::class);
    }

    public function overtimes()
    {
        return $this->hasMany(Overtime::class);
    }

    public function balances()
    {
        return $this->hasMany(Balance::class);
    }

    public function Usertype()
    {
        return $this->belongsTo(Usertype::class);
    }

    public function comlists()
    {
        return $this->hasMany(Comlist::class);
    }
}
