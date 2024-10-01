<?php

namespace Modules\Gateways\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Modules\Gateways\Traits\Processor;
use Modules\Gateways\Entities\PaymentRequest;

class IntouchPayController extends Controller
{
    use Processor;

    private mixed $config_values;
    private $agency_code;
    private $login_agent;
    private $password_agent;
    private string $config_mode;
    private PaymentRequest $payment;

    public function __construct(PaymentRequest $payment)
    {
        // Charger les configurations de l'API Intouch
        $config = $this->payment_config('intouch', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
        }

        if ($config) {
            $this->agency_code = $this->config_values->agency_code;
            $this->login_agent = $this->config_values->login_agent;
            $this->password_agent = $this->config_values->password_agent;
            $this->config_mode = ($config->mode == 'test') ? 'test' : 'live';
        }

        $this->payment = $payment;
    }

    public function paymentO(Request $req): View|Application|Factory|JsonResponse|\Illuminate\Contracts\Foundation\Application|RedirectResponse
    {
        $validator = Validator::make($req->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $req['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        return view('Gateways::payment.intouchOM', compact('data'));
    }


    public function callbackO(Request $request): Application|JsonResponse|Redirector|\Illuminate\Contracts\Foundation\Application|RedirectResponse
    {
        $payment_id = $request->paymentID;
        $mobile_number = (string)$request->mobile_number;
        // Faire le paiement
        $this->makePaymentO($payment_id, $mobile_number);
        
        // Attendre 20 secondes pour permettre à l'utilisateur de finaliser le paiement
        sleep(60);

        $response = $this->checkPaymentStatus($payment_id);
        
        if ($response->json('status') == 'SUCCESSFUL') {
            $this->payment::where(['id' => $payment_id])->update([
                'payment_method' => 'intouch',
                'is_paid' => 1,
                'transaction_id' => $response->json('transactionId'),
            ]);

            $data = $this->payment::where(['id' => $payment_id])->first();

            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }
            return $this->payment_response($data, 'success');
        }
        $payment_data = $this->payment::where(['id' => $payment_id])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data, 'fail');
    }


    // Méthode pour effectuer le paiement via l'API Intouch
    public function makePaymentO($payment_id, $mobile_number): PromiseInterface|Response
    {
        $payment_data = $this->payment::where(['id' => $payment_id])->first();
        $base_url = $this->config_mode == 'test' ? 'https://sandbox.intouch.net/api' : 'https://apidist.gutouch.net/apidist/sec';

        $amount = (string)$payment_data->payment_amount;
        $callback_url = (string)route('intouchOM.callback');

        // Construction de l'URL et des paramètres pour Intouch
        $url = "{$base_url}/touchpayapi/{$this->agency_code}/transaction?loginAgent={$this->login_agent}&passwordAgent={$this->password_agent}";
        $params = [
            'idFromClient' => $payment_id,
            'additionnalInfos' => [
                'recipientEmail' => 'dzokoukegni@gmail.com',
                'recipientFirstName' => 'borel',
                'recipientLastName' => 'marco',
                'destinataire' => $mobile_number,
            ],
            'amount' => $amount,
            'callback' => $callback_url,
            'recipientNumber' => $mobile_number,
            'serviceCode' => 'CM_PAIEMENTMARCHAND_OM_TP',
        ];

        // Effectuer la requête PUT à l'API Intouch
        $response = Http::withBasicAuth($this->login_agent, $this->password_agent)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put($url, $params);

        return $response;
    }


    public function checkPaymentStatus($payment_id): PromiseInterface|Response
{
    // Récupérer les détails du paiement à partir de la base de données
    $payment = $this->payment::where('id', $payment_id)->first();

    if (!$payment) {
        return response()->json(['error' => 'Payment not found'], 404);
    }

    // Appel à l'API pour vérifier le statut du paiement
    $response = Http::withHeaders([
        'Authorization' => 'Basic M0NFRDlCQTdFNzY3NTk1MjI0MTcwMUM5N0YwMTVENkRFQUM0RkExOTdDNjczMkRBNUJGMkJFNDZDNTM2Rjc0QjpGNDFBNjFBMTJCOTU1NzE1QzJFNDhFN0JBRTkxQTlDMjhERThDRkZEN0UzRTg4MUIwRUJBNUFGMDM0NUYwQTAw',
        'Content-Type' => 'application/json',
    ])->post('https://apidist.gutouch.net/apidist/sec/NKWEK10292/check_status', [
        'partner_id' => 'PAW2393',
        'partner_transaction_id' => $payment_id,
        'login_api' => '655792588',
        'password_api' => '7BjcsTQcLY',
    ]);
    // Gérer la réponse de l'API
    return $response;
}

}
