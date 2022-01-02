<?php

namespace App\Http\Controllers\campaigns;

use App\Http\Controllers\Controller;
use App\Model\campaign;
use App\Model\campaignorders;
use App\Model\campaignTransaction;
use App\Model\commissionratioModel;
use App\Model\NotificationAlertModel;
use App\Model\WalletHistoryModel;
use App\Transaction;
use Auth;
use Illuminate\Http\Request;
use Mail;

class orderHistoryController extends Controller
{
    public function __construct()
    {
        $this->outputData = array();
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function run($id)
    {
        $Id = base64_decode($id);
        $RunCam = campaign::find($Id);

        $ordr = new campaignorders;
        $ordr->campaign_id = $Id;
        $ordr->publisher_id = Auth::user()->id;
        $ordr->advertiser_id = $RunCam->advertiser_id;
        $ordr->advert_code = $this->code();
        $ordr->campaign_price = $RunCam->campaign_cost_per_action;
        $ordr->save();

        $Notify = new NotificationAlertModel;
        $Notify->heading = $RunCam['campaign_title'] . " Campaign is Activated";
        $Notify->content = $RunCam['campaign_title'] . " Campaign is Activated, for more information read embedd details";
        $Notify->type = "Campaign is Activated";
        $Notify->Notify_Receivers_Id = Auth::user()->id;
        $Notify->save();

        \Session::flash('flash_message', "Campaign activated successfully");
        return redirect('/campaigns/show/' . $id);
    }

    public function code()
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $string = '';
        $max = strlen($characters) - 1;
        for ($i = 0; $i < 10; $i++) {
            $string .= $characters[mt_rand(0, $max)];
        }

        if ($this->NumberExists($string)) {
            return $this->code();
        }
        return $string;
    }

    public function NumberExists($number)
    {
        return campaignorders::where('advert_code', $number)->exists();
    }

    public function formdetail(Request $request)
    {
        try {

            $input = $request->all();
            $camp_code = $input['publisher_code'];
            $advert = campaignorders::with('campaign', 'advertiser', 'publisher')
                ->where('advert_code', $camp_code)
                ->first();
            //print_r($advert->campaign);
            //print_r($advert->campaign->form_id);
            $form['formid'] = $advert->campaign->form_id;
            $form['formclass'] = $advert->campaign->form_class;
            $form['buttonid'] = $advert->campaign->button_id;
            $form['dom_modified'] = $advert->campaign->dom_modified;
            $form['selector'] = $advert->campaign->selector;

            return json_encode($form);
        } catch (\Throwable $th) {
            return json_encode(array(
                'status' => 500,
                'message' => 'error - campaign code is invalid',
            ));
        }
    }

