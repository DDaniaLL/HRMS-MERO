<?php

namespace App\Console;

use App\Models\Balance;
use App\Models\Comlist;
use Carbon\Carbon;
use DateTime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Mail;
use App\Mail\Comlistnotification as MailComlistnotification;

class Kernel extends ConsoleKernel
{
  
    protected function schedule(Schedule $schedule): void
    {
          $schedule->call(function () {
            $comlists = Comlist::whereNull('expired_date')->get();

            foreach($comlists as $comlist)
            {
                $userid = $comlist->user_id;
                $y = floatval($comlist->hours);
    
                $datenow = Carbon::now();
                $comlistcreatedate = new DateTime($comlist->created_at);
                $dateenow = new DateTime($datenow);
                $intervall = $comlistcreatedate->diff($dateenow);
                $probationdays = $intervall->format('%a');

                $dayname = Carbon::parse($comlist->autodate)->format('l');
    
                if ($probationdays == '60')
                {
                    $firstnotify = "Notifed 1 time";
                    $comlist->status=$firstnotify;
                    $comlist->save();

                    $details = [
                        'requestername' => $comlist->user->name,
                        // 'linemanagername' => $requester->linemanager,
                        // 'linemanageremail' => $linemanageremail,
                        'title' => 'Compensation balance auto delete - Overtime ID: '.$comlist->overtime_id.' if 3 months passed and the balance was not used',
                        // 'overtimetype' => $overtime->type,
                        'dayname' => $dayname,
                        'date' => $comlist->autodate,
                        // 'start_hour' => $overtime->start_hour,
                        // 'end_hour' => $overtime->end_hour,
                        'hours' => $comlist->hours,
                        // 'status' => $overtime->status,
                        // 'comment' =>  $overtime->reason,
                        // 'lmcomment' => $overtime->lmcomment
                    ];
                   
                    Mail::to($comlist->user->email)->send(new MailComlistnotification($details));
                }

                if ($probationdays == '70')
                {

                    $secondnotify = "Notifed 2 times";
                    $comlist->status=$secondnotify;
                    $comlist->save();
                  
                    $details = [
                        'requestername' => $comlist->user->name,
                        // 'linemanagername' => $requester->linemanager,
                        // 'linemanageremail' => $linemanageremail,
                        'title' => 'Compensation balance auto delete - Overtime ID: '.$comlist->overtime_id.' if 3 months passed and the balance was not used',
                        // 'overtimetype' => $overtime->type,
                        'dayname' => $dayname,
                        'date' => $comlist->autodate,
                        // 'start_hour' => $overtime->start_hour,
                        // 'end_hour' => $overtime->end_hour,
                        'hours' => $comlist->hours,
                        // 'status' => $overtime->status,
                        // 'comment' =>  $overtime->reason,
                        // 'lmcomment' => $overtime->lmcomment
                    ];
                   
                    Mail::to($comlist->user->email)->send(new MailComlistnotification($details));
                }

                if ($probationdays > '90')
                {  
                    $comlist->expired_date=$datenow;
                    $comlist->save();
    
                    $x = Balance::where([
                        ['user_id', $userid],
                        ['leavetype_id', '18'],
                    ])->pluck('value')->first();
    
                    if ($x == '0')

                    {
                        $comlist->status="Used";
                        
                        $comlist->save();

                    }
                    else if ($x >= $y)
                    {
                        $newbalance = $x - $y;
                        $comlist->status="fulllost";
                        $comlist->expired_value=$y;
                        $comlist->save();
                        Balance::where([
                            ['user_id', $userid],
                            ['leavetype_id', '18'],
                        ])->first()?->update(['value' => $newbalance]);
                    }
    
                    else 
                    {
                        $comlist->status="partiallost";
                        $comlist->expired_value=$x;
                        $comlist->save();
                        Balance::where([
                            ['user_id', $userid],
                            ['leavetype_id', '18'],
                        ])->first()?->update(['value' => '0']);
                    }
                       
                }
            }
        })->everySixHours();



    }

  
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
