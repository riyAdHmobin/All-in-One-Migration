<?php
	?>
<?php if ( is_readable( AI1WM_STORAGE_PATH ) && is_writable( AI1WM_STORAGE_PATH ) ) : ?>
<style>
	.file-input-button {
	display: inline-block;
	padding: 10px 20px;
	font-size: 16px;
	cursor: pointer;
	background-color: #007bff;
	color: #fff;
	border: none;
	border-radius: 5px;
	}
	.file-input-button input[type="file"] {
	display: none;
	}
</style>
<div class="ai1wm-import-messages"></div>
<div class="ai1wm-import-form">
	<div class="hide-if-no-js">
		<div class="ai1wm-drag-drop-area" id="ai1wm-drag-drop-area">
			<div id="ai1wm-import-init">
				<p>
					<i class="ai1wm-icon-cloud-upload"></i><br />
					<?php _e( 'Drag & Drop to upload', AI1WM_PLUGIN_NAME ); ?>
				</p>
				<div class="ai1wm-button-import">
					<div class="" id="ai1wm-import-file">
						<!--<div class="ai1wm-button-main" id="ai1wm-import-file">-->
						<!--<span><?php _e( 'Import From', AI1WM_PLUGIN_NAME ); ?></span>-->
						<!--<span class="ai1mw-lines">-->
						<!--    <span class="ai1wm-line ai1wm-line-first"></span>-->
						<!--    <span class="ai1wm-line ai1wm-line-second"></span>-->
						<!--    <span class="ai1wm-line ai1wm-line-third"></span>-->
						<!--</span>-->
						<label for="ai1wm-select-file" class="file-input-button">
						<span>Upload File</span>
						<input type="file" id="ai1wm-select-file" style="display: none;" />
						</label>
						<!--<label for="ai1wm-select-file" class="file-input-button">Upload File</label>-->
						<!--<input type="file" id="ai1wm-select-file" style="display: none;" />-->
					</div>
				</div>
				<!--<div class="ai1wm-button-group ai1wm-button-import ai1wm-expandable">-->
				<!--	<div class="ai1wm-button-main">-->
				<!--		<span><?php _e( 'Import From', AI1WM_PLUGIN_NAME ); ?></span>-->
				<!--		<span class="ai1mw-lines">-->
				<!--			<span class="ai1wm-line ai1wm-line-first"></span>-->
				<!--			<span class="ai1wm-line ai1wm-line-second"></span>-->
				<!--			<span class="ai1wm-line ai1wm-line-third"></span>-->
				<!--		</span>-->
				<!--	</div>-->
				<!--	<ul class="ai1wm-dropdown-menu ai1wm-import-providers">-->
				<!--		<?php foreach ( apply_filters( 'ai1wm_import_buttons', array() ) as $button ) : ?>-->
				<!--			<li>-->
				<!--				<?php echo $button; ?>-->
				<!--			</li>-->
				<!--		<?php endforeach; ?>-->
				<!--	</ul>-->
				<!--</div>-->
			</div>
		</div>
	</div>
</div>
<?php else : ?>
<div class="ai1wm-message ai1wm-red-message">
	<?php
		printf(
			__(
				'<h3>Site could not be imported!</h3>' .
				'<p>Please make sure that storage directory <strong>%s</strong> has read and write permissions.</p>',
				AI1WM_PLUGIN_NAME
			),
			AI1WM_STORAGE_PATH
		);
		?>
</div>
<?php endif; ?>