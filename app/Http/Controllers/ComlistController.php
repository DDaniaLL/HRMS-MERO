<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Comlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ComlistController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();
        $comlist = Comlist::where('user_id', $user->id)->get();

        $balances = Balance::where('user_id', $user->id)->get();
        $subsets = $balances->map(function ($balance) {
            return collect($balance->toArray())

                ->only(['value', 'leavetype_id'])
                ->all();
        });

        $leave18 = $subsets->firstwhere('leavetype_id', '18');
        $balance18 = round($leave18['value'], 3);

        return view('comlists.index', ['comlists' => $comlist, 'balance18' => $balance18]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Comlist $comlist)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Comlist $comlist)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Comlist $comlist)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Comlist $comlist)
    {
        //
    }
}
