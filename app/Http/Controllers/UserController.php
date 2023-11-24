<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Leave;
use App\Models\Leavetype;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\Balance as MailBalance;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends Controller
{
    
    public function index()
    {
        $currentuser = Auth::user();
        $users = User::all()->except(1);
        return view('admin.users.index', ['users' => $users, 'user' => $currentuser]);
        
    }

   
    public function create()
    {
        $users = User::all();
        $contract_enddate = date('Y-12-31');
        return view('admin.users.create', ['users' => $users,'contract_enddate'=>$contract_enddate]);
    }

    
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'employee_number' => 'required|unique:users,employee_number',
            'contract' => 'required',
            'grade' => 'required',
            'position',
            'department',
            'joined_date' => 'required',
            'contract_enddate' => 'required',            
            'linemanager',
            'hradmin',
            'email'  => 'required|email|unique:users,email,NULL,id,deleted_at,NULL',            
        ]);

        $user = User::create($request->validated());
    
        $year = date("Y", strtotime($user->joined_date));
        $day = date("d", strtotime($user->joined_date));
        $month = date("m", strtotime($user->joined_date));
        $datenow = Carbon::now();
        $yearnow = $datenow->year;

        if ($user->contract == 'National') {
            if ($year < $yearnow) {
                $userannualleavebalance = '20';
            } else {

                if ($day < '15') {
                    $userannualleavebalance = (1.67 * (12 - $month + 1));
                }

                else if ($day >= '15') {
                    $userannualleavebalance = ((1.67 * (12 - $month)) + 0.5);
                }
            }
        } else {

            if ($year < $yearnow) {
                $userannualleavebalance = '30';
            } else {

                if ($day < '15') {
                    $userannualleavebalance = (2.5 * (12 - $month + 1));
                }

                else if ($day >= '15') {
                    $userannualleavebalance = ((2.5 * (12 - $month)) + 0.5);
                }
            }
        }
        $annualleavehalfday = $userannualleavebalance * 2;

        $leavetypes = Leavetype::all();
        foreach ($leavetypes as $leavetype) {

            if ($leavetype->name == 'Annual leave') {
                $user->balances()->create([

                    'name' => $leavetype->name,
                    'value' => $userannualleavebalance,
                    'leavetype_id' => $leavetype->id,
                ]);

            } elseif ($leavetype->name == 'Annual leave - First half') {
                $user->balances()->create([

                    'name' => $leavetype->name,
                    'value' => $annualleavehalfday,
                    'leavetype_id' => $leavetype->id,
                ]);

            } elseif ($leavetype->name == 'Annual leave - Second half') {
                $user->balances()->create([

                    'name' => $leavetype->name,
                    'value' => $annualleavehalfday,
                    'leavetype_id' => $leavetype->id,
                ]);
            } else {
                $user->balances()->create([
                    'name' => $leavetype->name,
                    'value' => $leavetype->value,
                    'leavetype_id' => $leavetype->id,
                ]);
            }
        }

        DB::table('users')->where('name', $request->linemanager)->update(['usertype_id' => '2']);

        $request->session()->flash('successMsg', trans('overtimeerror.createsuccess'));
        return redirect()->route('admin.users.show', $user);

    }

    
    public function show(User $user)
    {
        $hruser = Auth::user();
        $staff = User::where('linemanager', $user->name)->get();

        if($user->contract == "International")
        {
            $balances = Balance::where('user_id', $user->id)->get();
                        $subsets = $balances->map(function ($balance) {
                            return collect($balance->toArray())
                
                                ->only(['value', 'leavetype_id'])
                                ->all();
                        });
    
                        $leave1 = $subsets->firstwhere('leavetype_id', '1');
                        $balance1 = round($leave1['value'],3);
    
                        $leave8 = $subsets->firstwhere('leavetype_id', '8');
                        $balance8 = round($leave8['value'],3);
    
                        $leave9 = $subsets->firstwhere('leavetype_id', '9');
                        $balance9 = round($leave9['value'],3);
    
                        $leave12 = $subsets->firstwhere('leavetype_id', '12');
                        $balance12 = round($leave12['value'],3);
    
                        $leave15 = $subsets->firstwhere('leavetype_id', '15');
                        $balance15 = round($leave15['value'],3);
    
                        $leave24 = $subsets->firstwhere('leavetype_id', '24');
                        $balance24 = round($leave24['value'],3);
    
                        $leave25 = $subsets->firstwhere('leavetype_id', '25');
                        $balance25 = round($leave25['value'],3);
                
                        $leave26 = $subsets->firstwhere('leavetype_id', '26');
                        $balance26 = round($leave26['value'],3);
    
                        $leaves = Leave::where('user_id', $user->id)->get();
                        $overtimes = Overtime::where('user_id', $user->id)->get();
                
                        return view('admin.users.show', [
                            'user' => $user,
                            'balance1' => $balance1,
                            'balance8' => $balance8,
                            'balance9' => $balance9,
                            'balance12' => $balance12,
                            'balance15' => $balance15,
                            'balance24' => $balance24,
                            'balance25' => $balance25,
                            'balance26' => $balance26,
                            'leaves'=> $leaves,
                            'overtimes' => $overtimes,
                            'employees'=>$staff,
                        ]);
        }
        else
        {
            $balances = Balance::where('user_id', $user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
            
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $leave1 = $subsets->firstwhere('leavetype_id', '1');
                    $balance1 = round($leave1['value'],3);
            
                    $leave2 = $subsets->firstwhere('leavetype_id', '2');
                    $balance2 = round($leave2['value'],3);
            
                    $leave3 = $subsets->firstwhere('leavetype_id', '3');
                    $balance3 = round($leave3['value'],3);
            
                    $leave4 = $subsets->firstwhere('leavetype_id', '4');
                    $balance4 = round($leave4['value'],3);
            
                    $leave5 = $subsets->firstwhere('leavetype_id', '5');
                    $balance5 = round($leave5['value'],3);
            
                    $leave6 = $subsets->firstwhere('leavetype_id', '6');
                    $balance6 = round($leave6['value'],3);
            
                    $leave7 = $subsets->firstwhere('leavetype_id', '7');
                    $balance7 = round($leave7['value'],3);
            
                    $leave8 = $subsets->firstwhere('leavetype_id', '8');
                    $balance8 = round($leave8['value'],3);
            
                    $leave9 = $subsets->firstwhere('leavetype_id', '9');
                    $balance9 = round($leave9['value'],3);
            
                    $leave10 = $subsets->firstwhere('leavetype_id', '10');
                    $balance10 = round($leave10['value'],3);
            
                    $leave11 = $subsets->firstwhere('leavetype_id', '11');
                    $balance11 = round($leave11['value'],3);
            
                    $leave12 = $subsets->firstwhere('leavetype_id', '12');
                    $balance12 = round($leave12['value'],3);
            
            
                    $leave15 = $subsets->firstwhere('leavetype_id', '15');
                    $balance15 = round($leave15['value'],3);
            
                   
                    $leave18 = $subsets->firstwhere('leavetype_id', '18');
                    $balance18 = round($leave18['value'],3);
        
                    $leaves = Leave::where('user_id', $user->id)->get();
                    $overtimes = Overtime::where('user_id', $user->id)->get();
            
                    return view('admin.users.show', [
                        'user' => $user,
                        'balance1' => $balance1,
                        'balance2' => $balance2,
                        'balance3' => $balance3,
                        'balance4' => $balance4,
                        'balance5' => $balance5,
                        'balance6' => $balance6,
                        'balance7' => $balance7,
                        'balance8' => $balance8,
                        'balance9' => $balance9,
                        'balance10' => $balance10,
                        'balance11' => $balance11,
                        'balance12' => $balance12,
                        'balance15' => $balance15,
                        'balance18' => $balance18,
                        'leaves'=> $leaves,
                        'overtimes' => $overtimes,
                        'employees'=>$staff,
                    ]);
        }

    }

    
    public function edit(User $user)
    {
        $hruser = Auth::user();
        $userss = User::all();
        $hruser = Auth::user();
        return view('admin.users.edit', ['user' => $user,  'hruser' => $hruser, 'userss' => $userss]);
    }

    
    public function update(User $user, Request $request)
    {
        $request->validate([
            'name' => 'required',
            'employee_number' => 'required|unique:users,employee_number,' . $user->id,
            'contract' ,
            'position',
            'office',
            'department',
            'vip',
            'grade'=> 'required',
            'joined_date' => 'required',
            'contract_enddate' => 'required',
            'linemanager',
            'hradmin',
            'email'  => 'required|email|unique:users,email,' .$user->id,
            'password',
        ]);

        $hruser = Auth::user();
        if($user->joined_date !== $request->joined_date)
        {
            $mustchangeannual = '1';
        }
        else if ($user->joined_date == $request->joined_date)
        {
            $mustchangeannual = '2';
        }

        $user->name = $request->name;
        $user->employee_number = $request->employee_number;
        $user->birth_date = $request->birth_date;
        $user->contract = $request->contract;
        $user->position = $request->position;
        if ($hruser->office == "AO2")
        {
            $user->office = $request->office;
        }
        $user->department = $request->department;
        $user->grade = $request->grade;
        $user->linemanager = $request->linemanager;
        $user->joined_date = $request->joined_date;
        $user->contract_enddate = $request->contract_enddate;
        if ($hruser->superadmin == "yes")
        {
            $user->hradmin = $request->hradmin;
        }
        
        $user->email = $request->email;

        if (isset($request->password)) 
        {
            $user->password = Hash::make($request->password);
        }

        $user->save();


        $checkifuserhasleave = Leave::where([
        ['user_id', $user->id],
        ])->get();


        if ($checkifuserhasleave->isEmpty() && $user->id > 390 && $mustchangeannual == "1") {

            $yearr = date('Y', strtotime($user->joined_date));
            $dayy = date('d', strtotime($user->joined_date));
            $monthh = date('m', strtotime($user->joined_date));
            $datenoww = Carbon::now();
            $yearnoww = $datenoww->year;
          

            if ($user->contract == 'National') {
                if ($yearr < $yearnoww) {
                    $userannualleavebalancee = '21';
                } else {
                    if ($dayy < '15') {
                        $userannualleavebalancee = (1.67 * (12 - $monthh + 1));
                    }
                    if ($dayy >= '15') {
                        $userannualleavebalancee = ((1.67 * (12 - $monthh)) + 0.5);
                    }
                }
            } else {
                if ($yearr < $yearnoww) {
                    $userannualleavebalancee = '30';
                } else {
                    if ($dayy < '15') {
                        $userannualleavebalancee = (2.5 * (12 - $monthh + 1));
                    }
                    if ($dayy >= '15') {
                        $userannualleavebalancee = ((2.5 * (12 - $monthh)) + 0.5);
                    }
                }
            }

            $annualleavehalfdayy = $userannualleavebalancee * 2;

            $user->balances()->where('name', 'Annual leave')->update([
                'value' => $userannualleavebalancee,
            ]);

            $user->balances()->where('name', 'Annual leave - First half')->update([
                'value' => $annualleavehalfdayy,
            ]);

            $user->balances()->where('name', 'Annual leave - Second half')->update([
                'value' => $annualleavehalfdayy,
            ]);
        }

        DB::table('users')->where('name', $request->linemanager)->update(['usertype_id' => '2']);
        $request->session()->flash('successMsg', trans('overtimeerror.updatesuccess'));
        return redirect()->route('admin.users.show', $user);
    }

    
    public function destroy(User $user)
    {
        $user->leaves()->delete();
        $user->overtimes()->delete();
        $user->balances()->delete();
        $user->email = $user->email . '_deleted' . $user->id;
        $user->employee_number = $user->employee_number . '_deleted' . $user->id;
        $user->save();
        $user->delete();
        return redirect()->route('admin.users.index');
    }

    public function removesuspend($id)
    {
        $user = User::find($id);
        $user->status = 'active';
        $user->save();
        return redirect()->route('admin.users.index');
    }

    public function suspend($id)
    {
        $user = User::find($id);
        $user->status = 'suspended';
        $user->save();
        return redirect()->route('admin.users.show', $user);

    }

    public function balanceedit(User $user)
    {

        $hruser = Auth::user();
        if ($hruser->superadmin !== 'yes') {
            abort(403);

        } else {
            if($user->contract == "International")
            {
                $balances = Balance::where('user_id', $user->id)->get();
                $subsets = $balances->map(function ($balance) {
                    return collect($balance->toArray())
        
                        ->only(['value', 'leavetype_id'])
                        ->all();
                });

                $leave1 = $subsets->firstwhere('leavetype_id', '1');
                $balance1 = round($leave1['value'],3);

                $leave8 = $subsets->firstwhere('leavetype_id', '8');
                $balance8 = round($leave8['value'],3);

                $leave9 = $subsets->firstwhere('leavetype_id', '9');
                $balance9 = round($leave9['value'],3);

                $leave12 = $subsets->firstwhere('leavetype_id', '12');
                $balance12 = round($leave12['value'],3);

                $leave15 = $subsets->firstwhere('leavetype_id', '15');
                $balance15 = round($leave15['value'],3);

                $leave24 = $subsets->firstwhere('leavetype_id', '24');
                $balance24 = round($leave24['value'],3);

                $leave25 = $subsets->firstwhere('leavetype_id', '25');
                $balance25 = round($leave25['value'],3);
        
                $leave26 = $subsets->firstwhere('leavetype_id', '26');
                $balance26 = round($leave26['value'],3);

        
                return view('admin.users.balanceedit', [
                    'user' => $user,
                    'balance1' => $balance1,
                    'balance8' => $balance8,
                    'balance9' => $balance9,
                    'balance12' => $balance12,
                    'balance15' => $balance15,
                    'balance24' => $balance24,
                    'balance25' => $balance25,
                    'balance26' => $balance26,
                ]);
            }
            else
            {
                
            $balances = Balance::where('user_id', $user->id)->get();
            $subsets = $balances->map(function ($balance) {
                return collect($balance->toArray())
    
                    ->only(['value', 'leavetype_id'])
                    ->all();
            });
            $leave1 = $subsets->firstwhere('leavetype_id', '1');
            $balance1 = $leave1['value'];
    
            $leave2 = $subsets->firstwhere('leavetype_id', '2');
            $balance2 = $leave2['value'];
    
            $leave3 = $subsets->firstwhere('leavetype_id', '3');
            $balance3 = $leave3['value'];
    
            $leave4 = $subsets->firstwhere('leavetype_id', '4');
            $balance4 = $leave4['value'];
    
            $leave5 = $subsets->firstwhere('leavetype_id', '5');
            $balance5 = $leave5['value'];
    
            $leave6 = $subsets->firstwhere('leavetype_id', '6');
            $balance6 = $leave6['value'];
    
            $leave7 = $subsets->firstwhere('leavetype_id', '7');
            $balance7 = $leave7['value'];
    
            $leave8 = $subsets->firstwhere('leavetype_id', '8');
            $balance8 = $leave8['value'];
    
            $leave9 = $subsets->firstwhere('leavetype_id', '9');
            $balance9 = $leave9['value'];
    
            $leave10 = $subsets->firstwhere('leavetype_id', '10');
            $balance10 = $leave10['value'];
    
            $leave11 = $subsets->firstwhere('leavetype_id', '11');
            $balance11 = $leave11['value'];
    
            $leave12 = $subsets->firstwhere('leavetype_id', '12');
            $balance12 = $leave12['value'];
    
            // $leave13 = $subsets->firstwhere('leavetype_id', '13');
            // $balance13 = $leave13['value'];
    
            // $leave14 = $subsets->firstwhere('leavetype_id', '14');
            // $balance14 = $leave14['value'];
    
            $leave15 = $subsets->firstwhere('leavetype_id', '15');
            $balance15 = $leave15['value'];
    
            // $leave16 = $subsets->firstwhere('leavetype_id', '16');
            // $balance16 = $leave16['value'];
    
            // $leave17 = $subsets->firstwhere('leavetype_id', '17');
            // $balance17 = $leave17['value'];
            $leave18 = $subsets->firstwhere('leavetype_id', '18');
            $balance18 = $leave18['value'];
    


        

            return view('admin.users.balanceedit', [
                'user' => $user,
                'balance1' => $balance1,
                'balance2' => $balance2,
                'balance3' => $balance3,
                'balance4' => $balance4,
                'balance5' => $balance5,
                'balance6' => $balance6,
                'balance7' => $balance7,
                'balance8' => $balance8,
                'balance9' => $balance9,
                'balance10' => $balance10,
                'balance11' => $balance11,
                'balance12' => $balance12,
                'balance15' => $balance15,
                'balance18' => $balance18,
            ]);
            }

        }

    }

    public function balanceupdate(Request $request, User $user)
    {
        $hruser = Auth::user();
        if ($hruser->superadmin !== 'yes')
        {
            abort(403);
        }
        else
        {  
            if ($user->contract == "International")
            {
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '1'],
                ])->first()?->update(['value' => $request->annual_leave]);
    
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '8'],
                ])->first()?->update(['value' => $request->maternity_leave]);
    
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '24'],
                ])->first()?->update(['value' => $request->homeleave]);
    
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '9'],
                ])->first()?->update(['value' => $request->paternity_leave]);
    
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '25'],
                ])->first()?->update(['value' => $request->rr]);
    
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '12'],
                ])->first()?->update(['value' => $request->welfare_leave]);
    
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '26'],
                ])->first()?->update(['value' => $request->sick_leave_sc]);
    
    
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '15'],
                ])->first()?->update(['value' => $request->unpaid_leave]);
            }
            else
            {
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '1'],
                ])->first()?->update(['value' => $request->annual_leave]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '2'],
                ])->first()?->update(['value' => $request->sick_leave]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '3'],
                ])->first()?->update(['value' => $request->sick_leave30]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '4'],
                ])->first()?->update(['value' => $request->sick_leave20]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '5'],
                ])->first()?->update(['value' => $request->marriage_leave]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '6'],
                ])->first()?->update(['value' => $request->compassion_first]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '7'],
                ])->first()?->update(['value' => $request->compassion_second]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '8'],
                ])->first()?->update(['value' => $request->maternity_leave]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '9'],
                ])->first()?->update(['value' => $request->paternity_leave]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '12'],
                ])->first()?->update(['value' => $request->welfare_leave]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '15'],
                ])->first()?->update(['value' => $request->unpaid_leave]);
        
                Balance::where([
                    ['user_id', $user->id],
                    ['leavetype_id', '18'],
                ])->first()?->update(['value' => $request->compansention]);
            }
             
            $emailme = "danial.janboura@nrc.no";
            $timeofchange = date('Y-m-d H:i:s');
            $details = [
                'hrname' => $hruser->name,
                'staffaffected' => $user->name,
                'timeofchange' => $timeofchange,
                'reason' => $request->reason,
            ];
            Mail::to($emailme)->send(new MailBalance($details));       
            $request->session()->flash('successMsg', trans('overtimeerror.balancesuccess'));
            return redirect()->route('admin.users.show', $user);
        }
       

    }

    public function export()
    {
        $user = Auth::user();
        if ($user->hradmin !== 'yes')
        {
            abort(403);
        }
        else
        {
            $users = User::all()->except(1);
            return Excel::download(new UsersExport($users), 'users.xlsx');
        }
    }


    public function balanceexport(Request $request)
    {

        $request->validate([
            'start_date',
            'name',
        ]);
        $hruser = Auth::user();

        $name= $request->name;
        $contract = $request->contract;
        $start_date=$request->start_date;
        $staffstatus = $request->staffstatus;
        $linemanager = $request->linemanager;

        if ($start_date == Null)
        {
            $start_datee = "2000-01-01";
        }

        else if ($start_date !== Null)
        {
            $start_datee = $start_date;
        }

        if ($staffstatus == Null)
        {
            $staffstatuse = ['active','suspended'];
        }

        else if ($staffstatus !== Null)
        {
            $staffstatuse = $staffstatus;
        }

        if ($contract == Null)
        {
            $contracte = ['National', 'International', 'NA'];
        }

        else if ($contract !== Null)
        {
            $contracte = $contract;
        }


        if ($request->name == null)
        {   
            $staffwithstatus = User::WhereIn('status', $staffstatuse)->get();
            if (count($staffwithstatus))
            {
                $hrsubsets = $staffwithstatus->map(function ($staffwithstatus) {
                    return collect($staffwithstatus->toArray())
                        ->only(['id'])
                        ->all();
                });
                $users = User::whereIn('id', $hrsubsets)->where([
                    ['joined_date', '>=', $start_datee],
            
                ])->WhereIn('contract', $contracte)->get();
            }
        }
        else
        {
            $userid = User::where('name',$name)->value('id');
            $users = User::where([
                ['id', $userid],
                ['joined_date', '>=', $start_datee],  
            ])->WhereIn('contract', $contracte)->get();
        }

        if ($linemanager !== Null)
        {
            $staff = User::where('linemanager', $linemanager)->get();
            if (count($staff))
            {
            $subsets = $staff->map(function ($staff) {
                return collect($staff->toArray())

                    ->only(['id'])
                    ->all();
            });

            $users = User::whereIn('id', $subsets)->where([
                ['joined_date', '>=', $start_datee],
            ])->WhereIn('contract', $contracte)->get();
        }

        else {
            $users = User::where([
                ['joined_date', '>=', $start_datee],
            ])->WhereIn('contract', $contracte)->Where('status', "nothing to show")->get();
        }
        }

        return Excel::download(new BalanceExport($users), 'balances.xlsx');
    }

    public function import(Request $request)
    {
        $file = $request->file('file');

        Excel::import(new UsersImport, $request->file('file'));
       
    }

    public function importshow()
    {
        return view('admin.users.importshow');
    }

    public function createbalance()
    {
        $users = User::all()->except(1);
        $leavetypes = Leavetype::all();
        foreach ($users as $user) {
            foreach ($leavetypes as $leavetype) {
                $user->balances()->create([
                    'name' => $leavetype->name,
                    'value' => $leavetype->value,
                    'leavetype_id' => $leavetype->id,
                ]);
            }
        }
        return redirect()->route('admin.users.index');
    }

    public function search(Request $request)
    {

        $request->validate([
            'start_date',
            'name',
        ]);

        $hruser = Auth::user();

        $name = $request->name;
        $contract = $request->contract;
        $start_date = $request->start_date;

        $staffstatus = $request->staffstatus;
        $linemanager = $request->linemanager;
 

        if ($start_date == null) {
            $start_datee = '2000-01-01';
        } elseif ($start_date !== null) {
            $start_datee = $start_date;
        }

        if ($staffstatus == null) {
            $staffstatuse = ['active', 'suspended'];
        } elseif ($staffstatus !== null) {
            $staffstatuse = $staffstatus;
        }

        if ($contract == null) {
            $contracte = ['Natioanl', 'International', 'NA'];
        } elseif ($contract !== null) {
            $contracte = $contract;
        }

        if ($request->name == null) {

            $staffwithsameoffice = User::WhereIn('status', $staffstatuse)->get();
            if (count($staffwithsameoffice))
            {
                $hrsubsets = $staffwithsameoffice->map(function ($staffwithsameoffice) {
                    return collect($staffwithsameoffice->toArray())
                        ->only(['id'])
                        ->all();
                });
                $users = User::whereIn('id', $hrsubsets)->where([
                    ['joined_date', '>=', $start_datee],
                ])->WhereIn('contract', $contracte)->get();
                
            }  

        } else {
            $userid = User::where('name', $name)->value('id');
            $users = User::where([
                ['id', $userid],
                ['joined_date', '>=', $start_datee],
            ])->WhereIn('contract', $contracte)->get();
        }

        if ($linemanager !== null) {
            $staff = User::where('linemanager', $linemanager)->get();
            if (count($staff)) {
                $subsets = $staff->map(function ($staff) {
                    return collect($staff->toArray())

                        ->only(['id'])
                        ->all();
                });
                $users = User::whereIn('id', $subsets)->where([
                    ['joined_date', '>=', $start_datee],
                ])->WhereIn('contract', $contracte)->get();
            } else {
                $users = User::where([
                   ['joined_date', '>=', $start_datee],
                ])->WhereIn('contract', $contracte)->Where('status', 'nothing to show')->get();
            }

        }

        switch ($request->input('action')) {
            case 'view':
                return view('admin.users.search', ['users' => $users, 'name' => $name, 'start_date' => $start_datee]);
                break;

            case 'excel':
                return Excel::download(new UsersExport($users), 'users.xlsx');
                break;
        }

    }







    
}
