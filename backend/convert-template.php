<tr class="accessally-zippy-list-existing-row">
	<td class="accessally-zippy-course-id-col">{{id}}</td>
	<td class="accessally-zippy-course-title-col">
		<a target="_blank" href="{{edit-link}}">{{name}}</a>
	</td>
	<td class="accessally-zippy-course-detail-col">{{details}}</td>
	<td class="accessally-zippy-course-convert-col">
		<select id="accessally-zippy-operation-{{id}}" data-dependency-source="accessally-zippy-operation-{{id}}">
			<option value="no">Do not convert</option>
			{{stage-release-option}}
			<option value="alone">Convert to a Standalone course</option>
			<option value="wp">Convert to regular WordPress pages (Advanced)</option>
		</select>
		<div style="display:none" hide-toggle data-dependency="accessally-zippy-operation-{{id}}" data-dependency-value-not="no"
			 accessally-convert-course="{{id}}"
			 class="accessally-setting-convert-button">
			Convert
		</div>
	</td>
</tr>