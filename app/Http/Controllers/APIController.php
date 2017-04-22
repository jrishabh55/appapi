<?php

namespace App\Http\Controllers;

use App\CreditLog;
use App\InstallLog;
use App\Offer;
use App\RechargeRequest;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use const true;
use function APIError;
use function is_integer;
use function json_decode;
use function password_verify;
use function str_random;


class APIController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('api');
        $this->middleware('auth', ['except' => ['createUser', 'loginUser']]);
    }

    public function getOffers(int $offer = NULL)
    {
        $requestType = 'GetOffers';
        $country = Auth::user()->country;

        if (!empty($offer))
            $json = Offer::active($country)->where('id', $offer)->first();
        else
            $json = Offer::active($country)->get()->toArray();


        if (!empty($json))
            return APIResponse($requestType, ['offers' => $json]);
        else
            return APIError($requestType, ['Entry not found' => 'The item you are trying to access cannot be found.'], 500);

    }

    public function getUserData(int $id = NULL)
    {
        $requestType = 'GetUserData';
        if (!empty($id) && is_integer($id)) {
            $user = User::where('id', $id)->first();
        } else if (Auth::check()) {
            $user = User::findOrFail(Auth::user()->id)->with('installLogs', 'creditLogs')->first();
        } else {
            $user = NULL;
        }
        $user = Auth::user()->creditLogs;
        if (!empty($user))
            return APIResponse($requestType, ['user' => $user]);
        else
            return APIError($requestType, ['Entry not found' => 'The item you are trying to access cannot be found.']);
    }

    public function getUserCredits(int $user = NULL)
    {
        $requestType = 'GetUsersCredits';
        if (!empty($user)) {
            $credits = User::where('id', $user)->first()->credits ?? NULL;
        } else {
            if (Auth::check())
                $credits = Auth::user()->credits;
            else
                $credits = NULL;
        }

        if ($credits)
            return APIResponse($requestType, ['credits' => $credits]);
        else
            return APIError($requestType, ['Entry not found' => 'The item you are trying to access cannot be found.']);
    }

    public function createUser(Request $request)
    {
        $requestType = 'CreateUser';
        try {
            $this->validate($request, [
                'name' => 'required|string',
                'password' => 'required|string|min:8',
                'number' => 'required|numeric|min:10|unique:users',
                'email' => 'required|email|unique:users',
                'country' => 'required|string',
                'device_id' => 'required|min:10|max:20|unique:users',
            ]);


            $user = new User;
            $user->name = $request->input('name');
            $user->password = $request->input('password');
            $user->number = $request->input('number');
            $user->email = $request->input('email');
            $user->country = $request->input('country');
            $user->device_id = $request->input('device_id');
            $user->access_token = str_random(64);

            if ($user->saveOrFail())
                return APIResponse($requestType, ['user' => $user->makeVisible('access_token')]);
            else
                return APIError($requestType, ['error' => 'Failed for some reason']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return APIError($requestType, json_decode($e->getResponse()->getContent(), true));
        }
    }

    public function offerInstallLogs()
    {
        $requestType = 'InstalledOffers';
        $user = Auth::user();
        $logs = DB::table('install_logs')->join('offers', 'install_logs.package', '=', 'offers.package_id')
            ->where('install_logs.user_id', $user->id)
            ->select('install_logs.package', 'install_logs.device_id', 'install_logs.created_at as installed_on', 'offers.name', 'offers.credits', 'offers.image_location')
            ->get()->toArray();
        if (!empty($logs))
            return APIResponse($requestType, ['installed' => $logs]);
        else
            return APIError($requestType, ['error' => 'Failed for some reason']);

    }

    public function offerInstall(Request $request)
    {

        $requestType = 'OfferLogs';

        try {

            $this->validate($request, [
                'package' => 'required|string'
            ]);

            if (!$request->has('package'))
                return APIError($requestType, ["Invalid id" => "The pre-requisite id is invalid or not found."]);

            $user = Auth::user();

            $package = $request->input('package');

            if (InstallLog::where('user_id', $user->id)->where('package', $package)->count())
                return APIError($requestType, ["Already availed" => "The offer is already availed."]);

            $offer = Offer::where('package_id', $package)->first();

            $credits = $offer->credits;

            $log = new InstallLog;
            $log->package = $package;
            $log->credits = $credits;
            $log->user_id = $user->id;
            $log->device_id = $user->device_id;

            $t = new CreditLog;
            $t->user_id = $user->id;
            $t->value = $credits;
            $t->ip = $request->ip();
            $t->log_line = 'Credits added for package: ' . $package . '.';

            if ($log->saveOrFail() && $t->saveOrFail() && $user->addCredits($credits))
                return APIResponse($requestType, ['user' => $user]);
            else
                return APIError($requestType, ['error' => 'Failed for some reason']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return APIError($requestType, json_decode($e->getResponse()->getContent(), true));
        }
    }

    public function requestRecharge(Request $request)
    {

        $requestType = 'RequestRecharge';
        try {
            $this->validate($request, [
                'recharge' => 'required|integer|min:10',
                'number' => 'required|number|min:10',
                'provider' => 'required|string'
            ]);


            $user = Auth::user();
            $recharge = (int)$request->input('recharge');
            $number = $request->input('number');
            $provider = $request->input('provider');

            if ($user->credits < $recharge)
                return APIError($requestType, ["Invalid id" => "Insufficient Credits."]);

            $temp = new RechargeRequest;
            $temp->user_id = $user->id;
            $temp->recharge = $recharge;
            $temp->number = $number;
            $temp->ip = $request->ip();

            $t = new CreditLog;
            $t->user_id = $user->id;
            $t->credited = false;
            $t->value = $recharge;
            $t->ip = $request->ip();
            $t->log_line = 'Recharge on Number: ' . $recharge . ' Provider: ' . $provider . '.';

            if ($temp->saveOrFail() && $t->saveOrFail() && $user->deductCredits($recharge))
                return APIResponse($requestType, ['user' => $user]);
            else
                return APIError($requestType, ['error' => 'Failed for some reason']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return APIError($requestType, json_decode($e->getResponse()->getContent(), true));
        }
    }

    public function loginUser(Request $request)
    {
        $requestType = 'LoginRequest';
        try {
            $this->validate($request, [
                'email' => 'required|email|exists:users',
                'password' => 'required|string|min:8',
                'device_id' => 'required|min:10|max:20',
            ]);

            $user = User::where('email', $request->input('email'))->first();

            if (!password_verify($request->input('password'), $user->password))
                return APIError($requestType, ['error' => 'Invalid Password.']);

            if (!$user->verified)
                return APIError($requestType, ['error' => 'User is not Verified.']);


            if ($user->updateAccessToken() && $user->updateDeviceId($request->input('device_id')))
                return APIResponse($requestType, ['user' => $user->makeVisible('access_token')]);
            else
                return APIError($requestType, ['error' => 'Failed for some reason']);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return APIError($requestType, json_decode($e->getResponse()->getContent(), true));

        }
    }

    public function changePassword(Request $request)
    {
        $requestType = 'ChangePasswordRequest';
        try {
            $this->validate($request, [
                'password' => 'required|string|min:8',
            ]);

            $user = Auth::user();

            if ($user->changePassword($request->input('password')))
                return APIResponse($requestType, ['user' => $user]);
            else
                return APIError($requestType, ['error' => 'Failed for some reason']);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return APIError($requestType, json_decode($e->getResponse()->getContent(), true));

        }

    }

    public function toggleVerification()
    {
        $requestType = 'ChangePasswordRequest';
        try {
            $user = Auth::user();

            if ($user->toggleVerified())
                return APIResponse($requestType, ['user' => $user]);
            else
                return APIError($requestType, ['error' => 'Failed for some reason']);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return APIError($requestType, json_decode($e->getResponse()->getContent(), true));

        }

    }

    public function creditLogs()
    {
        $requestType = 'CreditLogs';

        $user = Auth::user();

        return APIResponse($requestType, ['logs' => $user->creditLogs]);

    }

}
