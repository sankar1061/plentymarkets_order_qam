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
 * @author       Novalnet AG
 * @copyright(C) Novalnet AG
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenpflichtig/lizenz
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
use Novalnet\NovalnetConstants;
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
    
    private $redirectPayment = ['NOVALNET_SOFORT', 'NOVALNET_PAYPAL', 'NOVALNET_IDEAL', 'NOVALNET_EPS', 'NOVALNET_GIROPAY', 'NOVALNET_PRZELEWY'];

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param FrontendSessionStorageFactoryContract $sessionStorage
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
     * Push notification
     *
     */
    public function pushNotification($type, $msg) {
		$notifications = json_decode($this->sessionStorage->getPlugin()->getValue('notifications'));
		array_push($notifications,[
						'message' => $msg,
						'type'    => $type,
						'code'    => 0
			]);
		$this->sessionStorage->getPlugin()->setValue('notifications', json_encode($notifications));
	}

    /**
     * Validate  the response data.
     *
     */
    public function validateResponse()
    {
        $nnPaymentData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');

        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', null);
        
        $nnPaymentData['order_no']       = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
        $nnPaymentData['mop']            = $this->sessionStorage->getPlugin()->getValue('mop');
        $nnPaymentData['payment_method'] = strtolower($this->paymentHelper->getPaymentKeyByMop($nnPaymentData['mop']));
        $this->executePayment($nnPaymentData);

        $transactionData = [
            'amount'           => $nnPaymentData['amount'] * 100,
            'callback_amount'  => $nnPaymentData['paid_amount'],
            'tid'              => $nnPaymentData['tid'],
            'ref_tid'          => $nnPaymentData['tid'],
            'payment_name'     => $nnPaymentData['payment_method'],
            'order_no'         => $nnPaymentData['order_no'],
        ];
		
        $this->transactionLogData->saveTransaction($transactionData);
        
        if(!$this->isRedirectPayment(strtoupper($nnPaymentData['payment_method']))) {
            $this->sendPostbackCall($nnPaymentData);
        }
     }
     
	/**
     * Creates the payment for the order generated in plentymarkets.
     *
     * @param array $requestData 
     * @param bool $callbackfailure
     * 
     * @return array
     */
    public function executePayment($requestData, $callbackfailure = false)
    {
        try {
            if(!$callbackfailure &&  in_array($requestData['status'], ['100', '90'])) {
				if(in_array($requestData['tid_status'], ['86','90'])) {
					$requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_payment_pending_status'));
					$requestData['paid_amount'] = 0;
				} elseif($requestData['tid_status'] == '75') {
					$requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_payment_guarantee_status'));
					$requestData['paid_amount'] = 0;
				} elseif($requestData['payment_id'] == '41' && $requestData['status'] == '100') {
					$requestData['order_status'] = trim($this->config->get('Novalnet.novalnet_invoice_callback_order_status'));
					$requestData['paid_amount'] = 0;
				} else {
					$requestData['order_status'] = trim($this->config->get('Novalnet.'. $requestData['payment_method'] .'_order_completion_status'));
					$requestData['paid_amount'] = $requestData['amount'];
				}
            } else {
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
     * Build transaction comments for the order
     *
     * @param array $requestData
     * @return string
     */
    public function getTransactionComments($requestData)
    {
        $lang = strtolower((string)$requestData['lang']);
		$comments = '';
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('nn_tid', $lang) . $requestData['tid'];
	    
        if(!empty($requestData['test_mode'])) {
            $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('test_order', $lang);
        }
        
        if($requestData['status'] != '100')
		{
			$responseText = $this->paymentHelper->getNovalnetStatusText($requestData);
			$comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('transaction_cancellation', $lang) . $responseText . PHP_EOL;    
		} else {
			if(in_array($requestData['payment_id'], ['40','41'])) {
				$comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('guarantee_text');
				if( $requestData['tid_status'] == '75')
				{
					$comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('gurantee_pending_payment_text');
				}
			}
			
			if(in_array($requestData['payment_method'], ['novalnet_invoice','novalnet_prepayment']))
			{
				$comments .= PHP_EOL . $this->getInvoicePrepaymentComments($requestData);
			}
			else if($requestData['payment_id'] == '59')
			{
				if(!empty($requestData['cp_checkout_token']))
				{
					$this->sessionStorage->getPlugin()->setValue('novalnet_checkout_token', $requestData['cp_checkout_token']);
					$this->sessionStorage->getPlugin()->setValue('novalnet_checkout_url', $this->getBarzhalenTestMode($requestData['test_mode']));
				}
				$comments .= PHP_EOL . $this->getCashPaymentComments($requestData);
			}
		}

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
		if ($requestData['tid_status'] == '100') {  
        $comments = $this->paymentHelper->getTranslatedText('transfer_amount_text');
        if(!empty($requestData['due_date']))
        {
            $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('due_date') . date('Y/m/d', (int)strtotime($requestData['due_date']));
        }

        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('account_holder_novalnet') . $requestData['invoice_account_holder'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('iban') . $requestData['invoice_iban'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bic') . $requestData['invoice_bic'];
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('bank') . $this->paymentHelper->checkUtf8Character($requestData['invoice_bankname']) . ' ' . $this->paymentHelper->checkUtf8Character($requestData['invoice_bankplace']);
        $comments .= PHP_EOL . $this->paymentHelper->getTranslatedText('amount') . $requestData['amount'] . ' ' . $requestData['currency'];

        $comments .= PHP_EOL. $this->paymentHelper->getTranslatedText('payment_reference1') .' ' . 'TID '. $requestData['tid']. PHP_EOL . $this->paymentHelper->getTranslatedText('payment_reference2').' ' .('BNR-' . $requestData['product'] . '-' . $requestData['order_no']). PHP_EOL;
        $comments .= PHP_EOL;
	    }
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
                $comments = $this->paymentHelper->getTranslatedText('cashpayment_expire_date') . $requestData['cashpayment_due_date'] . PHP_EOL;
        $comments .= PHP_EOL . PHP_EOL . $this->paymentHelper->getTranslatedText('cashpayment_near_you') . PHP_EOL . PHP_EOL . PHP_EOL;

        $strnos = 0;
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
            $comments .= $requestData['nearest_store_title_' . $i] . PHP_EOL;
            $comments .= $countryName . PHP_EOL;
            $comments .= $this->paymentHelper->checkUtf8Character($requestData['nearest_store_street_' . $i]) . PHP_EOL;
            $comments .= $requestData['nearest_store_city_' . $i] . PHP_EOL;
            $comments .= $requestData['nearest_store_zipcode_' . $i] . PHP_EOL . PHP_EOL;
        }

        return $comments;
    }

    /**
     * Build Novalnet server request parameters
     *
     * @param Basket $basket
     * @param PaymentKey $paymentKey
     *
     * @return array
     */
    public function getRequestParameters(Basket $basket, $paymentKey = '')
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
            'test_mode'          => (bool)($this->config->get($testModeKey) == 'true'),
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
            'amount'             => $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            'currency'           => $basket->currency,
            'remote_ip'          => $this->paymentHelper->getRemoteAddress(),
            'system_ip'          => $this->paymentHelper->getServerAddress(),
            'system_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl,
            'system_name'        => 'Plentymarkets',
            'system_version'     => NovalnetConstants::PLUGIN_VERSION,
            'notify_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/novalnet/callback/',
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

        if(!empty($address->phone)) {
            $paymentRequestData['tel'] = $address->phone;
        }

        if(is_numeric($referrerId = $this->paymentHelper->getNovalnetConfig('referrer_id'))) {
            $paymentRequestData['referrer_id'] = $referrerId;
        }
        $url = $this->getPaymentData($paymentKey, $paymentRequestData);

        return [
            'data' => $paymentRequestData,
            'url'  => $url
        ];
    }

    /**
     * Get payment related param
     *
     * @param array $paymentRequestData
     * @param string $paymentKey
     */
    public function getPaymentData($paymentKey, &$paymentRequestData )
    {
		$url = $this->getpaymentUrl($paymentKey);
		if(in_array($paymentKey, ['NOVALNET_CC', 'NOVALNET_SEPA', 'NOVALNET_PAYPAL', 'NOVALNET_INVOICE'])) {
			$onHoldLimit = $this->paymentHelper->getNovalnetConfig(strtolower($paymentKey) . '_on_hold');
            if(is_numeric($onHoldLimit) && $paymentRequestData['amount'] >= $onHoldLimit) {
                $paymentRequestData['on_hold'] = '1';
            }
            if($paymentKey == 'NOVALNET_CC')
			{
				$onHoldLimit = $this->paymentHelper->getNovalnetConfig('novalnet_cc_on_hold');
				if($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' ) {
					if($this->config->get('Novalnet.novalnet_cc_3d') == 'true') {
						$paymentRequestData['cc_3d'] = '1';
					}
					$url = NovalnetConstants::CC3D_PAYMENT_URL;
				}
			}
			else if($paymentKey == 'NOVALNET_SEPA')
			{
				$dueDate = $this->paymentHelper->getNovalnetConfig('novalnet_sepa_due_date');
				if(is_numeric($dueDate) && $dueDate >= 2 && $dueDate <= 14) {
					$paymentRequestData['sepa_due_date'] = $this->paymentHelper->dateFormatter($dueDate);
				}
				
			}
			else if($paymentKey == 'NOVALNET_INVOICE')
			{
				$paymentRequestData['invoice_type'] = 'INVOICE';
				$invoiceDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_invoice_due_date');
				if(is_numeric($invoiceDueDate)) {
					$paymentRequestData['due_date'] = $this->paymentHelper->dateFormatter($invoiceDueDate);
				}
			}
		}
        else if($paymentKey == 'NOVALNET_PREPAYMENT')
        {
            $paymentRequestData['invoice_type'] = 'PREPAYMENT';
        }
        else if($paymentKey == 'NOVALNET_CASHPAYMENT')
        {
            $cashpaymentDueDate = $this->paymentHelper->getNovalnetConfig('novalnet_cashpayment_due_date');
            if(is_numeric($cashpaymentDueDate)) {
                $paymentRequestData['cashpayment_due_date'] = $this->paymentHelper->dateFormatter($cashpaymentDueDate);
			}
        }

        if($this->isRedirectPayment($paymentKey))
        {
	    $paymentRequestData['uniqid'] = $this->paymentHelper->getUniqueId();
            $this->encodePaymentData($paymentRequestData);
            $paymentRequestData['implementation'] = 'ENC';
            $paymentRequestData['return_url'] = $paymentRequestData['error_return_url'] = $this->getReturnPageUrl();
            $paymentRequestData['return_method'] = $paymentRequestData['error_return_method'] = 'POST';
	    $paymentRequestData['user_variable_0'] = $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl;
        }
        
        return $url;
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
            $postbackData['invoice_ref'] = 'BNR-' . $requestData['product'] . '-' . $requestData['order_no'];
        }
		$this->paymentHelper->executeCurl($postbackData, NovalnetConstants::PAYPORT_URL);
    }
    
    /**
     * Check if the payment is redirection or not
     *
     * @param string $paymentKey
     */
    public function isRedirectPayment($paymentKey) {
		return (bool) (in_array($paymentKey, $this->redirectPayment) || ($paymentKey == 'NOVALNET_CC' && ($this->config->get('Novalnet.novalnet_cc_3d') == 'true' || $this->config->get('Novalnet.novalnet_cc_3d_fraudcheck') == 'true' )));
	}

    /**
     * Encode the server request parameters
     *
     * @param array
     */
    public function encodePaymentData(&$paymentRequestData)
    {
        foreach (['auth_code', 'product', 'tariff', 'amount', 'test_mode'] as $key) {
            // Encoding payment data
            $paymentRequestData[$key] = $this->paymentHelper->encodeData($paymentRequestData[$key], $paymentRequestData['uniqid']);
        }

        // Generate hash value
        $paymentRequestData['hash'] = $this->paymentHelper->generateHash($paymentRequestData);
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
    * Get the payment process URL by using plenty payment key
    *
    * @param string $paymentKey
    * @return string
    */
    public function getpaymentUrl($paymentKey)
    {
        $payment = [
            'NOVALNET_INVOICE'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_PREPAYMENT'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_CC'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_SEPA'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_CASHPAYMENT'=>NovalnetConstants::PAYPORT_URL,
            'NOVALNET_PAYPAL'=>NovalnetConstants::PAYPAL_PAYMENT_URL,
            'NOVALNET_IDEAL'=>NovalnetConstants::SOFORT_PAYMENT_URL,
            'NOVALNET_EPS'=>NovalnetConstants::GIROPAY_PAYMENT_URL,
            'NOVALNET_GIROPAY'=>NovalnetConstants::GIROPAY_PAYMENT_URL,
            'NOVALNET_PRZELEWY'=>NovalnetConstants::PRZELEWY_PAYMENT_URL,
            'NOVALNET_SOFORT'=>NovalnetConstants::SOFORT_PAYMENT_URL,
        ];

        return $payment[$paymentKey];
    }

   /**
    * Get Barzahlen slip URL by using Testmode
    *
    * @param string $response
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
    * Get payment key by plenty payment key
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
    * Get payment type by plenty payment Key
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
        return [
			'standard_style_label' => $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_label'),
			'standard_style_input' => $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_field'),
			'standard_style_css' => $this->paymentHelper->getNovalnetConfig('novalnet_cc_standard_style_css'),
        ];
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
            // Get guarantee minimum amount value
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

                // Billing address
                $billingAddress = ['street_address' => (($billingAddress->street) ? $billingAddress->street : $billingAddress->address1),
                                   'city'           => $billingAddress->town,
                                   'postcode'       => $billingAddress->postalCode,
                                   'country'        => $customerBillingIsoCode,
                                  ];
                // Shipping address
                $shippingAddress = ['street_address' => (($shippingAddress->street) ? $shippingAddress->street : $shippingAddress->address1),
                                    'city'           => $shippingAddress->town,
                                    'postcode'       => $shippingAddress->postalCode,
                                    'country'        => $customerShippingIsoCode,
                                   ];

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
            ) && $basket->currency == 'EUR' && ($addressValidation || ($billingAddress === $shippingAddress)))
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
