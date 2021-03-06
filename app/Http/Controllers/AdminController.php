<?php

namespace App\Http\Controllers;

use App\InstallLog;
use App\Offer;
use App\RechargeRequest;
use App\User;
use Illuminate\Http\Request;
use function redirect;
use function setInputs;
use function status;
use function view;

class AdminController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('admin');
    }

    public function addApp()
    {
        return view('addApp')->with(status());
    }

    public function addAppHandel(Request $request)
    {
        try{
            $this->validate($request, [
                'name' => 'required|string',
                'url' => 'required|url',
                'package_id' => 'required|unique:offers',
                'credits' => 'required|numeric|min:0',
                'country' => 'required|string',
                'img' => 'required|url',
                'valid' => 'required|date|after_or_equal:tomorrow',
                'desc' => 'string|min:20'
            ]);


            $offer = new Offer;
            $offer->name = $request->input('name');
            $offer->url = $request->input('url');
            $offer->package_id = $request->input('package_id');
            $offer->credits = $request->input('credits');
            $offer->country = $request->input('country');
            $offer->image_location = $request->input('img');
            $offer->valid_until = $request->input('valid');
            $offer->desc = $request->input('desc', '');

            $status = $offer->saveOrFail() ? 'true' : 'false';

            return redirect($request->path() . '?status=' . $status);

        }catch (\Illuminate\Validation\ValidationException $e) {
            setInputs($request->all());
            setErrors($e->getResponse()->getContent());
            return redirect($request->path() . '?status=false');
        }
    }

    public function listOffers()
    {
        return view('listApp')->with(status(['offers' => Offer::orderByDesc('id')->get()]));
    }

    public function deleteOffer($id)
    {
        $response = Offer::findOrFail($id)->delete() ?  'true' : 'false';

        return redirect(url('/list-apps?status='.$response));
    }


    public function switchOfferVisibility($id)
    {
        $offer = Offer::findOrFail($id);

        if($offer) {
            $offer->hidden = !$offer->hidden;
            $response = $offer->saveOrFail() ? 'true' : 'false';
        }else
            $response = 'false';

        return redirect(url('/list-apps?status='.$response));
    }

    public function listAPI()
    {
        return view('api.list');
    }

    public function listUsers()
    {
        return view('users.list', status(['users' => User::orderByDesc('id')->get()]));
    }

    public function deleteUser($id)
    {
        $response = User::findOrFail($id)->delete() ? 'true' : 'false';

        return redirect(url('/list-users?status=' . $response));
    }

    public function listRecharge()
    {
        return view('listRecharge', status(['recharges' => RechargeRequest::all()]));
    }

    public function listOfferInstalls()
    {
        return view('listInstallLogs', status(['installs' => InstallLog::all()]));
    }

    public function approveRecharge($id)
    {
        $recharge = RechargeRequest::findOrFail($id);

        if ($recharge && $recharge->approved === false) {
            $recharge->approved = true;
            $response = $recharge->saveOrFail() ? 'true' : 'false';
        } else
            $response = 'false';

        return redirect(url('/list-recharge?status=' . $response));

    }

}
