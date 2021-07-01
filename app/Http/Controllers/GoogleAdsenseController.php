<?php

namespace App\Http\Controllers;

use App\Models\AdsenseReport;
use Carbon\Carbon;
use App\Models\AdsenseAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery\CountValidator\Exception;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;

class GoogleAdsenseController extends Controller
{
    //
    protected $client;
    protected $config;
    protected $token;

    public function __construct($token = '')
    {
    }

    public function index()
    {

    }

    public function getIndex(Request $request)
    {
        $rs = null;
        $array_toview = array();
        $acc_service = null;
        $client = null;
        if ($request->start == '') {
            if (date("d") >= 2) {
                $request->start = date('Y-m-d', strtotime('first day of this month', time()));
            } else {
                $request->start = date('Y-m-d', strtotime('last month'));
            }
        }
        if ($request->end == '') $request->end = date('Y-m-d', strtotime('this month'));

        if ($request->pub_id != '') {
            $adsense_account = AdsenseAccount::where('adsense_pub_id', $request->pub_id)->first();

            if ($adsense_account) {
                $adsense_account->updated_at = Carbon::now();
                $adsense_account->save();
                try {
                    $client = $this->get_gclient($request, $adsense_account);
                } catch (\Google_Exception $exception) {
                    //  print_r($exception);
                    //  exit();
                }
                try {
                    $refresh = \GuzzleHttp\json_decode($adsense_account->access_token_full);
                    $client->refreshToken($refresh->refresh_token);
                    $verify = $client->verifyIdToken();
                    $access_token = $client->getAccessToken();
                    $service = new \Google_Service_AdSense($client);
                    try {

                        $acc_service = $this->get_user($service);
                        //print_r($acc_service);
                    } catch (\Google_Service_Exception $exception) {
                        print_r("lỗi: " . $exception->GetMessage());
                        exit();
                    }
                    $user_id = $acc_service["id"];
                    $array_data['account'] = $acc_service;

                    $alerts = $service->accounts_alerts->listAccountsAlerts('pub-' . $user_id);
                    $payments = $service->accounts_payments->listAccountsPayments('pub-' . $user_id);
                    $array_data['alerts'] = $alerts->getItems();
                    $array_data['payments'] = $payments->getItems();
                    if ($request->type == 'act') {
                        $params = array('metric' => array('INDIVIDUAL_AD_IMPRESSIONS', 'PAGE_VIEWS', 'CLICKS', 'COST_PER_CLICK', 'AD_REQUESTS_CTR', 'PAGE_VIEWS_RPM', 'EARNINGS'), 'dimension' => array('MONTH',), 'useTimezoneReporting' => true);
                        $array_data['metric'] = array('Date', 'Impressions', 'Page views', 'Clicks',
                            'CPC($)', 'CTR(%)', 'RPM', 'Earnings($)');
                        $array_data['coloumpercent'] = 6;
                    } elseif ($request->type == 'nagion') {
                        $params = array('metric' => array('INDIVIDUAL_AD_IMPRESSIONS', 'PAGE_VIEWS', 'CLICKS', 'COST_PER_CLICK', 'AD_REQUESTS_CTR', 'PAGE_VIEWS_RPM', 'EARNINGS'), 'dimension' => array('DATE', 'COUNTRY_NAME'), 'useTimezoneReporting' => true);
                        $array_data['metric'] = array('Date', 'COUNTRY NAME', 'Impressions', 'Page views', 'Clicks', 'CPC($)', 'CTR(%)', 'RPM', 'Earnings($)');
                        $array_data['coloumpercent'] = 6;
                    } elseif ($request->type == 'month') {
                        $params = array('metric' => array('EARNINGS'), 'useTimezoneReporting' => true, 'dimension' => 'DATE');
                        $array_data['metric'] = array('Domain Name', 'Earnings($)');
                        $array_data['coloumpercent'] = false;
                    } else {
                        $params = array('metric' => array('INDIVIDUAL_AD_IMPRESSIONS', 'PAGE_VIEWS', 'CLICKS', 'COST_PER_CLICK', 'AD_REQUESTS_CTR', 'PAGE_VIEWS_RPM', 'EARNINGS'), 'dimension' => array('DATE'), 'useTimezoneReporting' => true);
                        $array_data['metric'] = array('Date', 'Impressions', 'Page views', 'Clicks', 'CPC($)', 'CTR(%)', 'RPM', 'Earnings($)');
                        $array_data['coloumpercent'] = 5;
                    }

                    $rs = $service->reports->generate($request->start, $request->end, $params);
                    $array_data['report'] = $rs;
                    $array_toview = $array_data;
                    if (count((array)$array_data['report']['rows']) > 0) {
                        foreach (array_reverse($array_toview['report']['rows']) as $item) {
                            $a_rr = AdsenseReport::updateOrCreate(
                                [
                                    "pub_id" => $user_id,
                                    "date" => $item[0]
                                ],
                                [
                                    "pageview" => $item[2],
                                    "impression" => $item[1],
                                    "click" => $item[3],
                                    "cpc" => $item[4] ? $item[4] : 0,
                                    "ctr" => $item[5] ? $item[5] : 0,
                                    "total" => $item[7] ? $item[7] : 0,
                                ]);
                            //echo $item[3] . PHP_EOL;
                        }
                    }

                } catch (Exception $ex) {
                    Log::error($ex->getMessage());
                }
                return view('adsense.report')->with('data', $array_toview);
            } else {
                echo '<META http-equiv="refresh" content="0;URL=' . url("googleadsense/index") . '">';
            }

        } else {

            return view('adsense.index');
        }
    }

