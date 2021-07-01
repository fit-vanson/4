<?php

namespace App\Http\Controllers;

use App\Models\AdsenseReport;
use App\Models\AdsenseAccount;
use Carbon\Carbon;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Mockery\CountValidator\Exception;


class CronController extends Controller
{
    //
    protected $client;
    protected $config;
    protected $token;

    function __construct()
    {

    }

    public function getIndex(Request $request)
    {

        $rs = null;
        $array_toview = array();
        $acc_service = null;
        $client = null;
        $service = null;
        if ($request->start == '') {
            if (date("d") >= 2) {
                $request->start = date('Y-m-d', strtotime('first day of this month', time()));
            } else {
                $request->start = date('Y-m-d', strtotime('last month'));
            }
        }
        if ($request->end == '') $request->end = date('Y-m-d', strtotime('this month'));
        $time_to_cron = Carbon::now()->subMinute(30);
        $time_to_cron->setTimezone('Asia/Ho_Chi_Minh');

        echo $time_to_cron . PHP_EOL;
        $adsense_account = AdsenseAccount::where('updated_at', '<=', $time_to_cron)->limit(5)->get();
//        $adsense_account = AdsenseAccount::all();
//        $adsense_account = AdsenseAccount::where('adsense_pub_id', 8914453978634437)->get();
//        $adsense_account = AdsenseAccount::where('adsense_pub_id', 4095363882508864)->get();

        if ($adsense_account) {
            foreach ($adsense_account as $item) {
                $item->updated_at = Carbon::now();
                $item->error = "OK";
                $item->save();
                echo '<br/>'.'Dang chay Pub ID:' . $item->adsense_pub_id . PHP_EOL;
                $client = $this->get_gclient($request, $item);
                try {
                    $refresh = \GuzzleHttp\json_decode($item->access_token_full);
                    $client->refreshToken($refresh->refresh_token);
                    $client->verifyIdToken();
                    $access_token = $client->getAccessToken();
                    $service = new \Google_Service_AdSense($client);
                    $acc_service = $this->get_user($service);
                    $user_id = $acc_service["id"];
                    $array_data['account'] = $acc_service;
                    $alerts = $service->accounts_alerts->listAccountsAlerts('accounts/pub-' . $user_id);
                    $payments = $service->accounts_payments->listAccountsPayments('accounts/pub-' . $user_id);
                    $array_data['alerts'] = $alerts->getAlerts();
                    $array_data['payments'] = $payments->getPayments();


                    if ($request->type == 'act') {
                        $params = array(
                            'metrics' => array('INDIVIDUAL_AD_IMPRESSIONS', 'PAGE_VIEWS', 'CLICKS', 'COST_PER_CLICK', 'AD_REQUESTS_CTR', 'PAGE_VIEWS_RPM', 'ESTIMATED_EARNINGS'),
                            'dimensions' => array('MONTH'),
                        );
                        $array_data['metrics'] = array('Date', 'Impressions', 'Page views', 'Clicks',
                            'CPC($)', 'CTR(%)', 'RPM', 'Earnings($)');
                        $array_data['coloumpercent'] = 6;
                    } elseif ($request->type == 'nagion') {
                        $params = array(
                            'metrics' => array('INDIVIDUAL_AD_IMPRESSIONS', 'PAGE_VIEWS', 'CLICKS', 'COST_PER_CLICK', 'AD_REQUESTS_CTR', 'PAGE_VIEWS_RPM', 'ESTIMATED_EARNINGS'),
                            'dimensions' => array('DATE', 'COUNTRY_NAME'),[]
                        );
                        $array_data['metrics'] = array('Date', 'COUNTRY NAME', 'Impressions', 'Page views', 'Clicks', 'CPC($)', 'CTR(%)', 'RPM', 'Earnings($)');
                        $array_data['coloumpercent'] = 6;
                    } elseif ($request->type == 'month') {
                        $params = array(
                            'metrics' => array('ESTIMATED_EARNINGS'),
                            'dimensions' => 'DATE',
                        );
                        $array_data['metrics'] = array('Domain Name', 'Earnings($)');
                        $array_data['coloumpercent'] = false;
                    } else {
                        $params = array(
                            'metrics' => array('INDIVIDUAL_AD_IMPRESSIONS', 'PAGE_VIEWS', 'CLICKS', 'COST_PER_CLICK', 'AD_REQUESTS_CTR', 'PAGE_VIEWS_RPM', 'ESTIMATED_EARNINGS'),
                            'dimensions' => array('DATE'),
                        );
                        $array_data['metrics'] = array('Date', 'Impressions', 'Page views', 'Clicks', 'CPC($)', 'CTR(%)', 'RPM', 'Earnings($)');
                        $array_data['coloumpercent'] = 5;
                    }
                    $startDate = array(
                        'startDate.day' => getdate(strtotime($request->start))['mday'],
                        'startDate.year' => getdate(strtotime($request->start))['year'],
                        'startDate.month' => getdate(strtotime($request->start))['mon'],
                    );
                    $endDate = array(
                        'endDate.day' =>getdate(strtotime($request->end))['mday'],
                        'endDate.year' => getdate(strtotime($request->end))['year'],
                        'endDate.month' => getdate(strtotime($request->end))['mon'],
                    );
                    $optParams = array_merge($startDate,$endDate,$params);
                    $rs = $service->accounts_reports->generate('accounts/pub-' . $user_id,$optParams);
                    $array_data['report'] = $rs;
                    //dd($rs);

                    dd($array_data);

                    $array_toview = $array_data;
                    if (isset($rs) && isset($rs['rows'])) {
//                    if (count((array)$array_data['report']['rows']) > 0) {
                        foreach (array_reverse($array_toview['report']['rows']) as $item) {
                            AdsenseReport::updateOrCreate(
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
                            echo '<br/>'.$item[3] . PHP_EOL;
                        }
                    }


                }
                catch(\Google_Service_Exception $ex){
                    dd($ex->GetMessage());
                    $item->status = 0;
                    $item->error = $ex->GetMessage();
                    dd($item);
                    $item->save();
                }
                catch (Exception $ex) {
                    dd($ex->getMessage());
                    $item->status = 0;
                    $item->error = $ex->GetMessage();
                    $item->save();
                    echo 'getIndex ==> '.$ex->getMessage();
                }
            }


        } else {
            echo 'Chưa đến time cron' . PHP_EOL;
        }


    }

    function get_get_token_callback(Request $request)
    {
        $adsense_account = AdsenseAccount::where('id', $request->state)->first();
        $g_client = $this->get_gclient($request, $adsense_account);

        if (isset($request->code) && $request->code != '') {
            try{
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

            }catch (\Exception $exception){
                echo 'get_get_token_callback ==> '.$exception->getMessage();
            }
            //echo '<META http-equiv="refresh" content="0;URL=' . url("googleadsense/index") . '">';
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
            try {
                $code = $request->code;
                $auth_result = $g_client->authenticate($code);

                $access_token = $g_client->getAccessToken();


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
                    AdsenseAccount::updateOrCreate([
                        'adsense_pub_id' => $user_id,
                    ], [

                        'adsense_name' => $user_name,
                        'access_token_full' => \GuzzleHttp\json_encode($access_token),
                        'access_token' => \GuzzleHttp\json_decode(\GuzzleHttp\json_encode($access_token))->access_token
                    ]);
                }
            } catch (\Exception $exception) {
                $adsense_account->status = 0;
                $adsense_account->error = $exception->GetMessage();
                $adsense_account->save();
                echo 'get_get_ga_token ==> '.$exception->getMessage();
            }

            return;

        }
        $authUrl = $g_client->createAuthUrl();
        echo '<META http-equiv="refresh" content="0;URL=' . $authUrl . '">';
        return;
    }


