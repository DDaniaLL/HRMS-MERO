<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HolidayController extends Controller
{
   
    public function index()
    {
        $holiday = Holiday::all();
        return view('admin.holidays.index', ['holidays' => $holiday]);
    }

    
    public function create()
    {
        $authuser = Auth::user();
        if ($authuser->hradmin == "yes")
        {
            return view('admin.holidays.create');
        }
        else
        {
            abort(403);
        }
    }

    
    public function store(Request $request)
    {
        $authuser = Auth::user();
        if ($authuser->hradmin !== "yes")
        {
            abort(403);
        }
        else
        {
            $request->validate([
                'name' => 'required|regex:/^[\pL\s]+$/u|min:3|unique:holidays,name',
                'year' => 'required',
                'file' => 'required|mimes:jpeg,png,jpg,pdf|max:3072',
            ],[
                'name.regex' => trans('overtimeerror.regex'), // custom message
            ]);    
            $path = $request->file('file')->storeAs('public/files', $request->name . '.pdf');

            $holiday = new Holiday();
            $holiday->name = $request->name;
            $holiday->year = $request->year;
    
            $holiday->path = $path;
            $holiday->save();
    
            $holiday = Holiday::all();
            return view('admin.holidays.index', ['holidays' => $holiday]);
        }
    }

   
    public function show(Holiday $holiday)
    {
        //
    }

   
    public function edit(Holiday $holiday)
    {
        //
    }

   
    public function update(Request $request, Holiday $holiday)
    {
        //
    }

    
    public function destroy(Holiday $holiday)
    {
        $authuser = Auth::user();
        if ($authuser->hradmin == "yes")
        {
            $file_path = public_path() . '/storage/files/' . $holiday->name . '.pdf';
            unlink($file_path);
            $holiday->delete();
            return redirect()->route('admin.holidays.index');
        }
        else
        {
            abort(403);
        }
    }
}
