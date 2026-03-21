<?php
/**
 * Plugin Name: Delete All WooCommerce Products
 * Plugin URI:  https://github.com/al-nemirov/delete-all-wc-products
 * Description: Adds a button to permanently delete all WooCommerce products (including variations) with batched deletion, dry-run preview, backup, and audit logging.
 * Version:     2.0
 * Author:      Alexander Nemirov
 * Author URI:  https://github.com/al-nemirov
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: delete-all-wc-products
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package DeleteAllWCProducts
 */

// Защита от прямого доступа к файлу
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Batch size for chunked deletion.
 *
 * @since 2.0
 */
if ( ! defined( 'DAWP_BATCH_SIZE' ) ) {
	define( 'DAWP_BATCH_SIZE', 50 );
}

/**
 * Option key for storing the confirmation token.
 *
 * @since 2.0
 */
define( 'DAWP_CONFIRM_TOKEN_OPTION', 'dawp_confirm_token' );

/**
 * Option key for storing the audit log.
 *
 * @since 2.0
 */
define( 'DAWP_AUDIT_LOG_OPTION', 'dawp_audit_log' );

/**
 * Upload sub-directory for backup files.
 *
 * @since 2.0
 */
define( 'DAWP_BACKUP_DIR', 'dawp-backups' );

/* =========================================================================
 * 1. ADMIN MENU
 * ========================================================================= */

/**
 * Регистрирует подменю плагина в разделе «Товары» (Products).
 *
 * Добавляет пункт «Delete All Products» в подменю WooCommerce Products.
 * Доступ ограничен пользователями с правом manage_options (администраторы).
 *
 * @since 1.0
 *
 * @return void
 */
function dawp_add_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=product',  // Родительское меню — Товары
		'Delete All Products',          // Заголовок страницы
		'Delete All Products',          // Название пункта меню
		'manage_options',               // Требуемое право доступа
		'delete-all-products',          // Уникальный slug страницы
		'dawp_render_admin_page'        // Callback-функция рендера страницы
	);
}
add_action( 'admin_menu', 'dawp_add_admin_menu' );

/* =========================================================================
 * 2. AJAX HANDLERS
 * ========================================================================= */

/**
 * Registers AJAX handlers for batched deletion, dry-run, backup, and confirmation.
 *
 * @since 2.0
 */
add_action( 'wp_ajax_dawp_dry_run',          'dawp_ajax_dry_run' );
add_action( 'wp_ajax_dawp_request_confirm',  'dawp_ajax_request_confirm' );
add_action( 'wp_ajax_dawp_create_backup',    'dawp_ajax_create_backup' );
add_action( 'wp_ajax_dawp_delete_batch',     'dawp_ajax_delete_batch' );

/**
 * AJAX: Dry-run — returns a summary of products that would be deleted.
 *
 * @since 2.0
 *
 * @return void Outputs JSON response.
 */
function dawp_ajax_dry_run() {
	check_ajax_referer( 'dawp_ajax_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$products = get_posts( array(
		'post_type'   => array( 'product', 'product_variation' ),
		'numberposts' => -1,
		'post_status' => 'any',
		'fields'      => 'ids',
	) );

	$total = count( $products );

	if ( 0 === $total ) {
		wp_send_json_success( array(
			'total'    => 0,
			'products' => array(),
			'message'  => 'No products found.',
		) );
	}

	// Gather summary details (limit detail list to first 200 for performance).
	$detail_ids = array_slice( $products, 0, 200 );
	$list       = array();

	foreach ( $detail_ids as $pid ) {
		$post = get_post( $pid );
		if ( ! $post ) {
			continue;
		}

		$sku = '';
		if ( function_exists( 'wc_get_product' ) ) {
			$wc_product = wc_get_product( $pid );
			if ( $wc_product ) {
				$sku = $wc_product->get_sku();
			}
		}

		$list[] = array(
			'id'     => $pid,
			'title'  => $post->post_title,
			'type'   => $post->post_type,
			'status' => $post->post_status,
			'sku'    => $sku,
		);
	}

	wp_send_json_success( array(
		'total'    => $total,
		'showing'  => count( $list ),
		'products' => $list,
	) );
}

