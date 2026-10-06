<?php
/**
 * Gateway/methods catalog sync.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Sync;

use Ifthenpay\Forminator\Api\IfthenpayClient;
use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs Gateway Key rows and the methods catalog from ifthenpay into settings.
 */
class GatewaySync {

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Sets up the sync.
	 *
	 * @param SettingsRepository|null $settings Settings storage.
	 * @param IfthenpayClient|null    $client   Ifthenpay API client.
	 */
	public function __construct( ?SettingsRepository $settings = null, ?IfthenpayClient $client = null ) {
		$this->settings = $settings ?? new SettingsRepository();
		$this->client   = $client ?? new IfthenpayClient();
	}

	/**
	 * First-time connection: stores the Backoffice Key if it has a Gateway Key row, and builds its methods table.
	 *
	 * @param string $backoffice_key The Backoffice Key to connect.
	 */
	public function connect( string $backoffice_key ): bool {
		$rows = $this->client->get_gateway_keys( $backoffice_key, true );

		if ( array() === $rows ) {
			return false;
		}

		$this->settings->set_backoffice_key( $backoffice_key );

		$gateway_keys = array_map( array( $this, 'extract_gateway_key' ), $rows );
		$this->settings->set_gateway_keys( $gateway_keys );
		$this->settings->set_gateway_key( $gateway_keys[0] );
		$this->settings->set_methods( $this->build_methods( $rows[0] ) );

		return true;
	}

	/**
	 * Re-fetches the Gateway Key list and rebuilds the methods table for the selected key.
	 */
	public function sync(): bool {
		$rows = $this->client->get_gateway_keys( $this->settings->get_backoffice_key() );

		if ( array() === $rows ) {
			return false;
		}

		$gateway_keys = array_map( array( $this, 'extract_gateway_key' ), $rows );
		$this->settings->set_gateway_keys( $gateway_keys );

		$gateway_key = $this->settings->get_gateway_key();

		if ( ! in_array( $gateway_key, $gateway_keys, true ) ) {
			$gateway_key = $gateway_keys[0];
			$this->settings->set_gateway_key( $gateway_key );
		}

		$row = $this->find_row_for_gateway_key( $rows, $gateway_key );
		$this->settings->set_methods( $this->build_methods( $row ?? array() ) );

		return true;
	}

	/**
	 * Switches the active Gateway Key and rebuilds its methods table.
	 *
	 * @param string $gateway_key The Gateway Key to switch to.
	 */
	public function switch_gateway_key( string $gateway_key ): bool {
		if ( ! in_array( $gateway_key, $this->settings->get_gateway_keys(), true ) ) {
			return false;
		}

		$rows = $this->client->get_gateway_keys( $this->settings->get_backoffice_key() );
		$row  = $this->find_row_for_gateway_key( $rows, $gateway_key );

		$this->settings->set_gateway_key( $gateway_key );
		$this->settings->set_methods( $this->build_methods( $row ?? array() ) );
		$this->settings->set_default_method( '' );

		return true;
	}

	/**
	 * Whether the stored settings predate a catalog field (e.g. `logo`/`alias`) and need a resync.
	 */
	public function needs_backfill(): bool {
		if ( ! $this->settings->is_connected() ) {
			return false;
		}

		if ( array() === $this->settings->get_gateway_keys() ) {
			return true;
		}

		foreach ( $this->settings->get_methods() as $method ) {
			if ( ! array_key_exists( 'logo', $method ) || ! array_key_exists( 'logo_dark', $method ) || ! array_key_exists( 'visible', $method ) || '' === $method['alias'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Merges ifthenpay's methods catalog with the accounts provisioned on this gateway row.
	 * I keep `IsVisible: false` entries too, so the entries page can still show their icon/alias.
	 * `logo_dark` is empty when ifthenpay has no dark variant; callers fall back to `logo`.
	 *
	 * @param array<string, mixed> $gateway_row The Gateway Key row to resolve accounts from.
	 * @return array<int, array{entity: string, alias: string, logo: string, logo_dark: string, enabled: bool, account: string, position: int, visible: bool}>
	 */
	private function build_methods( array $gateway_row ): array {
		$catalog  = $this->client->get_available_methods();
		$existing = array_column( $this->settings->get_methods(), null, 'entity' );
		$methods  = array();

		foreach ( $catalog as $entry ) {
			$entity  = (string) ( $entry['Entity'] ?? '' );
			$alias   = (string) ( $entry['Method'] ?? '' );
			$account = $this->resolve_account( $gateway_row, $entity );
			$visible = ! empty( $entry['IsVisible'] );

			$methods[] = array(
				'entity'    => $entity,
				'alias'     => $alias,
				'logo'      => (string) ( $entry['SmallImageUrl'] ?? $entry['ImageUrl'] ?? '' ),
				'logo_dark' => (string) ( $entry['SmallImageUrlDark'] ?? '' ),
				'account'   => $account,
				'enabled'   => $visible && '' !== $account && ! empty( $existing[ $entity ]['enabled'] ),
				'position'  => (int) ( $entry['Position'] ?? 0 ),
				'visible'   => $visible,
			);
		}

		return $methods;
	}

	/**
	 * Reads the Gateway Key value out of a gateway row, whichever key name the API used.
	 *
	 * @param array<string, mixed> $row A Gateway Key row.
	 */
	private function extract_gateway_key( array $row ): string {
		return (string) ( $row['GatewayKey'] ?? $row['gatewayKey'] ?? $row['Chave'] ?? '' );
	}

	/**
	 * Finds the row matching a Gateway Key, or falls back to the first row.
	 *
	 * @param array<int, array<string, mixed>> $rows        The Gateway Key rows to search.
	 * @param string                           $gateway_key The Gateway Key to find.
	 * @return array<string, mixed>|null
	 */
	private function find_row_for_gateway_key( array $rows, string $gateway_key ): ?array {
		foreach ( $rows as $row ) {
			if ( $this->extract_gateway_key( $row ) === $gateway_key ) {
				return $row;
			}
		}

		return $rows[0] ?? null;
	}

	/**
	 * A method's `ENTITY|ACCOUNT` segment from the row, with stray spacing trimmed.
	 * Multibanco's column is "Multibanco", not its entity code "MB" like every other method.
	 *
	 * @param array<string, mixed> $row    The Gateway Key row.
	 * @param string               $entity The method entity code.
	 */
	private function resolve_account( array $row, string $entity ): string {
		$column = 'MB' === $entity ? 'Multibanco' : $entity;
		$value  = $row[ $column ] ?? '';

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		return implode( '|', array_map( 'trim', explode( '|', $value, 2 ) ) );
	}
}
