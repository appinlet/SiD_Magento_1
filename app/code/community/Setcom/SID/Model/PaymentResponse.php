<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */
class Setcom_SID_Model_PaymentResponse
{
    const SID_COMPLETED = 'COMPLETED';
    const SID_CANCELLED = 'CANCELLED';

    protected $_order = null;

    public function processResponse( array $request )
    {
        $sid_status     = strtoupper( $request["SID_STATUS"] );
        $sid_merchant   = $request["SID_MERCHANT"];
        $sid_country    = $request["SID_COUNTRY"];
        $sid_currency   = $request["SID_CURRENCY"];
        $sid_reference  = $request["SID_REFERENCE"];
        $sid_amount     = $request["SID_AMOUNT"];
        $sid_bank       = $request["SID_BANK"];
        $sid_date       = $request["SID_DATE"];
        $sid_receiptno  = $request["SID_RECEIPTNO"];
        $sid_tnxid      = $request["SID_TNXID"];
        $sid_custom_01  = $request["SID_CUSTOM_01"];
        $sid_custom_02  = $request["SID_CUSTOM_02"];
        $sid_custom_03  = $request["SID_CUSTOM_03"];
        $sid_custom_04  = $request["SID_CUSTOM_04"];
        $sid_custom_05  = $request["SID_CUSTOM_05"];
        $sid_consistent = $request["SID_CONSISTENT"];

        $sid_secret = $this->_getConfigValue( "private_key" );

        $consistent_check = strtoupper( hash( 'sha512', $sid_status . $sid_merchant . $sid_country . $sid_currency
            . $sid_reference . $sid_amount . $sid_bank . $sid_date . $sid_receiptno
            . $sid_tnxid . $sid_custom_01 . $sid_custom_02 . $sid_custom_03 . $sid_custom_04
            . $sid_custom_05 . $sid_secret ) );

        if ( $consistent_check != $sid_consistent ) {
            Mage::throwException( "Consistent is invalid." );
        }

        $order_id = $sid_reference;
        $this->_getOrder( $order_id );

        if ( !$this->_order ) {
            Mage::throwException( 'Order not found.' );
        }

        if ( $sid_merchant != $this->_getConfigValue( "merchant_code" ) ) {
            Mage::throwException( 'Merchant code received does not match stores merchant code.' );
        }

        if ( (float) $sid_amount != (float) $this->_order->getGrandTotal() ) {
            Mage::throwException( 'Amount paid does not match order amount.' );
        }

        /*
        Mage::log(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        Mage::log($this->_order->getStatus()); */

        if ( $this->_order->getStatus() == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT ) {
            if ( $sid_status == self::SID_COMPLETED ) {
                $payment = $this->_order->getPayment();
                $payment->setStatus( Mage_Sales_Model_Order::STATE_COMPLETE )
                    ->setShouldCloseParentTransaction( 1 )
                    ->setIsTransactionClosed( 1 )
                    ->registerCaptureNotification( $sid_amount );
                $this->_order->save();

                $payment->setAdditionalInformation( "sid_tnxid", $sid_tnxid )
                    ->setAdditionalInformation( "sid_receiptno", $sid_receiptno )
                    ->setAdditionalInformation( "sid_bank", $sid_bank )
                    ->setAdditionalInformation( "sid_status", $sid_status )
                    ->save();

                $invoice = $payment->getCreatedInvoice();
                if ( $invoice && !$this->_order->getEmailSent() ) {
                    $this->_order->sendNewOrderEmail()
                        ->setIsCustomerNotified( true )
                        ->save();
                }

            } elseif ( $sid_status == self::SID_CANCELLED ) {
                $sid_status = "pending_payment";
                $this->_order->setStatus( $sid_status )
                //->registerCancellation($sid_status, false)
                    ->save();
            }
        }

        if ( $sid_status == self::SID_COMPLETED ) {
            return true;
        }

        return false;
    }

    public function updateOrder( $data )
    {
        $orderEntityId = $data["SID_CUSTOM_02"];
        if ( $orderEntityId ) {
            $order      = Mage::getModel( 'sales/order' )->load( $orderEntityId );
            $sid_status = self::SID_CANCELLED;
            $order->setStatus( $sid_status )
                ->registerCancellation( $sid_status, false )
                ->save();
        }
    }

    protected function _getOrder( $id )
    {
        if ( empty( $this->_order ) ) {
            $this->_order = Mage::getModel( 'sales/order' )->loadByIncrementId( $id );
        }

        return $this->_order;
    }

    protected function _getConfigValue( $key )
    {
        return Mage::getStoreConfig( "payment/sid/$key" );
    }
}
