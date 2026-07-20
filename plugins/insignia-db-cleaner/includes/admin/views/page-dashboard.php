<?php
/**
 * View: main plugin screen — "Rustic Charm" redesign.
 *
 * Layout: fixed left sidebar (brand + vertical nav + rescan) with the
 * content area on the right. Server-renders the first paint, then
 * assets/js/insignia-admin.js takes over for tab switching, scanning,
 * cleaning and saving settings via AJAX.
 *
 * IMPORTANT: every ID / class the JS relies on is unchanged
 * (insignia-dbc-* ids, .insignia-tab, data-insignia-tab, .insignia-panel,
 * data-insignia-panel, [data-insignia-group], .insignia-card__status,
 * .insignia-meta-item, .insignia-seg, row cells, etc).
 *
 * @package Insignia_DB_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$scan_service = new Insignia_DBC_Scan_Service();
$report       = $scan_service->scan_all();
$db_overview  = $scan_service->database_overview();
$settings     = Insignia_DBC_Settings::get();
$groups       = Insignia_DBC_Item_Registry::get_groups();
$tables       = Insignia_DBC_DB_Helper::get_tables();

// Pre-compute per-group totals for the dashboard cards.
$group_totals = array();
foreach ( $groups as $group_key => $group_label ) {
	$group_totals[ $group_key ] = array( 'count' => 0, 'size' => 0 );
}
foreach ( $report['items'] as $item ) {
	if ( isset( $group_totals[ $item['group'] ] ) ) {
		$group_totals[ $item['group'] ]['count'] += $item['count'];
		$group_totals[ $item['group'] ]['size']  += $item['size'];
	}
}
?>
<div id="insignia-dbc-root" data-insignia-theme="light">
<div class="insignia-shell">

	<!-- ============ SIDEBAR ============ -->
	<aside class="insignia-side">
		<span class="insignia-shape insignia-shape--side-ring"></span>
		<span class="insignia-shape insignia-shape--side-dot"></span>

		<div class="insignia-brand">
			<span class="insignia-brand__icon">
				<svg class="insignia-logo-mark" width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M12 2L4 5v6c0 5 3.4 8.7 8 10 4.6-1.3 8-5 8-10V5l-8-3z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" fill="none"/>
					<path d="M8.5 12.2l2.4 2.4 4.6-4.9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</span>
			<div class="insignia-brand__text">
				<h1 class="insignia-brand__title"><?php esc_html_e( 'Insignia', 'insignia-db-cleaner' ); ?></h1>
				<p class="insignia-brand__sub">
					<?php esc_html_e( 'DB Cleaner', 'insignia-db-cleaner' ); ?>
					<span class="insignia-brand__ver">v<?php echo esc_html( INSIGNIA_DBC_VERSION ); ?></span>
				</p>
			</div>
		</div>

		<nav class="insignia-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Plugin sections', 'insignia-db-cleaner' ); ?>">
			<button type="button" class="insignia-tab is-active" data-insignia-tab="dashboard" role="tab">
				<span class="insignia-tab__icon dashicons dashicons-dashboard"></span>
				<span class="insignia-tab__label"><?php esc_html_e( 'Dashboard', 'insignia-db-cleaner' ); ?></span>
			</button>
			<button type="button" class="insignia-tab" data-insignia-tab="cleanup" role="tab">
				<span class="insignia-tab__icon dashicons dashicons-trash"></span>
				<span class="insignia-tab__label"><?php esc_html_e( 'Cleanup', 'insignia-db-cleaner' ); ?></span>
				<span class="insignia-tab__count" id="insignia-dbc-cleanup-count"><?php echo esc_html( Insignia_DBC_Format::number( $report['totals']['count'] ) ); ?></span>
			</button>
			<button type="button" class="insignia-tab" data-insignia-tab="optimize" role="tab">
				<span class="insignia-tab__icon dashicons dashicons-performance"></span>
				<span class="insignia-tab__label"><?php esc_html_e( 'Optimize', 'insignia-db-cleaner' ); ?></span>
			</button>
			<button type="button" class="insignia-tab" data-insignia-tab="settings" role="tab">
				<span class="insignia-tab__icon dashicons dashicons-admin-generic"></span>
				<span class="insignia-tab__label"><?php esc_html_e( 'Settings', 'insignia-db-cleaner' ); ?></span>
			</button>
		</nav>

		<div class="insignia-side__foot">
			<button type="button" class="insignia-btn insignia-btn--ghost insignia-btn--block" id="insignia-dbc-rescan">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Rescan Database', 'insignia-db-cleaner' ); ?>
			</button>
			<p class="insignia-side__note"><?php esc_html_e( 'Back up before bulk deleting.', 'insignia-db-cleaner' ); ?></p>
		</div>
	</aside>

	<!-- ============ CONTENT ============ -->
	<main class="insignia-main">

		<header class="insignia-topbar">
			<span class="insignia-shape insignia-shape--arch"></span>
			<span class="insignia-shape insignia-shape--ring"></span>
			<span class="insignia-shape insignia-shape--grain"></span>
			<div class="insignia-topbar__text">
				<h2 class="insignia-topbar__title"><?php esc_html_e( 'Database Health', 'insignia-db-cleaner' ); ?></h2>
				<p class="insignia-topbar__sub"><?php esc_html_e( 'Keep your WordPress database lean, tidy and fast.', 'insignia-db-cleaner' ); ?></p>
			</div>
		</header>

		<!-- ============ DASHBOARD ============ -->
		<section class="insignia-panel" data-insignia-panel="dashboard">

			<div class="insignia-stats">
				<div class="insignia-stat insignia-stat--feature">
					<span class="insignia-shape insignia-shape--stat-blob"></span>
					<span class="insignia-stat__icon"><span class="dashicons dashicons-database"></span></span>
					<div class="insignia-stat__main">
						<span class="insignia-stat__value" id="insignia-dbc-stat-count"><?php echo esc_html( Insignia_DBC_Format::number( $report['totals']['count'] ) ); ?></span>
						<span class="insignia-stat__label"><?php esc_html_e( 'Items To Clean', 'insignia-db-cleaner' ); ?></span>
					</div>
				</div>
				<div class="insignia-stat">
					<span class="insignia-stat__icon"><span class="dashicons dashicons-archive"></span></span>
					<div class="insignia-stat__main">
						<span class="insignia-stat__value" id="insignia-dbc-stat-size"><?php echo esc_html( Insignia_DBC_Format::bytes( $report['totals']['size'] ) ); ?></span>
						<span class="insignia-stat__label"><?php esc_html_e( 'Reclaimable Space', 'insignia-db-cleaner' ); ?></span>
					</div>
				</div>
				<div class="insignia-stat">
					<span class="insignia-stat__icon"><span class="dashicons dashicons-editor-table"></span></span>
					<div class="insignia-stat__main">
						<span class="insignia-stat__value" id="insignia-dbc-stat-tables"><?php echo esc_html( Insignia_DBC_Format::number( $db_overview['table_count'] ) ); ?></span>
						<span class="insignia-stat__label"><?php esc_html_e( 'Database Tables', 'insignia-db-cleaner' ); ?></span>
					</div>
				</div>
				<div class="insignia-stat">
					<span class="insignia-stat__icon"><span class="dashicons dashicons-chart-bar"></span></span>
					<div class="insignia-stat__main">
						<span class="insignia-stat__value" id="insignia-dbc-stat-dbsize"><?php echo esc_html( $db_overview['total_size_human'] ); ?></span>
						<span class="insignia-stat__label"><?php esc_html_e( 'Total Database Size', 'insignia-db-cleaner' ); ?></span>
					</div>
				</div>
			</div>

			<div class="insignia-grid" id="insignia-dbc-group-cards">
				<?php
				foreach ( $groups as $group_key => $group_label ) :
					$totals  = $group_totals[ $group_key ];
					$initial = mb_strtoupper( mb_substr( $group_label, 0, 1 ) );
					?>
				<div class="insignia-card" data-insignia-group="<?php echo esc_attr( $group_key ); ?>">
					<span class="insignia-shape insignia-shape--card-corner"></span>
					<div class="insignia-card__head">
						<span class="insignia-card__avatar"><?php echo esc_html( $initial ); ?></span>
						<div class="insignia-card__head-text">
							<p class="insignia-card__name"><?php echo esc_html( $group_label ); ?></p>
							<span class="insignia-card__status <?php echo $totals['count'] > 0 ? 'is-on' : 'is-off'; ?>"><span class="insignia-card__status-dot"></span><?php
								echo $totals['count'] > 0
									? esc_html( sprintf( _n( '%s item found', '%s items found', $totals['count'], 'insignia-db-cleaner' ), Insignia_DBC_Format::number( $totals['count'] ) ) )
									: esc_html__( 'Clean', 'insignia-db-cleaner' );
							?></span>
						</div>
					</div>
					<div class="insignia-card__meta">
						<span class="insignia-meta-item"><span class="dashicons dashicons-archive"></span><?php echo esc_html( Insignia_DBC_Format::bytes( $totals['size'] ) ); ?></span>
					</div>
					<button type="button" class="insignia-card__btn insignia-dbc-clean-group" data-group="<?php echo esc_attr( $group_key ); ?>" <?php disabled( 0 === $totals['count'] ); ?>>
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Clean This Group', 'insignia-db-cleaner' ); ?>
					</button>
				</div>
				<?php endforeach; ?>
			</div>
		</section>

		<!-- ============ CLEANUP ============ -->
		<section class="insignia-panel" data-insignia-panel="cleanup" hidden>

			<div class="insignia-toolbar">
				<div class="insignia-toolbar__left">
					<span class="insignia-count-pill" id="insignia-dbc-cleanup-summary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: item count, 2: human size */
								__( '%1$s items · %2$s reclaimable', 'insignia-db-cleaner' ),
								Insignia_DBC_Format::number( $report['totals']['count'] ),
								$report['totals']['size_human'] ?? Insignia_DBC_Format::bytes( $report['totals']['size'] )
							)
						);
						?>
					</span>
				</div>
				<div class="insignia-toolbar__right">
					<div class="insignia-bulk-bar" id="insignia-dbc-bulk-bar" hidden>
						<span class="insignia-bulk-count"><span id="insignia-dbc-bulk-count">0</span> <?php esc_html_e( 'selected', 'insignia-db-cleaner' ); ?></span>
						<button type="button" class="insignia-btn insignia-btn--danger" id="insignia-dbc-clean-selected">
							<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clean Selected', 'insignia-db-cleaner' ); ?>
						</button>
					</div>
				</div>
			</div>

			<div class="insignia-table-wrap">
				<table class="insignia-table">
					<thead>
						<tr>
							<th class="insignia-col-check"><input type="checkbox" id="insignia-dbc-select-all"></th>
							<th><?php esc_html_e( 'Item', 'insignia-db-cleaner' ); ?></th>
							<th><?php esc_html_e( 'Group', 'insignia-db-cleaner' ); ?></th>
							<th><?php esc_html_e( 'Count', 'insignia-db-cleaner' ); ?></th>
							<th><?php esc_html_e( 'Size', 'insignia-db-cleaner' ); ?></th>
							<th class="insignia-col-actions"><?php esc_html_e( 'Action', 'insignia-db-cleaner' ); ?></th>
						</tr>
					</thead>
					<tbody id="insignia-dbc-cleanup-rows">
						<?php foreach ( $report['items'] as $item ) : ?>
						<tr data-item="<?php echo esc_attr( $item['key'] ); ?>" class="<?php echo $item['count'] > 0 ? 'is-active' : ''; ?>">
							<td class="insignia-col-check">
								<input type="checkbox" class="insignia-dbc-row-check" value="<?php echo esc_attr( $item['key'] ); ?>" <?php disabled( 0 === $item['count'] ); ?>>
							</td>
							<td class="insignia-col-name">
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<span class="insignia-col-desc"><?php echo esc_html( $item['description'] ); ?></span>
							</td>
							<td><span class="insignia-group-chip"><?php echo esc_html( isset( $groups[ $item['group'] ] ) ? $groups[ $item['group'] ] : $item['group'] ); ?></span></td>
							<td class="insignia-dbc-cell-count"><?php echo esc_html( Insignia_DBC_Format::number( $item['count'] ) ); ?></td>
							<td class="insignia-dbc-cell-size"><?php echo esc_html( $item['size_human'] ); ?></td>
							<td class="insignia-col-actions">
								<button type="button" class="insignia-row-btn insignia-row-btn--primary insignia-dbc-clean-item" data-item="<?php echo esc_attr( $item['key'] ); ?>" <?php disabled( 0 === $item['count'] ); ?>>
									<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clean', 'insignia-db-cleaner' ); ?>
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>

		<!-- ============ OPTIMIZE ============ -->
		<section class="insignia-panel" data-insignia-panel="optimize" hidden>

			<div class="insignia-toolbar">
				<div class="insignia-toolbar__left">
					<span class="insignia-count-pill">
						<?php echo esc_html( sprintf( __( '%1$s tables · %2$s reclaimable overhead', 'insignia-db-cleaner' ), Insignia_DBC_Format::number( $db_overview['table_count'] ), $db_overview['overhead_human'] ) ); ?>
					</span>
				</div>
				<div class="insignia-toolbar__right">
					<button type="button" class="insignia-btn insignia-btn--primary" id="insignia-dbc-optimize-all">
						<span class="dashicons dashicons-performance"></span> <?php esc_html_e( 'Optimize All Tables', 'insignia-db-cleaner' ); ?>
					</button>
				</div>
			</div>

			<div class="insignia-table-wrap">
				<table class="insignia-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Table', 'insignia-db-cleaner' ); ?></th>
							<th><?php esc_html_e( 'Engine', 'insignia-db-cleaner' ); ?></th>
							<th><?php esc_html_e( 'Rows', 'insignia-db-cleaner' ); ?></th>
							<th><?php esc_html_e( 'Size', 'insignia-db-cleaner' ); ?></th>
							<th><?php esc_html_e( 'Overhead', 'insignia-db-cleaner' ); ?></th>
							<th class="insignia-col-actions"><?php esc_html_e( 'Action', 'insignia-db-cleaner' ); ?></th>
						</tr>
					</thead>
					<tbody id="insignia-dbc-optimize-rows">
						<?php foreach ( $tables as $table ) : ?>
						<tr data-table="<?php echo esc_attr( $table['name'] ); ?>">
							<td><code><?php echo esc_html( $table['name'] ); ?></code></td>
							<td><?php echo esc_html( $table['engine'] ); ?></td>
							<td><?php echo esc_html( Insignia_DBC_Format::number( $table['rows'] ) ); ?></td>
							<td><?php echo esc_html( Insignia_DBC_Format::bytes( $table['data_size'] ) ); ?></td>
							<td><?php echo esc_html( Insignia_DBC_Format::bytes( $table['overhead'] ) ); ?></td>
							<td class="insignia-col-actions">
								<button type="button" class="insignia-row-btn insignia-dbc-optimize-item" data-table="<?php echo esc_attr( $table['name'] ); ?>">
									<span class="dashicons dashicons-performance"></span> <?php esc_html_e( 'Optimize', 'insignia-db-cleaner' ); ?>
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>

		<!-- ============ SETTINGS ============ -->
		<section class="insignia-panel" data-insignia-panel="settings" hidden>

			<div class="insignia-settings-card">
				<form id="insignia-dbc-settings-form">

					<div class="insignia-setting-row">
						<div class="insignia-setting-row__label">
							<strong><?php esc_html_e( 'Only clean items older than', 'insignia-db-cleaner' ); ?></strong>
							<span><?php esc_html_e( 'Set to 0 to ignore age and clean everything that matches.', 'insignia-db-cleaner' ); ?></span>
						</div>
						<div class="insignia-setting-row__control">
							<input type="number" min="0" step="1" name="keep_days" id="insignia-dbc-keep-days" class="insignia-input insignia-input--num"
								value="<?php echo esc_attr( $settings['keep_days'] ); ?>">
							<?php esc_html_e( 'days', 'insignia-db-cleaner' ); ?>
						</div>
					</div>

					<div class="insignia-setting-row">
						<div class="insignia-setting-row__label">
							<strong><?php esc_html_e( 'Automatic Cleanup', 'insignia-db-cleaner' ); ?></strong>
							<span><?php esc_html_e( 'Run the selected cleanups on a schedule via WP-Cron.', 'insignia-db-cleaner' ); ?></span>
						</div>
						<label class="insignia-switch">
							<input type="checkbox" name="automation_enabled" id="insignia-dbc-automation-enabled" <?php checked( ! empty( $settings['automation_enabled'] ) ); ?>>
							<span class="insignia-switch__track"><span class="insignia-switch__thumb"></span></span>
						</label>
					</div>

					<div class="insignia-setting-row">
						<div class="insignia-setting-row__label">
							<strong><?php esc_html_e( 'Frequency', 'insignia-db-cleaner' ); ?></strong>
						</div>
						<div class="insignia-segmented" id="insignia-dbc-automation-freq" data-value="<?php echo esc_attr( $settings['automation_freq'] ); ?>">
							<button type="button" class="insignia-seg <?php echo 'daily' === $settings['automation_freq'] ? 'is-active' : ''; ?>" data-value="daily"><?php esc_html_e( 'Daily', 'insignia-db-cleaner' ); ?></button>
							<button type="button" class="insignia-seg <?php echo 'weekly' === $settings['automation_freq'] ? 'is-active' : ''; ?>" data-value="weekly"><?php esc_html_e( 'Weekly', 'insignia-db-cleaner' ); ?></button>
						</div>
					</div>

					<div class="insignia-setting-row insignia-setting-row--top">
						<div class="insignia-setting-row__label">
							<strong><?php esc_html_e( 'Items To Automate', 'insignia-db-cleaner' ); ?></strong>
							<span><?php esc_html_e( 'Only checked items are touched by the scheduled cleanup.', 'insignia-db-cleaner' ); ?></span>
						</div>
						<div class="insignia-chip-cloud">
							<?php foreach ( Insignia_DBC_Item_Registry::get_items() as $item_key => $item_meta ) : ?>
								<label class="insignia-chip">
									<input type="checkbox" name="automation_items[]" value="<?php echo esc_attr( $item_key ); ?>"
										<?php checked( in_array( $item_key, (array) $settings['automation_items'], true ) ); ?>>
									<?php echo esc_html( $item_meta['label'] ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="insignia-setting-row--info">
						<span class="insignia-info-icon">i</span>
						<span>
							<?php
							if ( ! empty( $settings['last_automation_run'] ) ) {
								echo esc_html(
									sprintf(
										/* translators: %s: date/time */
										__( 'Last automatic run: %s', 'insignia-db-cleaner' ),
										mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $settings['last_automation_run'] )
									)
								);
							} else {
								esc_html_e( 'Automatic cleanup has not run yet.', 'insignia-db-cleaner' );
							}
							?>
						</span>
					</div>

					<div class="insignia-settings-card__footer">
						<button type="submit" class="insignia-btn insignia-btn--primary" id="insignia-dbc-save-settings">
							<span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Save Settings', 'insignia-db-cleaner' ); ?>
						</button>
					</div>
				</form>
			</div>
		</section>

		<div class="insignia-footer-bar">
			<span class="insignia-footer-bar__info">
				<?php echo esc_html( sprintf( __( 'Insignia DB Cleaner %s — always back up your database before bulk deleting.', 'insignia-db-cleaner' ), INSIGNIA_DBC_VERSION ) ); ?>
			</span>
		</div>

	</main>
</div>

<div class="insignia-toasts" id="insignia-dbc-toasts" aria-live="polite"></div>
</div>
