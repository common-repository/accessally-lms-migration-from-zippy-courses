<div class="wrap">
<h2 style="display:none;"><?php _e('AccessAlly Zippy Course Conversion'); ?></h2>

<div id="accessally-zippy-course-convert-wait-overlay">
	<div class="accessally-zippy-course-convert-wait-content">
		<img src="<?php echo AccessAlly_ZippyCourseConversion::$PLUGIN_URI; ?>backend/wait.gif" alt="wait" width="128" height="128" />
	</div>
</div>
<div class="accessally-setting-container">
	<div class="accessally-setting-title">AccessAlly - Zippy Courses Custom Post Conversion</div>
	<div class="accessally-setting-section">
		<div class="accessally-setting-message-container">
			<p>Use this tool to convert Courses, Units and Lessons created in Zippy Courses to regular WordPress pages, so they can be re-used after Zippy Courses has been deactivated.</p>
			<ol>
				<li>Conversion does not modify the content of the courses, units, or lessons.</li>
				<li>The conversion process can be reverted, so Zippy courses, units, and lessons can be restored.</li>
				<li>Courses can automatically be converted to AccessAlly Course Wizard courses. <strong>Important:</strong> These are created as <strong>Drafts</strong> and they need to be further customized before they are published.</li>
			</ol>
		</div>
	</div>
	<?php echo $operation_code; ?>
</div>
</div>