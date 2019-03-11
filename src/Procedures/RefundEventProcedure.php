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
 
namespace Novalnet\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Novalnet\Services\PaymentService;
use Novalnet\Constants\NovalnetConstants;

/**
 * Class RefundEventProcedure
 */
class RefundEventProcedure
{
	use Loggable;
	
	/**
	 *
	 * @var PaymentHelper
	 */
	private $paymentHelper;
	
	/**
	 *
	 * @var PaymentService
	 */
	private $paymentService;
	
	/**
	 * Constructor.
	 *
	 * @param PaymentHelper $paymentHelper
	 * @param PaymentService $paymentService
	 */
	 
    public function __construct( PaymentHelper $paymentHelper, 
								 PaymentService $paymentService)
    {
        $this->paymentHelper   = $paymentHelper;
	    $this->paymentService  = $paymentService;
	}	
	
    /**
     * @param EventProceduresTriggered $eventTriggered
     * 
     */
    public function run(
        EventProceduresTriggered $eventTriggered
    ) {
        /* @var $order Order */
	 
	   $order = $eventTriggered->getOrder(); 
	  
	   $payments = pluginApp(\Plenty\Modules\Payment\Contracts\PaymentRepositoryContract::class);  
       $paymentDetails = $payments->getPaymentsByOrderId($order->id);
	   $orderAmount = (float) $order->amounts[0]->invoiceTotal;
	   $paymentKey = $paymentDetails[0]->method->paymentKey;
	   $key = $this->paymentService->getkeyByPaymentKey($paymentKey);
	   
	    foreach ($paymentDetails as $paymentDetail)
		{
			$property = $paymentDetail->properties;
			foreach($property as $proper)
			{
				  if($proper->typeId == 1)
				  {
						$tid = $proper->value;
				  }
				 if($proper->typeId == 30)
				  {
						$status = $proper->value;
				  }
			}
		}
        $this->getLogger(__METHOD__)->error('EventProcedure.triggerFunction', ['order' => $order]);
        
	    if ($status == '100' && ($order->amounts[0]->paidAmount) == $orderAmount)   
	    { 
			try {
				$paymentRequestData = [
					'vendor'         => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
					'auth_code'      => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
					'product'        => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
					'tariff'         => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
					'key'            => $key, 
					'refund_request' => 1, 
					'tid'            => $tid, 
					 'refund_param'  => (float) $orderAmount * 100 ,
					'remote_ip'      => $this->paymentHelper->getRemoteAddress(),
					'lang'           => 'EN'   
					 ];
					
					 $response = $this->paymentHelper->executeCurl($paymentRequestData, NovalnetConstants::PAYPORT_URL);
					  $responseData =$this->paymentHelper->convertStringToArray($response['response'], '&');	 
				if ($responseData['status'] == '100') {
					 $transactionComments = PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('refund_message', $paymentRequestData['lang']), $tid, (float) $orderAmount * 100);
					 $this->paymentHelper->createOrderComments((int)$order->id, $transactionComments);
					} else {
					$error = $this->paymentHelper->getNovalnetStatusText($responseData);
					$this->getLogger(__METHOD__)->error('Novalnet::doRefundError', $error);
				}
				} catch (\Exception $e) {
						$this->getLogger(__METHOD__)->error('Novalnet::doRefund', $e);
					}
				
				$paymentData['currency']    = $paymentDetails[0]->currency;
				$paymentData['paid_amount'] = (float) $orderAmount;
				$paymentData['tid']         = $tid;
				$paymentData['order_no']    = $order->id;
				$paymentData['type']        = 'debit';
				$paymentData['mop']         = $paymentDetails[0]->mopId;
				
				$this->paymentHelper->createPlentyPayment($paymentData);
	    }
    }
}
