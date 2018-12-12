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

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Frontend\Services\AccountService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;

/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{

    use Loggable;

    /**
     * @var ConfigRepository
     */
    private $config;

    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var TransactionLogData
     */
    private $transactionLogData;

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param FrontendSessionStorageFactoryContract $session
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param TransactionService $transactionLogData
     */
    public function __construct(ConfigRepository $config,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                AddressRepositoryContract $addressRepository,
                                CountryRepositoryContract $countryRepository,
                                WebstoreHelper $webstoreHelper,
                                PaymentHelper $paymentHelper,
                                TransactionService $transactionLogData)
    {
        $this->config                   = $config;
        $this->sessionStorage           = $sessionStorage;
        $this->addressRepository        = $addressRepository;
        $this->countryRepository        = $countryRepository;
        $this->webstoreHelper           = $webstoreHelper;
        $this->paymentHelper            = $paymentHelper;
        $this->transactionLogData       = $transactionLogData;
    }

    /**
     * Creates the payment for the order generated in plentymarkets.
     *
     * @param array $requestData
     *
     * @return array
     */
    public function executePayment($requestData,$callbackfailure = false)
    {
        try {
            $requestData['amount'] = (float) $requestData['amount'];
            if(!$callbackfailure)
            {

            if((in_array($requestData['payment_id'], ['34','78']) && in_array($requestData['tid_status'], ['86','90','85'])))
            {
                if($requestData['payment_id'] == '78')
                {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_przelewy_payment_pending_status'));
                }
                else
                {
                    $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_paypal_payment_pending_status'));
                }
                $requestData['paid_amount'] = 0;
            }
            elseif($requestData['payment_id'] == '41')
            {
                $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_invoice_callback_order_status'));
                $requestData['paid_amount'] = $requestData['amount'];
            }
            elseif(in_array($requestData['payment_id'], ['27','59']))
            {
                $requestData['order_status'] = trim($this->paymentHelper->getPaymentStatusByConfig($requestData['mop'], '_order_completion_status'));
                $requestData['paid_amount'] = 0;
            }
            else
            {
                $requestData['order_status'] = trim($this->paymentHelper->getPaymentStatusByConfig($requestData['mop'], '_order_completion_status'));
                $requestData['paid_amount'] = $requestData['amount'];
            }
        } else
            {
            $requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_order_cancel_status'));
            $requestData['paid_amount'] = '0';
            }

            $transactionComments = $this->getTransactionComments($requestData);
            $this->paymentHelper->createPlentyPayment($requestData);
            $this->paymentHelper->updateOrderStatus((int)$requestData['order_no'], $requestData['order_status']);
            $this->paymentHelper->createOrderComments((int)$requestData['order_no'], $transactionComments);
            return [
                'type' => 'success',
                'value' => $this->paymentHelper->getNovalnetStatusText($requestData)
            ];
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('ExecutePayment failed.', $e);
            return [
                'type'  => 'error',
                'value' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate  the response data in plentymarkets.
     *
     * @param array $requestData
     *
     */
    public function validateResponse()
        {
            
            $requestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
            if($requestData['payment_type'] == 'CASHPAYMENT' && !empty($requestData['cp_checkout_token']))
                {
                    $this->sessionStorage->getPlugin()->setValue('cashtoken',$requestData['cp_checkout_token']);
                    $this->sessionStorage->getPlugin()->setValue('testmode',$this->getBarzhalenTestMode($requestData['test_mode']));
                }
                        $this->sessionStorage->getPlugin()->setValue('nnPaymentData',null);
                        $requestData['order_no'] = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
                        $requestData['mop']      = $this->sessionStorage->getPlugin()->getValue('mop');
                        if(in_array($requestData['payment_type'],['INVOICE_START','DIRECT_DEBIT_SEPA','CASHPAYMENT']) || ($requestData['payment_type'] == 'CREDITCARD' && $this->config->get('Novalnet.novalnet_cc_3d') == 'false'))
                        {
                            $this->sendPostbackCall($requestData);
                        }
                        $paymentResult = $this->executePayment($requestData);
                        $isPrepayment = (bool)($requestData['payment_id'] == '27' && $requestData['invoice_type'] == 'PREPAYMENT');

                        $transactionData = [
                            'amount'           => $requestData['amount'] * 100,
                            'callback_amount'  => $requestData['amount'] * 100,
                            'tid'              => $requestData['tid'],
                            'ref_tid'          => $requestData['tid'],
                            'payment_name'     => $this->paymentHelper->getPaymentNameByResponse($requestData['payment_id'], $isPrepayment),
                            'payment_type'     => $requestData['payment_type'],
                            'order_no'         => $requestData['order_no'],
                        ];

                        if($requestData['payment_id'] == '27' || $requestData['payment_id'] == '59' || (in_array($requestData['tid_status'], ['85','86','90'])))
                            $transactionData['callback_amount'] = 0;

                        $this->transactionLogData->saveTransaction($transactionData);
                 }
  
    /**
     * Build transaction comments for the order
     *
     * @param array $requestData
     * @return string
     */
    public function getTransactionComments($requestData)
    {
        $lang = strtolower((string)$requestData['lang']);
    
        $comments  = '</br>' . $this->paymentHelper->getDisplayPaymentMethodName($requestData);
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('nn_tid',$lang) . $requestData['tid'];

        $paymentKey = strtolower((string) $this->paymentHelper->getPaymentKeyByMop($requestData['mop']));
        $testModeKey = 'Novalnet.' . $paymentKey . '_test_mode';
        if(!empty($requestData['test_mode']) || ($this->config->get($testModeKey) == 'true'))
            $comments .= '</br>' . $this->paymentHelper->getTranslatedText('test_order',$lang);

        if(in_array($requestData['payment_id'], ['40','41']))
            $comments .= '</br>' . $this->paymentHelper->getTranslatedText('guarantee_text');

        if(in_array($requestData['payment_id'], ['27','41']))
        {
            $comments .= '</br>' . $this->getInvoicePrepaymentComments($requestData);
        }
        else if($requestData['payment_id'] == '59')
        {
            $comments .= '</br>' . $this->getCashPaymentComments($requestData);
        }

        return $comments;
    }
    
    /**
     * Build transaction failure comments for the order
     *
     * @param array $requestData
     * @return string
     */
     public function getTransactionfailureComments($requestData)
     {
        $lang = strtolower((string)$requestData['lang']);
        $orderStatus = (float) $this->config->get('Novalnet.novalnet_order_cancel_status');
        $this->paymentHelper->updateOrderStatus($requestData['order_no'], $orderStatus);
        $comments  = '</br>' . $this->paymentHelper->getDisplayPaymentMethodName($requestData);
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('nn_tid',$lang) . $requestData['tid']; 
        
        $paymentKey = strtolower((string) $this->paymentHelper->getPaymentKeyByMop($requestData['mop']));
        $testModeKey = 'novalnet.' . $paymentKey . '_test_mode';
        
        if(!empty($requestData['test_mode']) || ($this->config->get($testModeKey) == 'true'))
            $comments .= '</br>' . $this->paymentHelper->getTranslatedText('test_order',$lang);
            
        $responseText = $this->paymentHelper->getNovalnetStatusText($requestData);
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('callback_transaction_cancellation',$lang) . $responseText; 
        return $comments;
    }
    
    /**
     * Build Invoice and Prepayment transaction comments
     *
     * @param array $requestData
     * @return string
     */
    public function getInvoicePrepaymentComments($requestData)
    {
        $comments = $this->paymentHelper->getTranslatedText('transfer_amount_text');
        if(!empty($requestData['due_date']))
        {
            $comments .= '</br>' . $this->paymentHelper->getTranslatedText('due_date') . date('Y/m/d', (int)strtotime($requestData['due_date']));
        }

        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('account_holder_novalnet') . $requestData['invoice_account_holder'];
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('iban') . $requestData['invoice_iban'];
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('bic') . $requestData['invoice_bic'];
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('bank') . $this->paymentHelper->checkUtf8Character($requestData['invoice_bankname']) . ' ' . $this->paymentHelper->checkUtf8Character($requestData['invoice_bankplace']);
        $comments .= '</br>' . $this->paymentHelper->getTranslatedText('amount') . $requestData['amount'] . ' ' . $requestData['currency'];

        $productId = $requestData['product'];
        if(!preg_match('/^[0-9]/', (string) $requestData['product']))
        {
            $productId = $this->paymentHelper->decodeData($requestData['product'], $requestData['uniqid']);
        }

       $comments .= '</br>'.'Payment Reference 1: TID '. $requestData['tid'].'</br>'. 'Payment Reference 2:'.' ' .('BNR-' . $productId . '-' . $requestData['order_no']).'</br>';        
        $comments .= '</br>';

        return $comments;
    }

    /**
      * Build cash payment transaction comments
      *
      * @param array $requestData
      * @return string
      */
    public function getCashPaymentComments($requestData)
    {
        $comments = $this->paymentHelper->getTranslatedText('cashpayment_expire_date') . $requestData['cashpayment_due_date'] . '</br>';
        $comments .= '</br><b>' . $this->paymentHelper->getTranslatedText('cashpayment_near_you') . '</b></br></br>';

        $strNos = 0;
        foreach($requestData as $key => $val)
        {
            if(strpos($key, 'nearest_store_title') !== false)
            {
                $strnos++;
            }
        }

        for($i = 1; $i <= $strnos; $i++)
        {
            $countryName = !empty($requestData['nearest_store_country_' . $i]) ? $requestData['nearest_store_country_' . $i] : '';

            $comments .= $requestData['nearest_store_title_' . $i] . '</br>';
            $comments .= $countryName . '</br>';
            $comments .= $this->paymentHelper->checkUtf8Character($requestData['nearest_store_street_' . $i]) . '</br>';
            $comments .= $requestData['nearest_store_city_' . $i] . '</br>';
            $comments .= $requestData['nearest_store_zipcode_' . $i] . '</br></br>';
        }

        return $comments;
    }

    /**
     * Build Novalnet server request parameters
     *
     * @param Basket $basket
     *
     * @return array
     */
    public function getRequestParameters(Basket $basket, $paymentKey)
    {
        $billingAddressId = $basket->customerInvoiceAddressId;
        $address = $this->addressRepository->findAddressById($billingAddressId);
        if(!empty($basket->customerShippingAddressId)){
            $shippingAddress = $this->addressRepository->findAddressById($basket->customerShippingAddressId);
    }
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();
        $paymentKeyLower = strtolower((string) $paymentKey);
        $testModeKey = 'Novalnet.' . $paymentKeyLower . '_test_mode';

        $paymentRequestData = [
                'vendor'             => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
                'auth_code'          => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
                'product'            => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
                'tariff'             => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
                'test_mode'          => (int)($this->config->get($testModeKey) == 'true'),
                'first_name'         => $address->firstName,
                'last_name'          => $address->lastName,
                'email'              => $address->email,
                'gender'             => 'u',
                'city'               => $address->town,
                'street'             => $address->street,
                'country_code'       => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
                'zip'                => $address->postalCode,
                'customer_no'        => ($customerId) ? $customerId : 'guest',
                'lang'               => strtoupper($this->sessionStorage->getLocaleSettings()->language),
                'amount'             => (sprintf('%0.2f', $basket->basketAmount) * 100),
                'currency'           => $basket->currency,
                'remote_ip'          => $this->paymentHelper->getRemoteAddress(),
                'uniqid'             => $this->paymentHelper->getUniqueId(),
                'system_ip'          =>  $_SERVER['SERVER_ADDR'],
                'system_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl,
                'system_name'        => 'PlentyMarket',
                'system_version'     => NovalnetConstants::PLUGIN_VERSION,
                'notify_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/callback',
                'key'                => $this->getkeyByPaymentKey($paymentKey),
                'payment_type'       => $this->getTypeByPaymentKey($paymentKey)
        ];

        if(!empty($address->houseNumber))
        {
            $paymentRequestData['house_no'] = $address->houseNumber;
        }
        else
        {
            $paymentRequestData['search_in_street'] = '1';
        }

        if(!empty($address->companyName)) {
            $paymentRequestData['company'] = $address->companyName;
        } elseif(!empty($shippingAddress->companyName)) {
        $paymentRequestData['company'] = $shippingAddress->companyName;
        }

        if(!empty($address->phone))
            $paymentRequestData['tel'] = $address->phone;

        $referrerId = $this->paymentHelper->getNovalnetConfig('referrer_id');
        if(is_numeric($referrerId))
            $paymentRequestData['referrer_id'] = $referrerId;

        $txnReference1 = strip_tags($this->config->get('Novalnet.' . $paymentKeyLower . '_reference1'));
        if(!empty($txnReference1))
        {
            $paymentRequestData['input1'] = 'reference1';
            $paymentRequestData['inputval1'] = $txnReference1;
        }

        $txnReference2 = strip_tags($this->config->get('Novalnet.' . $paymentKeyLower . '_reference2'));
        if(!empty($txnReference2))
        {
            $paymentRequestData['input2'] = 'reference2';
            $paymentRequestData['inputval2'] = $txnReference2;
        }

        $paymentRequestData['url'] = $this->getpaymentUrl($paymentKey);
      
        $this->getPaymentParam($paymentRequestData, $paymentKey);
        if(in_array($paymentRequestData['key'], ['33','34','49','50','69','78']) || ($paymentRequestData['key'] == '6' && !empty($paymentRequestData['cc_3d']))) {
            $this->encodePaymentData($paymentRequestData);
            $paymentRequestData['implementation'] = 'ENC';
        }

        $url = $paymentRequestData['url'];
        unset($paymentRequestData['url']);

        return [
            'data' => $paymentRequestData,
            'url'  => $url
        ];
    }

    /**
     * Get the Payment specific param
     *
     * @param array $paymentRequestData
     * @param string $paymentKey
     */
    public function getPaymentParam(&$paymentRequestData, $paymentKey)
    {
        if($paymentKey == 'NOVALNET_CC')
        {
            $onHoldLimit = $this->paymentHelper->getNovalnetConfig('novalnet_cc_on_hold');
            if(is_numeric($onHoldLimit) && $onHoldLimit <= $paymentRequestData['amount'])
                $paymentRequestData['on_hold'] = '1';

            if($this->config->get('Novalnet.cc_3d') == 'true') {
                $paymentRequestData['cc_3d'] = '1';
                $paymentRequestData['url'] = NovalnetConstants::CC3D_PAYMENT_URL;
            }
             if($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' ) {
                $paymentRequestData['url'] = NovalnetConstants::CC3D_PAYMENT_URL;
            }
        }
        else if($paymentKey == 'NOVALNET_SEPA')
        {
            $onHoldLimit = $this->paymentHelper->getNovalnetConfig('novalnet_sepa_on_hold');
            if(is_numeric($onHoldLimit) && $onHoldLimit <= $paymentRequestData['amount'])
                $paymentRequestData['on_hold'] = '1';
        }
        else if($paymentKey == 'NOVALNET_INVOICE')
        {
            $onHoldLimit = $this->paymentHelper->getNovalnetConfig('novalnet_invoice_on_hold');
            if(is_numeric($onHoldLimit) && $onHoldLimit <= $paymentRequestData['amount'])
                $paymentRequestData['on_hold'] = '1';

            $paymentRequestData['invoice_type'] = 'INVOICE';
            $invoiceDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_invoice_due_date');
            if(is_numeric($invoiceDueDate))
                $paymentRequestData['due_date'] = date( 'Y-m-d', strtotime( date( 'y-m-d' ) . '+ ' . $invoiceDueDate . ' days' ) );
        }
        else if($paymentKey == 'NOVALNET_PREPAYMENT')
        {
            $paymentRequestData['invoice_type'] = 'PREPAYMENT';
        }
        else if($paymentKey == 'NOVALNET_CASHPAYMENT')
        {
            $cashpaymentDueDate = $this->paymentHelper->getNovalnetConfig('cashpayment_due_date');
            if(is_numeric($cashpaymentDueDate))
                $paymentRequestData['cashpayment_due_date'] = date( 'Y-m-d', strtotime( date( 'y-m-d' ) . '+ ' . $cashpaymentDueDate . ' days' ) );
        }
        else if($paymentKey == 'NOVALNET_PAYPAL')
        {
            $onHoldLimit = $this->paymentHelper->getNovalnetConfig('novalnet_paypal_on_hold');
            if(is_numeric($onHoldLimit) && $onHoldLimit <= $paymentRequestData['amount'])
                $paymentRequestData['on_hold'] = '1';
        }

        if(in_array($paymentKey, ['NOVALNET_SOFORT', 'NOVALNET_PAYPAL', 'NOVALNET_IDEAL', 'NOVALNET_EPS', 'NOVALNET_GIROPAY', 'NOVALNET_PRZELEWY']) || ($paymentKey == 'NOVALNET_CC' && $this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' ))
        {
            $paymentRequestData['return_url']          = $paymentRequestData['error_return_url'] = $this->getReturnPageUrl();
            $paymentRequestData['return_method']       = $paymentRequestData['error_return_method'] = 'POST';
        }
    }

    /**
     * Send postback call to server for updating the order number for the transaction
     *
     * @param array $requestData
     */
    public function sendPostbackCall($requestData)
    {
        $postbackData = [
            'vendor'         => $requestData['vendor'],
            'product'        => $requestData['product'],
            'tariff'         => $requestData['tariff'],
            'auth_code'      => $requestData['auth_code'],
            'key'            => $requestData['payment_id'],
            'status'         => 100,
            'tid'            => $requestData['tid'],
            'order_no'       => $requestData['order_no'],
            'remote_ip'      => $this->paymentHelper->getRemoteAddress()
        ];

        if(in_array($requestData['payment_id'], ['27', '41']))
        {
            $productId = $requestData['product'];
            if(!preg_match('/^[0-9]/', (string) $requestData['product']))
            {
                $productId = $this->paymentHelper->decodeData($requestData['product'], $requestData['uniqid']);
            }

            $postbackData['invoice_ref'] = 'BNR-' . $productId . '-' . $requestData['order_no'];
        }
        $response = $this->paymentHelper->executeCurl($postbackData, NovalnetConstants::PAYPORT_URI);
    }

    /**
     * Encode the server request parameters
     *
     * @param array $encodePaymentData
     */
    public function encodePaymentData(&$paymentRequestData)
    {
        foreach (['auth_code', 'product', 'tariff', 'amount', 'test_mode'] as $key) {
            // Encoding payment data
            $paymentRequestData[$key] = $this->paymentHelper->encodeData($paymentRequestData[$key], $paymentRequestData['uniqid']);
         }

        if(!empty($paymentRequestData['return_url']))
        {
            // Generate hash value
            $paymentRequestData['hash'] = $this->paymentHelper->generateHash($paymentRequestData);
        }
    }

    /**
     * Get the payment response controller URL to be handled
     *
     * @return string
     */
    private function getReturnPageUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/paymentResponse/';
    }

    /**
    * Get the direct payment process controller URL to be handled
    *
    * @return string
    */
    public function getProcessPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/processPayment/';
    }
    
    /**
    * Get the redirect payment process controller URL to be handled
    *
    * @return string
    */
    public function getRedirectPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/redirectPayment/';
    }
    
    /**
    * Get the Payment process URL by using Payment Key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getpaymentUrl($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>NovalnetConstants::PAYPORT_URI,
            'NOVALNET_PREPAYMENT'=>NovalnetConstants::PAYPORT_URI,
            'NOVALNET_CC'=>NovalnetConstants::PAYPORT_URI,
            'NOVALNET_SEPA'=>NovalnetConstants::PAYPORT_URI,
            'NOVALNET_CASHPAYMENT'=>NovalnetConstants::PAYPORT_URI,
            'NOVALNET_PAYPAL'=>NovalnetConstants::PAYPAL_PAYMENT_URL,
            'NOVALNET_IDEAL'=>NovalnetConstants::IDEAL_PAYMENT_URL,
            'NOVALNET_EPS'=>NovalnetConstants::EPS_PAYMENT_URL,
            'NOVALNET_GIROPAY'=>NovalnetConstants::GIROPAY_PAYMENT_URL,
            'NOVALNET_PRZELEWY'=>NovalnetConstants::PRZELEWY_PAYMENT_URL,
            'NOVALNET_SOFORT'=>NovalnetConstants::SOFORT_PAYMENT_URL,
        ];

        return $payment[$paymentKey];
    }
    
   /**
    * Get the Payment process URL by using Testmode
    *
    * @param string $type
    * @return string
    */
    public function getBarzhalenTestMode($response)
    {
        $testmode = [
        '0'=>NovalnetConstants::BARZAHLEN_LIVEURL,
        '1'=>NovalnetConstants::BARZAHLEN_TESTURL
        
        ];
    
        return $testmode[$response];
    }

   /**
    * Get the Novalnet Payment Key by plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getkeyByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>'27',
            'NOVALNET_PREPAYMENT'=>'27',
            'NOVALNET_CC'=>'6',
            'NOVALNET_SEPA'=>'37',
            'NOVALNET_CASHPAYMENT'=>'59',
            'NOVALNET_PAYPAL'=>'34',
            'NOVALNET_IDEAL'=>'49',
            'NOVALNET_EPS'=>'50',
            'NOVALNET_GIROPAY'=>'69',
            'NOVALNET_PRZELEWY'=>'78',
            'NOVALNET_SOFORT'=>'33',
        ];

        return $payment[$paymentKey];
    }

    /**
    * Get Payment type by Plenty payment Key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getTypeByPaymentKey($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>'INVOICE_START',
            'NOVALNET_PREPAYMENT'=>'INVOICE_START',
            'NOVALNET_CC'=>'CREDITCARD',
            'NOVALNET_SEPA'=>'DIRECT_DEBIT_SEPA',
            'NOVALNET_CASHPAYMENT'=>'CASHPAYMENT',
            'NOVALNET_PAYPAL'=>'PAYPAL',
            'NOVALNET_IDEAL'=>'IDEAL',
            'NOVALNET_EPS'=>'EPS',
            'NOVALNET_GIROPAY'=>'GIROPAY',
            'NOVALNET_PRZELEWY'=>'PRZELEWY24',
            'NOVALNET_SOFORT'=>'ONLINE_TRANSFER',
        ];

        return $payment[$paymentKey];
    }

    /**
    * Get the Credit card payment form design configuration
    *
    * @return array
    */
    public function getCcDesignConfig()
    {
        $design = [];
        $design['holder_label_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_holder_label_css');
        $design['holder_field_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_holder_field_css');
        $design['number_label_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_number_label_css');
        $design['number_field_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_number_field_css');
        $design['date_label_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_date_label_css');
        $design['date_field_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_date_field_css');
        $design['cvc_label_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_cvc_label_css');
        $design['cvc_field_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_cvc_field_css');
        $design['standard_style_label'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_label');
        $design['standard_style_input'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_field');
        $design['standard_style_css'] = $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_css');
        return $design;
    }

    /**
    * Get the Payment Guarantee status
    *
    * @param object $basket
    * @param string $paymentKey
    * @return string
    */
    public function getGuaranteeStatus(Basket $basket, $paymentKey)
    {
        // Get payment name in lowercase
        $paymentKeyLow = strtolower((string) $paymentKey);

        $guaranteePayment = $this->config->get('Novalnet.'.$paymentKeyLow.'_payment_guarantee_active');
        if ($guaranteePayment == 'true') {
            // Get guarantee minimum and maximum amount value
            $minimumAmount = $this->paymentHelper->getNovalnetConfig($paymentKeyLow . '_guarantee_min_amount');
            $minimumAmount = ((preg_match('/^[0-9]*$/', $minimumAmount) && $minimumAmount >= '999')  ? $minimumAmount : '999');

            $amount        = (sprintf('%0.2f', $basket->basketAmount) * 100);

            $billingAddressId = $basket->customerInvoiceAddressId;
            $billingAddress = $this->addressRepository->findAddressById($billingAddressId);
            $customerBillingIsoCode = strtoupper($this->countryRepository->findIsoCode($billingAddress->countryId, 'iso_code_2'));

            $shippingAddressId = $basket->customerShippingAddressId;

            $addressValidation = false;
            if(!empty($shippingAddressId))
            {

                    $shippingAddress = $this->addressRepository->findAddressById($shippingAddressId);
                $customerShippingIsoCode = strtoupper($this->countryRepository->findIsoCode($shippingAddress->countryId, 'iso_code_2'));

                // Delivery address
                $deliveryAddress = array(
                                     'street_address' => (($billingAddress->street) ? $billingAddress->street : $billingAddress->address1),
                                     'city'           => $billingAddress->town,
                                     'postcode'       => $billingAddress->postalCode,
                                     'country'        => $customerBillingIsoCode,
                                    );
                // Billing address
                $billingAddress = array(
                                     'street_address' => (($shippingAddress->street) ? $shippingAddress->street : $shippingAddress->address1),
                                     'city'           => $shippingAddress->town,
                                     'postcode'       => $shippingAddress->postalCode,
                                     'country'        => $customerShippingIsoCode,
                                    );

             }
             else
             {
                 $addressValidation = true;
             }
            // Check guarantee payment
            if ((((int) $amount >= (int) $minimumAmount && in_array(
                $customerBillingIsoCode,
                [
                 'DE',
                 'AT',
                 'CH',
                ]
            ) && $basket->currency == 'EUR' && ($addressValidation || ($deliveryAddress === $billingAddress)))
            )) {
                $processingType = 'guarantee';
            } elseif ($this->config->get('Novalnet.'.$paymentKeyLow.'_payment_guarantee_force_active') == 'true') {
                $processingType = 'normal';
            } else {
                $processingType = 'error';
            }
            return $processingType;
        }//end if
        return 'normal';
    }
}