    public function getDomain($url)
    {
        $pieces = parse_url($url);
        $domain = isset($pieces['host']) ? $pieces['host'] : '';
        if (preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $domain, $regs)) {
            return $regs['domain'];
        }
        return false;
    }

    public function credit(Request $request)
    {
        $input = $request->all();
        $method = $request->method();
        $header = $request->header();
        $destUrl = "";
        if (isset($header['referer']['0']) && !empty($header['referer']['0'])) {
            $destUrl = $header['referer']['0'];
        }

        // Check if Method Is POST Or Not
        if ($method == "POST") {

            if (!isset($input['campaign_code']) || empty($input['campaign_code'])) {
                $this->outputData['message'] = "campaign code is required ";
                return response()->json($this->outputData, 400);
            }
            /* if(!isset($input['uniq_id']) || empty($input['uniq_id']) ){
            $this->outputData['message'] = "uniq id is required";
            return response()->json($this->outputData,400);
            }*/
            $code = $input['campaign_code'];
            $uniq_id = ""; //$input['uniq_id'];
            $advert = campaignorders::with('campaign', 'advertiser', 'publisher')
                ->where('advert_code', $code)
                ->first();

            //print_r($advert);
            //$destUrl=strtok($destUrl,'?');
            $destUrl = $this->getDomain($destUrl);
            //echo "*** url ".$advert->campaign->campaign_url.", des url ".$destUrl;

            //if(!empty($advert->campaign) && $advert->campaign->campaign_url == $destUrl ){
            if (!empty($advert->campaign) && (strpos(strval($advert->campaign->campaign_url), strval($destUrl)) !== false)) {

                $startDate = date('Y-m-d H:i:s');
                // $Time   = date('Y-m-d H:i:s', strtotime("-10 minutes", strtotime($startDate)));
                $Time = date('Y-m-d H:i:s', strtotime("-1 minutes", strtotime($startDate)));
                /*$isAlredyExist = campaignTransaction::where('client_ip',$this->getUserIp())
                ->where('campaign_code',$code)
                ->where('uniq_id',$uniq_id)
                ->count();*/
                $isAlredyExist = "0";

                if ($isAlredyExist == "0") {

                    $isSpam = campaignTransaction::where('client_ip', $this->getUserIp())
                        ->where('created_at', '<', $startDate)
                        ->where('created_at', '>', $Time)
                        ->first();
                    //turn off span protect
                    // $isSpam = null;
                    // return response()->json($advert,400);
                    if (!empty($advert) && $advert->campaign->isActive == "Active" && empty($isSpam)) {

                        $tra = new campaignTransaction;
                        $tra->advertiser_id = $advert->advertiser_id;
                        $tra->publisher_id = $advert->publisher_id;
                        $tra->client_ip = $this->getUserIp();
                        $tra->uniq_id = $uniq_id;
                        $tra->campaign_code = $code;
                        $tra->save();

                        $comm = commissionratioModel::first();
                        /*dd($comm);*/
                        //$price = $advert->campaign_price;
                        $price = $advert->campaign->campaign_cost_per_action;
                        $admincommission = ((float) $price * (float) $comm->admin_ratio) / 100;
                        $publishercommission = (float) $price - $admincommission;

                        $arrAdvertAmt = Transaction::select('id', 'amount')
                            ->where('user_id', $advert->advertiser_id)
                            ->first();
                        //   return response()->json($arrAdvertAmt,400);
                        //$Amt = $price + 1;
                        $Amt = $price;
                        //echo "*** adv am ".$arrAdvertAmt['amount'].", amt ".$Amt.", transaction id ".$arrAdvertAmt['id'];
                        //print_r($arrAdvertAmt);
                        //die();
                        if ($arrAdvertAmt['amount'] >= $Amt) {
                            //$final_advert_price['amount'] = $arrAdvertAmt['amount'] - $price - 1;
                            $remainingAmt = $arrAdvertAmt['amount'] - $price;
                            $final_advert_price['amount'] = $remainingAmt;

                            Transaction::where('user_id', $advert->advertiser_id)
                                ->where('id', $arrAdvertAmt['id'])
                                ->update($final_advert_price);

                            // $arrAdminWalletAmt = Transaction::select('amount')
                            // ->where('user_id',"1")
                            // ->first();

                            //$final_Admin_price['amount'] = $arrAdminWalletAmt['amount'] + $admincommission + 1;
                            // $final_Admin_price['amount'] = $arrAdminWalletAmt['amount'] + $admincommission;

                            //$adminCommisionAmt = $admincommission + 1;
                            $adminCommisionAmt = $admincommission;

                            $wallet = new WalletHistoryModel;
                            $wallet->user_id = "1";
                            $wallet->amount = "0";
                            $wallet->commision = $adminCommisionAmt;
                            $wallet->mode = "commision";
                            $wallet->comment = "$" . number_format($adminCommisionAmt, 2) . " was Credited to your Wallet for Campaign commision of " . $advert->campaign->campaign_title;
                            $wallet->save();

                            //$advertPrice = $price - 1;
                            $advertPrice = $price;

                            // Transaction::where('user_id',"1")
                            // ->update($final_Admin_price);

                            $arrPublisherAmt = Transaction::select('amount')
                                ->where('user_id', $advert->publisher_id)
                                ->first();

                            $final_publisher_price['amount'] = $arrPublisherAmt['amount'] + $publishercommission;

                            Transaction::where('user_id', $advert->publisher_id)
                                ->update($final_publisher_price);

                            $wallet = new WalletHistoryModel;
                            $wallet->user_id = $advert->advertiser_id;
                            $wallet->amount = $advertPrice;
                            $wallet->commision = "0";
                            $wallet->mode = "debit";
                            $wallet->comment = "Debited $" . $advertPrice . "for Campaign consumed ";
                            $wallet->save();

                            $Notify = new NotificationAlertModel;
                            $Notify->heading = "Your Campaign consumed";
                            $Notify->content = "Dear " . $advert->advertiser->name . ", Your Campaign " . $advert->campaign->campaign_title . " has just been patronized today at " . $startDate . ", kindly check and report any possible Malicious Activity";
                            $Notify->type = "Campaign consumed";
                            $Notify->Notify_Receivers_Id = $advert->advertiser_id;
                            $Notify->save();

                            $AdvertiserMsg = "Dear " . $advert->advertiser->name . ", Your Campaign " . $advert->campaign->campaign_title . " has just been patronized today at " . $startDate . ", kindly check and report any possible Malicious Activity";

                            if ($advert->advertiser->login == "phone") {

                                $AccountSid = "AC68fde1a039c71651c8f132177287b3e9"; // Your Account SID from www.twilio.com/console

                                $AuthToken = "c047895b53559aaf20cd8036f294108c"; // Your Auth Token from www.twilio.com/console

                                $client = new \Services_Twilio($AccountSid, $AuthToken);

                                try {
                                    $Client_message = $client->account->messages->create(array(
                                        "From" => "+12018856171", // From a valid Twilio number
                                        "To" => $advert->advertiser->phone, // Text this number
                                        "Body" => $AdvertiserMsg,
                                    ));
                                } catch (Services_Twilio_RestException $e) {
                                }
                            } else {
                                $email = $advert->advertiser->email;
                                $heading = "Campaign Consumed";
                                $data['heading'] = $heading;
                                $data['TextToClient'] = $AdvertiserMsg;
                                Mail::send('email.campaignconsumed', $data, function ($message) use ($email) {
                                    $message->from('noreply@sparkenproduct.in', 'Adbirt');
                                    $message->to($email)->subject('Campaign Consumed');
                                });
                            }

                            $wallet = new WalletHistoryModel;
                            $wallet->user_id = $advert->publisher_id;
                            $wallet->amount = $publishercommission;
                            $wallet->commision = "0";
                            $wallet->mode = "credit";
                            $wallet->comment = "Added Cash of " . $publishercommission;
                            $wallet->save();

                            $Notify = new NotificationAlertModel;
                            $Notify->heading = "Your Promoted Campaign has been consumed";
                            $Notify->content = "Dear " . $advert->publisher->name . ", your Promoted Campaign " . $advert->campaign->campaign_title . " from " . $advert->advertiser->name . " has just been patronize today at " . $startDate . " via your website " . $destUrl . "  NOTE: We do not tolerate SPAM and Invalid Activity on your Publisher’s Account.";
                            $Notify->type = "Campaign consumed";
                            $Notify->Notify_Receivers_Id = $advert->publisher_id;
                            $Notify->save();

                            $PublisherMsg = "Dear " . $advert->publisher->name . ", your Promoted Campaign " . $advert->campaign->campaign_title . " from " . $advert->advertiser->name . " has just been patronize today at " . $startDate . " via your website " . $destUrl . " NOTE: We do not tolerate SPAM and Invalid Activity on your Publisher’s Account.";

                            if ($advert->publisher->login == "phone") {

                                $AccountSid = "AC68fde1a039c71651c8f132177287b3e9"; // Your Account SID from www.twilio.com/console

                                $AuthToken = "c047895b53559aaf20cd8036f294108c"; // Your Auth Token from www.twilio.com/console

                                $client = new \Services_Twilio($AccountSid, $AuthToken);

                                try {
                                    $Client_message = $client->account->messages->create(array(
                                        "From" => "+12018856171", // From a valid Twilio number
                                        "To" => $advert->publisher->phone, // Text this number
                                        "Body" => $PublisherMsg,
                                    ));
                                } catch (Services_Twilio_RestException $e) {
                                }
                            } else {
                                $email = $advert->publisher->email;
                                $heading = "Campaign Consumed";
                                $data['heading'] = $heading;
                                $data['TextToClient'] = $PublisherMsg;
                                Mail::send('email.campaignconsumed', $data, function ($message) use ($email) {
                                    $message->from('noreply@sparkenproduct.in', 'Adbirt');
                                    $message->to($email)->subject('Campaign Consumed');
                                });
                            }

                            //process if campaign cost have been finished
                            //echo "*** price and remaiing price $price , $remainingAmt";
                            if ($remainingAmt < $price) {
                                $status['campaign_approval_status'] = "Pending";
                                Campaign::where('id', $advert->campaign_id)
                                    ->update($status);

                                //send email
                                $email = $advert->advertiser->email;
                                $heading = "Funds exhausted, kindly top up your wallet";
                                $paymentMsg = "Dear " . $advert->advertiser->name . ", you do not have sufficient balance on your wallet. So your campaign has been stopped to continue kindly add fund on your wallet.";

                                $data['heading'] = $heading;
                                $data['TextToClient'] = $paymentMsg;
                                Mail::send('email.campaignconsumed', $data, function ($message) use ($email) {
                                    $message->from('noreply@sparkenproduct.in', 'Adbirt');
                                    $message->to($email)->subject('Campaign Consumed');
                                });
                            }

                            $this->outputData['message'] = "Verified";
                            return response()->json($this->outputData, 200);
                        } else {
                            //change campaign status and send email to advertiser
                            $status['campaign_approval_status'] = "Pending";
                            Campaign::where('id', $advert->campaign_id)
                                ->update($status);

                            //send email
                            $email = $advert->advertiser->email;
                            $heading = "Funds exhausted, kindly top up your wallet";
                            $paymentMsg = "Dear " . $advert->advertiser->name . ", you do not have sufficient balance on your wallet. So your campaign has been stopped to continue kindly add fund on your wallet.";

                            $data['heading'] = $heading;
                            $data['TextToClient'] = $paymentMsg;
                            Mail::send('email.campaignconsumed', $data, function ($message) use ($email) {
                                $message->from('noreply@sparkenproduct.in', 'Adbirt');
                                $message->to($email)->subject('Campaign Consumed');
                            });

                            $this->outputData['message'] = "Unable to proceed, not enough balance available";
                            return response()->json($this->outputData, 400);
                        }
                    } else {
                        $this->outputData['message'] = "InCorrect Campaign Code Given or alredy requested from this source";
                        return response()->json($this->outputData, 400);
                    }
                } else {
                    $this->outputData['message'] = "user exist";
                    return response()->json($this->outputData, 400);
                }
            } else {
                $this->outputData['message'] = "source host & destination host not matched";
                return response()->json($this->outputData, 400);
            }
        } else {
            $this->outputData['message'] = "method_not_allowed";
            return response()->json($this->outputData, 405);
        }
    }


    public function checkIfUrlIsValidCampaign(Request $request)
    {
        // e.g https://birotojob.com

        $input = $request->all();
        // $method = $request->method();
        // $header = $request->header();

        $url_in_question = $input['url_in_question'];
        $id = $input['code'];
        $url_type = 'success'; // 'landing' or 'success'

        if ($id) {
            $publisher = campaignorders::where('advert_code', $id)
                ->where('campaign_running_status', 'activated')
                ->first();

            $bnr = campaign::where('id', $publisher->campaign_id)
                ->where('campaign_approval_status', 'Approved')
                ->where('isActive', 'Active')
                ->where('isDeleted', 'No')
                ->first();

            if (!empty($bnr)) {

                // --
                if (isset($input['url_type'])) {
                    $url_type = $input['url_type'];
                }

                $is_valid = false;
                if ($bnr->campaign_url == $url_in_question) {
                    if ($url_type == 'landing') {
                        $is_valid = true;
                    } else {
                        $is_valid = false;
                    }
                } elseif ($bnr->campaign_success_url == $url_in_question) {
                    if ($url_type == 'success') {
                        $is_valid = true;
                    } else {
                        $is_valid = false;
                    }
                }

                $this->outputData['campaign'] = $bnr;

                // $this->outputData['campaign'] = $campaign;
                $this->outputData['type'] = $url_type;

                $this->outputData['is_valid'] = $is_valid;

                return response()->json($this->outputData, 200);
                // --

            } else {
                dd('Invalid campaign, or campaign not yet approved');
            }
        } else {
            dd('Banner ID is missing');
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function getUserIp()
    {
        if ($_SERVER['REMOTE_ADDR']) {
            return $_SERVER['REMOTE_ADDR'];
        } else {
            return false;
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
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
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