    public function postIndex(Request $request)
    {
        $adsense_account = AdsenseAccount::all();
        $arrayjson = array();
        $arrayreturn = array();
        foreach ($adsense_account as $key => $value) {
            $report = $this->getReportToday($value->adsense_pub_id);

            $total_month = $this->getReportThisMonth($value->adsense_pub_id);
            $arrayjson[$key]['id'] = $key + 1;
            $arrayjson[$key]['adsense_pub_id'] = $value->adsense_pub_id;
            $arrayjson[$key]['adsense_name'] = $value->adsense_name;


            @$arrayjson[$key]['view'] = number_format($report['pageview'], 0);
            @$arrayjson[$key]['ctr_today'] = number_format($report['ctr'], 2);
            @$arrayjson[$key]['cpc'] = number_format($report['cpc'], 2);
            @$arrayjson[$key]['total'] = number_format($report['total'], 2);
            @$arrayjson[$key]['total_month'] = number_format($total_month, 2);
            @$arrayjson[$key]['note'] = $value->note;
            // $arrayjson[$key]['error'] = $value->error;
            $arrayjson[$key]["params"] = array('view_report' => url('/googleadsense/index') . '?pub_id=' .
                $value->adsense_pub_id, "delete" => "delete_adsense_account('$value->adsense_pub_id')", "error" => $value->error);
        }
        foreach ($arrayjson as $key => $value) {
            $arrayreturn[] = array_values($value);
        }
        return json_encode(array('data' => $arrayreturn, 'draw' => 1, 'recordsTotal' => count($arrayreturn), 'recordsFiltered' => count($arrayreturn)));
    }


    public function getReportToday($pub_id)
    {
        $report_all = AdsenseReport::where("pub_id", $pub_id)->where('date', date('Y-m-d'))->first();

    }

    public function getReportThisMonth($pub_id)
    {

        $total_all = AdsenseReport::whereBetween('date', [date('Y-m-d', strtotime('first day of this month', time())), date('Y-m-d', strtotime('last day of this month', time()))])->where("pub_id", $pub_id)->sum('total');

        return $total_all;
    }

    public function deleteIndex(Request $request)
    {
        try {
            $pub_id = $request->pub_id;
            // $count = AdsenseAccount::where('adsense_pub_id', '=', $pub_id)->where("error", 'LIKE', "%error%")->count();
            $delete_item = AdsenseAccount::where('adsense_pub_id', '=', $pub_id)->where("error", 'like', "%error%")->delete();
            // $users->delete();
            $msg['error'] = 1;
            $msg['msg'] = 'Đã xóa thành công';
            $msg['data'] = $delete_item;
            return $msg;
        } catch (\Exception $exception) {
            return $exception->getMessage();
        }
    }

    function post_add_ga(Request $request)
    {
        if (isset($request->id)) {
            $adsense_account = AdsenseAccount::where('id', $request->id)->first();
            $adsense_account->g_client_id = $request->g_client_id;
            $adsense_account->g_secret = $request->g_secret;
            $adsense_account->g_dev_key = $request->g_dev_key;
            $adsense_account->note = $request->note;
            $adsense_account->save();
        } else {
            $adsense_account = AdsenseAccount::create([
                'g_client_id' => $request->g_client_id,
                'g_secret' => $request->g_secret,
                'g_dev_key' => $request->g_dev_key,
                'note' => $request->note,
            ]);
            echo '<META http-equiv="refresh" content="0;URL=' . url("googleadsense/add-ga?id=" . $adsense_account->id) . '">';
            return;
        }


    }

    function get_add_ga(Request $request)
    {
        if (isset($request->id)) {
            $adsense_account = AdsenseAccount::where('id', $request->id)->first();
            return view('adsense.add_ga')->with('dataHome', $adsense_account);
        }
        return view('adsense.add_ga')->with('dataHome', array('id' => 0, 'g_client_id' => "", 'g_secret' => "", 'access_token' => "", 'g_dev_key' => "", 'note' => ''));
    }

