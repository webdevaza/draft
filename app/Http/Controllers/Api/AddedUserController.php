<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddedUserRequest;
use App\Http\Resources\AddedUserResource;
use App\Http\Resources\CountryResource;
use App\Models\AddedUser;
use App\Models\Attachment;
use App\Models\Country;
use App\Services\Search;
use App\Traits\AttachPhotosTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
class AddedUserController extends Controller
{
    use AttachPhotosTrait;
    public function __construct(private Search $search)
    {
        $this->authorizeResource(AddedUser::class);
        $this->search = new Search();
    }

    /**
     * Display a listing of the resource.
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request, Country $country)
    {
        if (isset($country['id'])) {
            return AddedUserResource::collection($country->addedUsers);
        }
        if ($request->has('risk')) {
            return AddedUserResource::collection(AddedUser::with(['country'])->where('sanction', $request->get('risk'))->orderBy('created_at', 'desc')->get());
        }
        return AddedUserResource::collection(AddedUser::with(['country'])->orderBy('created_at', 'desc')->get());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreAddedUserRequest $request)
    {
            $user = DB::transaction(function () use ($request) {
                $user = AddedUser::create(
                    Arr::except($request->validated(), ['passport_photo', 'cv_photo'])
                );
                $this->attachPhotos($request, $user);
                return $user;
            });

            return new AddedUserResource($user);
    }



    public function delete(Attachment $attachment)
    {
        $attachment->delete();
        return response()->json([], 204);
    }

    public function upload(Request $request, AddedUser $addedUser)
    {
        $request->validate([
            'passport_photo.*' => 'image',
            'cv_photo.*' => 'image',
        ]);

        if ($request->has('passport_photo') && is_array($request['passport_photo'])) {
            $passport_photo = $request->file('passport_photo');
            $this->attach($passport_photo, $addedUser, 'passport');
        }
        if ($request->has('cv_photo') && is_array($request['cv_photo'])) {
            $cv_photo = $request->file('cv_photo');
            $this->attach($cv_photo, $addedUser, 'cv');
        }


        return new AddedUserResource($addedUser);
    }


    /**
     * Display the specified resource.
     *
     * @param \App\Models\AddedUser $addedUser
     * @return \Illuminate\Http\Response
     */
    public function show(AddedUser $addedUser)
    {
        return new AddedUserResource($addedUser->loadMissing('userOperations'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\AddedUser $addedUser
     * @return \Illuminate\Http\Response
     */
    public function update(StoreAddedUserRequest $request, AddedUser $addedUser)
    {
        $addedUser->update(
            Arr::except($request->validated(), ['passport_photo', 'cv_photo'])
        );
        $hash = md5(($addedUser['last_name'] ?? null) . ($addedUser['first_name'] ?? null) . ($addedUser['middle_name'] ?? null) . ($addedUser['birth_date'] ?? null));
        $addedUser->hash = $hash;
        $addedUser->save();
        $this->attachPhotos($request, $addedUser);
        return new AddedUserResource($addedUser);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\AddedUser $addedUser
     * @return \Illuminate\Http\Response
     */
    public function destroy(AddedUser $addedUser)
    {
        $addedUser->delete();
        return response()->noContent();
    }

    public function search(Request $request)
    {
        try {

            $whiteListUsers = AddedUserResource::collection($this->search->searchFromClients('AddedUser', $request)->unique('hash')->all());
            if ($request->get('name') == null && $request->get('birth_date') == null) {
                return $whiteListUsers;
            }

            $whiteListUsers = $this->filterByDates($request, $whiteListUsers);
            list($blackLists, $results) = $this->getBlackedListUsers($request, $whiteListUsers);

            return $this->mergeBothUsers($whiteListUsers, $blackLists, $results);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500); // HTTP status code 500 for Internal Server Error
        }
    }

    public function parseDateString($date_string)
    {
        if (str_contains($date_string, ':')) {
            return Carbon::createFromFormat('d/m/Y H:i', $date_string)->format('Y-m-d H:i');
        } else {
            return Carbon::createFromFormat('d/m/Y', $date_string)->startOfDay()->format('Y-m-d H:i');
        }
    }

    public function countries()
    {
        return CountryResource::collection(Country::all());
    }

    public function filterByDates(Request $request, AnonymousResourceCollection $addedUsers)
    {
        if ($request->has('date1') && $request->has('date2')) {
            $startDate = $this->parseDateString($request->date1);
            $endDate = $this->parseDateString($request->date2);
            $addedUsers = $addedUsers->filter(function ($item) use ($startDate, $endDate) {
                return $item['created_at'] >= $startDate && $item['created_at'] <= $endDate;
            });
        }
        return $addedUsers->sortByDesc('created_at');
    }

    public function getBlackedListUsers(Request $request, $addedUsers)
    {
        $blackLists = AddedUserResource::collection($this->search->searchFromClients('BlackList', $request))->map(function ($item) {
            $item['hash'] = md5($item['last_name'] . $item['first_name'] . $item['middle_name'] . ((isset($item['birth_date'])) ? $item['birth_date']->format('d/m/Y') : ''));
//                \Storage::disk('local')->append('incomess.txt', ($item['last_name'] . $item['first_name'] . $item['middle_name'] . ((isset($item['birth_date'])) ? $item['birth_date']->format('d/m/Y') : '')));
            return $item;
        });
        $results = $addedUsers->merge($blackLists)->toJson();
        $results = collect((array)json_decode($results, true));
        return array($blackLists, $results);
    }

    /**
     * @param $whiteListUsers
     * @param mixed $blackLists
     * @param mixed $results
     * @return array
     */
    public function mergeBothUsers($whiteListUsers, mixed $blackLists, mixed $results): array
    {
        $counted = $whiteListUsers->merge($blackLists)
            ->countBy(function ($item) {
                return $item['hash'];
            });

        $mk = [];
        $r = $results->reject(function ($items) use ($counted, &$mk) {
            return $counted[$items['hash']] > 1 && $items['black_list'] == false;
        });

        $counted->map(function ($key, $item) use ($r, &$mk) {
            $r = $r->where('hash', $item);
            $type = implode(',', $r->pluck('type')->toArray());
            $data = $r->first();
            $data['type'] = $type;
            $mk[] = $data;
        });
        return $mk;
    }
}
