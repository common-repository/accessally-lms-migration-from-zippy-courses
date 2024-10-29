<div id="accessally-zippy-course-convert-container">
	<div class="accessally-setting-section">
		<div class="accessally-setting-header">Existing Zippy Courses</div>
		<div class="accessally-zippy-list-existing-container">
			<table class="accessally-zippy-list-existing">
				<tr>
					<th class="accessally-zippy-course-id-col">ID</th>
					<th class="accessally-zippy-course-title-col">Course name</th>
					<th class="accessally-zippy-course-detail-col">Details</th>
					<th class="accessally-zippy-course-convert-col">Conversion option</th>
				</tr>
				{{zippy-courses}}
			</table>
		</div>
	</div>
	<div class="accessally-setting-section" {{show-existing}}>
		<div class="accessally-setting-header">Converted courses</div>
		<div class="accessally-zippy-list-existing-container">
			<table class="accessally-zippy-list-existing">
				<tbody>
					<tr>
						<th class="accessally-zippy-list-existing-name-col">Name</th>
						<th class="accessally-zippy-list-existing-edit-col">Edit</th>
						<th class="accessally-zippy-list-existing-revert-col">Revert</th>
					</tr>
					{{existing-courses}}
				</tbody>
			</table>
		</div>
	</div>
</div>