    function get_get_token_callback(Request $request)
    {
        $adsense_account = AdsenseAccount::where('id', $request->state)->first();
        $g_client = $this->get_gclient($request, $adsense_account);

        if (isset($request->code) && $request->code != '') {
            $code = $request->code;
            $auth_result = $g_client->authenticate($code);
            //Log::debug("auth_result: ".$auth_result['auth_result']);
            $access_token = $g_client->getAccessToken();
            //Log::debug('access_token:'.$access_token);

            $service = new \Google_Service_AdSense($g_client);
            $acc_service = $this->get_user($service);

            $user_name = $acc_service["name"];
            $user_id = $acc_service["id"];
            //$adsense_account=AdsenseAccount::where('adsense_pub_id',$user_id)->first();
            $adsense_account->adsense_pub_id = $user_id;
            $adsense_account->adsense_name = $user_name;
            $adsense_account->access_token_full = \GuzzleHttp\json_encode($access_token);
            $adsense_account->access_token = \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($access_token))->access_token;
            $adsense_account->save();

            echo '<META http-equiv="refresh" content="0;URL=' . url("googleadsense/index") . '">';
            return;

        }
        $authUrl = $g_client->createAuthUrl();
        echo '<META http-equiv="refresh" content="0;URL=' . $authUrl . '">';
        return;
    }

    function get_get_ga_token(Request $request)
    {
        if (isset($request->id)) {
            $adsense_account = AdsenseAccount::where('id', $request->id)->first();
        }
        $g_client = $this->get_gclient($request, $adsense_account);

        if (isset($request->code) && $request->code != '') {
            $code = $request->code;
            $auth_result = $g_client->authenticate($code);
            Log::debug("auth_result: " . $auth_result['auth_result']);
            $access_token = $g_client->getAccessToken();
            Log::debug('access_token:' . $access_token);

            $service = new \Google_Service_AdSense($g_client);
            $acc_service = $this->get_user($service);

            $user_name = $acc_service["name"];
            $user_id = $acc_service["id"];
            $adsense_account = AdsenseAccount::where('adsense_pub_id', $user_id)->first();
            if (count($adsense_account) > 0) {
                $adsense_account->access_token_full = \GuzzleHttp\json_encode($access_token);
                $adsense_account->access_token = \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($access_token))->access_token;
                $adsense_account->save();
            } else {
                AdsenseAccount::create([
                    'adsense_pub_id' => $user_id,
                    'adsense_name' => $user_name,
                    'access_token_full' => \GuzzleHttp\json_encode($access_token),
                    'access_token' => \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($access_token))->access_token
                ]);
            }
            echo '<META http-equiv="refresh" content="0;URL=' . url("googleadsense/index") . '">';
            return;

        }
        $authUrl = $g_client->createAuthUrl();
        echo '<META http-equiv="refresh" content="0;URL=' . $authUrl . '">';
        return;
    }


    function get_user($service)
    {
        $info = $service->accounts;
        $item = $info->listAccounts();

        Log::debug('item: ' . print_r($item, true));
        $acc = $item['items'][0];
        $result["id"] = preg_replace("/[^0-9]/", "", $acc['id']);
        $result["name"] = $acc['name'];
        $result["create"] = date("Y-m-d", substr($acc['creation_time'], 0, -3));;
        return $result;
    }

    function get_gclient($request, $adsense_account)
    {
        //	g_client_id, g_secret
        //	g_dev_key
        $g_client = new \Google_Client();
        try {
            $this->config = Config::get('google');
            // set application name
            $g_client->setApplicationName(array_get($this->config, 'application_name', ''));

            // set oauth2 configs
            $g_client->setClientId($adsense_account->g_client_id);
            $g_client->setClientSecret($adsense_account->g_secret);

            $redirect_uri = $request->fullUrl();
            $redirect_uri = substr($redirect_uri, 0, strrpos($redirect_uri, '/'));
            $redirect_uri .= "/get-token-callback";
            Log::debug('redirect_uri: ' . $redirect_uri);

            $g_client->setRedirectUri($redirect_uri);
            $g_client->setState(array('a_id' => $adsense_account->id));
            $g_client->setScopes(array_get($this->config, 'scopes', []));
            $g_client->setAccessType(array_get($this->config, 'access_type', 'offline'));
            $g_client->setApprovalPrompt('force');
            $g_client->setRequestVisibleActions('http://schema.org/AddAction');
            $g_client->setDeveloperKey($adsense_account->g_dev_key);

        } catch (\Google_Exception $exception) {
            print_r($exception);
        }
        return $g_client;
    }

}
