<?php

namespace App\Http\Controllers;

use App\Company;
use App\Http\Requests\WaybillFormRequest;
use App\Mail\WaybillNotification;
use App\PickupRequest;
use App\Quote;
use App\Service;
use App\Services\WaybillPdfService;
use App\Skid;
use App\User;
use App\UserType;
use App\Waybill;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Mail;
use Response;
use URL;
use Validator;

class WaybillsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('no-shipping-clerks');
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        /* @var User $currentUser */
        $currentUser = $request->user();

        $search_error = false;
        if ($request->get('search')) {
            $search = Waybill::filterByUser($currentUser)->where('waybill_number', $request->get('search'))->first();

            if ($search) {
                return redirect()->action('WaybillsController@show', ['id' => $search->id]);
            } else {
                $search_error = true;
            }
        }

        $year = $request->get('year');
        $date_label = $year ? "archive for $year" : 'last 120 days';

        $waybills = Waybill::with('user', 'company')
            ->filterByUser($currentUser, $year)
            ->select('waybills.*')
            ->withCount('pickupRequest')
            ->orderByCompany()
            ->orderByDesc('waybills.created_at');

        if ($currentUser->user_type === UserType::USER) {
            $waybills = $waybills->whereIn('company_id', $currentUser->companies->pluck('id'));
        } elseif ($currentUser->user_type === UserType::SALES_REP) {
            $waybills = $waybills->whereIn('company_id', $currentUser->companies->pluck('id')->merge($currentUser->repAccounts->pluck('id')));
        }

        return view('waybills.index', [
            'waybills' => $waybills->get(),
            'date_label' => $date_label,
            'search_error' => $search_error,
        ]);
    }

    public function date(Request $request)
    {
        $currentUser = $request->user();
        $month = $request->get('month', 'now');

        $waybills = Waybill::with('user', 'company')
            ->where('created_at', '>=', Carbon::parse($month)->startOfMonth())
            ->where('created_at', '<=', Carbon::parse($month)->endOfMonth())
            ->withCount('pickupRequest')
            ->orderBy('waybills.created_at', 'DESC');

        if ($currentUser->user_type === UserType::USER) {
            $waybills = $waybills->whereIn('company_id', $currentUser->companies->pluck('id'));
        } elseif ($currentUser->user_type === UserType::SALES_REP) {
            $waybills = $waybills->whereIn('company_id', $currentUser->companies->pluck('id')->merge($currentUser->repAccounts->pluck('id')));
        }

        return view('waybills.date')
            ->with('waybills', $waybills->get())
            ->with('month', Carbon::parse($month));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        /** @var User $user */
        $user = Auth::user()->load('companies', 'companies.services');

        if (! str_contains(URL::previous(), '/waybills/preview')) {
            $request->session()->remove('waybill');
        }
        $waybill = $request->session()->get('waybill', null);

        /** @var Company $company */
        $company = $request->get('client_id')
            ? $user->companies()->where('id', $request->get('client_id'))->first()
            : ($waybill
                ? $user->companies()->where('id', $waybill->company_id)->first()
                : $user->companies()->where('primary', 1)->first());

        $canadian_services = $company->services->where('active', 1)->where('service_type', 'canadian');
        $international_services = $company->services->where('active', 1)->where('service_type', 'international');
        $fulfillment_services = $company->services->where('active', 1)->where('service_type', 'fulfillment');

        return view('waybills.create', [
            'user' => $user,
            'company' => $company,
            'waybill' => $waybill,
            'canadian_services' => $canadian_services,
            'international_services' => $international_services,
            'fulfillment_services' => $fulfillment_services,
        ]);
    }

    public function convert(Request $request, $quote_id)
    {
        /** @var User $user */
        $user = Auth::user()->load('companies', 'companies.services');

        /** @var Quote $quote */
        $quote = Quote::where('id', $quote_id)->whereIn('company_id', $user->companies->pluck('id'))->with('details')->firstOrFail();

        /** @var Company $company */
        $company = $quote->company;

        $canadian_services = $company->services->where('active', 1)->where('service_type', 'canadian');
        $international_services = $company->services->where('active', 1)->where('service_type', 'international');
        $fulfillment_services = $company->services->where('active', 1)->where('service_type', 'fulfillment');

        $waybill = $request->session()->get('waybill', null);

        // convert quote into waybill
        if ($waybill === null) {
            $waybill = new Waybill();

            $waybill->shipper_company = $company->name;
            $waybill->shipper_contact = $user->name;
            $waybill->shipper_address_line_1 = $company->address_line_1;
            $waybill->shipper_address_line_2 = $company->address_line_2;
            $waybill->shipper_address_line_3 = $company->address3;
            $waybill->shipper_phone = $user->phone;

            $waybill->consignee_company = '360 Distribution';
            $waybill->consignee_contact = 'Jamie Czajka';
            $waybill->consignee_address_line_1 = '6201 Ace Industrial Drive';
            $waybill->consignee_address_line_3 = 'Cudahy, WI 53110';
            $waybill->consignee_phone = '866-360-7582';

            $services = [];

            foreach ($quote->details as $detail) {
                if ($detail->piece_count > 0) {
                    $id = $detail->service_id;
                    $services[$id] = [
                        'pieces' => $detail->piece_count,
                        'pounds' => $detail->pounds,
                    ] ;
                }
            }
            $waybill->previewServices = collect($services);

            $waybill->quote_number = $quote_id;
            $waybill->job_reference_number = $quote->job_reference_number;
            $waybill->notes = $quote->notes;
        }

        return view('waybills.convert', [
            'user' => $user->id,
            'company' => $company,
            'quote_id' => $quote_id,
            'waybill' => $waybill,
            'canadian_services' => $canadian_services,
            'international_services' => $international_services,
            'fulfillment_services' => $fulfillment_services,
        ]);
    }

    public function preview(WaybillFormRequest $request, WaybillPdfService $pdfService)
    {
        $waybill = new Waybill([
            'shipper_company'          => $request->get('shipper_company'),
            'shipper_contact'          => $request->get('shipper_contact'),
            'shipper_address_line_1'   => $request->get('shipper_address_line_1'),
            'shipper_address_line_2'   => $request->get('shipper_address_line_2'),
            'shipper_address_line_3'   => $request->get('shipper_address_line_3'),
            'shipper_phone'            => $request->get('shipper_phone'),
            'consignee_company'        => $request->get('consignee_company'),
            'consignee_contact'        => $request->get('consignee_contact'),
            'consignee_address_line_1' => $request->get('consignee_address_line_1'),
            'consignee_address_line_2' => $request->get('consignee_address_line_2'),
            'consignee_address_line_3' => $request->get('consignee_address_line_3'),
            'consignee_phone'          => $request->get('consignee_phone'),
            'ship_date'                => $request->get('ship_date'),
            'quote_number'             => $request->get('quote_number'),
            'job_reference_number'     => $request->get('job_reference_number'),
            'notes'                    => $request->get('notes'),
            'company_id'               => $request->get('company_id'),
        ]);

        $service_fields = array_filter($request->request->all(), function ($param) {
            return strpos($param, 'service_') === 0;
        }, ARRAY_FILTER_USE_KEY);

        $services = [];
        foreach ($service_fields as $key => $val) {
            $pounds = $request->get(str_replace('service_', 'pounds_', $key));
            $services[$val] = [
                'pieces' => $request->get(str_replace('service_', 'pieces_', $key)),
                'pounds' => $pounds != '' ? $pounds : 0,
            ];
        }

        $waybill->previewServices = collect($services);

        $preview_image = $pdfService->generatePreview($waybill);

        $request->session()->put('waybill', $waybill);
						
		/* get the international service data */
		$services_id = array_keys($services);
		
		$international_service_exist = Service::where('service_type','international')
						 ->whereIn('id', $services_id)
						 ->exists();
						 
        $quote_id = $request->get('quote_id');

        return view('waybills.preview')
            ->with('preview_image', $preview_image)
			->with('international_service_exist', $international_service_exist)
            ->with('quote_id', $quote_id);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @param WaybillPdfService $pdfService
     * @return Response
     */
    public function store(Request $request, WaybillPdfService $pdfService)
    {
        /** @var Waybill $waybill */
        $waybill = $request->session()->get('waybill', null);

        $waybill->notify_discrepancies = $request->get('notifyDiscrepancies');
		

        // pull services out of the model so we can setup a proper relation
        $services = $waybill->previewServices;
        unset($waybill->previewServices);

        // attach the current user to the waybill
        /** @var User $user */
        $user = Auth::user()->load('companies');
        $waybill->user()->associate($user);

        /** @var Company $company */
        $company = $user->companies()->where('id', $waybill->company_id)->first();
        $waybill->company_id = $company->id;

        // get the next waybill number
        $waybill_number = DB::table('waybills')->max('waybill_number') + 1;
        $waybill->waybill_number = $waybill_number;

        // set rep based on current company setting
        $waybill->sales_rep_id = $company->sales_rep_id;

        // save waybill to database
        $waybill->save();

        // if this is a converted quote, store the waybill id in the quote
        $quote_id = $request->get('quote_id');
        if ($quote_id) {
            $quote = Quote::find($quote_id);
            $quote->waybill_id = $waybill->id;
            $quote->save();
        }

        // save services then convert collection to simple array
        // for use by PDF generator
        foreach ($services as $service_id => $details) {
            $service = Service::find($service_id);
            $waybill->services()->save($service, $details);
        }
        $waybill->load('services');

        // generate PDF
        $pdf_path = $pdfService->generatePDF('S', $waybill);

        // send email notifications
        Mail::send(new WaybillNotification($user, $company, public_path('storage/temp/' . $pdf_path)));

        // clear session
        $request->session()->forget('waybill');

        // show success message with link to download waybill
        return view('waybills.complete', [
            'pdf_path' => $pdf_path,
            'waybill_id' => $waybill->id,
        ]);
    }

    public function pickup($id)
    {
        /** @var User $user */
        $user = Auth::user();
        $user->load('companies');

        /** @var Company $company */
        $company = $user->companies->where('primary', 1)->first();

        /** @var Waybill $waybill */
        $waybill = null;
        if ($id !== null) {
            $waybill = Waybill::findOrFail($id);

            if (! $user->companies->pluck('id')->contains($waybill->company_id)) {
                return redirect('/waybills');
            }
        }
        return view('waybills.pickup')
            ->with('waybill', $waybill)
            ->with('user', $user)
            ->with('company', $company);
    }

    public function storePickup(Request $request)
    {
        $rules = [
            'pickup_contact' => 'required',
            'pickup_phone' => 'required',
            'pickup_email' => 'required',
            'pickup_date' => 'required',
            'pickup_waybill_number' => 'required',
            'pickup_company' => 'required',
            'pickup_address_line_1' => 'required',
            'pickup_address_line_3' => 'required',
            'pickup_ready_time' => 'required',
            'pickup_close_time' => 'required',
            'skid_length_1' => 'nullable|numeric|required_with:skid_width_1,skid_height_1,skid_weight_1',
            'skid_width_1' => 'nullable|numeric|required_with:skid_length_1,skid_height_1,skid_weight_1',
            'skid_height_1' => 'nullable|numeric|required_with:skid_width_1,skid_length_1,skid_weight_1',
            'skid_weight_1' => 'nullable|numeric|required_with:skid_width_1,skid_height_1,skid_length_1',
            'skid_length_2' => 'nullable|numeric|required_with:skid_width_2,skid_height_2,skid_weight_2',
            'skid_width_2' => 'nullable|numeric|required_with:skid_length_2,skid_height_2,skid_weight_2',
            'skid_height_2' => 'nullable|numeric|required_with:skid_width_2,skid_length_2,skid_weight_2',
            'skid_weight_2' => 'nullable|numeric|required_with:skid_width_2,skid_height_2,skid_length_2',
            'skid_length_3' => 'nullable|numeric|required_with:skid_width_3,skid_height_3,skid_weight_3',
            'skid_width_3' => 'nullable|numeric|required_with:skid_length_3,skid_height_3,skid_weight_3',
            'skid_height_3' => 'nullable|numeric|required_with:skid_width_3,skid_length_3,skid_weight_3',
            'skid_weight_3' => 'nullable|numeric|required_with:skid_width_3,skid_height_3,skid_length_3',
            'skid_length_4' => 'nullable|numeric|required_with:skid_width_4,skid_height_4,skid_weight_4',
            'skid_width_4' => 'nullable|numeric|required_with:skid_length_4,skid_height_4,skid_weight_4',
            'skid_height_4' => 'nullable|numeric|required_with:skid_width_4,skid_length_4,skid_weight_4',
            'skid_weight_4' => 'nullable|numeric|required_with:skid_width_4,skid_height_4,skid_length_4',
            'skid_length_5' => 'nullable|numeric|required_with:skid_width_5,skid_height_5,skid_weight_5',
            'skid_width_5' => 'nullable|numeric|required_with:skid_length_5,skid_height_5,skid_weight_5',
            'skid_height_5' => 'nullable|numeric|required_with:skid_width_5,skid_length_5,skid_weight_5',
            'skid_weight_5' => 'nullable|numeric|required_with:skid_width_5,skid_height_5,skid_length_5',
        ];

        $validator = Validator::make($request->all(), $rules);

        $waybill = Waybill::where('waybill_number', $request->pickup_waybill_number)->whereIn('company_id', Auth::user()->companies->pluck('id'))->first();

        if ($waybill === null) {
            $validator->after(function ($v) {
                $v->errors()->add('pickup_waybill_number', 'Invalid waybill number');
            });
        }

        $validator->validate();

        $pickup = new PickupRequest();
        $pickup->waybill_id = $waybill->id;
        $pickup->pickup_contact = $request->pickup_contact;
        $pickup->pickup_phone = $request->pickup_phone;
        $pickup->pickup_email = $request->pickup_email;
        $pickup->pickup_date = $request->pickup_date;
        $pickup->pickup_company = $request->pickup_company;
        $pickup->pickup_address_line_1 = $request->pickup_address_line_1;
        $pickup->pickup_address_line_2 = $request->pickup_address_line_2 !== '' ? $request->pickup_address_line_2 : null;
        $pickup->pickup_address_line_3 = $request->pickup_address_line_3;
        $pickup->pickup_ready_time = $request->pickup_ready_time;
        $pickup->pickup_close_time = $request->pickup_close_time;
        $pickup->notes = $request->notes !== '' ? $request->notes : null;
        $pickup->save();

        $fields = $request->all();

        foreach ($fields as $field => $value) {
            if (strpos($field, 'skid_length_') === 0 && $value !== '') {
                $skid_number = explode("_", $field)[2];
                $length = $value;
                $height = $request->get('skid_height_' . $skid_number);
                $width = $request->get('skid_width_' . $skid_number);
                $weight = $request->get('skid_weight_' . $skid_number);

                if ($length !== null && $height !== null && $width != null && $weight !== null) {
                    Skid::create([
                        'pickup_request_id' => $pickup->id,
                        'skid_number' => $skid_number,
                        'length' => $length,
                        'height' => $height,
                        'width' => $width,
                        'weight' => $weight,
                    ]);
                }
            }
        }

        $pickup->load('skids');

        // send email notifications
        Mail::send(new \App\Mail\PickupRequest($pickup));

        return view('waybills.pickup-complete');
    }

    /**
     * Display the specified resource.
     *
     * @param Request $request
     * @param $id
     * @param WaybillPdfService $pdfService
     * @return Response
     */
    public function show(Request $request, $id, WaybillPdfService $pdfService)
    {
        if (! is_numeric($id)) {
            return redirect('/waybills');
        }

        /* @var User $currentUser */
        $currentUser = $request->user();

        /** @var Waybill $waybill */
        $waybill = Waybill::findorFail($id);

        if ($currentUser->user_type === UserType::USER) {
            if (! $currentUser->companies->pluck('id')->contains($waybill->company_id)) {
                return redirect('/waybills');
            }
        } elseif($currentUser->user_type === UserType::SALES_REP) {
            if (! $currentUser->companies->pluck('id')->contains($waybill->company_id) &&
                ! $currentUser->repAccounts->pluck('id')->contains($waybill->company_id)) {
                return redirect('/waybills');
            }
        }

        $newServices = [];

        foreach ($waybill->services as $service) {
            $newServices[$service->id] = [
                'pieces' => $service->pivot->pieces,
                'pounds' => $service->pivot->pounds,
            ];
        }
        $waybill->services = $newServices;

        $pdfService->downloadPDF($waybill);
    }
}