/**
 * AJAX: Request server-side confirmation token.
 *
 * Generates a one-time token tied to the current user and product count,
 * implementing the two-step confirmation process.
 *
 * @since 2.0
 *
 * @return void Outputs JSON response.
 */
function dawp_ajax_request_confirm() {
	check_ajax_referer( 'dawp_ajax_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$product_count = isset( $_POST['product_count'] ) ? absint( $_POST['product_count'] ) : 0;

	if ( 0 === $product_count ) {
		wp_send_json_error( array( 'message' => 'No products to delete.' ) );
	}

	// Generate a unique token bound to the user, count, and time.
	$token = wp_generate_password( 32, false );
	$data  = array(
		'token'   => $token,
		'user_id' => get_current_user_id(),
		'count'   => $product_count,
		'created' => time(),
	);

	update_option( DAWP_CONFIRM_TOKEN_OPTION, $data, false );

	wp_send_json_success( array(
		'token'   => $token,
		'count'   => $product_count,
		'message' => sprintf( 'Confirm deletion of %d items. This token expires in 5 minutes.', $product_count ),
	) );
}

/**
 * AJAX: Create a JSON backup of all product IDs, titles, and SKUs.
 *
 * Writes the backup file to wp-content/uploads/dawp-backups/.
 *
 * @since 2.0
 *
 * @return void Outputs JSON response.
 */
function dawp_ajax_create_backup() {
	check_ajax_referer( 'dawp_ajax_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$products = get_posts( array(
		'post_type'   => array( 'product', 'product_variation' ),
		'numberposts' => -1,
		'post_status' => 'any',
		'fields'      => 'ids',
	) );

	if ( empty( $products ) ) {
		wp_send_json_error( array( 'message' => 'No products to back up.' ) );
	}

	$backup = array();
	foreach ( $products as $pid ) {
		$post = get_post( $pid );
		if ( ! $post ) {
			continue;
		}

		$sku = '';
		if ( function_exists( 'wc_get_product' ) ) {
			$wc_product = wc_get_product( $pid );
			if ( $wc_product ) {
				$sku = $wc_product->get_sku();
			}
		}

		$backup[] = array(
			'id'     => $pid,
			'title'  => $post->post_title,
			'sku'    => $sku,
			'type'   => $post->post_type,
			'status' => $post->post_status,
		);
	}

	// Write to uploads directory.
	$upload_dir = wp_upload_dir();
	$backup_dir = trailingslashit( $upload_dir['basedir'] ) . DAWP_BACKUP_DIR;

	if ( ! file_exists( $backup_dir ) ) {
		wp_mkdir_p( $backup_dir );
	}

	// Protect directory from web access.
	$htaccess = $backup_dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Deny from all\n" );
	}

	$filename  = 'products-backup-' . gmdate( 'Y-m-d-His' ) . '.json';
	$filepath  = trailingslashit( $backup_dir ) . $filename;
	$json      = wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	$written   = file_put_contents( $filepath, $json );

	if ( false === $written ) {
		wp_send_json_error( array( 'message' => 'Failed to write backup file.' ) );
	}

	wp_send_json_success( array(
		'file'    => $filename,
		'path'    => $filepath,
		'count'   => count( $backup ),
		'message' => sprintf( 'Backup created: %s (%d products).', $filename, count( $backup ) ),
	) );
}

/**
 * AJAX: Delete a single batch of products.
 *
 * Validates the server-side confirmation token before executing.
 * Deletes up to DAWP_BATCH_SIZE products per request. Returns remaining count
 * so the client can issue follow-up requests until done.
 *
 * @since 2.0
 *
 * @return void Outputs JSON response.
 */
