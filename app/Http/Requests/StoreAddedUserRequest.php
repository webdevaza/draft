<?php

namespace App\Http\Requests;

use App\Models\BlackList;
use App\Models\Country;
use Illuminate\Foundation\Http\FormRequest;

class StoreAddedUserRequest extends FormRequest
{
    private $rules = [];
    public function all($keys = null)
    {
        $data = parent::all($keys);
        if(!$this->route('added_user')){
            $hash = md5(trim($data['last_name'] ?? '') . trim($data['first_name'] ?? '') . trim($data['middle_name'] ?? '') . trim($data['birth_date'] ?? ''));
            $data['hash'] = $hash;
        }
        return $data;
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'last_name' => (!$this->route('added_user')) ? 'required' : '',
            'first_name' => (!$this->route('added_user')) ? 'required' : '',
            'middle_name' => (!$this->route('added_user')) ? '' : '',
            'birth_date' => (!$this->route('added_user')) ? 'required|date_format:d/m/Y' : '',
            'verification_date' => 'nullable|date_format:d/m/Y',
            'country_id' => (!$this->route('added_user')) ? 'required' : '',
            'pass_num_inn' => (!$this->route('added_user')) ? 'required_if:country_id,1|unique:added_users|digits_between:6,14' : '',
            'hash' => 'required|sometimes|unique_fio_dob:last_name,first_name,middle_name,birth_date|check_in_black_list:last_name,first_name,middle_name,birth_date',

            'passport_photo.*' => 'image',

            'cv_photo.*' => 'nullable|sometimes|mimes:doc,docx,xls,xlsx,pdf,csv,jpg,jpeg,png,bmp|max:20000',


//            'cv_photo.*' => 'file|mimes:jpg, jpeg, bmp,png,pdf,doc,docx',

            'passport_id' => (!($this->route('added_user')) && $this->input('country_id'))  ?
                'required_unless:country_id,1|unique:added_users' : '',
            'passport_authority' => (!($this->route('added_user')) && $this->input('country_id'))  ?
                'required_unless:country_id,1' : '',
            'passport_authority_code' => (!($this->route('added_user')) && $this->input('country_id'))  ?
                'required_unless:country_id,1' : '',
            'passport_issued_at' => (!($this->route('added_user')) && $this->input('country_id'))  ?
                'required_unless:country_id,1' : '',
            'passport_expires_at' => (!($this->route('added_user')) && $this->input('country_id'))  ?
                'required_unless:country_id,1|' : '',
            'sanction'=> 'integer',
            'verification'=> 'boolean'

        ];
    }
    protected function prepareForValidation()
    {
        $sanction = $this->input('sanction');
        $country_id = $this->input('country_id');
        $country = Country::find($country_id);
        $value = 0;
        if($country){
            $value = $country->sanction;
        }

        $this->merge([
            'sanction' => $sanction ? $sanction : $value,
        ]);

    }
}
