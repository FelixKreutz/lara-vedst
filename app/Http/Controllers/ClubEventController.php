<?php

namespace Lara\Http\Controllers;

use Illuminate\Http\Request;

use Lara\Http\Requests;
use Lara\Http\Controllers\Controller;

use Session;
use Cache;
use DateTime;
use DateInterval;
use DateTimeZone;
use View;
use Input;
use Config;
use Log;
use Redirect;

use Carbon\Carbon;

use Lara\ClubEvent;
use Lara\Schedule;
use Lara\ScheduleEntry;
use Lara\Jobtype;
use Lara\Person;
use Lara\Club;
use Lara\Place;

class ClubEventController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param  int $year
     * @param  int $month
     * @param  int $day
     *
     * @return view createClubEventView
     * @return Place[] places
     * @return Schedule[] templates
     * @return Jobtype[] jobtypes
     * @return string $date
     * @return \Illuminate\Http\Response
     */
    public function create($year = null, $month = null, $day = null, $templateId = null)
    {    
        // Filling missing date and template number in case none are provided
        if ( is_null($year) ) {
            $year = date("Y");
        }

        if ( is_null($month) ) {
            $month = date("m");
        }

        if ( is_null($day) ) {
            $day = date("d");
        }

        if ( is_null($templateId) ) {
            $templateId = 0;    // 0 = no template
        }

        // prepare correct date format to be used in the forms
        $date = strftime("%d-%m-%Y", strtotime($year.$month.$day));

        // get a list of possible clubs to create an event at
        $places = Place::orderBy('plc_title', 'ASC')
                       ->lists('plc_title', 'id');
        
        // get a list of available templates to choose from
        $templates = Schedule::where('schdl_is_template', '=', '1')
                             ->orderBy('schdl_title', 'ASC')
                             ->get();

        // get a list of available job types
        $jobtypes = Jobtype::where('jbtyp_is_archived', '=', '0')
                           ->orderBy('jbtyp_title', 'ASC')
                           ->get();

        // if a template id was provided, load the schedule needed and extract job types
        if ( $templateId != 0 ) {
            $template = Schedule::where('id', '=', $templateId)
                                ->first();
            
            // put template data into entries
            $entries = $template->getEntries()
                            ->with('getJobType')
                            ->getResults();
        

            // put name of the active template for further use
            $activeTemplate = $template->schdl_title;
        } else {
            // fill variables with no data if no template was chosen
            $activeTemplate = "";
            $entries = null;
        }
                
        return View::make('createClubEventView', compact('places', 
                                                         'templates', 
                                                         'jobtypes', 
                                                         'entries', 
                                                         'activeTemplate', 
                                                         'date'));
    }


    /**
     * Store a newly created resource in storage.
     * Create a new event with schedule and write it to the database.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //validate passwords
        if (Input::get('password') != Input::get('passwordDouble')) {
            Session::put('message', Config::get('messages_de.password-mismatch') );
            Session::put('msgType', 'danger');
            return Redirect::back()->withInput(); 
            }

        $newEvent = $this->editClubEvent(null);
        $newEvent->save();  

        $newSchedule = new Schedule();
        $newSchedule->schdl_due_date = null;
        $newSchedule->evnt_id = $newEvent->id;

        // log revision
        $newSchedule->entry_revisions = json_encode(array("0"=>
                               ["entry id" => null,
                                "job type" => null,
                                "action" => "Dienstplan erstellt",
                                "old id" => null,
                                "old value" => null,
                                "new id" => null,
                                "new value" => null,
                                "user id" => Session::get('userId') != NULL ? Session::get('userId') : "",
                                "user name" => Session::get('userId') != NULL ? Session::get('userName') . '(' . Session::get('userClub') . ')' : "Gast",
                                "from ip" => \Illuminate\Support\Facades\Request::getClientIp(),
                                "timestamp" => (new DateTime)->format('Y-m-d H:i:s')
                                ]));

        $newSchedule->save();

        $newEntries = ScheduleController::createScheduleEntries($newSchedule->id);
        foreach($newEntries as $newEntry)
        {
            $newEntry->schdl_id = $newSchedule->id;
            $newEntry->save();

            // log revision
            ScheduleController::logRevision($newEntry->getSchedule,     // schedule object
                                            $newEntry,                  // entry object
                                            "Dienst erstellt",          // action description
                                            null,                       // old value
                                            null);                      // new value
        }

        // log the action
        Log::info('Create event: User ' . Session::get('userName') . '(' . Session::get('userId') . ', ' 
                 . Session::get('userGroup') . ') created event ' . $newEvent->evnt_title . ' (ID: ' . $newEvent->id . ').');
            
        // show new event
        return Redirect::action('ClubEventController@show', array('id' => $newEvent->id));
    }

    /**
     * Generates the view for a specific event, including the schedule.
     *
     * @param  int $id
     * @return view ClubEventView
     * @return ClubEvent $clubEvent
     * @return ScheduleEntry[] $entries
     * @return RedirectResponse
     */
    public function show($id)
    {  
        $clubEvent = ClubEvent::with('getPlace')
                              ->findOrFail($id);
        
        if(!Session::has('userId') 
        AND $clubEvent->evnt_is_private==1)
            
        {
            Session::put('message', Config::get('messages_de.access-denied'));
            Session::put('msgType', 'danger');
            return Redirect::action('MonthController@showMonth', array('year' => date('Y'), 
                                                                   'month' => date('m')));
        }
    
        $schedule = Schedule::findOrFail($clubEvent->getSchedule->id);

        $entries = ScheduleEntry::where('schdl_id', '=', $schedule->id)
                                ->with('getJobType',
                                       'getPerson', 
                                       'getPerson.getClub')
                                ->get();

        $clubs = Club::orderBy('clb_title')->lists('clb_title', 'id');
        
        $persons = Cache::remember('personsForDropDown', 10 , function()
        {
            $timeSpan = new DateTime("now");
            $timeSpan = $timeSpan->sub(DateInterval::createFromDateString('3 months'));
            return Person::whereRaw("prsn_ldap_id IS NOT NULL AND (prsn_status IN ('aktiv', 'kandidat') OR updated_at>='".$timeSpan->format('Y-m-d H:i:s')."')")
                            ->orderBy('clb_id')
                            ->orderBy('prsn_name')
                            ->get();
        });

        $revisions = json_decode($clubEvent->getSchedule->entry_revisions, true);
        if (!is_null($revisions)) {
            // deleting ip adresses from output for privacy reasons
            foreach ($revisions as $entry) {
                unset($entry["from ip"]);
            }
        }
        
        return View::make('clubEventView', compact('clubEvent', 'entries', 'clubs', 'persons', 'revisions'));
    }


    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        dd("test");//
    }

    /**
     * Delete an event specified by parameter $id with schedule and scheduleEntries.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        // Check credentials: you can only delete, if you have rigths for marketing or clubleitung.     
        if(!Session::has('userId') 
            OR (Session::get('userGroup') != 'marketing'
                AND Session::get('userGroup') != 'clubleitung'))
        {
            Session::put('message', Config::get('messages_de.access-denied'));
            Session::put('msgType', 'danger');
            return Redirect::action('MonthController@showMonth', array('year' => date('Y'), 
                                                                   'month' => date('m')));
        }
        
        // at first get all the data
        $event = ClubEvent::find($id);
        
        // check if the event exists
        if (is_null($event)) {
            Session::put('message', Config::get('messages_de.event-doesnt-exist') );
            Session::put('msgType', 'danger');
            return Redirect::back();
        }
        
        // log the action
        Log::info('Delete event: User ' . Session::get('userName') . '(' . Session::get('userId') . ', ' 
                 . Session::get('userGroup') . ') deleted event ' . $event->evnt_title . ' (ID:' . $event->id . ').');

        $schedule = $event->getSchedule();
        
        $entries = $schedule->GetResults()->getEntries()->GetResults();
        
        // delete data in reverse order because of dependencies in database
        foreach ($entries as $entry) {
            $entry->delete();
        }

        $schedule->delete();
        
        $event->delete();
        

        // show current month afterwards
        Session::put('message', Config::get('messages_de.event-delete-ok'));
        Session::put('msgType', 'success');
        return Redirect::action( 'MonthController@showMonth', ['year' => date("Y"), 
                                                               'month' => date('m')] );
    }





//--------- PRIVATE FUNCTIONS ------------


    /**
    * Edit or create a clubevent with its entered information.
    * If $id is null create a new clubEvent, otherwise the clubEvent specified by $id will be edit. 
    *
    * @param int $id
    * @return ClubEvent clubEvent
    */
    private function editClubEvent($id)
    {
        $event = new ClubEvent;
        if(!is_null($id)) {
            $event = ClubEvent::findOrFail($id);        
        }
        
        // format: strings; no validation needed
        $event->evnt_title           = Input::get('title');
        $event->evnt_subtitle        = Input::get('subtitle');
        $event->evnt_public_info     = Input::get('publicInfo');
        $event->evnt_private_details = Input::get('privateDetails');    
        $event->evnt_type            = Input::get('evnt_type');

        // create new place
        if (!Place::where('plc_title', '=', Input::get('place'))->first())      
        {
            $place = new Place;
            $place->plc_title = Input::get('place');
            $place->save();

            $event->plc_id = $place->id;
        }
        // use existing place
        else    
        {
            $event->plc_id = Place::where('plc_title', '=', Input::get('place'))->first()->id;
        }

        // format: date; validate on filled value  
        if(!empty(Input::get('beginDate')))
        {
            $newBeginDate = new DateTime(Input::get('beginDate'), new DateTimeZone(Config::get('app.timezone')));
            $event->evnt_date_start = $newBeginDate->format('Y-m-d');
        }
        else
        {
            $event->evnt_date_start = date('Y-m-d', mktime(0, 0, 0, 0, 0, 0));;
        }
            
        if(!empty(Input::get('endDate')))
        {
            $newEndDate = new DateTime(Input::get('endDate'), new DateTimeZone(Config::get('app.timezone')));
            $event->evnt_date_end = $newEndDate->format('Y-m-d');
        }
        else
        {
            $event->evnt_date_end = date('Y-m-d', mktime(0, 0, 0, 0, 0, 0));;
        }
        
        // format: time; validate on filled value  
        if(!empty(Input::get('beginTime'))) $event->evnt_time_start = Input::get('beginTime');
        else $event->evnt_time_start = mktime(0, 0, 0);
        if(!empty(Input::get('endTime'))) $event->evnt_time_end = Input::get('endTime');
        else $event->evnt_time_end = mktime(0, 0, 0);
        
        // format: tinyInt; validate on filled value
        // reversed this: input=1 means "event is public", input=0 means "event is private"
        $event->evnt_is_private = (Input::get('isPrivate') == '1') ? 0 : 1;
        
        return $event;
    }


}


