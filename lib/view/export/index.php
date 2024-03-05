<?php
    

?>

<div class="ai1wm-container">
	<!--<div class="ai1wm-row">-->
	<div>
		<div class="ai1wm-left">
			<div class="ai1wm-holder">
				<h1>
					<!--<i class="ai1wm-icon-export"></i>-->
					<?php _e( 'Export', AI1WM_PLUGIN_NAME ); ?>
				</h1>

				<!--<?php include AI1WM_TEMPLATES_PATH . '/common/report-problem.php'; ?>-->

				<form action="" method="post" id="ai1wm-export-form" class="ai1wm-clear">

					<!--<?php include AI1WM_TEMPLATES_PATH . '/export/find-replace.php'; ?>-->

					<?php do_action( 'ai1wm_export_left_options' ); ?>

					<!--<?php include AI1WM_TEMPLATES_PATH . '/export/advanced-settings.php'; ?>-->

					<?php include AI1WM_TEMPLATES_PATH . '/export/export-buttons.php'; ?>

					<input type="hidden" name="ai1wm_manual_export" value="1" />

				</form>

				<?php do_action( 'ai1wm_export_left_end' ); ?>

			</div>
		</div>
	</div>
</div>

