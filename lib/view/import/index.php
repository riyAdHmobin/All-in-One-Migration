<?php

?>

<div class="ai1wm-container">
	<!--<div class="ai1wm-row">-->
	<div>
		<div class="ai1wm-left">
			<div class="ai1wm-holder">
				<h1>
					<!--<i class="ai1wm-icon-publish"></i>-->
					<?php _e( 'Import', AI1WM_PLUGIN_NAME ); ?>
				</h1>

				<!--<?php include AI1WM_TEMPLATES_PATH . '/common/report-problem.php'; ?>-->

				<form action="" method="post" id="ai1wm-import-form" class="ai1wm-clear" enctype="multipart/form-data">

					<!--<p>-->
					<!--	<?php _e( 'Use the box below to upload a wpress file.', AI1WM_PLUGIN_NAME ); ?><br />-->
					<!--</p>-->

					<?php do_action( 'ai1wm_import_left_options' ); ?>

					<?php include AI1WM_TEMPLATES_PATH . '/import/import-buttons.php'; ?>

					<input type="hidden" name="ai1wm_manual_import" value="1" />

				</form>

				<?php do_action( 'ai1wm_import_left_end' ); ?>

			</div>
		</div>
	</div>
</div>