    function get_user($service)
    {
        try {
            $info = $service->accounts;
            $item = $info->listAccounts();


            $acc = $item['accounts'][0];

            $result["id"] = preg_replace("/[^0-9]/", "", $acc['name']);
            $result["name"] = $acc['displayName'];
            $result["create"] = date("Y-m-d",strtotime($acc['createTime']));

            return $result;
        } catch (\Exception $exception) {
            echo 'get_user ==> '.$exception->getMessage();
        }
    }

    function get_gclient($request, $adsense_account)
    {
        //	g_client_id, g_secret
        //	g_dev_key
        try {
            $g_client = new Google_Client();
            $this->config = Config::get('google');
            // set application name
            $g_client->setApplicationName(Arr::get($this->config, 'application_name', ''));

            // set oauth2 configs
            $g_client->setClientId($adsense_account->g_client_id);
            $g_client->setClientSecret($adsense_account->g_secret);

            $redirect_uri = $request->fullUrl();
            $redirect_uri = substr($redirect_uri, 0, strrpos($redirect_uri, '/'));
            $redirect_uri .= "/get-token-callback";
            $g_client->setRedirectUri($redirect_uri);
            $g_client->setState(array('a_id' => $adsense_account->id));
            $g_client->setScopes(Arr::get($this->config, 'scopes', []));
            $g_client->setAccessType(Arr::get($this->config, 'access_type', 'offline'));
            $g_client->setApprovalPrompt('force');
            $g_client->setRequestVisibleActions('http://schema.org/AddAction');
            $g_client->setDeveloperKey($adsense_account->g_dev_key);

            return $g_client;
        } catch (\Exception $exception) {
            echo 'get_gclient ==> '.$exception->getMessage();
            return;
        }
    }

}
