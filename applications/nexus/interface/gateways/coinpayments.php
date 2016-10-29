<?php
/**
 * @brief		Coinpayments Gateway
 * @author		<a href='https://flawlessmanagement.com'>FlawlessManagement</a>
 * @copyright	(c) 2016 FlawlessManagement
 * @package		IPS Community Suite
 * @subpackage	Nexus
 * @since		25 Jab 2016
 * @version		1.0.0
 */

require_once '../../../../init.php';
\IPS\Session\Front::i();

/* Load Transaction */
try
{
	$transaction = \IPS\nexus\Transaction::load( \IPS\Request::i()->nexusTransactionId );

	if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING && $transaction->status !== \IPS\nexus\Transaction::STATUS_WAITING )
	{
		throw new \OutofRangeException;
	}
}
catch ( \OutOfRangeException $e )
{
	\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=nexus&module=payments&controller=checkout&do=transaction&id=&t=" . \IPS\Request::i()->nexusTransactionId, 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https ) );
}

try
{
	$g_settings = json_decode( $transaction->method->settings, TRUE );
	$cp_merchant_id = $g_settings['merchant_id'];
	$cp_ipn_secret = $g_settings['ipn_secret'];
	$cp_debug_email = $g_settings['debug_email'];

	if ( !isset( \IPS\Request::i()->ipn_mode ) || \IPS\Request::i()->ipn_mode != 'hmac' ) {
		errorAndDie( 'IPN Mode is not HMAC' );
	}

	if ( !isset( $_SERVER['HTTP_HMAC'] ) || empty( $_SERVER['HTTP_HMAC'] ) ) {
		errorAndDie( 'No HMAC signature sent.' );
	}

	$request = file_get_contents( 'php://input' );
	if ( $request === FALSE || empty( $request ) ) {
		errorAndDie( 'Error reading POST data' );
	}

	if ( !isset( \IPS\Request::i()->merchant ) || \IPS\Request::i()->merchant != trim( $cp_merchant_id ) ) {
		errorAndDie( 'No or incorrect Merchant ID passed' );
	}

	$hmac = hash_hmac( "sha512", $request, trim( $cp_ipn_secret ) );
	if ( $hmac != $_SERVER['HTTP_HMAC'] ) {
		errorAndDie( 'HMAC signature does not match' );
	}

	$order_currency = $transaction->currency;
	$order_total = $transaction->amount;

	// HMAC Signature verified at this point, load some variables.
	$amount1 = floatval( \IPS\Request::i()->amount1 );
	$currency1 = \IPS\Request::i()->currency1;
	$status = intval( \IPS\Request::i()->status );

	// Check the original currency to make sure the buyer didn't change it.
	if ( $currency1 != $order_currency ) {
		errorAndDie( 'Original currency mismatch!' );
	}

	// Check amount against order total
	if ( $amount1 < $order_total ) {
		errorAndDie( 'Amount is less than order total!' );
	}

	if ( $status >= 100 || $status == 2 ) {
		// payment is complete or queued for nightly payout, success
		$transaction->auth = NULL;
		$transaction->approve(NULL);
		$transaction->save();
		$transaction->sendNotification();
	} else if ($status < 0) {
		//payment error, this is usually final but payments will sometimes be reopened if there was no exchange rate conversion or with seller consent
		$transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
		$transaction->save();
		$transaction->sendNotification();
	} else {
		//payment is pending, you can optionally add a note to the order page
		if( $transaction->status !== \IPS\nexus\Transaction::STATUS_WAITING )
		{
			$transaction->status = \IPS\nexus\Transaction::STATUS_WAITING;
			$transaction->save();
			$transaction->sendNotification();
		}
	}
	die('IPN OK');
}
catch ( \Exception $e )
{
	\IPS\Output::i()->redirect( $transaction->invoice->checkoutUrl()->setQueryString( array( '_step' => 'checkout_pay', 'err' => $e->getMessage() ) ) );
}

function errorAndDie( $error_msg ) {
	global $cp_debug_email;
	if ( !empty( $cp_debug_email ) )
	{
		$report = 'Error: '.$error_msg."\n\n";
		$report .= "POST Data\n\n";
		foreach ( $_POST as $k => $v )
		{
			$report .= "|$k| = |$v|\n";
		}
		$email = \IPS\Email::buildFromContent( "CoinPayments IPN Error", $report );
		$email->send( $cp_debug_email );
	}
	die( 'IPN Error: '.$error_msg );
}