function dawp_ajax_delete_batch() {
	check_ajax_referer( 'dawp_ajax_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	// Validate server-side confirmation token.
	$token = isset( $_POST['confirm_token'] ) ? sanitize_text_field( $_POST['confirm_token'] ) : '';
	$saved = get_option( DAWP_CONFIRM_TOKEN_OPTION, array() );

	if ( empty( $saved ) || empty( $token ) ) {
		wp_send_json_error( array( 'message' => 'Missing confirmation token. Please start the process again.' ) );
	}

	if ( ! hash_equals( $saved['token'], $token ) ) {
		wp_send_json_error( array( 'message' => 'Invalid confirmation token.' ) );
	}

	if ( (int) $saved['user_id'] !== get_current_user_id() ) {
		wp_send_json_error( array( 'message' => 'Token user mismatch.' ) );
	}

	// Token expires after 5 minutes.
	if ( ( time() - (int) $saved['created'] ) > 300 ) {
		delete_option( DAWP_CONFIRM_TOKEN_OPTION );
		wp_send_json_error( array( 'message' => 'Confirmation token expired. Please start again.' ) );
	}

	// Fetch one batch of product IDs.
	$batch_size  = DAWP_BATCH_SIZE;
	$product_ids = get_posts( array(
		'post_type'   => array( 'product', 'product_variation' ),
		'numberposts' => $batch_size,
		'post_status' => 'any',
		'fields'      => 'ids',
	) );

	if ( empty( $product_ids ) ) {
		// All done — clean up token and transients.
		delete_option( DAWP_CONFIRM_TOKEN_OPTION );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		wp_send_json_success( array(
			'deleted_batch' => 0,
			'remaining'     => 0,
			'done'          => true,
			'message'       => 'All products have been deleted.',
		) );
	}

	$deleted = 0;
	foreach ( $product_ids as $id ) {
		if ( wp_delete_post( $id, true ) ) {
			$deleted++;
		}
	}

	// Count remaining products.
	$remaining = dawp_count_all_products();

	$done = ( 0 === $remaining );

	if ( $done ) {
		delete_option( DAWP_CONFIRM_TOKEN_OPTION );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
	}

	wp_send_json_success( array(
		'deleted_batch' => $deleted,
		'remaining'     => $remaining,
		'done'          => $done,
	) );
}

/* =========================================================================
 * 3. AUDIT LOG
 * ========================================================================= */

/**
 * Records an entry in the plugin audit log.
 *
 * Stores up to the last 200 entries in the wp_options table.
 *
 * @since 2.0
 *
 * @param string $action      Action identifier (e.g. 'delete_started', 'delete_completed').
 * @param array  $extra_data  Optional additional data to store.
 *
 * @return void
 */
function dawp_audit_log( $action, $extra_data = array() ) {
	$log = get_option( DAWP_AUDIT_LOG_OPTION, array() );

	$entry = array_merge(
		array(
			'action'    => $action,
			'user_id'   => get_current_user_id(),
			'user_name' => wp_get_current_user()->user_login,
			'timestamp' => current_time( 'mysql' ),
			'ip'        => dawp_get_client_ip(),
		),
		$extra_data
	);

	$log[] = $entry;

	// Keep only the last 200 entries.
	if ( count( $log ) > 200 ) {
		$log = array_slice( $log, -200 );
	}

	update_option( DAWP_AUDIT_LOG_OPTION, $log, false );
}

/**
 * Returns the client IP address.
 *
 * @since 2.0
 *
 * @return string
 */
function dawp_get_client_ip() {
	$headers = array(
		'HTTP_CLIENT_IP',
		'HTTP_X_FORWARDED_FOR',
		'REMOTE_ADDR',
	);

	foreach ( $headers as $header ) {
		if ( ! empty( $_SERVER[ $header ] ) ) {
			$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			return trim( $ip[0] );
		}
	}

	return '0.0.0.0';
}

/* =========================================================================
 * 4. HELPER FUNCTIONS
 * ========================================================================= */

/**
 * Counts all products and variations.
 *
 * @since 2.0
 *
 * @return int
 */
function dawp_count_all_products() {
	global $wpdb;

	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_type IN ('product', 'product_variation')
		 AND post_status != 'auto-draft'"
	);
}

/* =========================================================================
 * 5. ADMIN PAGE RENDER
 * ========================================================================= */

/**
 * Enqueue admin scripts on our plugin page only.
 *
 * @since 2.0
 *
 * @param string $hook The current admin page hook.
 *
 * @return void
 */
function dawp_admin_enqueue_scripts( $hook ) {
	if ( 'product_page_delete-all-products' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'dawp-admin-js',
		false, // Inline script registered below.
		array( 'jquery' ),
		'2.0',
		true
	);
}
add_action( 'admin_enqueue_scripts', 'dawp_admin_enqueue_scripts' );

