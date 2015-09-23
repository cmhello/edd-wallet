<?php
/**
 * Deposit functions
 *
 * @package     EDD\Wallet\Deposit
 * @since       1.0.0
 */


// Exit if accessed directly
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Process deposit
 *
 * @since       1.0.0
 * @return      void
 */
function edd_wallet_process_deposit() {
	// Verify the nonce
	if( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'edd-wallet-deposit-nonce' ) ) {
		wp_die( __( 'Nonce verification failed','edd-wallet' ), __( 'Error', 'edd-wallet' ), array( 'response' => 403 ) );
	}

	// Make sure the cart is empty
	edd_empty_cart();

	// Setup the fee label
	$label = edd_get_option( 'edd_wallet_deposit_description', __( 'Deposit to wallet', 'edd-wallet' ) );
	$label = str_replace( '{val}', edd_currency_filter( edd_format_amount( $_POST['edd_wallet_deposit_amount'] ) ), $label );

	// Setup the fee (product) for the deposit
	$fee = array(
		'amount'        => $_POST['edd_wallet_deposit_amount'],
		'label'         => $label,
		'type'          => 'item',
		'no_tax'        => true,
		'id'            => 'edd-wallet-deposit'
	);

	EDD()->fees->add_fee( $fee );

	// Redirect to checkout
	wp_redirect( edd_get_checkout_uri(), 303 );
	edd_die();
}
add_action( 'edd_wallet_process_deposit', 'edd_wallet_process_deposit' );


/**
 * Process admin deposit
 *
 * @since       1.0.0
 * @return      void
 */
function edd_wallet_process_admin_deposit() {
	// Verify the nonce
	if( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'edd-wallet-admin-deposit-nonce' ) ) {
		wp_die( __( 'Nonce verification failed','edd-wallet' ), __( 'Error', 'edd-wallet' ), array( 'response' => 403 ) );
	}

	// Ensure that the deposit value is a number
	if( ! is_numeric( $_POST['wallet-amount'] ) || (float) $_POST['wallet-amount'] == 0 ) {
		wp_redirect( admin_url( 'edit.php?post_type=download&page=edd-customers&view=wallet&id=' . $_POST['wallet-user'] . '&edd-message=wallet_deposit_invalid' ) );
		exit;
	}

	// Get the current value of their wallet
	$value = get_user_meta( $_POST['wallet-user'], '_edd_wallet_value', true );
	$value = ( $value ? $value : 0 );

	// Adjust their balance
	if( $_POST['wallet-edit-type'] == 'admin-deposit' ) {
		// Add the deposit value
		$value = $value + (float) $_POST['wallet-amount'];

		// Setup the edit type
		$type = 'admin-deposit';
		$message = 'wallet_deposit_succeeded';
	} else {
		// Subtract the withdraw value
		$value = $value - (float) $_POST['wallet-amount'];

		// Setup the edit type
		$type = 'admin-withdraw';
		$message = 'wallet_withdraw_succeeded';
	}

	if( $value < 0 ) {
		$message = 'wallet_deposit_failed';
	} else {
		// Update the user wallet
		update_user_meta( $_POST['wallet-user'], '_edd_wallet_value', $value );

		// Record the deposit
		$args = array(
			'user_id'       => $_POST['wallet-user'],
			'payment_id'    => 0,
			'type'          => $type,
			'amount'        => $_POST['wallet-amount']
		);

		$item = edd_wallet()->wallet->add( $args );

		// Maybe send email
		if( isset( $_POST['wallet-receipt'] ) && $_POST['wallet-receipt'] == '1' ) {
			edd_wallet_send_email( $type, $_POST['wallet-user'], $item );
		}
	}

	wp_redirect( admin_url( 'edit.php?post_type=download&page=edd-customers&view=wallet&id=' . $_POST['wallet-user'] . '&edd-message=' . $message ) );
	exit;
}
add_action( 'edd_wallet_process_admin_deposit', 'edd_wallet_process_admin_deposit' );


