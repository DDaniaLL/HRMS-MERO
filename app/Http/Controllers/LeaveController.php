<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Exports\LeavesExport;
use App\Models\Leave;
use App\Models\Leavetype;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use DateInterval;
use DatePeriod;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\Leave as MailLeave;
use App\Mail\Leaveafterlm as MailLeaveafterlm;
use App\Mail\Leavefinal as MailLeavefinal;
use App\Mail\Leaverejected as MailLeaverejected;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class LeaveController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();
        $leave = Leave::where('user_id', $user->id)->get();
        return view('leaves.index', ['leaves' => $leave]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $leavetypes = Leavetype::all();
        return view('leaves.create', ['leavetypes' => $leavetypes]);
    }

    
    public function store(Request $request)
    {
        $user = Auth::user();
        //balance of annual half first and second half (13) + (14) is from annual leave (1)
        if ($request->leavetype_id == '13' || $request->leavetype_id == '14') {

            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', '1');
            
            $finalfinal = $final['value'];
            $annualleavebalance = $finalfinal;

        }
        //balance of unpaid half first and second (16) + (17) is from unpaid leave (15)
        elseif ($request->leavetype_id == '16' || $request->leavetype_id == '17') {

            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', '15');
            $finalfinal = $final['value'];
            $unpaidleavebalance = $finalfinal;

        }
        //balance of sick leave half first and second (20) + (21) is from sick leave (2)
        elseif ($request->leavetype_id == '20' || $request->leavetype_id == '21') {

            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', '2');
            $finalfinal = $final['value'];
            $sickhalfleavebalance = $finalfinal;

        }

        elseif ($request->leavetype_id == '22' || $request->leavetype_id == '23') {

            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', '3');
            $finalfinal = $final['value'];
            $sick30halfleavebalance = $finalfinal;

        }

        //balance of comp hour (19) is from comp (18) after multiplying by 8
        elseif ($request->leavetype_id == '19') {

            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', '18');
            $finalfinal = $final['value'];
            $comphalfleavebalance = $finalfinal * 8;

        } else {
            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', $request->leavetype_id);
            $finalfinal = $final['value'];
            $currentbalance = $finalfinal;
        }

        $request->validate([
            'start_date' => 'required',
            'end_date' => 'required|after_or_equal:start_date',
            'leavetype_id' => 'required',
            'reason',
            'hours' => 'nullable|numeric|max:7',
            'file' => 'nullable|mimes:jpeg,png,jpg,pdf|max:3072',
        
        ],
        [
            'start_date.required'=> trans('leaveerror.startdatereq'), // custom message
            'end_date.required'=> trans('leaveerror.enddatereq'), // custom message
            'end_date.after_or_equal'=> trans('leaveerror.afterorequal'), // custom message
            'file.mimes'=> trans('leaveerror.mimes'), // custom message
            'file.max'=> trans('leaveerror.max'), // custom message
            'hours.max'=> trans('leaveerror.hmax'), // custom message
        ]
        );

        $nrcholidays = [
            '2023-01-01',
            '2023-03-21',
            '2023-04-09',
            '2023-04-16',
            '2023-04-17',
            '2023-04-23',
            '2023-04-24',
            '2023-04-25',
            '2023-05-01',
            '2023-06-28',
            '2023-06-29',
            '2023-06-30',
            '2023-07-01',
            '2023-07-02',
            '2023-07-03',
            '2023-07-19',
            '2023-09-28',
            '2023-10-08',
            '2023-12-25',
        ];
        
        $dateRange = CarbonPeriod::create($request->start_date, $request->end_date);
        $dates = array_map(fn ($date) => $date->format('Y-m-d'), iterator_to_array($dateRange));
        $datess = array_values($dates);
        $numberofmatches = count($matches=array_intersect($nrcholidays,$datess));
        $hours = $request->hours ;

        $fdate = $request->start_date;
        $ldate = $request->end_date;

        $start = new DateTime($fdate);
        $end = new DateTime($ldate);
        // otherwise the  end date is excluded (bug?)
        $end->modify('+1 day');

        $interval = $end->diff($start);

        // total days
        $sickpercentagedays = $interval->days;
        $days = $interval->days;
        
        // create an iterateable period of date (P1D equates to 1 day)
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        foreach ($period as $dt) {
            $curr = $dt->format('D');
            // substract if Saturday or Sunday
            if ($curr == 'Sat' || $curr == 'Fri') {
                $days--;
            }
        }
        $dayswithoutholidays = $days - $numberofmatches;
        $datenow = Carbon::now();
        $joineddate = new DateTime($user->joined_date);
        $dateenow = new DateTime($datenow);
        $intervall = $joineddate->diff($dateenow);
        $probationdays = $intervall->format('%a');

        $startdayname = Carbon::parse($fdate)->format('l');
        $enddayname = Carbon::parse($ldate)->format('l');

        // Annual leave conditions
        if ($request->leavetype_id == '1') {

            if ($probationdays >= '90' OR $user->contract == "International") {
           
                if ($user->contract == "International")
                {
                    $xxx = $currentbalance + 2.5;
                }
                else
                {
                    $xxx = $currentbalance;
                }

                if ($dayswithoutholidays <= $xxx) {


                    $leavessubmitted = Leave::where([
                        ['user_id', $user->id],
                        ['leavetype_id', '!=', '24'],
                        // ['start_date', $request->start_date],
                        ])->where(function($query) use ($request) {
                            $query->whereBetween('start_date', [$request->start_date,$request->end_date])
                        ->orWhereBetween('end_date', [$request->start_date,$request->end_date]);
                })->where(function($query) {
                            $query->where('status','Pending LM Approval')
                                        ->orWhere('status','Pending HR Approval')
                                        ->orWhere('status','Approved');
                })->get();


                $leavessubmittedcase2 = Leave::where([
                    ['user_id', $user->id],
                    ['leavetype_id','!=', '24'],
                    // ['start_date', $request->start_date],
                    ])->where(function($query) use ($request) {
                        $query->whereRaw('"'.$request->start_date.'" between `start_date` and `end_date`')
                        ->orwhereRaw('"'.$request->end_date.'" between `start_date` and `end_date`');       
                 })->where(function($query) {
                        $query->where('status','Pending LM Approval')
                                    ->orWhere('status','Pending HR Approval')
                                    ->orWhere('status','Approved');
                })->get();


                    $counted = count($leavessubmitted);
                    $countedcase2 = count($leavessubmittedcase2);

                    if($counted + $countedcase2 > 0)
                    {
                        return redirect()->back()->with("error", trans('leaveerror.sameday'));
                    }

                    else
                    {                    
                        
                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }

                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }
                    $leave->days = $dayswithoutholidays;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                        $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);                        
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');

                    }
  

                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with("error", trans('leaveerror.prob'));
            }
        }

        //sick leave
        elseif ($request->leavetype_id == '2') {
            if ($days <= $currentbalance) {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }
                if ($days > '1' and $request->hasFile('file') == null) {
                    return redirect()->back()->with("error",trans('leaveerror.attachment'));
                }

               $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
            {
                return redirect()->back()->with("error", trans('leaveerror.sameday'));
            }

                else
                {

                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = $dayswithoutholidays;
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';
                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');
            }

            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }
        }


        //sick leave half day
        elseif ($request->leavetype_id == '20' || $request->leavetype_id == '21') {
            if ($sickhalfleavebalance >= '0.5') {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }
                $leavessubmitted = Leave::where([
                    ['user_id', $user->id],
                    ['leavetype_id','!=', '13'],
                    ['leavetype_id','!=', '14'],
                    ['leavetype_id','!=', '16'],
                    ['leavetype_id','!=', '17'],
                    ['leavetype_id','!=', '19'],
                    ['leavetype_id','!=', '20'],
                    ['leavetype_id','!=', '21'],
                    ['leavetype_id','!=', '22'],
                    ['leavetype_id','!=', '23'],
                    // ['start_date', $request->start_date],
                    ])
                    // ->whereBetween(
                    //     'start_date', [$request->start_date,$request->end_date]
                    // )->orWhereBetween(
                    //     'end_date', [$request->start_date,$request->end_date]
                    // )
                    ->whereRaw(
                        '"'.$request->start_date.'" between `start_date` and `end_date`'
                    )->whereRaw(
                        '"'.$request->end_date.'" between `start_date` and `end_date`'
                    )->where(function($query) {
                        $query->where('status','Pending LM Approval')
                                    ->orWhere('status','Pending HR Approval')
                                    ->orWhere('status','Approved');
                    })->get();

                // for not submitting two halfdays from same type (first or second) on the same day
                 $leavessubmittedcase2 = Leave::where([
                ['user_id', $user->id],
                ['leavetype_id', $request->leavetype_id],
                // ['start_date', $request->start_date],
                ])->whereRaw(
                    '"'.$request->start_date.'" between `start_date` and `end_date`'
                )->whereRaw(
                    '"'.$request->end_date.'" between `start_date` and `end_date`'
                )->where(function($query) {
                    $query->where('status','Pending LM Approval')
                                ->orWhere('status','Pending HR Approval')
                                ->orWhere('status','Approved');
                     })->get();


                $counted = count($leavessubmitted);
                $countedcase2 = count($leavessubmittedcase2);

                // dd($counted);

                if($counted + $countedcase2 > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }

                else
                {

                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = '0.5';
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';
                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                // $user->notify(new EmailNotification($leave));
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');
            }

            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }
        }


        //sick 30 leave
        elseif ($request->leavetype_id == '3') {

            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', '2');
            $finalfinal = $final['value'];
            $sickhalfleavebalance = $finalfinal;

            if($sickhalfleavebalance > 0)
            {
                return redirect()->back()->with("error",trans('leaveerror.sicknotzero'));
            }

            else if ($days <= $currentbalance) {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.attachment'));
                }

            
            $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
            {
                return redirect()->back()->with("error", trans('leaveerror.sameday'));
            }


                else
                {

                
                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = $sickpercentagedays;
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';
                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                // $user->notify(new EmailNotification($leave));
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');

            }

            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }
        }


        //sick leave 30% half day
        elseif ($request->leavetype_id == '22' || $request->leavetype_id == '23') {

            if($sickhalfleavebalance > 0)
            {
                return redirect()->back()->with("error",trans('leaveerror.sicknotzero'));
            }


            else if ($sick30halfleavebalance >= '0.5') {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }
                $leavessubmitted = Leave::where([
                    ['user_id', $user->id],
                    ['leavetype_id','!=', '13'],
                    ['leavetype_id','!=', '14'],
                    ['leavetype_id','!=', '16'],
                    ['leavetype_id','!=', '17'],
                    ['leavetype_id','!=', '19'],
                    ['leavetype_id','!=', '20'],
                    ['leavetype_id','!=', '21'],
                    ['leavetype_id','!=', '22'],
                    ['leavetype_id','!=', '23'],
                    // ['start_date', $request->start_date],
                    ])
                    // ->whereBetween(
                    //     'start_date', [$request->start_date,$request->end_date]
                    // )->orWhereBetween(
                    //     'end_date', [$request->start_date,$request->end_date]
                    // )
                    ->whereRaw(
                        '"'.$request->start_date.'" between `start_date` and `end_date`'
                    )->whereRaw(
                        '"'.$request->end_date.'" between `start_date` and `end_date`'
                    )->where(function($query) {
                        $query->where('status','Pending LM Approval')
                                    ->orWhere('status','Pending HR Approval')
                                    ->orWhere('status','Approved');
                        })->get();

                // for not submitting two halfdays from same type (first or second) on the same day
                 $leavessubmittedcase2 = Leave::where([
                ['user_id', $user->id],
                ['leavetype_id', $request->leavetype_id],
                // ['start_date', $request->start_date],
                ])->whereRaw(
                    '"'.$request->start_date.'" between `start_date` and `end_date`'
                )->whereRaw(
                    '"'.$request->end_date.'" between `start_date` and `end_date`'
                )->where(function($query) {
                    $query->where('status','Pending LM Approval')
                                ->orWhere('status','Pending HR Approval')
                                ->orWhere('status','Approved');
                })->get();


                $counted = count($leavessubmitted);
                $countedcase2 = count($leavessubmittedcase2);

                // dd($counted);

                if($counted + $countedcase2 > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }

                else
                {

                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = '0.5';
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';
                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                // $user->notify(new EmailNotification($leave));
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');
            }

            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }
        }


        //sick 20 leave
        elseif ($request->leavetype_id == '4') {

            $subsets = $this->getBalancesForUser($user);
            $final = $subsets->firstwhere('leavetype_id', '2');
            $finalfinal = $final['value'];
            $sickhalfleavebalance = $finalfinal;

            if($sickhalfleavebalance > 0)
            {
                return redirect()->back()->with("error",trans('leaveerror.sicknotzero'));
            }

            else if ($days <= $currentbalance) {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.attachment'));
                }

 


            $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
            {
                return redirect()->back()->with("error", trans('leaveerror.sameday'));
            }


                else
                {

                
                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = $sickpercentagedays;
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';
                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                // $user->notify(new EmailNotification($leave));
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');

            }
            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }
        }

        // Marriage leave conditions
        elseif ($request->leavetype_id == '5') {

            if ($probationdays >= '180') {

                if ($days <= $currentbalance) {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }

    
                 $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }

                    else

                    {

                    
                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = $days;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                    $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');

                    }
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with("error",trans('leaveerror.sixmonthserv'));
            }

        }

        // Maternity leave coditions
        elseif ($request->leavetype_id == '8') {

            if ($probationdays >= '180') {

                if ($days <= $currentbalance) {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }


                //     $leavessubmitted = Leave::where([
                //         ['user_id', $user->id],
                //         ['start_date', $request->start_date],
                //         ])->where(function($query) {
                //             $query->where('status','Pending LM Approval')
                //                         ->orWhere('status','Pending HR Approval')
                //                         ->orWhere('status','Approved');
                // })->get();
    
                 $calculation = $this->checkForCrossDays($user, $request);

                 if($calculation[0] + $calculation[1] > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }



                    else

                    {
                    


                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = $sickpercentagedays;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                        $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');
                }
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with("error",trans('leaveerror.sixmonthserv'));
            }
        }

        // islamic leave coditions
        elseif ($request->leavetype_id == '10') {

            if ($probationdays >= '1825') {

                if ($days <= $currentbalance) {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }


                //     $leavessubmitted = Leave::where([
                //         ['user_id', $user->id],
                //         ['start_date', $request->start_date],
                //         ])->where(function($query) {
                //             $query->where('status','Pending LM Approval')
                //                         ->orWhere('status','Pending HR Approval')
                //                         ->orWhere('status','Approved');
                // })->get();
    
                 $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }


                    else

                    {


                    
                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = $days;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');
                }
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with("error", trans('leaveerror.fiveyearserv'));
            }
        }

        // christ leave coditions
        elseif ($request->leavetype_id == '11') {

            if ($probationdays >= '1825') {

                if ($days <= $currentbalance) {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }
                
                 $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }

                    else
                    {                  
                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = $days;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');

                }
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with("error", trans('leaveerror.fiveyearserv'));
            }
        }

        // welfare leave coditions
        elseif ($request->leavetype_id == '12') {

            if ($days <= $currentbalance) {
                if ($days <= '3') {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }
    
                 $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }
                    else
                    {
                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = $days;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                    $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');
                }

                } else {
                    return redirect()->back()->with("error", trans('leaveerror.welfarethree'));
                }
            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }

        }

        // Annual leave halfday coditions
        elseif ($request->leavetype_id == '13' || $request->leavetype_id == '14') {

            if ($probationdays >= '90') {

                if ($annualleavebalance >= '0.5') {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }
                $leavessubmitted = Leave::where([
                    ['user_id', $user->id],
                    ['leavetype_id','!=', '24'],
                    ['leavetype_id','!=', '13'],
                    ['leavetype_id','!=', '14'],
                    ['leavetype_id','!=', '16'],
                    ['leavetype_id','!=', '17'],
                    ['leavetype_id','!=', '19'],
                    ['leavetype_id','!=', '20'],
                    ['leavetype_id','!=', '21'],
                    ['leavetype_id','!=', '22'],
                    ['leavetype_id','!=', '23'],
                    // ['start_date', $request->start_date],
                    ])
                    // ->whereBetween(
                    //     'start_date', [$request->start_date,$request->end_date]
                    // )->orWhereBetween(
                    //     'end_date', [$request->start_date,$request->end_date]
                    // )
                    ->whereRaw(
                        '"'.$request->start_date.'" between `start_date` and `end_date`'
                    )->whereRaw(
                        '"'.$request->end_date.'" between `start_date` and `end_date`'
                    )->where(function($query) {
                        $query->where('status','Pending LM Approval')
                                    ->orWhere('status','Pending HR Approval')
                                    ->orWhere('status','Approved');
                  })->get();

                    // for not submitting two halfdays from same type (first or second) on the same day
                $leavessubmittedcase2 = Leave::where([
                ['user_id', $user->id],
                ['leavetype_id', $request->leavetype_id],
                // ['start_date', $request->start_date],
                ])->whereRaw(
                    '"'.$request->start_date.'" between `start_date` and `end_date`'
                )->whereRaw(
                    '"'.$request->end_date.'" between `start_date` and `end_date`'
                )->where(function($query) {
                    $query->where('status','Pending LM Approval')
                                ->orWhere('status','Pending HR Approval')
                                ->orWhere('status','Approved');
                 })->get();


                $counted = count($leavessubmitted);
                $countedcase2 = count($leavessubmittedcase2);

                // dd($counted);

                if($counted + $countedcase2 > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }
                    else
                    {
                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = '0.5';
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';
                    } else {
                        $leave->status = 'Pending LM Approval';

                    $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');

                }
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with("error", trans('leaveerror.prob'));
            }
        }

        // comp leave hours coditions
        elseif ($request->leavetype_id == '19') {

            if ($hours <= $comphalfleavebalance) {         

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }
 

            $leavessubmitted = Leave::where([
            ['user_id', $user->id],
            ['leavetype_id','!=', '13'],
            ['leavetype_id','!=', '14'],
            ['leavetype_id','!=', '16'],
            ['leavetype_id','!=', '17'],
            ['leavetype_id','!=', '19'],
            ['leavetype_id','!=', '20'],
            ['leavetype_id','!=', '21'],
                // ['start_date', $request->start_date],
                ])->whereRaw(
                        '"'.$request->start_date.'" between `start_date` and `end_date`'
                    )->whereRaw(
                        '"'.$request->end_date.'" between `start_date` and `end_date`'
                    )->where(function($query) {
                    $query->where('status','Pending LM Approval')
                                ->orWhere('status','Pending HR Approval')
                                ->orWhere('status','Approved');
                })->get();

                      $counted = count($leavessubmitted);
                     if($counted > 0)
                 {
                return redirect()->back()->with("error", trans('leaveerror.sameday'));
                 }
                else
                {

                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }
    
                    $leave->hours = $hours;
                    $leave->days = $leave->hours / 8;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';
    
                    } else {
    
                        $leave->status = 'Pending LM Approval';
                        $linemanageremail = User::where('name',$user->linemanager)->value('email');
    
                            
                            $details = [
                                'requestername' => $user->name,
                                'linemanagername' => $user->linemanager,
                                'linemanageremail' => $linemanageremail,
                                'title' => 'Leave Request Approval - '.$leave->leavetype->name,
                                'leavetype' => $leave->leavetype->name,
                                'startdayname' => $startdayname,
                                'start_date' => $leave->start_date,
                                'enddayname' => $enddayname,
                                'end_date' =>  $leave->end_date,
                                'days' => $leave->days,
                                'comment' =>  $leave->reason
                            ];
                           
                            Mail::to($linemanageremail)->send(new MailLeave($details));
                    }
    
                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');
                }


            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }

        }
                //compensation full day
        elseif ($request->leavetype_id == '18') {

            // $days <= $currentbalance

            if ($days <= $currentbalance) {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }

    
            $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
            {
                return redirect()->back()->with("error", trans('leaveerror.sameday'));
            }
                else
                {
               
                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = $days;
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';
                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');
            }
            } else {
                return redirect()->back()->with("error",trans('leaveerror.nobalance'));
            }

        }

        // Paternity leave coditions
        elseif ($request->leavetype_id == '9') {

            if ($probationdays >= '180') {

                if ($days <= $currentbalance) {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }

        
    
                 $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }

                    else
                    {


                  
                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = $days;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';

                    $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');

                }
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with("error",trans('leaveerror.sixmonthserv'));
            }

        }

        //unpaid halfday
        elseif ($request->leavetype_id == '16' || $request->leavetype_id == '17') {

           

                if ($unpaidleavebalance >= '0.5') {

                    if ($request->hasFile('file')) {
                        $path = $request->file('file')->store('public/leaves');
                    }

                $leavessubmitted = Leave::where([
                    ['user_id', $user->id],
                    ['leavetype_id','!=', '13'],
                    ['leavetype_id','!=', '14'],
                    ['leavetype_id','!=', '16'],
                    ['leavetype_id','!=', '17'],
                    ['leavetype_id','!=', '19'],
                    ['leavetype_id','!=', '20'],
                    ['leavetype_id','!=', '21'],
                    ['leavetype_id','!=', '22'],
                    ['leavetype_id','!=', '23'],
                    // ['start_date', $request->start_date],
                    ])->whereRaw(
                        '"'.$request->start_date.'" between `start_date` and `end_date`'
                    )->whereRaw(
                        '"'.$request->end_date.'" between `start_date` and `end_date`'
                    )->where(function($query) {
                        $query->where('status','Pending LM Approval')
                                    ->orWhere('status','Pending HR Approval')
                                    ->orWhere('status','Approved');
            })->get();


            $leavessubmittedcase2 = Leave::where([
                ['user_id', $user->id],
                ['leavetype_id', $request->leavetype_id],
                // ['start_date', $request->start_date],
                ])->whereRaw(
                    '"'.$request->start_date.'" between `start_date` and `end_date`'
                )->whereRaw(
                    '"'.$request->end_date.'" between `start_date` and `end_date`'
                )->where(function($query) {
                    $query->where('status','Pending LM Approval')
                                ->orWhere('status','Pending HR Approval')
                                ->orWhere('status','Approved');
                })->get();


                $counted = count($leavessubmitted);
                $countedcase2 = count($leavessubmittedcase2);

                if($counted + $countedcase2 > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }

                    else
                    {


                    
                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = '0.5';
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = auth()->user()->id;
                    if (!isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';

                    $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                    }

                    $leave->save();
                    $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                    return redirect()->route('leaves.index');

                }
                } else {
                    return redirect()->back()->with("error",trans('leaveerror.nobalance'));
                }
            
        }

        //first degree days should be less than 5 days
        elseif ($request->leavetype_id == '6') {

            if ($days <= '5') {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }
            $calculation = $this->checkForCrossDays($user, $request);
            if($calculation[0] + $calculation[1] > 0)
            {
                return redirect()->back()->with("error", trans('leaveerror.sameday'));
            }

                else

                {

                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = $days;
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';

                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');
            }
            } else {
                return redirect()->back()->with("error", trans('leaveerror.fivedaysfistdeg'));
            }

        }

        //second degree days should be less than 3 days
        elseif ($request->leavetype_id == '7') {

            if ($days <= '3') {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }

            $calculation = $this->checkForCrossDays($user, $request);

            if($calculation[0] + $calculation[1] > 0)
            {
                return redirect()->back()->with("error", trans('leaveerror.sameday'));
            }
                
                else
                {
                
                $leave = new Leave();
                $leave->start_date = $request->start_date;
                $leave->end_date = $request->end_date;
                $leave->reason = $request->reason;
                if ($request->hasFile('file')) {
                    $leave->path = $path;
                }

                $leave->days = $days;
                $leave->leavetype_id = $request->leavetype_id;
                $leave->user_id = auth()->user()->id;
                if (!isset($user->linemanager)) {
                    $leave->status = 'Pending HR Approval';

                } else {

                    $leave->status = 'Pending LM Approval';

                $this->sendEmailToLm($user, $leave, $startdayname, $enddayname);
                }

                $leave->save();
                $request->session()->flash('successMsg',trans('overtimeerror.success')); 
                return redirect()->route('leaves.index');
            }
            } else {
                return redirect()->back()->with("error", trans('leaveerror.threedayperrequest'));
            }

        } 

        //sick leave SC
        elseif ($request->leavetype_id == '26') {
            if ($currentbalance > 0) {
                
                if ($days > '3') {
                    return redirect()->back()->with('error', trans('leaveerror.sickscthree'));
                }
                if ($request->reason == null)
                {
                    return redirect()->back()->with('error', trans('leaveerror.selfcertificate'));
                }
                else
                {
                    
                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                } 

                $leavessubmitted = Leave::where([
                    ['user_id', $user->id],
                    // ['start_date', $request->start_date],
                ])->where(function ($query) use ($request) {
                    $query->whereBetween('start_date', [$request->start_date, $request->end_date])
                        ->orWhereBetween('end_date', [$request->start_date, $request->end_date]);
                })->where(function ($query) {
                    $query->where('status', 'Pending LM Approval')
                        ->orWhere('status', 'Pending HR Approval')
                        ->orWhere('status', 'Approved');
                })->get();

                $leavessubmittedcase2 = Leave::where([
                    ['user_id', $user->id],
                    // ['start_date', $request->start_date],
                ])->where(function ($query) use ($request) {
                    $query->whereRaw('"'.$request->start_date.'" between `start_date` and `end_date`')
                        ->orwhereRaw('"'.$request->end_date.'" between `start_date` and `end_date`');
                })->where(function ($query) {
                    $query->where('status', 'Pending LM Approval')
                        ->orWhere('status', 'Pending HR Approval')
                        ->orWhere('status', 'Approved');
                })->get();

                $counted = count($leavessubmitted);
                $countedcase2 = count($leavessubmittedcase2);

                if ($counted + $countedcase2 > 0) {
                    return redirect()->back()->with('error', trans('leaveerror.sameday'));
                } else {

                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = $dayswithoutholidays;
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = $user->id;
                    if (! isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                        $linemanageremail = User::where('name', $user->linemanager)->value('email');

                        
                        $details = [
                            'requestername' => $user->name,
                            'linemanagername' => $user->linemanager,
                            'linemanageremail' => $linemanageremail,
                            'title' => 'Leave Request Approval - '.$leave->leavetype->name,
                            'leavetype' => $leave->leavetype->name,
                            'startdayname' => $startdayname,
                            'start_date' => $leave->start_date,
                            'enddayname' => $enddayname,
                            'end_date' => $leave->end_date,
                            'days' => $leave->days,
                            'comment' => $leave->reason,
                        ];

                        Mail::to($linemanageremail)->send(new MailLeave($details));
                    }

                    $leave->save();
                    // $user->notify(new EmailNotification($leave));
                    $request->session()->flash('successMsg', trans('overtimeerror.success'));

                    return redirect()->route('leaves.index');
                }

                }

            } else {
                return redirect()->back()->with('error', trans('leaveerror.nobalance'));
            }
        }
                
        
        // Home leave coditions
        elseif ($request->leavetype_id == '24') {
            
            if ($days >= 2 && $currentbalance >= 2) {

                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('public/leaves');
                }

                $counted = 0;
                
                $countedcase2 = 0;
                
                if ($counted + $countedcase2 > 0) {
                    return redirect()->back()->with('error', trans('leaveerror.sameday'));
                } else {

                    $leave = new Leave();
                    $leave->start_date = $request->start_date;
                    $leave->end_date = $request->end_date;
                    $leave->reason = $request->reason;
                    if ($request->hasFile('file')) {
                        $leave->path = $path;
                    }

                    $leave->days = '2';
                    $leave->leavetype_id = $request->leavetype_id;
                    $leave->user_id = $user->id;
                    if (! isset($user->linemanager)) {
                        $leave->status = 'Pending HR Approval';

                    } else {

                        $leave->status = 'Pending LM Approval';
                        $linemanageremail = User::where('name', $user->linemanager)->value('email');

                        
                        $details = [
                            'requestername' => $user->name,
                            'linemanagername' => $user->linemanager,
                            'linemanageremail' => $linemanageremail,
                            'title' => 'Leave Request Approval - '.$leave->leavetype->name,
                            'leavetype' => $leave->leavetype->name,
                            'startdayname' => $startdayname,
                            'start_date' => $leave->start_date,
                            'enddayname' => $enddayname,
                            'end_date' => $leave->end_date,
                            'days' => $leave->days,
                            'comment' => $leave->reason,
                        ];

                        Mail::to($linemanageremail)->send(new MailLeave($details));
                    }

                    $leave->save();
                    $request->session()->flash('successMsg', trans('overtimeerror.success'));

                    return redirect()->route('leaves.index');
                }

            } else {
                return redirect()->back()->with('error', trans('leaveerror.nobalance'));
            }

        }


        // R&R leave coditions
        elseif ($request->leavetype_id == '25') {

            if ($probationdays >= '60' OR $user->contract == "International") {

                if ($days <= $currentbalance) {

                    if ($request->reason == null)
                        {
                            return redirect()->back()->with('error', trans('leaveerror.entrydate'));
                        }

                        if ($request->hasFile('file')) {
                            $path = $request->file('file')->store('public/leaves');
                        }


                    $leavessubmitted = Leave::where([
                        ['user_id', $user->id],
                        ['leavetype_id','!=', '24'],
                        // ['start_date', $request->start_date],
                    ])->where(function ($query) use ($request) {
                        $query->whereBetween('start_date', [$request->start_date, $request->end_date])
                            ->orWhereBetween('end_date', [$request->start_date, $request->end_date]);
                    })->where(function ($query) {
                        $query->where('status', 'Pending LM Approval')
                            ->orWhere('status', 'Pending HR Approval')
                            ->orWhere('status', 'Approved');
                    })->get();

                    $leavessubmittedcase2 = Leave::where([
                        ['user_id', $user->id],
                        ['leavetype_id','!=', '24'],
                        // ['start_date', $request->start_date],
                    ])->where(function ($query) use ($request) {
                        $query->whereRaw('"'.$request->start_date.'" between `start_date` and `end_date`')
                            ->orwhereRaw('"'.$request->end_date.'" between `start_date` and `end_date`');
                    })->where(function ($query) {
                        $query->where('status', 'Pending LM Approval')
                            ->orWhere('status', 'Pending HR Approval')
                            ->orWhere('status', 'Approved');
                    })->get();

                    $counted = count($leavessubmitted);
                    $countedcase2 = count($leavessubmittedcase2);

                    if ($counted + $countedcase2 > 0) {
                        return redirect()->back()->with('error', trans('leaveerror.sameday'));
                    } else {

                        $leave = new Leave();
                        $leave->start_date = $request->start_date;
                        $leave->end_date = $request->end_date;
                        $leave->reason = $request->reason;
                        if ($request->hasFile('file')) {
                            $leave->path = $path;
                        }

                        $leave->days = $days;
                        $leave->leavetype_id = $request->leavetype_id;
                        $leave->user_id = $user->id;
                        if (! isset($user->linemanager)) {
                            $leave->status = 'Pending HR Approval';

                        } else {

                            $leave->status = 'Pending LM Approval';
                        }

                        $leave->save();
                        $request->session()->flash('successMsg', trans('overtimeerror.success'));

                        return redirect()->route('leaves.index');

                    }
                } else {
                    return redirect()->back()->with('error', trans('leaveerror.nobalance'));
                }
            } else {
                return redirect()->back()->with('error', trans('leaveerror.prob'));
            }
        }


        //remining leaves type is: 15 (unpaid full day leave) and Sick DC
        else {

            if ($request->leavetype_id == '28' AND $request->hasFile('file') == null) {
                return redirect()->back()->with("error",trans('leaveerror.attachment'));
            }
            if ($request->hasFile('file')) {
                $path = $request->file('file')->store('public/leaves');
            }

            $leavessubmitted = Leave::where([
            ['user_id', $user->id],
            // ['start_date', $request->start_date],
            ])->where(function($query) use ($request) {
                $query->whereBetween('start_date', [$request->start_date,$request->end_date])
            ->orWhereBetween('end_date', [$request->start_date,$request->end_date]);
             })->where(function($query) {
                $query->where('status','Pending LM Approval')
                            ->orWhere('status','Pending HR Approval')
                            ->orWhere('status','Approved');
            })->get();


            $leavessubmittedcase2 = Leave::where([
              ['user_id', $user->id],
          // ['start_date', $request->start_date],
           ])->where(function($query) use ($request) {
            $query->whereRaw('"'.$request->start_date.'" between `start_date` and `end_date`')
              ->orwhereRaw('"'.$request->end_date.'" between `start_date` and `end_date`');       
             })->where(function($query) {
            $query->where('status','Pending LM Approval')
                        ->orWhere('status','Pending HR Approval')
                        ->orWhere('status','Approved');
         })->get();


           $counted = count($leavessubmitted);
           $countedcase2 = count($leavessubmittedcase2);

                if($counted + $countedcase2 > 0)
                {
                    return redirect()->back()->with("error", trans('leaveerror.sameday'));
                }
            else
            {        

            $leave = new Leave();
            $leave->start_date = $request->start_date;
            $leave->end_date = $request->end_date;
            $leave->reason = $request->reason;
            if ($request->hasFile('file')) {
                $leave->path = $path;
            }
            $leave->days = $sickpercentagedays;
            $leave->leavetype_id = $request->leavetype_id;
            $leave->user_id = auth()->user()->id;
            if (!isset($user->linemanager)) {
                $leave->status = 'Pending HR Approval';
            } else {

                $leave->status = 'Pending LM Approval';

                $linemanageremail = User::where('name',$user->linemanager)->value('email');

                        
                        $details = [
                            'requestername' => $user->name,
                            'linemanagername' => $user->linemanager,
                            'linemanageremail' => $linemanageremail,
                            'title' => 'Leave Request Approval - '.$leave->leavetype->name,
                            'leavetype' => $leave->leavetype->name,
                            'startdayname' => $startdayname,
                            'start_date' => $leave->start_date,
                            'enddayname' => $enddayname,
                            'end_date' =>  $leave->end_date,
                            'days' => $leave->days,
                            'comment' =>  $leave->reason
                        ];
                       
                        Mail::to($linemanageremail)->send(new MailLeave($details));
            }
            $leave->save();

            $request->session()->flash('successMsg',trans('overtimeerror.success')); 
            return redirect()->route('leaves.index');
            }
        }

        
    }


    public function show($leaveid)
    {
        $id = decrypt($leaveid);
        $leave = Leave::findOrFail($id);
        $authuser = Auth::user();
        $leavelmname = $leave->user->linemanager;
        $authusername = $authuser->name;

        if ($leave->user == $authuser OR $authuser->hradmin == "yes" OR $authusername == $leavelmname)
        {
            $users = User::all();
            $currentlm = $leave->user->linemanager;
    
            return view('leaves.show', ['leave' => $leave,'users' => $users,'currentlm'=>$currentlm]);
        }
        else
        {
            abort (403);
        }
    }

   
    public function edit(Leave $leave)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Leave $leave)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $authuser = Auth::user();
        $leave = Leave::find($id);
 
        if ($authuser->id == $leave->user->id)
        {
            if (isset($leave->path)) {
                $file_path = public_path() . '/storage/leaves/' . basename($leave->path);
                unlink($file_path);
            }
    
            $leave->delete();
            return redirect()->route('leaves.index')->with("success", "Leave is canceled");
        }
        else
        {
            abort(403);
        }
    }

    public function approved(Request $request,$id)
    {
        $lmuser = Auth::user();
        $leave = Leave::find($id);

        $requester=$leave->user;
        if($lmuser->usertype_id == "2" && $lmuser->name == $requester->linemanager)
        {

            $leave->status = 'Pending HR Approval';
            $leave->lmapprover = $lmuser->name;
            $leave->lmapprover = $lmuser->name;
            $leave->lmcomment = $request->comment;
    
            $startdayname = Carbon::parse($leave->start_date)->format('l');
            $enddayname = Carbon::parse($leave->end_date)->format('l');
    
           
            // $linemanageremail = User::where('name',$requester->linemanager)->value('email');
    
            
            $details = [
                'requestername' => $requester->name,
                'linemanagername' => $requester->linemanager,
                // 'linemanageremail' => $linemanageremail,
                'title' => 'Leave Request - '.$leave->leavetype->name. ' - Approved by Line Manager',
                'startdayname' => $startdayname,
                'start_date' => $leave->start_date,
                'enddayname' => $enddayname,
                'end_date' =>  $leave->end_date,
                'days' => $leave->days,
                'status' => $leave->status,
                'comment' =>  $leave->reason,
                'lmcomment' => $leave->lmcomment
            ];
           
            Mail::to($requester->email)->send(new MailLeaveafterlm($details));
    
            $leave->save();
    
            return redirect()->route('leaves.approval');
    
        }
        else
        {
            abort(403);
        }

    }

    public function declined(Request $request,$id)
    {
        $lmuser = Auth::user();
        
        $leave = Leave::find($id);
        $requester=$leave->user;
        
        if($lmuser->usertype_id == "2" && $lmuser->name == $requester->linemanager)
        {
           
            $leave->status = 'Declined by LM';
            $leave->lmapprover = $lmuser->name;
            $leave->lmcomment = $request->comment;
            $startdayname = Carbon::parse($leave->start_date)->format('l');
            $enddayname = Carbon::parse($leave->end_date)->format('l');
    
    
           
            // $linemanageremail = User::where('name',$requester->linemanager)->value('email');
    
            
            $details = [
                'requestername' => $requester->name,
                'linemanagername' => $requester->linemanager,
                // 'linemanageremail' => $linemanageremail,
                'title' => 'Leave Request - '.$leave->leavetype->name. ' - Declined by Line Manager',
                'startdayname' => $startdayname,
                'start_date' => $leave->start_date,
                'enddayname' => $enddayname,
                'end_date' =>  $leave->end_date,
                'days' => $leave->days,
                'status' => $leave->status,
                'comment' =>  $leave->reason,
                'lmcomment' => $leave->lmcomment
            ];
           
            Mail::to($requester->email)->send(new MailLeaveafterlm($details));
            
            $leave->save();
    
            return redirect()->route('leaves.approval');
        }
        else
        {

            abort(403);

        }


    }

    public function forward(Request $request,$id)
    {
        $leave = Leave::find($id);
        $leave->status = 'Pending extra Approval';
        $leave->exapprover = $request->extra;
        $leave->save();
        return redirect()->route('leaves.hrapproval');
    }

    public function exapproved(Request $request,$id)
    {
        $exuser = Auth::user();
        $leave = Leave::find($id);
        $leave->status = 'Approved by extra Approval';
        $leave->exapprover = $exuser->name;
        $leave->excomment = $request->comment;
        $leave->save();

        return redirect()->route('leaves.approval');
    }
    public function exdeclined(Request $request,$id)
    {
        $exuser = Auth::user();
        $leave = Leave::find($id);
        $leave->status = 'Declined by extra Approval';
        $leave->exapprover = $exuser->name;
        $leave->excomment = $request->comment;
        $leave->save();

        return redirect()->route('leaves.approval');
    }

    public function hrapproved(Request $request,$id)
    {
        $hruser = Auth::user();
        if($hruser->hradmin !== "yes")
        {
            abort(403);
        }
        else {
            $leave = Leave::find($id);

                // annual half days leaves
                if ($leave->leavetype_id == '13' || $leave->leavetype_id == '14') {

                    $balances = Balance::where('user_id', $leave->user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
        
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $final = $subsets->firstwhere('leavetype_id', '1');
        
                    $finalfinal = $final['value'];
                    $currentbalanceforannual = $finalfinal;
        
                    $newbalance = $currentbalanceforannual - $leave->days;
        
                }
        
                    // unpaid half days leaves
                 elseif ($leave->leavetype_id == '16' || $leave->leavetype_id == '17') {
        
                    $balances = Balance::where('user_id', $leave->user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
        
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $final = $subsets->firstwhere('leavetype_id', '15');
        
                    $finalfinal = $final['value'];
                    $currentbalanceforunpaid = $finalfinal;
        
                    $newbalance = $currentbalanceforunpaid - $leave->days;
        
                }
                   // sick half days leaves
                   elseif ($leave->leavetype_id == '20' || $leave->leavetype_id == '21') {
        
                    $balances = Balance::where('user_id', $leave->user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
        
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $final = $subsets->firstwhere('leavetype_id', '2');
        
                    $finalfinal = $final['value'];
                    $currentbalanceforsick = $finalfinal;
        
                    $newbalance = $currentbalanceforsick - $leave->days;
        
                }

//sick leave sc
elseif ($leave->leavetype_id == '26') {

    $balances = Balance::where('user_id', $leave->user->id)->get();
    $subsets = $balances->map(function ($balance) {
        return collect($balance->toArray())

            ->only(['value', 'leavetype_id'])
            ->all();
    });
    $final = $subsets->firstwhere('leavetype_id', '26');

    $finalfinal = $final['value'];
    $currentbalanceforcomp = $finalfinal;

    $newbalance = $currentbalanceforcomp - 1;

}


//r&r
elseif ($leave->leavetype_id == '25') {

    $balances = Balance::where('user_id', $leave->user->id)->get();
    $subsets = $balances->map(function ($balance) {
        return collect($balance->toArray())

            ->only(['value', 'leavetype_id'])
            ->all();
    });
    $final = $subsets->firstwhere('leavetype_id', '25');

    $finalfinal = $final['value'];
    $currentbalanceforcomp = $finalfinal;

    $newbalance = 0;

}

                   // sick 30% half days leaves
                   elseif ($leave->leavetype_id == '22' || $leave->leavetype_id == '23') {
        
                    $balances = Balance::where('user_id', $leave->user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
        
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $final = $subsets->firstwhere('leavetype_id', '3');
        
                    $finalfinal = $final['value'];
                    $currentbalanceforsick = $finalfinal;
        
                    $newbalance = $currentbalanceforsick - $leave->days;
        
                }
        
                    
        //comp hour
                elseif ($leave->leavetype_id == '19') {
        
                    $balances = Balance::where('user_id', $leave->user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
        
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $final = $subsets->firstwhere('leavetype_id', '18');
        
                    $finalfinal = $final['value'];
                    $currentbalanceforcomp = $finalfinal;
        
                    $newbalance = $currentbalanceforcomp - ($leave->hours / 8);
        

                } 

                //Unpaid Full day
                elseif ($leave->leavetype_id == '15') {
        
                    $balances = Balance::where('user_id', $leave->user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
        
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $final = $subsets->firstwhere('leavetype_id', '15');
        
                    $finalfinal = $final['value'];
                    $currentbalanceforcomp = $finalfinal;
        
                    $newbalance = 1 + $leave->days;
        

                } 
                
                else {
                    $balances = Balance::where('user_id', $leave->user->id)->get();
                    $subsets = $balances->map(function ($balance) {
                        return collect($balance->toArray())
        
                            ->only(['value', 'leavetype_id'])
                            ->all();
                    });
                    $final = $subsets->firstwhere('leavetype_id', $leave->leavetype_id);
        
                    $finalfinal = $final['value'];
                    $currentbalance = $finalfinal;
        
                    $newbalance = $currentbalance - $leave->days;
    
                }

                if($newbalance < 0 AND $leave->user->contract !== "International")
                {
                    return redirect()->route('leaves.hrapproval')->with("error", "No enough balance for this type, you can only decline the leave"); 
                }
                else
                {
                    $leave->status = 'Approved';
                    $leave->hrapprover = $hruser->name;
                    $leave->hrcomment = $request->comment;
            
                    $startdayname = Carbon::parse($leave->start_date)->format('l');
                    $enddayname = Carbon::parse($leave->end_date)->format('l');
                    
                    $requester=$leave->user;

                    $details = [
                        'requestername' => $requester->name,
                        'linemanagername' => $requester->linemanager,
                        'hrname' => $leave->hrapprover,
                        'title' => 'Leave Request - '.$leave->leavetype->name. ' - Approved by HR',
                        'startdayname' => $startdayname,
                        'start_date' => $leave->start_date,
                        'enddayname' => $enddayname,
                        'end_date' =>  $leave->end_date,
                        'days' => $leave->days,
                        'status' => $leave->status,
                        'comment' =>  $leave->reason,
                        'newbalance' => $newbalance,
                        'lmcomment' => $leave->lmcomment,
                        'hrcomment' => $leave->hrcomment
                    ];
                   
                    Mail::to($requester->email)->send(new MailLeavefinal($details));
            
                    $leave->save();
            
                    // annual half days leaves
                    if ($leave->leavetype_id == '13' || $leave->leavetype_id == '14') {
            
                        $balances = Balance::where('user_id', $leave->user->id)->get();
                        $subsets = $balances->map(function ($balance) {
                            return collect($balance->toArray())
            
                                ->only(['value', 'leavetype_id'])
                                ->all();
                        });
                        $final = $subsets->firstwhere('leavetype_id', '1');
            
                        $finalfinal = $final['value'];
                        $currentbalanceforannual = $finalfinal;
            
                        $newbalance = $currentbalanceforannual - $leave->days;
            
                        Balance::where([
                            ['user_id', $leave->user->id],
                            ['leavetype_id', '1'],
                        ])->update(['value' => $newbalance]);
                    }
                        // unpaid half days leaves
                     elseif ($leave->leavetype_id == '16' || $leave->leavetype_id == '17') {
            
                        $balances = Balance::where('user_id', $leave->user->id)->get();
                        $subsets = $balances->map(function ($balance) {
                            return collect($balance->toArray())
            
                                ->only(['value', 'leavetype_id'])
                                ->all();
                        });
                        $final = $subsets->firstwhere('leavetype_id', '15');
            
                        $finalfinal = $final['value'];
                        $currentbalanceforannual = $finalfinal;
            
                        $newbalance = $currentbalanceforannual - $leave->days;
            
                        Balance::where([
                            ['user_id', $leave->user->id],
                            ['leavetype_id', '15'],
                        ])->update(['value' => $newbalance]);
            
                    }
                    // sick sc
                    elseif ($leave->leavetype_id == '26')
                    {
                        $balances = Balance::where('user_id', $leave->user->id)->get();
                        $subsets = $balances->map(function ($balance) {
                            return collect($balance->toArray())

                                ->only(['value', 'leavetype_id'])
                                ->all();
                        });
                        $final = $subsets->firstwhere('leavetype_id', $leave->leavetype_id);

                        $finalfinal = $final['value'];
                        $currentbalance = $finalfinal;

                        $newbalance = $currentbalance - 1;

                        Balance::where([
                            ['user_id', $leave->user->id],
                            ['leavetype_id', $leave->leavetype_id],
                        ])->update(['value' => $newbalance]);
                    }
                        // sick half days leaves
                        elseif ($leave->leavetype_id == '20' || $leave->leavetype_id == '21') {
            
                            $balances = Balance::where('user_id', $leave->user->id)->get();
                            $subsets = $balances->map(function ($balance) {
                                return collect($balance->toArray())
                
                                    ->only(['value', 'leavetype_id'])
                                    ->all();
                            });
                            $final = $subsets->firstwhere('leavetype_id', '2');
                
                            $finalfinal = $final['value'];
                            $currentbalanceforannual = $finalfinal;
                
                            $newbalance = $currentbalanceforannual - $leave->days;
                
                            Balance::where([
                                ['user_id', $leave->user->id],
                                ['leavetype_id', '2'],
                            ])->update(['value' => $newbalance]);
                
                        }


                        // sick 30% half days leaves
                        elseif ($leave->leavetype_id == '21' || $leave->leavetype_id == '22') {
            
                            $balances = Balance::where('user_id', $leave->user->id)->get();
                            $subsets = $balances->map(function ($balance) {
                                return collect($balance->toArray())
                
                                    ->only(['value', 'leavetype_id'])
                                    ->all();
                            });
                            $final = $subsets->firstwhere('leavetype_id', '3');
                
                            $finalfinal = $final['value'];
                            $currentbalanceforannual = $finalfinal;
                
                            $newbalance = $currentbalanceforannual - $leave->days;
                
                            Balance::where([
                                ['user_id', $leave->user->id],
                                ['leavetype_id', '3'],
                            ])->update(['value' => $newbalance]);
                
                        }
            
                     elseif ($leave->leavetype_id == '19') {
            
                        $balances = Balance::where('user_id', $leave->user->id)->get();
                        $subsets = $balances->map(function ($balance) {
                            return collect($balance->toArray())
            
                                ->only(['value', 'leavetype_id'])
                                ->all();
                        });
                        $final = $subsets->firstwhere('leavetype_id', '18');
            
                        $finalfinal = $final['value'];
                        $currentbalanceforannual = $finalfinal;
            
                        $newbalance = $currentbalanceforannual - ($leave->hours / 8);
            
                        Balance::where([
                            ['user_id', $leave->user->id],
                            ['leavetype_id', '18'],
                        ])->update(['value' => $newbalance]);
                    } else {
                        $balances = Balance::where('user_id', $leave->user->id)->get();
                        $subsets = $balances->map(function ($balance) {
                            return collect($balance->toArray())
            
                                ->only(['value', 'leavetype_id'])
                                ->all();
                        });
                        $final = $subsets->firstwhere('leavetype_id', $leave->leavetype_id);
            
                        $finalfinal = $final['value'];
                        $currentbalance = $finalfinal;
            
                        $newbalance = $currentbalance - $leave->days;
            
                        Balance::where([
                            ['user_id', $leave->user->id],
                            ['leavetype_id', $leave->leavetype_id],
                        ])->update(['value' => $newbalance]);
                    }
            
                    return redirect()->route('leaves.hrapproval');
                }
        }
    }
    public function hrdeclined(Request $request,$id)
    {
        $hruser = Auth::user();
        if($hruser->hradmin !== "yes")
        {
            abort(403);
        }
        else
        {
            $leave = Leave::find($id);
            $leave->status = 'Declined by HR';
            $leave->hrapprover = $hruser->name;
            $leave->hrcomment = $request->comment;
    
    
            $startdayname = Carbon::parse($leave->start_date)->format('l');
            $enddayname = Carbon::parse($leave->end_date)->format('l');
            
            $requester=$leave->user;
    
            $details = [
                'requestername' => $requester->name,
                'linemanagername' => $requester->linemanager,
                'hrname' => $leave->hrapprover,
                'title' => 'Leave Request - '.$leave->leavetype->name. ' - Declined by HR',
                'startdayname' => $startdayname,
                'start_date' => $leave->start_date,
                'enddayname' => $enddayname,
                'end_date' =>  $leave->end_date,
                'days' => $leave->days,
                'status' => $leave->status,
                'comment' =>  $leave->reason,
                'lmcomment' => $leave->lmcomment,
                'hrcomment' => $leave->hrcomment
            ];
           
            Mail::to($requester->email)->send(new MailLeaverejected($details));

            $leave->save();
            return redirect()->route('leaves.hrapproval');
        }
    }
    public function export()
    {
        $hruser = Auth::user();
        if ($hruser->office == "AO2")
        {
            $leaves= Leave::all();
        }
        else
        {
        $staffwithsameoffice = User::where('office',$hruser->office)->get();
            if (count($staffwithsameoffice))
            {
                $hrsubsets = $staffwithsameoffice->map(function ($staffwithsameoffice) {
                    return collect($staffwithsameoffice->toArray())
                        ->only(['id'])
                        ->all();
                });
                $leaves = Leave::wherein('user_id', $hrsubsets)->get(); 
    
    }
}
    return Excel::download(new LeavesExport($leaves), 'leaves.xlsx');
    }

    public function pdf(Request $request)
    {
        $request->validate([
            'start_date' => 'required',
            'end_date' => 'required|after_or_equal:start_date',
            // 'leavetype' => 'required',
            'name'=> 'required',
           
        ]);

        $name= $request->name;
        $start_date=$request->start_date;
        $end_date=$request->end_date;
        // $leavetype=$request->leavetype;
       
        $userid = User::where('name',$name)->value('id');
        $userpeopleid = User::where('name',$name)->value('employee_number');
  
 
        $leaves = Leave::where([

            ['user_id', $userid],
            ['start_date', '>=', $start_date],
            ['end_date', '<=', $end_date],

        ])->get();
        $hruser = Auth::user();
        $date = Carbon::now();
      
        $pdf = Pdf::loadView('admin.allstaffleaves.report', ['name'=>$name,'userpeopleid'=>$userpeopleid,'hruser'=>$hruser,'date'=>$date, 'start_date'=>$start_date,'end_date'=>$end_date,'leaves'=>$leaves])->setOptions(['defaultFont' => 'sans-serif', 'isHtml5ParserEnabled'=> 'true', 'isRemoteEnabled'=> 'true', 'isPhpEnabled'=> 'true'])->setpaper('a4','portrait');
        return $pdf->stream();
    }

    public function hrdelete(Request $request,$id)
    {
        $leave = Leave::find($id);
        

         // annual half days leaves
         if ($leave->leavetype_id == '13' || $leave->leavetype_id == '14') {
            
            $balances = Balance::where('user_id', $leave->user->id)->get();
            $subsets = $balances->map(function ($balance) {
                return collect($balance->toArray())

                    ->only(['value', 'leavetype_id'])
                    ->all();
            });
            $final = $subsets->firstwhere('leavetype_id', '1');

            $finalfinal = $final['value'];
            $currentbalanceforannual = $finalfinal;

            $newbalance = $currentbalanceforannual + $leave->days;

            Balance::where([
                ['user_id', $leave->user->id],
                ['leavetype_id', '1'],
            ])->update(['value' => $newbalance]);
        }
            // unpaid half days leaves
         elseif ($leave->leavetype_id == '16' || $leave->leavetype_id == '17') {

            $balances = Balance::where('user_id', $leave->user->id)->get();
            $subsets = $balances->map(function ($balance) {
                return collect($balance->toArray())

                    ->only(['value', 'leavetype_id'])
                    ->all();
            });
            $final = $subsets->firstwhere('leavetype_id', '15');

            $finalfinal = $final['value'];
            $currentbalanceforannual = $finalfinal;

            $newbalance = $currentbalanceforannual + $leave->days;

            Balance::where([
                ['user_id', $leave->user->id],
                ['leavetype_id', '15'],
            ])->update(['value' => $newbalance]);

        }

           // sick half days leaves
            elseif ($leave->leavetype_id == '20' || $leave->leavetype_id == '21') {

                $balances = Balance::where('user_id', $leave->user->id)->get();
                $subsets = $balances->map(function ($balance) {
                    return collect($balance->toArray())
    
                        ->only(['value', 'leavetype_id'])
                        ->all();
                });
                $final = $subsets->firstwhere('leavetype_id', '2');
    
                $finalfinal = $final['value'];
                $currentbalanceforannual = $finalfinal;
    
                $newbalance = $currentbalanceforannual + $leave->days;
    
                Balance::where([
                    ['user_id', $leave->user->id],
                    ['leavetype_id', '2'],
                ])->update(['value' => $newbalance]);
    
            }

            // sick 30% half days leaves
            elseif ($leave->leavetype_id == '22' || $leave->leavetype_id == '23') {

                $balances = Balance::where('user_id', $leave->user->id)->get();
                $subsets = $balances->map(function ($balance) {
                    return collect($balance->toArray())
    
                        ->only(['value', 'leavetype_id'])
                        ->all();
                });
                $final = $subsets->firstwhere('leavetype_id', '3');
    
                $finalfinal = $final['value'];
                $currentbalanceforannual = $finalfinal;
    
                $newbalance = $currentbalanceforannual + $leave->days;
    
                Balance::where([
                    ['user_id', $leave->user->id],
                    ['leavetype_id', '3'],
                ])->update(['value' => $newbalance]);
    
            }

         elseif ($leave->leavetype_id == '19') {

            $balances = Balance::where('user_id', $leave->user->id)->get();
            $subsets = $balances->map(function ($balance) {
                return collect($balance->toArray())

                    ->only(['value', 'leavetype_id'])
                    ->all();
            });
            $final = $subsets->firstwhere('leavetype_id', '18');

            $finalfinal = $final['value'];
            $currentbalanceforannual = $finalfinal;

            $newbalance = $currentbalanceforannual + ($leave->hours / 8);

            Balance::where([
                ['user_id', $leave->user->id],
                ['leavetype_id', '18'],
            ])->update(['value' => $newbalance]);
        } else {
            $balances = Balance::where('user_id', $leave->user->id)->get();
            $subsets = $balances->map(function ($balance) {
                return collect($balance->toArray())

                    ->only(['value', 'leavetype_id'])
                    ->all();
            });
            $final = $subsets->firstwhere('leavetype_id', $leave->leavetype_id);

            $finalfinal = $final['value'];
            $currentbalance = $finalfinal;

            $newbalance = $currentbalance + $leave->days;

            Balance::where([
                ['user_id', $leave->user->id],
                ['leavetype_id', $leave->leavetype_id],
            ])->update(['value' => $newbalance]);
        }

        $leave->delete();
        $request->session()->flash('successMsg',trans('overtimeerror.hrdelete')); 
        return redirect()->route('admin.allstaffleaves.index');
        
    }

    public function lmrevert(Request $request,$id)
    {
        $leave = Leave::find($id);
        $leave->status = 'Pending LM Approval';
        $leave->lmapprover = null;
        $leave->lmcomment = null;

        $leave->save();
   
        
        
        $request->session()->flash('successMsg',trans('overtimeerror.lmrevert')); 
        return redirect()->route('admin.allstaffleaves.index');
    }


    public function search(Request $request)
    {
        $request->validate([
       
            'start_date',
            'end_date' => 'nullable|after_or_equal:start_date',
            'name',
        ]);
        $hruser = Auth::user();

        $name= $request->name;
        $leavetype = $request->leavetype_id;
        $start_date=$request->start_date;
        $end_date=$request->end_date;
        $status = $request->status;
        $staffstatus = $request->staffstatus;
        $linemanager = $request->linemanager;
     

        if ($start_date == Null)
        {
            $start_datee = "2023-01-01";
        }

        else if ($start_date !== Null)
        {
            $start_datee = $start_date;
        }

        if ($end_date == Null)
        {
            $end_datee = "2023-12-31";
        }

        else if ($end_date !== Null)
        {
            $end_datee = $end_date;
        }

        if ($staffstatus == Null)
        {
            $staffstatuse = ['active','suspended'];
        }

        else if ($staffstatus !== Null)
        {
            $staffstatuse = $staffstatus;
        }
        
        if ($status == Null)
        {
            $statuse = ['Approved','Declined by HR','Declined by LM','Pending HR Approval','Pending LM Approval','Pending extra Approval','Approved by extra Approval','Declined by extra Approval'];
            
        }

        else if ($status !== Null)
        {
            $statuse = $status;
        }


        if ($leavetype == Null)
        {
            $leavetypee = Leavetype::all()->pluck('id')->toArray();
        }

        else if ($leavetype !== Null)
        {
            $leavetypee = $leavetype;
        }


        if ($request->name == null)
        {   
            $staffwithsameoffice = User::WhereIn('status', $staffstatuse)->get();
            if (count($staffwithsameoffice))
            {
                $hrsubsets = $staffwithsameoffice->map(function ($staffwithsameoffice) {
                    return collect($staffwithsameoffice->toArray())
                        ->only(['id'])
                        ->all();
                });
                $leaves = Leave::whereIn('user_id', $hrsubsets)->where([
                    ['start_date', '>=', $start_datee],
                    ['end_date', '<=', $end_datee],
                ])->WhereIn('leavetype_id', $leavetypee)->WhereIn('status', $statuse)->get();
                
            }             

        }
        else
        {
            $userid = User::where('name',$name)->value('id');     

            $leaves = Leave::where([
                ['user_id', $userid],
                ['start_date', '>=', $start_datee],
                ['end_date', '<=', $end_datee],
    
            ])->WhereIn('leavetype_id', $leavetypee)->WhereIn('status', $statuse)->get();
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

            $leaves = Leave::whereIn('user_id', $subsets)->where([
                ['start_date', '>=', $start_datee],
                ['end_date', '<=', $end_datee],
            ])->WhereIn('leavetype_id', $leavetypee)->WhereIn('status', $statuse)->get();
        }

        else {
            $leaves = Leave::where([
                ['start_date', '>=', $start_datee],
                ['end_date', '<=', $end_datee],
            ])->WhereIn('leavetype_id', $leavetypee)->Where('status', "nothing to show")->get();
        }
        }

        switch ($request->input('action')) {
            case 'view':
                return view('admin.allstaffleaves.search', ['leaves' => $leaves, 'name'=>$name,'start_date' =>$start_datee, 'end_date'=>$end_datee]);
                break;
    
            case 'excel':
                return Excel::download(new LeavesExport($leaves), 'leaves.xlsx');
                break;
            }
    }

    public function sendEmailToLm(User $user, Leave $leave, $startdayname, $enddayname)
    {
        $linemanageremail = User::where('name', $user->linemanager)->value('email');

                        
                        $details = [
                            'requestername' => $user->name,
                            'linemanagername' => $user->linemanager,
                            'linemanageremail' => $linemanageremail,
                            'title' => 'Leave Request Approval - '.$leave->leavetype->name,
                            'leavetype' => $leave->leavetype->name,
                            'startdayname' => $startdayname,
                            'start_date' => $leave->start_date,
                            'enddayname' => $enddayname,
                            'end_date' => $leave->end_date,
                            'days' => $leave->days,
                            'comment' => $leave->reason,
                        ];

                        Mail::to($linemanageremail)->send(new MailLeave($details));
    }

    public function checkForCrossDays(User $user, Request $request)
    {
        $leavessubmitted = Leave::where([
            ['user_id', $user->id],
            // ['start_date', $request->start_date],
            ])->where(function($query) use ($request) {
                $query->whereBetween('start_date', [$request->start_date,$request->end_date])
            ->orWhereBetween('end_date', [$request->start_date,$request->end_date]);
            })->where(function($query) {
                $query->where('status','Pending LM Approval')
                            ->orWhere('status','Pending HR Approval')
                            ->orWhere('status','Approved');
         })->get();


        $leavessubmittedcase2 = Leave::where([
            ['user_id', $user->id],
            // ['start_date', $request->start_date],
             ])->where(function($query) use ($request) {
            $query->whereRaw('"'.$request->start_date.'" between `start_date` and `end_date`')
            ->orwhereRaw('"'.$request->end_date.'" between `start_date` and `end_date`');       
            })->where(function($query) {
            $query->where('status','Pending LM Approval')
                        ->orWhere('status','Pending HR Approval')
                        ->orWhere('status','Approved');
          })->get();
          

        $counted = count($leavessubmitted);
        $countedcase2 = count($leavessubmittedcase2);

        return [$counted, $countedcase2];
    }

    public function getBalancesForUser(User $user)
    {
        $balances = Balance::where('user_id', $user->id)->get();
        $subsets = $balances->map(function ($balance) {
            return collect($balance->toArray())

                ->only(['value', 'leavetype_id'])  
                ->all();
        });

        return $subsets;
    }



}
