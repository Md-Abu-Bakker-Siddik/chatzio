<?php
/**
 * Chatzio -> License admin page.
 *
 * @var array $state from Chatzio_License::get_state()
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_pro    = Chatzio_License::is_pro();
$updated   = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
$selftest  = ! empty( $_GET['selftest'] );
$test_rows = $selftest ? get_transient( 'chatzio_license_selftest_' . get_current_user_id() ) : false;
if ( $selftest && $test_rows ) {
	delete_transient( 'chatzio_license_selftest_' . get_current_user_id() );
}

$flash = Chatzio_License::consume_flash();

$form_key = isset( $state['key'] ) ? (string) $state['key'] : '';
if ( is_array( $flash ) && ! empty( $flash['entered_key'] ) ) {
	$form_key = (string) $flash['entered_key'];
}

$show_diagnostics = defined( 'WP_DEBUG' ) && WP_DEBUG;

if ( ! $flash && $updated === 'saved' ) {
	$flash = [
		'type'    => 'success',
		'message' => __( 'License key saved.', 'chatzio-ai' ),
	];
}

$status_label = [
	'valid'         => __( 'Active', 'chatzio-ai' ),
	'invalid'       => __( 'Invalid', 'chatzio-ai' ),
	'wrong_product' => __( 'Wrong product', 'chatzio-ai' ),
	'expired'       => __( 'Expired', 'chatzio-ai' ),
	'wrong_domain'  => __( 'Wrong domain', 'chatzio-ai' ),
	'not_activated' => __( 'Not activated', 'chatzio-ai' ),
	'unknown'       => __( 'Not configured', 'chatzio-ai' ),
];

$status_help = [
	'valid'         => __( 'Your paid features are unlocked on this site.', 'chatzio-ai' ),
	'invalid'       => __( 'This key is not recognized by the license server.', 'chatzio-ai' ),
	'wrong_product' => __( 'This key belongs to another product, not Chatzio.', 'chatzio-ai' ),
	'expired'       => __( 'Renew your plan to reactivate paid features.', 'chatzio-ai' ),
	'wrong_domain'  => __( 'This key is currently activated on another domain.', 'chatzio-ai' ),
	'not_activated' => __( 'The key exists but is not active on this site.', 'chatzio-ai' ),
	'unknown'       => __( 'Paste your key and activate to start using paid features.', 'chatzio-ai' ),
];

$variant_label = [
	'monthly'  => __( 'Monthly', 'chatzio-ai' ),
	'lifetime' => __( 'Lifetime', 'chatzio-ai' ),
	'free'     => __( 'Free', 'chatzio-ai' ),
];

$status_raw  = isset( $state['status'] ) ? (string) $state['status'] : 'unknown';
$status_text = isset( $status_label[ $status_raw ] ) ? $status_label[ $status_raw ] : ucfirst( $status_raw );
$status_note = isset( $status_help[ $status_raw ] ) ? $status_help[ $status_raw ] : '';
$pill_class  = $is_pro ? 'ok' : 'bad';
?>
<div class="wrap chatzio-admin chatzio-license-wrap">
	<h1><?php esc_html_e( 'Chatzio License', 'chatzio-ai' ); ?></h1>

	<?php if ( ! $is_pro ) : ?>
		<div class="chatzio-inline-warning">
			<strong><?php esc_html_e( 'Chatzio is currently disabled on this site.', 'chatzio-ai' ); ?></strong>
			<?php esc_html_e( 'Activate a valid key below to enable paid features.', 'chatzio-ai' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $flash && ! empty( $flash['message'] ) ) : ?>
		<div id="chatzio-license-toast" class="chatzio-license-toast <?php echo esc_attr( $flash['type'] === 'success' ? 'ok' : 'error' ); ?>" role="status" aria-live="polite">
			<span class="dashicons <?php echo esc_attr( $flash['type'] === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span>
			<span><?php echo esc_html( (string) $flash['message'] ); ?></span>
			<button type="button" class="chatzio-toast-close" aria-label="<?php esc_attr_e( 'Close', 'chatzio-ai' ); ?>">×</button>
		</div>
	<?php endif; ?>

	<div class="chatzio-license-grid">
		<div class="chatzio-card chatzio-license-status-card">
			<h2><?php esc_html_e( 'Current status', 'chatzio-ai' ); ?></h2>
			<p class="chatzio-status-line">
				<span class="pcl-pill <?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( $status_text ); ?></span>
				<?php if ( $status_note !== '' ) : ?>
					<span class="chatzio-status-note"><?php echo esc_html( $status_note ); ?></span>
				<?php endif; ?>
			</p>

			<table class="chatzio-license-meta">
				<tr>
					<th><?php esc_html_e( 'Plan', 'chatzio-ai' ); ?></th>
					<td><?php echo esc_html( isset( $variant_label[ $state['variant'] ] ) ? $variant_label[ $state['variant'] ] : '—' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Expires (UTC)', 'chatzio-ai' ); ?></th>
					<td><?php echo esc_html( ! empty( $state['expires_at'] ) ? (string) $state['expires_at'] : __( 'Never', 'chatzio-ai' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last check', 'chatzio-ai' ); ?></th>
					<td><?php echo esc_html( ! empty( $state['last_check'] ) ? gmdate( 'Y-m-d H:i:s', (int) $state['last_check'] ) . ' UTC' : __( 'Never', 'chatzio-ai' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Server message', 'chatzio-ai' ); ?></th>
					<td><?php echo esc_html( ! empty( $state['last_message'] ) ? (string) $state['last_message'] : '—' ); ?></td>
				</tr>
			</table>
		</div>

		<div class="chatzio-card">
			<h2><?php esc_html_e( 'Manage license', 'chatzio-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Paste your purchase key and click Activate. You can re-check status or deactivate any time.', 'chatzio-ai' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="chatzio-license-form chatzio-license-action-form">
				<?php wp_nonce_field( 'chatzio_license_activate' ); ?>
				<input type="hidden" name="action" value="chatzio_license_activate" />
				<label class="screen-reader-text" for="chatzio_license_key_input"><?php esc_html_e( 'License key', 'chatzio-ai' ); ?></label>
				<input id="chatzio_license_key_input" type="text" class="large-text code" name="license_key" value="<?php echo esc_attr( $form_key ); ?>" placeholder="PCL-XXXX-XXXX-XXXX" autocomplete="off" />
				<p>
					<button type="submit" class="button button-primary button-large" data-busy-label="<?php esc_attr_e( 'Activating...', 'chatzio-ai' ); ?>">
						<?php esc_html_e( 'Activate license', 'chatzio-ai' ); ?>
					</button>
				</p>
			</form>

			<div class="chatzio-license-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="chatzio-license-action-form">
					<?php wp_nonce_field( 'chatzio_license_validate' ); ?>
					<input type="hidden" name="action" value="chatzio_license_validate" />
					<button type="submit" class="button" data-busy-label="<?php esc_attr_e( 'Checking...', 'chatzio-ai' ); ?>">
						<?php esc_html_e( 'Re-check now', 'chatzio-ai' ); ?>
					</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="chatzio-license-action-form" onsubmit="return confirm('<?php echo esc_js( __( 'Deactivate this license on this site and clear the stored key?', 'chatzio-ai' ) ); ?>');">
					<?php wp_nonce_field( 'chatzio_license_deactivate' ); ?>
					<input type="hidden" name="action" value="chatzio_license_deactivate" />
					<button type="submit" class="button button-link-delete" data-busy-label="<?php esc_attr_e( 'Deactivating...', 'chatzio-ai' ); ?>">
						<?php esc_html_e( 'Deactivate on this site', 'chatzio-ai' ); ?>
					</button>
				</form>
			</div>
		</div>

		<?php if ( $show_diagnostics ) : ?>
		<div class="chatzio-card">
			<h2><?php esc_html_e( 'Diagnostics', 'chatzio-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Visible only because WP_DEBUG is enabled.', 'chatzio-ai' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="chatzio-license-action-form">
				<?php wp_nonce_field( 'chatzio_license_selftest' ); ?>
				<input type="hidden" name="action" value="chatzio_license_selftest" />
				<button type="submit" class="button" data-busy-label="<?php esc_attr_e( 'Running...', 'chatzio-ai' ); ?>"><?php esc_html_e( 'Run self-test', 'chatzio-ai' ); ?></button>
			</form>
			<?php if ( $selftest && is_array( $test_rows ) && ! empty( $test_rows ) ) : ?>
				<table class="widefat striped" style="margin-top:12px;">
					<thead><tr><th><?php esc_html_e( 'Check', 'chatzio-ai' ); ?></th><th><?php esc_html_e( 'Result', 'chatzio-ai' ); ?></th><th><?php esc_html_e( 'Detail', 'chatzio-ai' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $test_rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo ! empty( $row['ok'] ) ? '<span class="pcl-pill ok">OK</span>' : '<span class="pcl-pill bad">FAIL</span>'; ?></td>
								<td><code><?php echo esc_html( (string) $row['detail'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
</div>

<style>
.chatzio-license-wrap .chatzio-license-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(360px,1fr)); gap:18px; margin-top:18px; }
.chatzio-license-wrap .chatzio-card { background:#fff; border:1px solid #d0d7de; border-radius:10px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.chatzio-license-wrap .chatzio-card h2 { margin:0 0 10px; }
.chatzio-license-wrap .chatzio-inline-warning { margin:14px 0 4px; padding:12px 14px; border-radius:8px; border:1px solid #f0c3c3; background:#fff4f4; color:#7e1f1f; }
.chatzio-license-wrap .chatzio-status-line { display:flex; align-items:center; gap:10px; margin:4px 0 12px; }
.chatzio-license-wrap .chatzio-status-note { color:#50575e; font-size:13px; }
.chatzio-license-wrap .chatzio-license-meta { width:100%; border-collapse:collapse; }
.chatzio-license-wrap .chatzio-license-meta th { text-align:left; width:38%; padding:6px 8px 6px 0; color:#50575e; font-weight:600; vertical-align:top; }
.chatzio-license-wrap .chatzio-license-meta td { padding:6px 0; word-break:break-word; }
.chatzio-license-wrap .chatzio-license-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
.chatzio-license-wrap .pcl-pill { display:inline-block; padding:2px 10px; border-radius:999px; font-weight:600; font-size:12px; line-height:18px; text-transform:uppercase; letter-spacing:.4px; }
.chatzio-license-wrap .pcl-pill.ok { background:#d6f5db; color:#1d7a36; }
.chatzio-license-wrap .pcl-pill.bad { background:#fde2e2; color:#a32424; }

.chatzio-license-toast {
	position:fixed;
	top:64px;
	right:20px;
	z-index:10002;
	display:flex;
	align-items:center;
	gap:8px;
	max-width:520px;
	padding:12px 14px;
	border-radius:10px;
	box-shadow:0 8px 24px rgba(0,0,0,.18);
	font-weight:500;
}
.chatzio-license-toast.ok { background:#e9f8ec; border:1px solid #b4e4be; color:#185f2b; }
.chatzio-license-toast.error { background:#fff0f0; border:1px solid #f3b4b4; color:#7c1f1f; }
.chatzio-license-toast .dashicons { font-size:18px; width:18px; height:18px; }
.chatzio-license-toast .chatzio-toast-close {
	margin-left:auto;
	border:0;
	background:transparent;
	color:inherit;
	cursor:pointer;
	font-size:18px;
	line-height:1;
	padding:0 4px;
}

@media (max-width: 782px) {
	.chatzio-license-wrap .chatzio-license-grid { grid-template-columns:1fr; }
	.chatzio-license-toast { left:12px; right:12px; top:52px; max-width:none; }
}
</style>

<script>
(function(){
	var toast = document.getElementById('chatzio-license-toast');
	if (toast) {
		var closeBtn = toast.querySelector('.chatzio-toast-close');
		if (closeBtn) {
			closeBtn.addEventListener('click', function(){ toast.remove(); });
		}
		setTimeout(function(){ if (toast && toast.parentNode) { toast.remove(); } }, 7000);
	}

	var forms = document.querySelectorAll('.chatzio-license-action-form');
	forms.forEach(function(form){
		form.addEventListener('submit', function(){
			var btn = form.querySelector('button[type="submit"]');
			if (!btn) { return; }
			if (btn.dataset.busyLabel) {
				btn.dataset.originalLabel = btn.textContent;
				btn.textContent = btn.dataset.busyLabel;
			}
			btn.disabled = true;
		});
	});
})();
</script>
