<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the GNU General Public License
 *
 * @author Novalnet <technic@novalnet.de>
 * @copyright Novalnet
 * @license GNU General Public License
 *
 * Script : NovalnetOrderConfirmationDataProvider.php
 *
 */

namespace Novalnet\Providers;

use Plenty\Plugin\Templates\Twig;

use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use \Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;

/**
 * Class NovalnetOrderConfirmationDataProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetOrderConfirmationDataProvider
{
    /**
     * Setup the Novalnet transaction comments for the requested order
     *
     * @param Twig $twig
     * @param Arguments $arg 
     * @param PaymentRepositoryContract $paymentRepositoryContract
     * @return string
     */
    public function call(Twig $twig, PaymentRepositoryContract $paymentRepositoryContract, $arg)
    {
        $paymentHelper = pluginApp(PaymentHelper::class);
		$sessionStorage = pluginApp(FrontendSessionStorageFactoryContract::class);
		
        $order = $arg[0];
  
		$barzahlentoken =	(string)$sessionStorage->getPlugin()->getValue('cashtoken');
		$testmode 		=	(string)$sessionStorage->getPlugin()->getValue('testmode');
		$payments		=	$paymentRepositoryContract->getPaymentsByOrderId($order['id']);
		
        foreach($payments as $payment)
        {
            if($paymentHelper->isNovalnetPaymentMethod($payment->mopId))
            {
                $orderId = (int) $payment->order['orderId'];

                $authHelper = pluginApp(AuthHelper::class);
                $orderComments = $authHelper->processUnguarded(
                        function () use ($orderId) {
                            $commentsObj = pluginApp(CommentRepositoryContract::class);
                            $commentsObj->setFilters(['referenceType' => 'order', 'referenceValue' => $orderId]);
                            return $commentsObj->listComments();
                        }
                );
                $comment = '';
                foreach($orderComments as $data)
                {
                    $comment .= (string)$data->text;
                    $comment .= '</br>';
                }
		  
              $payment_type = (string)$paymentHelper->getPaymentKeyByMop($payment->mopId);

                return $twig->render('Novalnet::NovalnetOrderHistory', ['comments' => html_entity_decode($comment),'barzahlentoken' => html_entity_decode($barzahlentoken),'payment_type' => html_entity_decode($payment_type),'testmode' => html_entity_decode($testmode)]);
            }
        }
    }
}
