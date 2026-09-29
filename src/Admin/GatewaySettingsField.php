<?php
/**
 * Gateway payment methods table renderer.
 *
 * @package Ifthenpay\Forminator
 */

declare(strict_types=1);

namespace Ifthenpay\Forminator\Admin;

use Ifthenpay\Forminator\Repository\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the methods table: one row per catalog method, an "Enabled"
 * checkbox, a star-toggle "Default Method" radio, and a "Request Activation"
 * button for anything not yet provisioned.
 *
 * A radio group is used for the default method, never a select or checkbox
 * group — see the WPREA "Default Method Selector" contract.
 */
class GatewaySettingsField {

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Sets up the field.
	 *
	 * @param SettingsRepository|null $settings Settings storage; a fresh instance is used when omitted.
	 */
	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? new SettingsRepository();
	}

	/**
	 * Renders the full methods table markup.
	 */
	public function render(): string {
		// Hidden (API `IsVisible: false`) methods stay in storage for their
		// icon/alias but aren't offered here — ifthenpay isn't currently
		// exposing them, so there's nothing to toggle.
		$methods        = array_filter( $this->settings->get_methods(), static fn ( $method ) => $method['visible'] ?? true );
		$default_method = $this->settings->get_default_method();

		if ( array() === $methods ) {
			return '<p class="iftp-fm-empty">' . esc_html__( 'Connect your Backoffice Key to load the available payment methods.', 'ifthenpay-payments-for-forminator' ) . '</p>';
		}

		ob_start();
		?>
		<table class="iftp-fm-methods-table sui-table">
			<thead>
				<tr>
					<th scope="col" class="iftp-fm-col-control"><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-forminator' ); ?></th>
					<th scope="col" class="iftp-fm-col-control iftp-fm-col-divider"><?php esc_html_e( 'Default', 'ifthenpay-payments-for-forminator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-forminator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Account', 'ifthenpay-payments-for-forminator' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'ifthenpay-payments-for-forminator' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $methods as $method ) : ?>
					<?php $this->render_row( $method, $default_method ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders a single method row.
	 *
	 * @param array{entity: string, alias: string, logo?: string, logo_dark?: string, enabled: bool, account: string, position: int, visible?: bool} $method         The catalog method row.
	 * @param string                                                                                                             $default_method The currently configured default method entity.
	 */
	private function render_row( array $method, string $default_method ): void {
		$logo        = $method['logo'] ?? '';
		$logo_dark   = $method['logo_dark'] ?? '';
		$provisioned = '' !== $method['account'];
		// Only the bare account code is shown — the Method column already
		// names the entity. Classic Multibanco (numeric Entidade|Subentidade)
		// keeps both parts; dynamic Multibanco ("MB|EDT-…") drops the prefix.
		$account_parts   = explode( '|', $method['account'] );
		$show_full       = 'MB' === $method['entity'] && ctype_digit( trim( $account_parts[0] ) );
		$account_display = ! $provisioned ? '' : (
			$show_full ? implode( ' | ', array_map( 'trim', $account_parts ) ) : trim( (string) end( $account_parts ) )
		);
		$is_default      = $method['entity'] === $default_method;
		$row_class       = $provisioned ? '' : ' iftp-fm-row--unprovisioned';
		?>
		<tr class="iftp-fm-method-row<?php echo esc_attr( $row_class ); ?>" data-entity="<?php echo esc_attr( $method['entity'] ); ?>">
			<td class="iftp-fm-col-control">
				<input
					type="checkbox"
					class="iftp-fm-method-enabled"
					name="enabled_methods[]"
					value="<?php echo esc_attr( $method['entity'] ); ?>"
					aria-label="<?php /* translators: %s: payment method name, e.g. "MB WAY" */ echo esc_attr( sprintf( __( 'Enable %s', 'ifthenpay-payments-for-forminator' ), $method['alias'] ) ); ?>"
					<?php checked( $method['enabled'] ); ?>
					<?php disabled( ! $provisioned ); ?>
				/>
			</td>
			<td class="iftp-fm-col-control iftp-fm-col-divider">
				<input
					type="radio"
					class="screen-reader-text iftp-fm-default-method"
					id="iftp-fm-default-<?php echo esc_attr( $method['entity'] ); ?>"
					name="default_method"
					value="<?php echo esc_attr( $method['entity'] ); ?>"
					<?php checked( $is_default ); ?>
					<?php disabled( ! $method['enabled'] ); ?>
				/>
				<label
					for="iftp-fm-default-<?php echo esc_attr( $method['entity'] ); ?>"
					class="iftp-fm-star<?php echo $method['enabled'] ? '' : ' iftp-fm-star--hidden'; ?>"
					title="<?php esc_attr_e( 'Default payment method', 'ifthenpay-payments-for-forminator' ); ?>"
				><span aria-hidden="true">&#9733;</span><span class="screen-reader-text">
					<?php
					/* translators: %s: payment method name, e.g. "MB WAY" */
					echo esc_html( sprintf( __( 'Make %s the default payment method', 'ifthenpay-payments-for-forminator' ), $method['alias'] ) );
					?>
				</span></label>
			</td>
			<td class="iftp-fm-col-method">
				<span class="iftp-fm-logo-slot">
					<?php if ( '' !== $logo ) : ?>
						<img src="<?php echo esc_url( $logo ); ?>"<?php echo '' !== $logo_dark ? ' data-logo-dark="' . esc_url( $logo_dark ) . '"' : ''; ?> alt="" loading="lazy" />
					<?php else : ?>
						<?php echo esc_html( $method['entity'] ); ?>
					<?php endif; ?>
				</span>
				<span class="iftp-fm-method-label<?php echo $provisioned ? '' : ' iftp-fm-dimmed'; ?>">
					<?php echo esc_html( $method['alias'] ); ?>
				</span>
			</td>
			<td>
				<?php if ( $provisioned ) : ?>
					<code class="iftp-fm-account-pill"><?php echo esc_html( $account_display ); ?></code>
				<?php endif; ?>
			</td>
			<td>
				<div class="iftp-fm-status-cell">
					<?php if ( $provisioned ) : ?>
						<?php // Both labels ship in the markup so admin.js can swap them live as "Enabled" is toggled. ?>
						<span
							class="iftp-fm-status-badge <?php echo $method['enabled'] ? 'iftp-fm-status-badge--enabled' : 'iftp-fm-status-badge--activated'; ?>"
							data-iftp-status-badge
							data-label-enabled="<?php esc_attr_e( 'Enabled', 'ifthenpay-payments-for-forminator' ); ?>"
							data-label-activated="<?php esc_attr_e( 'Activated', 'ifthenpay-payments-for-forminator' ); ?>"
						><?php echo $method['enabled'] ? esc_html__( 'Enabled', 'ifthenpay-payments-for-forminator' ) : esc_html__( 'Activated', 'ifthenpay-payments-for-forminator' ); ?></span>
					<?php else : ?>
						<span class="iftp-fm-status-badge iftp-fm-status-badge--unavailable"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-forminator' ); ?></span>
						<button
							type="button"
							class="sui-button sui-button-ghost iftp-fm-request-activation"
							data-entity="<?php echo esc_attr( $method['entity'] ); ?>"
						>
							<?php esc_html_e( 'Request Activation', 'ifthenpay-payments-for-forminator' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</td>
		</tr>
		<?php
	}
}
