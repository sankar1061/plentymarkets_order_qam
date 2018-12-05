<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * Released under the GNU General Public License.
 * This free contribution made by request.
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet
 * @copyright(C) Novalnet. All rights reserved. <https://www.novalnet.de/>
 */

namespace Novalnet\Controllers;

use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;

use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Plenty\Plugin\Templates\Twig;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Mail\Contracts\MailerContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use \Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Plugin\Translation\Translator;
use \stdClass;


/**
 * Class CallbackController
 *
 * @package Novalnet\Controllers
 */
class CallbackController extends Controller
{
    use Loggable;

    /**
     * @var config
     */
    private $config;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var twig
     */
    private $twig;

    /**
     * @var transaction
     */
    private $transaction;
    
     /**
     * @var paymentService
     */
    private $paymentService;
    
    /**
     * @var orderRepository
     */
    private $orderRepository;

    /*
     * @var aryPayments
     * @Array Type of payment available - Level : 0
     */
    protected $aryPayments = ['CREDITCARD', 'INVOICE_START', 'DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'PAYPAL', 'ONLINE_TRANSFER', 'IDEAL', 'GIROPAY', 'PRZELEWY24', 'EPS', 'CASHPAYMENT'];

    /**
     * @var aryChargebacks
     * @Array Type of Chargebacks available - Level : 1
     */
    protected $aryChargebacks = ['PRZELEWY24_REFUND', 'RETURN_DEBIT_SEPA', 'REVERSAL', 'CREDITCARD_BOOKBACK', 'CREDITCARD_CHARGEBACK', 'PAYPAL_BOOKBACK', 'REFUND_BY_BANK_TRANSFER_EU', 'CASHPAYMENT_REFUND'];

    /**
     * @var aryCollection
     * @Array Type of CreditEntry payment and Collections available - Level : 2
     */
    protected $aryCollection = ['INVOICE_CREDIT', 'CREDIT_ENTRY_CREDITCARD', 'CREDIT_ENTRY_SEPA', 'DEBT_COLLECTION_SEPA', 'DEBT_COLLECTION_CREDITCARD', 'CASHPAYMENT_CREDIT'];

    /**
     * @var arySubscription
     */
    protected $arySubscription = ['SUBSCRIPTION_STOP'];

    /**
     * @var aryPaymentGroups
     */
    protected $aryPaymentGroups = [
            'novalnet_cc'   => [
                            'CREDITCARD',
                            'CREDITCARD_BOOKBACK',
                            'CREDITCARD_CHARGEBACK',
                            'CREDIT_ENTRY_CREDITCARD',
                            'DEBT_COLLECTION_CREDITCARD',
                            'SUBSCRIPTION_STOP',
                        ],
            'novalnet_sepa'  => [
                            'DIRECT_DEBIT_SEPA',
                            'RETURN_DEBIT_SEPA',
                            'CREDIT_ENTRY_SEPA',
                            'DEBT_COLLECTION_SEPA',
                            'GUARANTEED_DIRECT_DEBIT_SEPA',
                            'REFUND_BY_BANK_TRANSFER_EU',
                            'SUBSCRIPTION_STOP',
                        ],
            'novalnet_invoice' => [
                            'INVOICE_START',
                            'GUARANTEED_INVOICE',
                            'INVOICE_CREDIT',
                            'SUBSCRIPTION_STOP'
                        ],
            'novalnet_prepayment'   => [
                            'INVOICE_START',
                            'INVOICE_CREDIT',
                            'SUBSCRIPTION_STOP'
                        ],
            'novalnet_cashpayment'  => [
                            'CASHPAYMENT',
                            'CASHPAYMENT_CREDIT',
                            'CASHPAYMENT_REFUND',
                        ],
            'novalnet_banktransfer' => [
                            'ONLINE_TRANSFER',
                            'REVERSAL',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_paypal'=> [
                            'PAYPAL',
                            'SUBSCRIPTION_STOP',
                            'PAYPAL_BOOKBACK',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_ideal' => [
                            'IDEAL',
                            'REVERSAL',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_eps'   => [
                            'EPS',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_giropay'    => [
                            'GIROPAY',
                            'REFUND_BY_BANK_TRANSFER_EU'
                        ],
            'novalnet_przelewy24' => [
                            'PRZELEWY24',
                            'PRZELEWY24_REFUND'
                        ],
            ];

    /**
     * @var aryCaptureParams
     * @Array Callback Capture parameters
     */
    protected $aryCaptureParams = [];

    /**
     * @var paramsRequired
     */
    protected $paramsRequired = [];

    /**
     * @var ipAllowed
     * @IP-ADDRESS Novalnet IP, is a fixed value, DO NOT CHANGE!!!!!
     */
    protected $ipAllowed = ['195.143.189.210', '195.143.189.214'];

    /**
     * PaymentController constructor.
     *
     * @param Request $request
     * @param ConfigRepository $config
     * @param PaymentHelper $paymentHelper
     * @param Twig $twig
     * @param TransactionService $tranactionService
     */
    public function __construct(  Request $request,
                                  ConfigRepository $config,
                                  PaymentHelper $paymentHelper,
                                  PaymentService $paymentService,
                                  Twig $twig,
                                  TransactionService $tranactionService,
                                  OrderRepositoryContract $orderRepository
                                )
    {
        $this->config				= $config;
        $this->paymentHelper		= $paymentHelper;
        $this->paymentService		= $paymentService;
        $this->twig					= $twig;
        $this->transaction			= $tranactionService;
        $this->orderRepository		= $orderRepository;
        $this->aryCaptureParams		= $request->all();
        $this->paramsRequired		= ['vendor_id', 'tid', 'payment_type', 'status', 'tid_status'];

        if(!empty($this->aryCaptureParams['subs_billing']))
        {
            $this->paramsRequired[] = 'signup_tid';
        }
        elseif(isset($this->aryCaptureParams['payment_type']) && in_array($this->aryCaptureParams['payment_type'], array_merge($this->aryChargebacks, $this->aryCollection)))
        {
            $this->paramsRequired[] = 'tid_payment';
        }
     
        
    }

    /**
     * Execute callback process for the payment levels
     *
     */
    public function processCallback()
    {
        $displayTemplate = $this->validateIpAddress();

        if ($displayTemplate)
        {
            return $this->renderTemplate($displayTemplate);
        }

        $displayTemplate = $this->validateCaptureParams($this->aryCaptureParams);

        if ($displayTemplate)
        {
            return $this->renderTemplate($displayTemplate);
        }

        if(!empty($this->aryCaptureParams['signup_tid']))
        {   // Subscription
            $this->aryCaptureParams['shop_tid'] = $this->aryCaptureParams['signup_tid'];
        }
        else if(in_array($this->aryCaptureParams['payment_type'], array_merge($this->aryChargebacks, $this->aryCollection)))
        {
            $this->aryCaptureParams['shop_tid'] = $this->aryCaptureParams['tid_payment'];
        }
        else if(!empty($this->aryCaptureParams['tid']))
        {
            $this->aryCaptureParams['shop_tid'] = $this->aryCaptureParams['tid'];
        }

        if(empty($this->aryCaptureParams['vendor_activation']))
        {
            $nnTransactionHistory = $this->getOrderDetails();

            if(is_string($nnTransactionHistory))
            {
                return $this->renderTemplate($nnTransactionHistory);
            }
            
	
		
		$orderob = $this->orderObject($nnTransactionHistory->orderNo); 
		
		$orderLanguage= $this->orderLanguage($orderob);


            if($this->getPaymentTypeLevel() == 2 && $this->aryCaptureParams['tid_status'] == '100')
            {
                // Credit entry for the payment types Invoice, Prepayment and Cashpayment.
                if(in_array($this->aryCaptureParams['payment_type'], ['INVOICE_CREDIT', 'CASHPAYMENT_CREDIT']) && $this->aryCaptureParams['tid_status'] == 100)
                {
					
                    if($this->aryCaptureParams['subs_billing'] != 1)
                    {
						
                        if ($nnTransactionHistory->order_paid_amount < $nnTransactionHistory->order_total_amount)
                        {
	
                            $callbackComments  = '</br>';
                            $callbackComments .= sprintf($this->paymentHelper->getTranslatedText('callback_initial_execution',$orderLanguage), $this->aryCaptureParams['shop_tid'], ($this->aryCaptureParams['amount'] / 100), $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ).'</br>';

                            if($nnTransactionHistory->order_total_amount <= ($nnTransactionHistory->order_paid_amount + $this->aryCaptureParams['amount']))
                            {
                                $paymentConfigName = substr($nnTransactionHistory->paymentName, 9);
                                $orderStatus = $this->config->get('Novalnet.' . $paymentConfigName . '_callback_order_status');
                                $this->paymentHelper->updateOrderStatus($nnTransactionHistory->orderNo, (float)$orderStatus);
                            }

                            $this->saveTransactionLog($nnTransactionHistory);

                            $paymentData['currency']    = $this->aryCaptureParams['currency'];
                            $paymentData['paid_amount'] = (float) ($this->aryCaptureParams['amount'] / 100);
                            $paymentData['tid']         = $this->aryCaptureParams['tid'];
                            $paymentData['order_no']    = $nnTransactionHistory->orderNo;
                            $paymentData['mop']         = $nnTransactionHistory->mopId;
                            $this->paymentHelper->createPlentyPayment($paymentData);
                            $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                            $this->sendCallbackMail($callbackComments);
                            return $this->renderTemplate($callbackComments);
                        }
                        else
                        {
                            return $this->renderTemplate('Novalnet callback received. Callback Script executed already. Refer Order :'.$nnTransactionHistory->orderNo);
                        }
                    }
                }
                else
                {
                    return $this->renderTemplate('Novalnet Callbackscript received. Payment type ( '.$this->aryCaptureParams['payment_type'].' ) is not applicable for this process!');
                }
            }
            else if($this->getPaymentTypeLevel() == 1 && $this->aryCaptureParams['tid_status'] == 100)
            {
                $callbackComments = '</br>';
                $callbackComments .= (in_array($this->aryCaptureParams['payment_type'], ['CREDITCARD_BOOKBACK', 'PAYPAL_BOOKBACK', 'REFUND_BY_BANK_TRANSFER_EU', 'PRZELEWY24_REFUND', 'CASHPAYMENT_REFUND'])) ? sprintf($this->paymentHelper->getTranslatedText('callback_bookback_execution',$orderLanguage), $nnTransactionHistory->tid, sprintf('%0.2f', ($this->aryCaptureParams['amount']/100)) , $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ) . '</br>' : sprintf( $this->paymentHelper->getTranslatedText('callback_chargeback_execution',$orderLanguage), $nnTransactionHistory->tid, sprintf( '%0.2f',( $this->aryCaptureParams['amount']/100) ), $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ) . '</br>';

                $this->saveTransactionLog($nnTransactionHistory);

                $paymentData['currency']    = $this->aryCaptureParams['currency'];
                $paymentData['paid_amount'] = (float) ($this->aryCaptureParams['amount']/100);
                $paymentData['tid']         = $this->aryCaptureParams['tid'];
                $paymentData['type']        = 'debit';
                $paymentData['order_no']    = $nnTransactionHistory->orderNo;
                $paymentData['mop']         = $nnTransactionHistory->mopId;

                $this->paymentHelper->createPlentyPayment($paymentData);
                $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                $this->sendCallbackMail($callbackComments);
                return $this->renderTemplate($callbackComments);
            }
            elseif($this->getPaymentTypeLevel() == 0 )
            {
                if(in_array($this->aryCaptureParams['payment_type'], ['PAYPAL','PRZELEWY24']) && $this->aryCaptureParams['status'] == '100' && $this->aryCaptureParams['tid_status'] == '100')
                {
                    if ($nnTransactionHistory->order_paid_amount < $nnTransactionHistory->order_total_amount)
                    {
                        $callbackComments  = '</br>';
                        $callbackComments .= sprintf($this->paymentHelper->getTranslatedText('callback_initial_execution',$orderLanguage), $this->aryCaptureParams['shop_tid'], ($this->aryCaptureParams['amount']/100), $this->aryCaptureParams['currency'], date('Y-m-d H:i:s'), $this->aryCaptureParams['tid'] ).'</br>';

                        $this->saveTransactionLog($nnTransactionHistory, true);

                        $paymentData['currency']    = $this->aryCaptureParams['currency'];
                        $paymentData['paid_amount'] = (float) ($this->aryCaptureParams['amount']/100);
                        $paymentData['tid']         = $this->aryCaptureParams['tid'];
                        $paymentData['order_no']    = $nnTransactionHistory->orderNo;
                        $paymentData['mop']         = $nnTransactionHistory->mopId;
                        $orderStatus = (float) $this->config->get('Novalnet.order_completion_status');

                        $this->paymentHelper->createPlentyPayment($paymentData);
                        $this->paymentHelper->updateOrderStatus($nnTransactionHistory->orderNo, $orderStatus);
                        $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                        $this->sendCallbackMail($callbackComments);

                        return $this->renderTemplate($callbackComments);
                    }
                    else
                    {
                        return $this->renderTemplate('Novalnet Callbackscript received. Order already Paid');
                    }
                }
                elseif('PRZELEWY24' == $this->aryCaptureParams['payment_type'] && (!in_array($this->aryCaptureParams['tid_status'], ['100','86']) || '100' != $this->aryCaptureParams['status']))
                {
                    // Przelewy24 cancel.
                    $callbackComments = '</br>' . sprintf($this->paymentHelper->getTranslatedText('callback_transaction_cancellation',$orderLanguage),$this->paymentHelper->getNovalnetStatusText($this->aryCaptureParams) ) . '</br>';
                    $orderStatus = (float) $this->config->get('Novalnet.order_cancel_status');
                    $this->paymentHelper->updateOrderStatus($nnTransactionHistory->orderNo, $orderStatus);
                    $this->paymentHelper->createOrderComments($nnTransactionHistory->orderNo, $callbackComments);
                    $this->sendCallbackMail($callbackComments);
                    return $this->renderTemplate($callbackComments);
                }
                else
                {
               $error = 'Novalnet Callbackscript received. Payment type ( '.$this->aryCaptureParams['payment_type'].' ) is not applicable for this process!';
                    return $this->renderTemplate($error);
                }
            }
            else
            {
                return $this->renderTemplate('Novalnet callback received. TID Status ('.$this->aryCaptureParams['tid_status'].') is not valid: Only 100 is allowed');
            }
        }

        return $this->renderTemplate('Novalnet callback received. Callback Script executed already.');
    }

    /**
     * Validate the IP control check
     *
     * @return bool|string
     */
    public function validateIpAddress()
    {
        $client_ip = $this->paymentHelper->getRemoteAddress();
        if(!in_array($client_ip, $this->ipAllowed) && $this->config->get('Novalnet.callback_test_mode') != 'true')
        {
            return 'Novalnet callback received. Unauthorised access from the IP '. $client_ip;
        }
        return '';
    }

    /**
     * Validate request param
     *
     * @param array $data
     * @return array|string
     */
    public function validateCaptureParams($aryCaptureParams)
    {
        if(!isset($aryCaptureParams['vendor_activation']))
        {
            foreach ($this->paramsRequired as $param)
            {
                if (empty($aryCaptureParams[$param]))
                {
                    return 'Required param ( ' . $param . '  ) missing!';
                }

                if (in_array($param, ['tid', 'tid_payment', 'signup_tid']) && !preg_match('/^\d{17}$/', $aryCaptureParams[$param]))
                {
                    return 'Novalnet callback received. Invalid TID ['. $aryCaptureParams[$param] . '] for Order.';
                }
            }
        }

        return '';
    }

    /**
     * Find and retrieves the shop order ID for the Novalnet transaction
     *
     * @return object|string
     */
    public function getOrderDetails()
    {
        $order = $this->transaction->getTransactionData('tid', $this->aryCaptureParams['shop_tid']);
        
        $orderId= (!empty($this->aryCaptureParams['order_no'])) ? $this->aryCaptureParams['order_no'] : '';

        if(!empty($order))
        {
            $orderDetails = $order[0]; // Setting up the order details fetched
            $orderObj                     = pluginApp(stdClass::class);
             
            $orderObj->tid                = $this->aryCaptureParams['shop_tid'];
            $orderObj->order_total_amount = $orderDetails->amount;
            // Collect paid amount information from the novalnet_callback_history
            $orderObj->order_paid_amount  = 0;
            $orderObj->orderNo            = $orderDetails->orderNo;
            $orderObj->paymentName        = $orderDetails->paymentName;
            
            $mop = $this->paymentHelper->getPaymentMethodByKey(strtolower($orderDetails->paymentName));
            $orderObj->mopId              = $mop;

            $paymentTypeLevel = $this->getPaymentTypeLevel();

            if ($paymentTypeLevel != 1)
            {
                $orderAmountTotal = $this->transaction->getTransactionData('orderNo', $orderDetails->orderNo);
                if(!empty($orderAmountTotal))
                {
                    $amount = 0;
                    foreach($orderAmountTotal as $data)
                    {
                        $amount += $data->callbackAmount;
                    }
                    $orderObj->order_paid_amount = $amount;
                }
            }

            if (!isset($orderDetails->paymentName) || !in_array($this->aryCaptureParams['payment_type'], $this->aryPaymentGroups[$orderDetails->paymentName]))
            {
                return 'Novalnet callback received. Payment Type [' . $this->aryCaptureParams['payment_type'] . '] is not valid.';
            }

            if (!empty($this->aryCaptureParams['order_no']) && $this->aryCaptureParams['order_no'] != $orderDetails->orderNo)
            {
                return 'Novalnet callback received. Order Number is not valid.';
            }
        }
        else
		{ 
			if(!empty($orderId))
			{
				$order_ref = $this->orderObject($orderId);
				if(empty($order_ref))
				{
				$mailNotification = $this->build_notification_message();
				$message = $mailNotification['message'];
				$subject = $mailNotification['subject'];
			
				$mailer = pluginApp(MailerContract::class);
				$mailer->sendHtml($message,'technic@novalnet.de',$subject,[],[]);
                return $this->renderTemplate($mailNotification['message']);
				}
				
			
				 
				$this->handleCommunicationBreak($order_ref);
				return  $this->renderTemplate('Novalnet handlecommunication break executed successfully.');
				
			
			}
			else{
					return 'Transaction mapping failed';
				}
        }

        return $orderObj;
    }
    
    
    /**
     * Build the mail subject and message for the Novalnet Technic Team
     * 
     * @return array
     */
    function build_notification_message() {

    $subject = 'Critical error on shop system plentymarkets:seo: order not found for TID: ' . $this->aryCaptureParams['shop_tid'];
    $message = "Dear Technic team,<br/><br/>Please evaluate this transaction and contact our Technic team and Backend team at Novalnet.<br/><br/>";
    foreach(array('vendor_id', 'product_id', 'tid', 'tid_payment', 'tid_status', 'order_no', 'payment_type', 'email') as $key) {
        if (!empty($this->aryCaptureParams[$key])) {
                            $message .= "$key: " . $this->aryCaptureParams[$key] . '<br/>';
                    }
    }
    
    return array('subject'=>$subject, 'message'=>$message);
    }

    
    /**
     * Retrieves the order object from shop order ID
     *
     * @return object
     */
    public function orderObject($orderId)
    {
	  $orderId = (int)$orderId;
		$authHelper = pluginApp(AuthHelper::class);
				$order_ref = $authHelper->processUnguarded(
                function () use ($orderId) {
					$order_obj = $this->orderRepository->findOrderById($orderId);
			
					
					return $order_obj;
				});
				
				return $order_ref;
		
	}
	
	
	/**
     * Get the order language based on the order object
     *
     * @return string
     */
	public function orderLanguage($orderObj)
	{
		foreach($orderObj->properties as $property)
		{
			if($property->typeId == '6' )
			{
				$language = $property->value;
		
				
				return $language;
			}
	    }
	}

    /**
     * Get the callback payment level based on the payment type
     *
     * @return int
     */
    public function getPaymentTypeLevel()
    {
        if(in_array($this->aryCaptureParams['payment_type'], $this->aryPayments))
        {
            return 0;
        }
        else if(in_array($this->aryCaptureParams['payment_type'], $this->aryChargebacks))
        {
            return 1;
        }
        else if(in_array($this->aryCaptureParams['payment_type'], $this->aryCollection))
        {
            return 2;
        }
    }

    /**
     * Setup the transction log for the callback executed
     *
     * @param $txnHistory
     * @param $initialLevel
     */
    public function saveTransactionLog($txnHistory, $initialLevel = false)
    {
        $insertTransactionLog['callback_amount'] = ($initialLevel) ? $txnHistory->order_total_amount : $this->aryCaptureParams['amount'];
        $insertTransactionLog['amount']          = $txnHistory->order_total_amount;
        $insertTransactionLog['tid']             = $this->aryCaptureParams['shop_tid'];
        $insertTransactionLog['ref_tid']         = $this->aryCaptureParams['tid'];
        $insertTransactionLog['payment_name']    = $txnHistory->paymentName;
        $insertTransactionLog['order_no']        = $txnHistory->orderNo;
 
        $this->transaction->saveTransaction($insertTransactionLog);
    }

    /**
     * Send the vendor script email for the execution
     *
     * @param $mailContent
     * @return bool
     */
    public function sendCallbackMail($mailContent)
    {
        try
        {
            $enableTestMail = ($this->config->get('Novalnet.enable_email') == 'true');

            if($enableTestMail)
            {
                $toAddress  = $this->config->get('Novalnet.email_to');
                $bccAddress = $this->config->get('Novalnet.email_bcc');
                $subject    = 'Novalnet Callback Script Access Report';

                if(!empty($bccAddress))
                {
                    $bccMail = explode(',', $bccAddress);
                }
                else
                {
                    $bccMail = [];
                }

                $ccAddress = []; # Setting it empty as we handle only to and bcc addresses.

                $mailer = pluginApp(MailerContract::class);
                $mailer->sendHtml($mailContent, $toAddress, $subject, $ccAddress, $bccMail);
            }
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Novalnet::CallbackMailNotSend', $e);
            return false;
        }
    }

    /**
     * Render twig template for callback message
     *
     * @param $templateData
     * @return string
     */
    public function renderTemplate($templateData)
    {
        return $this->twig->render('Novalnet::callback.callback', ['comments' => $templateData]);
    }
    
    /**
     * Handling communication breakup
     *
     * @param array $orderObj
     * @return none
	 */
    public function handleCommunicationBreak($orderObj)
    
    {
	    $orderlanguage = $this->orderLanguage($orderObj);
	    
		if(in_array($this->aryCaptureParams['payment_type'],array('PAYPAL', 'ONLINE_TRANSFER', 'IDEAL', 'GIROPAY', 'PRZELEWY24', 'EPS','CREDITCARD')))
		foreach($orderObj->properties as $property)
		{
			
			if($property->typeId == '3' && $this->paymentHelper->isNovalnetPaymentMethod($property->value))
			{
				
				$requestData = $this->aryCaptureParams;
				$requestData['lang'] = $orderlanguage; 
				$requestData['mop']= $property->value;
				$payment_type = (string)$this->paymentHelper->getPaymentKeyByMop($property->value);
				$requestData['payment_id'] = $this->paymentService->getkeyByPaymentKey($payment_type); 
					
					$transactionData						= pluginApp(stdClass::class);
					
                    $transactionData->paymentName			= $this->paymentHelper->getPaymentNameByResponse($requestData['payment_id']);  
                    $transactionData->orderNo				= $requestData['order_no'];
                    $transactionData->order_total_amount	= $requestData['amount'];
							
					
					
				if($this->aryCaptureParams['status'] == '100' && in_array($this->aryCaptureParams['tid_status'],array(85,86,90,100)))
				{
					
					$this->paymentService->executePayment($requestData);
                    $this->saveTransactionLog($transactionData);
					
					
				}
				else{
					$requestData['type'] = 'cancel';
					$this->paymentService->executePayment($requestData,true);
					$this->aryCaptureParams['amount'] = '0';
					
					 $this->saveTransactionLog($transactionData);
					

					}
					$callbackComments = $this->paymentHelper->getTranslatedText('callback_handlecommunication',$requestData['lang']). date('Y-m-d H:i:s');
					
					$this->paymentHelper->createOrderComments($this->aryCaptureParams['order_no'], $callbackComments);
					$this->sendCallbackMail($callbackComments);
					return $this->renderTemplate($callbackComments);
					
		
			} else {
					
				return 'Novalnet callback received: Given payment type is not matched.';
			}
	}
	return $this->renderTemplate('Novalnet_callback script executed.');
		
	}
	
}
