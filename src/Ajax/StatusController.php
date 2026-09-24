<?php
/**
 * Frontend payment status polling AJAX handler.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Ajax;

use Ifthenpay\Forminator\Api\IfthenpayClient;
use Ifthenpay\Forminator\Forminator\TransactionStore;
use Ifthenpay\Forminator\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backs the frontend status popup's polling loop (`assets/js/frontend.js`).
 * Every call re-reads `TransactionStore` first — the webhook is what
 * actually resolves the payment; this only reports what's already known,
 * plus an optional one-shot live check against ifthenpay's own transaction-
 * status API when a `transaction_id` is available (see
 * `deferInitialPopupForQuickCheck` in the frontend script) so a payment that
 * finished before the popup even opened doesn't sit there as "pending".
 */
class StatusController {

	public const ACTION = 'iftp_fm_status';

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Sets up the handler.
	 *
	 * @param TransactionStore|null $transactions Transactions storage; a fresh instance is used when omitted.
	 * @param IfthenpayClient|null  $client       Ifthenpay API client; a fresh instance is used when omitted.
	 */
	public function __construct( ?TransactionStore $transactions = null, ?IfthenpayClient $client = null ) {
		$this->transactions = $transactions ?? new TransactionStore();
		$this->client       = $client ?? new IfthenpayClient();
	}

	/**
	 * Registers the priv and nopriv AJAX actions.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'check' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'check' ) );
	}

	/**
	 * Reports the stored status for a reference, with an optional one-shot live check.
	 */
	public function check(): void {
		if ( ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page.', 'ifthenpay-payments-for-forminator' ) ), 403 );
		}

		$ref            = sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) );
		$transaction_id = sanitize_text_field( wp_unslash( $_POST['transaction_id'] ?? '' ) );

		$record = $this->transactions->get( $ref );

		if ( null === $record ) {
			wp_send_json_error( array( 'message' => __( 'Unknown payment reference.', 'ifthenpay-payments-for-forminator' ) ) );
		}

		// Worth keeping even if the payment never confirms — the webhook may
		// have already resolved the status without ever seeing a transaction
		// id, so this is otherwise the only chance to capture it.
		if ( '' !== $transaction_id && '' === (string) ( $record['transaction_id'] ?? '' ) ) {
			$this->transactions->record_transaction_id( $ref, $transaction_id );
			$record['transaction_id'] = $transaction_id;
		}

		$status = (string) $record['status'];

		// One-shot live check so the popup can flip to "paid" before the
		// webhook lands. I only report it, never store it: the transaction
		// id comes from the browser and nothing ties it to this ref, so
		// someone could pass another (cheaper) paid transaction's id. Only
		// the webhook marks a row paid, and it also creates the entry.
		if ( TransactionStore::STATUS_PENDING === $status && '' !== $transaction_id ) {
			$live = $this->client->get_transaction_status( $transaction_id );

			if ( 'paid' === strtolower( (string) ( $live['Status'] ?? '' ) ) ) {
				$status = TransactionStore::STATUS_PAID;
			}
		}

		wp_send_json_success(
			array(
				'status'  => $status,
				'message' => Plugin::status_message( $status ),
			)
		);
	}
}