/**
 * Remove deposit from cart on item add
 *
 * @since       1.0.0
 * @param       int $download_id The download being added
 * @param       array $options Options for the download
 * @return      void
 */
function edd_wallet_clear_deposits_from_cart( $download_id, $options ) {
	$deposit = EDD()->fees->get_fee( 'edd-wallet-deposit' ); 

	// Deposits and items can't be handled at the same time!
	if( $deposit ) {
		EDD()->fees->remove_fee( 'edd-wallet-deposit' );
	}
}
add_action( 'edd_pre_add_to_cart', 'edd_wallet_clear_deposits_from_cart', 10, 2 );


/**
 * Add the deposited amount to the wallet after payment
 *
 * @since       1.0.0
 * @param       int $payment_id The ID of the payment
 * @return      void
 */
function edd_wallet_add_funds( $payment_id ) {
	$fees = edd_get_payment_fees( $payment_id );

	if( $fees && count( $fees ) == 1 ) {
		if( $fees[0]['id'] == 'edd-wallet-deposit' ) {

			// Disable purchase receipts... we send our own emails
			remove_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );

			// Send our custom emails
			edd_wallet_send_email( 'user', $payment_id );

			// Get the ID of the purchaser
			$user_id = edd_get_payment_user_id( $payment_id );

			// Get the current value of their wallet
			$value = get_user_meta( $user_id, '_edd_wallet_value', true );
			$value = ( $value ? $value : 0 );

			// Add the deposit value
			$value = $value + $fees[0]['amount'];

			// Update the user wallet
			update_user_meta( $user_id, '_edd_wallet_value', $value );

			// Record the deposit
			$args = array(
				'user_id'       => $user_id,
				'payment_id'    => $payment_id,
				'type'          => 'deposit',
				'amount'        => $fees[0]['amount']
			);

			edd_wallet()->wallet->add( $args );

			// Tag the payment so we can find it later
			edd_update_payment_meta( $payment_id, '_edd_wallet_deposit', $user_id );
		}
	}
}
add_action( 'edd_complete_purchase', 'edd_wallet_add_funds' );


/**
 * Build a list of wallet activity
 *
 * @since       1.0.0
 * @param       int $user_id The ID of the user to lookup
 * @return      array $activity The wallet activity
 */
function edd_wallet_get_activity( $user_id ) {
	$activity = edd_wallet()->wallet->get_customer_wallet( $user_id );

	return $activity;
}


/**
 * Display notices on admin wallet edit
 *
 * @since       1.0.0
 * @return      void
 */
function edd_wallet_edit_notice() {
	if( isset( $_GET['edd-message'] ) && $_GET['edd-message'] == 'wallet_deposit_succeeded' && current_user_can( 'view_shop_reports' ) ) {
		add_settings_error( 'edd-notices', 'edd-wallet-deposit-succeeded', __( 'The deposit has been made.', 'edd-wallet' ), 'updated' );
	}

	if( isset( $_GET['edd-message'] ) && $_GET['edd-message'] == 'wallet_withdraw_succeeded' && current_user_can( 'view_shop_reports' ) ) {
		add_settings_error( 'edd-notices', 'edd-wallet-withdraw-succeeded', __( 'The withdrawal has been made.', 'edd-wallet' ), 'updated' );
	}

	if( isset( $_GET['edd-message'] ) && $_GET['edd-message'] == 'wallet_deposit_failed' && current_user_can( 'view_shop_reports' ) ) {
		add_settings_error( 'edd-notices', 'edd-wallet-deposit-failed', __( 'You can not withdraw more than the current balance.', 'edd-wallet' ), 'error' );
	}

	if( isset( $_GET['edd-message'] ) && $_GET['edd-message'] == 'wallet_deposit_invalid' && current_user_can( 'view_shop_reports' ) ) {
		add_settings_error( 'edd-notices', 'edd-wallet-deposit-invalid', __( 'Please enter a valid amount which is greater than 0.', 'edd-wallet' ), 'error' );
	}
}
add_action( 'admin_notices', 'edd_wallet_edit_notice' );
