<?php

namespace App\Models;

use App\Http\Resources\AddedUserResource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class LegalEntity extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $dates = ['birth_date','verification_date'];

    public static function boot()
    {
        parent::boot();

        static::deleting(function ($user) {
            $user->attachments()->delete();
            $user->userOperations()->delete();
        });
    }

    public function setBirthDateAttribute($value)
    {
        $this->attributes['birth_date'] = Carbon::createFromFormat('d/m/Y',$value)->format('Y-m-d');
    }

    public function setVerificationDateAttribute($value)
    {
        if ($value!=null){
            $this->attributes['verification_date'] = Carbon::createFromFormat('d/m/Y',$value)->format('Y-m-d');
        }else{
            $this->attributes['verification_date'] = now()->format('Y-m-d');
        }

    }

    public function country(){
        return $this->belongsTo(Country::class,'country_id','id');
    }

    public function userOperations(){
        return $this->hasMany(UserOperation::class,'legal_id','id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

}
