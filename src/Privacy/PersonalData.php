<?php
/**
 * Personal data export/erasure for the transactions table.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Privacy;

use Ifthenpay\Forminator\Forminator\TransactionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks our transactions into Tools > Export/Erase Personal Data. Forminator handles its own entries;
 * this covers the name, email and submitted values I keep per payment attempt.
 */
class PersonalData {

	private const GROUP_ID = 'iftp-fm-payments';
	private const PER_PAGE = 100;

	/**
	 * Transactions storage.
	 *
	 * @var TransactionStore
	 */
	private TransactionStore $transactions;

	/**
	 * Sets up the handler.
	 *
	 * @param TransactionStore|null $transactions Transactions storage.
	 */
	public function __construct( ?TransactionStore $transactions = null ) {
		$this->transactions = $transactions ?? new TransactionStore();
	}

	/**
	 * Registers the exporter and eraser.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Adds our exporter to core's list.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters[ self::GROUP_ID ] = array(
			'exporter_friendly_name' => __( 'ifthenpay payments', 'ifthenpay-payments-for-forminator' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Adds our eraser to core's list.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers[ self::GROUP_ID ] = array(
			'eraser_friendly_name' => __( 'ifthenpay payments', 'ifthenpay-payments-for-forminator' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * One page of the email's payments, in core's export format.
	 *
	 * @param string $email The requester's email.
	 * @param int    $page  1-based page number.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$records = $this->transactions->find_by_email( $email, self::PER_PAGE, ( max( 1, $page ) - 1 ) * self::PER_PAGE );
		$data    = array();

		foreach ( $records as $record ) {
			$item = array(
				array(
					'name'  => __( 'Reference', 'ifthenpay-payments-for-forminator' ),
					'value' => $record['ref'],
				),
				array(
					'name'  => __( 'Date', 'ifthenpay-payments-for-forminator' ),
					'value' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['created_at'] ),
				),
				array(
					'name'  => __( 'Amount', 'ifthenpay-payments-for-forminator' ),
					'value' => number_format_i18n( $record['amount'], 2 ),
				),
				array(
					'name'  => __( 'Status', 'ifthenpay-payments-for-forminator' ),
					'value' => $record['status'],
				),
				array(
					'name'  => __( 'Payment method', 'ifthenpay-payments-for-forminator' ),
					'value' => $record['pay_method'],
				),
				array(
					'name'  => __( 'Name', 'ifthenpay-payments-for-forminator' ),
					'value' => $record['customer_name'],
				),
				array(
					'name'  => __( 'Email', 'ifthenpay-payments-for-forminator' ),
					'value' => $record['customer_email'],
				),
			);

			foreach ( $this->transactions->entry_data_pairs( $record ) as $pair ) {
				$item[] = array(
					'name'  => $pair['label'],
					'value' => $pair['value'],
				);
			}

			$data[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'ifthenpay payments', 'ifthenpay-payments-for-forminator' ),
				'item_id'     => 'iftp-fm-payment-' . $record['ref'],
				'data'        => $item,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $records ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymizes one batch of the email's payments. The payment rows stay, so the merchant's totals still add up.
	 * Erased rows stop matching the email, so every call reads from the start.
	 *
	 * @param string $email The requester's email.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email ): array {
		$records = $this->transactions->find_by_email( $email, self::PER_PAGE );
		$changed = $this->transactions->anonymize( array_column( $records, 'ref' ) );

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => false,
			'messages'       => array(),
			// A failed UPDATE would match the same rows forever, so I stop there too.
			'done'           => count( $records ) < self::PER_PAGE || 0 === $changed,
		);
	}
}