/**
 * Отображает страницу администрирования плагина.
 *
 * Includes: pre-flight check with product count, dry-run preview, backup
 * creation, two-step server-side confirmation, batched AJAX deletion with
 * progress bar, and audit log viewer.
 *
 * @since 1.0
 * @since 2.0 Rewritten with batched deletion, dry-run, backup, audit log.
 *
 * @return void
 */
function dawp_render_admin_page() {
	$product_count = dawp_count_all_products();
	$ajax_nonce    = wp_create_nonce( 'dawp_ajax_nonce' );
	?>
	<div class="wrap" id="dawp-wrap">
		<h1>Delete All WooCommerce Products</h1>

		<!-- Pre-flight summary -->
		<div class="card" style="max-width:720px; margin-bottom:20px;">
			<h2 style="margin-top:0;">Product Summary</h2>
			<p>
				Total products and variations in the database:
				<strong id="dawp-product-count"><?php echo esc_html( $product_count ); ?></strong>
			</p>
			<?php if ( 0 === $product_count ) : ?>
				<p><em>No products found. Nothing to delete.</em></p>
			<?php endif; ?>
		</div>

		<?php if ( $product_count > 0 ) : ?>

		<!-- Step 1: Dry-run / Preview -->
		<div class="card" style="max-width:720px; margin-bottom:20px;">
			<h2 style="margin-top:0;">Step 1: Preview (Dry Run)</h2>
			<p>See what would be deleted before committing. No products are removed in this step.</p>
			<button type="button" id="dawp-btn-dryrun" class="button button-secondary">Preview Products to Delete</button>

			<div id="dawp-dryrun-results" style="display:none; margin-top:15px;">
				<p id="dawp-dryrun-summary"></p>
				<div id="dawp-dryrun-table-wrap" style="max-height:300px; overflow-y:auto;">
					<table class="widefat striped" id="dawp-dryrun-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Title</th>
								<th>Type</th>
								<th>Status</th>
								<th>SKU</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>

		<!-- Step 2: Backup -->
		<div class="card" style="max-width:720px; margin-bottom:20px;">
			<h2 style="margin-top:0;">Step 2: Create Backup (Recommended)</h2>
			<p>Save product IDs, titles, and SKUs to a JSON file before deletion.</p>
			<button type="button" id="dawp-btn-backup" class="button button-secondary">Create Backup</button>
			<span id="dawp-backup-status" style="margin-left:10px;"></span>
		</div>

		<!-- Step 3: Delete -->
		<div class="card" style="max-width:720px; margin-bottom:20px; border-left:4px solid #dc3232;">
			<h2 style="margin-top:0; color:#dc3232;">Step 3: Delete All Products</h2>
			<p style="color:#dc3232; font-weight:bold;">
				WARNING: This action is irreversible! All products and variations will be permanently deleted (bypassing trash).
			</p>
			<p>
				Products to delete: <strong id="dawp-delete-count"><?php echo esc_html( $product_count ); ?></strong>
			</p>

			<p>
				<label>
					Type <strong>DELETE</strong> to confirm:
					<input type="text" id="dawp-confirm-input" class="regular-text" autocomplete="off" placeholder="Type DELETE here" style="margin-left:5px;">
				</label>
			</p>

			<button type="button" id="dawp-btn-delete" class="button button-primary" style="background:#dc3232; border-color:#b02828;" disabled>
				DELETE ALL PRODUCTS PERMANENTLY
			</button>

			<!-- Progress bar -->
			<div id="dawp-progress-wrap" style="display:none; margin-top:15px;">
				<div style="background:#e0e0e0; border-radius:4px; overflow:hidden; height:24px; position:relative; max-width:100%;">
					<div id="dawp-progress-bar" style="background:#0073aa; height:100%; width:0%; transition:width 0.3s;"></div>
					<span id="dawp-progress-text" style="position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); font-size:12px; font-weight:bold; color:#333;">0%</span>
				</div>
				<p id="dawp-progress-status" style="margin-top:8px;"></p>
			</div>
		</div>

		<?php endif; ?>

		<!-- Audit Log -->
		<div class="card" style="max-width:720px; margin-bottom:20px;">
			<h2 style="margin-top:0;">Audit Log</h2>
			<?php
			$log = get_option( DAWP_AUDIT_LOG_OPTION, array() );
			if ( empty( $log ) ) :
				?>
				<p><em>No log entries yet.</em></p>
			<?php else : ?>
				<div style="max-height:300px; overflow-y:auto;">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Date</th>
								<th>User</th>
								<th>Action</th>
								<th>Details</th>
								<th>IP</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_reverse( $log ) as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $entry['timestamp'] ); ?></td>
									<td><?php echo esc_html( $entry['user_name'] . ' (#' . $entry['user_id'] . ')' ); ?></td>
									<td><?php echo esc_html( $entry['action'] ); ?></td>
									<td>
										<?php
										$details = $entry;
										unset( $details['action'], $details['user_id'], $details['user_name'], $details['timestamp'], $details['ip'] );
										echo esc_html( ! empty( $details ) ? wp_json_encode( $details ) : '-' );
										?>
									</td>
									<td><?php echo esc_html( $entry['ip'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<script type="text/javascript">
	(function($) {
		'use strict';

		var ajaxUrl    = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
		var nonce      = '<?php echo esc_js( $ajax_nonce ); ?>';
		var totalCount = <?php echo (int) $product_count; ?>;
		var confirmToken = '';
		var isDeleting   = false;

		/* ----- Enable / disable delete button based on typed confirmation ----- */
		$('#dawp-confirm-input').on('input', function() {
			var val = $(this).val().trim().toUpperCase();
			$('#dawp-btn-delete').prop('disabled', val !== 'DELETE');
		});

		/* ----- Step 1: Dry Run ----- */
		$('#dawp-btn-dryrun').on('click', function() {
			var $btn = $(this);
			$btn.prop('disabled', true).text('Loading...');

			$.post(ajaxUrl, {
				action: 'dawp_dry_run',
				nonce: nonce
			}, function(response) {
				$btn.prop('disabled', false).text('Preview Products to Delete');

				if ( ! response.success ) {
					alert( response.data.message || 'Dry run failed.' );
					return;
				}

				var data = response.data;
				$('#dawp-dryrun-results').show();

				var summaryText = 'Total products found: ' + data.total;
				if ( data.showing < data.total ) {
					summaryText += ' (showing first ' + data.showing + ')';
				}
				$('#dawp-dryrun-summary').text( summaryText );

				var $tbody = $('#dawp-dryrun-table tbody').empty();
				$.each( data.products, function(i, p) {
					$tbody.append(
						'<tr>' +
						'<td>' + p.id + '</td>' +
						'<td>' + $('<span>').text(p.title).html() + '</td>' +
						'<td>' + p.type + '</td>' +
						'<td>' + p.status + '</td>' +
						'<td>' + $('<span>').text(p.sku || '-').html() + '</td>' +
						'</tr>'
					);
				});

				// Update count display.
				totalCount = data.total;
				$('#dawp-product-count, #dawp-delete-count').text( data.total );
			}).fail(function() {
				$btn.prop('disabled', false).text('Preview Products to Delete');
				alert('Request failed.');
			});
		});

		/* ----- Step 2: Backup ----- */
		$('#dawp-btn-backup').on('click', function() {
			var $btn    = $(this);
			var $status = $('#dawp-backup-status');
			$btn.prop('disabled', true).text('Creating...');
			$status.text('');

			$.post(ajaxUrl, {
				action: 'dawp_create_backup',
				nonce: nonce
			}, function(response) {
				$btn.prop('disabled', false).text('Create Backup');
				if ( response.success ) {
					$status.css('color', '#008000').text( response.data.message );
				} else {
					$status.css('color', '#dc3232').text( response.data.message || 'Backup failed.' );
				}
			}).fail(function() {
				$btn.prop('disabled', false).text('Create Backup');
				$status.css('color', '#dc3232').text('Request failed.');
			});
		});

		/* ----- Step 3: Delete ----- */
		$('#dawp-btn-delete').on('click', function() {
			if ( isDeleting ) return;

			// Extra client-side confirmation.
			if ( ! confirm('Are you ABSOLUTELY sure you want to delete ALL ' + totalCount + ' product(s)? This cannot be undone.') ) {
				return;
			}

			isDeleting = true;
			$(this).prop('disabled', true);
			$('#dawp-confirm-input').prop('disabled', true);

			// Step 3a: Request server-side confirmation token.
			$.post(ajaxUrl, {
				action: 'dawp_request_confirm',
				nonce: nonce,
				product_count: totalCount
			}, function(response) {
				if ( ! response.success ) {
					alert( response.data.message || 'Confirmation request failed.' );
					resetDeleteUI();
					return;
				}

				confirmToken = response.data.token;

				// Show progress.
				$('#dawp-progress-wrap').show();
				$('#dawp-progress-status').text( 'Starting deletion...' );

				dawpLogAction('delete_started', { total_products: totalCount });

				// Start batched deletion.
				deleteBatch( totalCount, 0 );
			}).fail(function() {
				alert('Could not obtain confirmation token.');
				resetDeleteUI();
			});
		});

		/**
		 * Recursively deletes products in batches via AJAX.
		 */
		function deleteBatch( originalTotal, totalDeleted ) {
			$.post(ajaxUrl, {
				action: 'dawp_delete_batch',
				nonce: nonce,
				confirm_token: confirmToken
			}, function(response) {
				if ( ! response.success ) {
					alert( response.data.message || 'Batch deletion failed.' );
					dawpLogAction('delete_error', { message: response.data.message || 'Unknown error', deleted_so_far: totalDeleted });
					resetDeleteUI();
					return;
				}

				var data        = response.data;
				totalDeleted   += data.deleted_batch;
				var pct         = originalTotal > 0 ? Math.round( ( totalDeleted / originalTotal ) * 100 ) : 100;

				$('#dawp-progress-bar').css( 'width', pct + '%' );
				$('#dawp-progress-text').text( pct + '%' );
				$('#dawp-progress-status').text(
					'Deleted ' + totalDeleted + ' of ' + originalTotal + ' items. ' +
					( data.remaining > 0 ? data.remaining + ' remaining...' : 'Done!' )
				);

				if ( data.done ) {
					dawpLogAction('delete_completed', { total_deleted: totalDeleted });
					$('#dawp-product-count, #dawp-delete-count').text('0');
					$('#dawp-progress-bar').css('background', '#46b450');
					$('#dawp-progress-status').append(' <strong>All products deleted successfully.</strong>');
					isDeleting = false;
				} else {
					// Continue with next batch.
					deleteBatch( originalTotal, totalDeleted );
				}
			}).fail(function() {
				alert('AJAX request failed during batch deletion.');
				dawpLogAction('delete_error', { message: 'AJAX failure', deleted_so_far: totalDeleted });
				resetDeleteUI();
			});
		}

		/**
		 * Log action via AJAX (fire-and-forget).
		 */
		function dawpLogAction( action, extra ) {
			$.post(ajaxUrl, $.extend({
				action: 'dawp_audit_log_entry',
				nonce: nonce,
				log_action: action
			}, extra || {}));
		}

		function resetDeleteUI() {
			isDeleting = false;
			$('#dawp-btn-delete').prop('disabled', true);
			$('#dawp-confirm-input').prop('disabled', false).val('');
		}

	})(jQuery);
	</script>
	<?php
}

/* =========================================================================
 * 6. AJAX AUDIT LOG ENTRY
 * ========================================================================= */

add_action( 'wp_ajax_dawp_audit_log_entry', 'dawp_ajax_audit_log_entry' );

/**
 * AJAX: Record an audit log entry from the client.
 *
 * @since 2.0
 *
 * @return void
 */
function dawp_ajax_audit_log_entry() {
	check_ajax_referer( 'dawp_ajax_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$log_action = isset( $_POST['log_action'] ) ? sanitize_text_field( $_POST['log_action'] ) : 'unknown';

	$allowed_keys = array( 'total_products', 'total_deleted', 'message', 'deleted_so_far' );
	$extra        = array();

	foreach ( $allowed_keys as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$extra[ $key ] = sanitize_text_field( $_POST[ $key ] );
		}
	}

	dawp_audit_log( $log_action, $extra );

	wp_send_json_success();
}

/* =========================================================================
 * 7. PLUGIN ACTIVATION / DEACTIVATION
 * ========================================================================= */

/**
 * Clean up on plugin deactivation.
 *
 * Removes the confirmation token option. The audit log is preserved
 * intentionally.
 *
 * @since 2.0
 *
 * @return void
 */
function dawp_deactivate() {
	delete_option( DAWP_CONFIRM_TOKEN_OPTION );
}
register_deactivation_hook( __FILE__, 'dawp_deactivate' );
