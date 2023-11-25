<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PolicyController extends Controller
{
    
    public function index()
    {
        $policy = Policy::all();
        return view('admin.policies.index', ['policies' => $policy]);
    }

    public function create()
    {
        $authuser = Auth::user();
        if ($authuser->hradmin == "yes")
        {
            return view('admin.policies.create');
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

                'name' => 'required|regex:/^[\pL\s]+$/u|min:3|unique:policies,name',
                'desc',
                'created_date' => 'required',
                'lastupdate_date' => 'required',
                'file' => 'required|mimes:jpeg,png,jpg,pdf|max:3072',
            ],[
                'name.regex' => trans('overtimeerror.regex'), // custom message
            ]);
    
            $path = $request->file('file')->storeAs('public/files', $request->name . '.pdf');
    
            $policy = new Policy();
            $policy->name = $request->name;
            $policy->desc = $request->desc;
            $policy->created_date = $request->created_date;
            $policy->lastupdate_date = $request->lastupdate_date;
            $policy->path = $path;
            $policy->save();
    
            $policy = Policy::all();
            return view('admin.policies.index', ['policies' => $policy]);
        }
    }

    public function show(Policy $policy)
    {
        //
    }

   
    public function edit(Policy $policy)
    {
        //
    }

    
    public function update(Request $request, Policy $policy)
    {
        //
    }

   
    public function destroy(Policy $policy)
    {
        $authuser = Auth::user();
        if ($authuser->hradmin == "yes")
        {
             // File::delete($policy->name);
             // File::delete(public_path("files/{{$policy->name}} . '.pdf'"));
          $file_path = public_path() . '/storage/files/' . $policy->name . '.pdf';
          unlink($file_path);
          $policy->delete();
          return redirect()->route('admin.policies.index');
        }
        else
        {
            abort(403);
        }
    }
}
