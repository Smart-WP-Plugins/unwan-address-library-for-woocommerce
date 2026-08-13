<?php
/**
 * Combined customer address book.
 *
 * @package Unwan
 *
 * @var \Unwan\AddressLibrary\AccountController $controller
 * @var \Unwan\AddressLibrary\AddressRepository $repository
 * @var int                                               $user_id
 * @var array<string,array<string,mixed>|null>            $defaults
 * @var array<int,array<string,mixed>>                    $entries
 * @var bool                                              $can_add
 * @var int                                               $search_threshold
 * @var array<string,string>                              $picker_labels
 * @var array<string,string>                              $account_labels
 * @var string                                            $edit_id
 * @var array<string,mixed>|null                          $edit_record
 * @var array<string,array>                               $form_fields
 */

defined( 'ABSPATH' ) || exit;
?>

<section id="unwan-account-ui" class="unwan-account">
	<?php if ( '' !== $edit_id && is_array( $edit_record ) ) : ?>
		<?php
		wc_get_template(
			'myaccount/address-form.php',
			array(
				'controller'  => $controller,
				'edit_id'     => $edit_id,
				'edit_record' => $edit_record,
				'form_fields' => $form_fields,
				'labels'      => $account_labels,
			),
			'',
			UNWAN_PATH . 'templates/'
		);
		?>
	<?php else : ?>
		<header class="unwan-account__header">
			<div>
				<h2 id="unwan-address-book-title"><?php echo esc_html( $account_labels['pageTitle'] ); ?></h2>
				<p><?php echo esc_html( $account_labels['pageDescription'] ); ?></p>
			</div>
			<?php if ( $can_add ) : ?>
				<a class="button unwan-button unwan-button--primary unwan-button--accent" href="<?php echo esc_url( $controller->get_url( 'new' ) ); ?>">
					<?php echo esc_html( $account_labels['addAddress'] ); ?>
				</a>
			<?php endif; ?>
		</header>

		<div class="unwan-defaults">
			<?php
			$unwan_default_labels = array(
				'shipping' => __( 'Ships to', 'unwan-for-woocommerce' ),
				'billing'  => __( 'Bills to', 'unwan-for-woocommerce' ),
			);
			?>
			<?php foreach ( $unwan_default_labels as $role => $unwan_label ) : ?>
				<?php $unwan_entry = $defaults[ $role ] ?? null; ?>
				<article class="unwan-default-card">
					<div class="unwan-default-card__copy">
						<span class="unwan-default-card__label"><?php echo esc_html( $unwan_label ); ?></span>
						<?php if ( is_array( $unwan_entry ) ) : ?>
							<strong class="unwan-default-card__street">
								<?php echo esc_html( $repository->format_street_text( (array) $unwan_entry['fields'] ) ); ?>
							</strong>
							<span class="unwan-default-card__details">
								<?php echo esc_html( $repository->get_recipient_name( (array) $unwan_entry['fields'] ) ); ?>
								<span aria-hidden="true">—</span>
								<?php echo esc_html( $repository->format_location_text( (array) $unwan_entry['fields'] ) ); ?>
							</span>
						<?php else : ?>
							<strong class="unwan-default-card__street"><?php esc_html_e( 'No default address', 'unwan-for-woocommerce' ); ?></strong>
							<span class="unwan-default-card__details"><?php esc_html_e( 'Add an address and assign this role.', 'unwan-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</div>
					<a
						class="unwan-default-card__change"
						href="<?php echo esc_url( is_array( $unwan_entry ) ? $controller->get_url( (string) $unwan_entry['id'] ) : $controller->get_url( 'new', $role ) ); ?>"
					>
						<?php echo esc_html( is_array( $unwan_entry ) ? __( 'Change', 'unwan-for-woocommerce' ) : __( 'Add', 'unwan-for-woocommerce' ) ); ?>
					</a>
				</article>
			<?php endforeach; ?>
		</div>

		<div id="unwan-account-list" class="unwan-address-list" data-unwan-account-list data-unwan-ui>
			<header class="unwan-address-list__header">
				<strong>
					<?php
					printf(
						/* translators: %d: total number of unique addresses. */
						esc_html( _n( '%d saved address', '%d saved addresses', count( $entries ), 'unwan-for-woocommerce' ) ),
						count( $entries )
					);
					?>
				</strong>
				<span class="unwan-address-list__count"><?php esc_html_e( 'Defaults first, then recently updated', 'unwan-for-woocommerce' ); ?></span>
			</header>

			<?php if ( empty( $entries ) ) : ?>
				<div class="unwan-empty-state">
					<h3><?php echo esc_html( $account_labels['emptyHeading'] ); ?></h3>
					<p><?php echo esc_html( $account_labels['emptyDescription'] ); ?></p>
					<?php if ( $can_add ) : ?>
						<a class="button unwan-button unwan-button--primary unwan-button--accent" href="<?php echo esc_url( $controller->get_url( 'new' ) ); ?>">
							<?php echo esc_html( $account_labels['addAddress'] ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<?php if ( count( $entries ) > $search_threshold ) : ?>
					<div class="unwan-address-search">
						<input
							class="unwan-address-search__input"
							type="search"
							name="unwan-account-address-filter"
							aria-label="<?php echo esc_attr( $picker_labels['searchLabel'] ); ?>"
							placeholder="<?php echo esc_attr( $picker_labels['searchPlaceholder'] ); ?>"
							autocomplete="new-search"
							autocapitalize="none"
							autocorrect="off"
							spellcheck="false"
							aria-autocomplete="none"
							inputmode="search"
							enterkeyhint="search"
							data-form-type="other"
							data-lpignore="true"
							data-1p-ignore="true"
							data-unwan-account-search
						>
					</div>
				<?php endif; ?>

				<div class="unwan-address-list__items">
					<?php foreach ( $entries as $unwan_entry ) : ?>
						<?php
						$unwan_fields      = (array) $unwan_entry['fields'];
						$unwan_roles       = array_values( (array) $unwan_entry['roles'] );
						$unwan_street      = $repository->format_street_text( $unwan_fields );
						$unwan_name        = $repository->get_recipient_name( $unwan_fields );
						$unwan_location    = $repository->format_location_text( $unwan_fields );
						$unwan_search_text = strtolower( implode( ' ', array( $unwan_street, $unwan_name, $unwan_location, (string) ( $unwan_fields['postcode'] ?? '' ) ) ) );
						$unwan_menu_id     = 'unwan-address-menu-' . sanitize_html_class( (string) $unwan_entry['id'] );
						?>
						<article class="unwan-address-item" data-unwan-address-item data-unwan-search="<?php echo esc_attr( $unwan_search_text ); ?>">
							<div class="unwan-address-item__content">
								<strong class="unwan-address-item__street">
									<?php echo esc_html( $unwan_street ); ?>
								</strong>
								<span class="unwan-address-item__details">
									<?php echo esc_html( $unwan_name ); ?>
									<span aria-hidden="true">—</span>
									<?php echo esc_html( $unwan_location ); ?>
									<?php if ( ! empty( $unwan_fields['phone'] ) ) : ?>
										<span aria-hidden="true"> · </span>
										<?php echo esc_html( $unwan_fields['phone'] ); ?>
									<?php endif; ?>
								</span>
							</div>

							<div class="unwan-address-item__meta">
								<div class="unwan-address-item__labels">
									<?php foreach ( $unwan_roles as $role ) : ?>
										<span class="unwan-address-label">
											<?php
											echo esc_html(
												'billing' === $role
													? __( 'Billing default', 'unwan-for-woocommerce' )
													: __( 'Shipping default', 'unwan-for-woocommerce' )
											);
											?>
										</span>
									<?php endforeach; ?>
								</div>

								<div class="unwan-address-item__menu">
									<button
										class="unwan-address-item__menu-toggle"
										type="button"
										aria-expanded="false"
										aria-haspopup="menu"
										aria-controls="<?php echo esc_attr( $unwan_menu_id ); ?>"
										<?php /* translators: %s: street address of this address-book entry. */ ?>
										aria-label="<?php echo esc_attr( sprintf( __( 'Actions for %s', 'unwan-for-woocommerce' ), $unwan_street ) ); ?>"
										data-unwan-menu-toggle
									>
										<span aria-hidden="true">•••</span>
									</button>
									<div class="unwan-address-item__menu-panel" id="<?php echo esc_attr( $unwan_menu_id ); ?>" role="menu" hidden data-unwan-menu>
										<a class="unwan-address-item__menu-action" role="menuitem" href="<?php echo esc_url( $controller->get_url( (string) $unwan_entry['id'] ) ); ?>">
											<?php esc_html_e( 'Edit', 'unwan-for-woocommerce' ); ?>
										</a>

										<?php foreach ( array( 'billing', 'shipping' ) as $role ) : ?>
											<form method="post" class="unwan-address-item__menu-form" action="<?php echo esc_url( $controller->get_url() ); ?>">
												<input type="hidden" name="unwan_action" value="make_primary">
												<input type="hidden" name="unwan_role" value="<?php echo esc_attr( $role ); ?>">
												<input type="hidden" name="unwan_id" value="<?php echo esc_attr( $unwan_entry['id'] ); ?>">
												<?php wp_nonce_field( 'unwan_make_primary', 'unwan_nonce' ); ?>
												<button
													type="submit"
													class="unwan-address-item__menu-action"
													role="menuitem"
													title="<?php echo esc_attr( in_array( $role, $unwan_roles, true ) ? ( 'billing' === $role ? __( 'Already the default billing address', 'unwan-for-woocommerce' ) : __( 'Already the default shipping address', 'unwan-for-woocommerce' ) ) : '' ); ?>"
													<?php disabled( in_array( $role, $unwan_roles, true ) ); ?>
												>
													<?php
													echo esc_html(
														'billing' === $role
															? __( 'Make default billing', 'unwan-for-woocommerce' )
															: __( 'Make default shipping', 'unwan-for-woocommerce' )
													);
													?>
												</button>
											</form>
										<?php endforeach; ?>

										<form
											method="post"
											class="unwan-address-item__menu-form"
											action="<?php echo esc_url( $controller->get_url() ); ?>"
											data-unwan-confirm="<?php echo esc_attr( __( 'Delete this saved address?', 'unwan-for-woocommerce' ) ); ?>"
										>
											<input type="hidden" name="unwan_action" value="delete">
											<input type="hidden" name="unwan_id" value="<?php echo esc_attr( $unwan_entry['id'] ); ?>">
											<?php wp_nonce_field( 'unwan_delete_address', 'unwan_nonce' ); ?>
											<button
												type="submit"
												class="unwan-address-item__menu-action unwan-address-item__menu-action--danger"
												role="menuitem"
												title="<?php echo esc_attr( ! empty( $unwan_roles ) ? __( 'Change its default roles before deleting this address', 'unwan-for-woocommerce' ) : '' ); ?>"
												<?php disabled( ! empty( $unwan_roles ) ); ?>
											>
												<?php esc_html_e( 'Delete', 'unwan-for-woocommerce' ); ?>
											</button>
										</form>
									</div>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
					<div class="unwan-address-list__empty" hidden data-unwan-account-empty>
						<?php echo esc_html( $picker_labels['noResults'] ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $can_add ) : ?>
			<p class="unwan-account__limit-note">
				<?php esc_html_e( 'You have reached the additional-address limit. Delete an extra address before adding another.', 'unwan-for-woocommerce' ); ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
