/* global accessally_zippy_course_convert_object */

jQuery(document).ready(function ($) {
	// <editor-fold defaultstate="collapsed" desc="data-dependency logic">
	function evaluate_dependency(collection, value, match_function, mismatch_function) {
		for (var index = 0; index < collection.length; ++index) {
			var $elem = $(collection[index]),
				dependency_value = $elem.attr('data-dependency-value'),
				dependency_value_not = $elem.attr('data-dependency-value-not'),
				key, matched = false;
			if (typeof dependency_value !== typeof undefined && dependency_value !== false) {
				dependency_value = dependency_value.split('|');
				matched = false;
				for (key in dependency_value) {
					if (dependency_value[key] === value) {
						matched = true;
						break;
					}
				}
				if (matched) {
					match_function($elem);
				} else {
					mismatch_function($elem);
				}
			}
			if (typeof dependency_value_not !== typeof undefined && dependency_value_not !== false) {
				if (dependency_value_not !== value) {
					match_function($elem);
				} else {
					mismatch_function($elem);
				}
			}
		}
	}
	$(document).on('change', '[data-dependency-source]', function() {
		var $element = $(this),
			value = 'no',
			dependency_name = $element.attr('data-dependency-source'),
			dependencies = $('[data-dependency="' + dependency_name + '"]');
		if($element.attr('type') === 'checkbox') {
			if ($element.is(':checked')){
				value = 'yes';
			}
		} else {
			value = $element.val();
		}

		if (value){
			value = value.replace(/\"/g, '\\"');
		}
		evaluate_dependency(dependencies.filter('[hide-toggle]'), value, function(elem) { elem.show(); }, function(elem) { elem.hide(); });
		evaluate_dependency(dependencies.filter('[readonly-toggle]'), value, function(elem) { elem.prop('readonly', false); }, function(elem) { elem.prop('readonly', true); });
		evaluate_dependency(dependencies.filter('[disable-toggle]'), value, function(elem) { elem.prop('disabled', false); }, function(elem) { elem.prop('disabled', true); });
	});
	// </editor-fold>

	// <editor-fold defaultstate="collapsed" desc="convert operation">
	var $wait_overlay = $('#accessally-zippy-course-convert-wait-overlay');
	$(document).on('click touchend', '[accessally-convert-course]', function() {
		var conf = confirm('Do you want to convert all items in this Zippy Course to regular WordPress pages?')
		if(conf !== true){
			return false;
		}
		$wait_overlay.show();
		var course_id = $(this).attr('accessally-convert-course'),
			conversion_operation = $('#accessally-zippy-operation-' + course_id).val(),
			data = {
				action: 'accessally_zippy_course_convert',
				id: course_id,
				op: conversion_operation,
				nonce: accessally_zippy_course_convert_object.nonce
			};

		$.ajax({
			type: "POST",
			url: accessally_zippy_course_convert_object.ajax_url,
			data: data,
			success: function(response) {
				try {
					process_convert_result(response);
				} catch (e) {
					alert('Conversion failed due to error: ' + e);
				} finally {
					$wait_overlay.hide();
				}
			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert('Conversion failed due to error: ' + thrownError);
				$wait_overlay.hide();
			}
		});
	});
	function process_convert_result(response) {
		var result = JSON.parse(response);
		if ('status' in result) {
			if (result['status'] === 'error') {
				throw result['message'];
			}
			var $display_elem = $('#accessally-zippy-course-convert-container');
			$display_elem.after(result['code']);
			$display_elem.remove();
			alert(result['message']);
		} else {
			throw 'Invalid response. Please refresh the page and try again.';
		}
	}
	// </editor-fold>
	// <editor-fold defaultstate="collapsed" desc="revert operation">
	$(document).on('click touchend', '[accessally-revert-course]', function() {
		var conf = confirm('Do you want to change previous converted pages back to a Zippy Course?'),
			course_id = $(this).attr('accessally-revert-course');
		if(conf !== true){
			return false;
		}
		$wait_overlay.show();
		var data = {
				action: 'accessally_zippy_course_revert',
				id: course_id,
				nonce: accessally_zippy_course_convert_object.nonce
			};

		$.ajax({
			type: "POST",
			url: accessally_zippy_course_convert_object.ajax_url,
			data: data,
			success: function(response) {
				try {
					process_convert_result(response);
				} catch (e) {
					alert('Revert failed due to error: ' + e);
				} finally {
					$wait_overlay.hide();
				}
			},
			error: function(xhr, ajaxOptions, thrownError) {
				alert('Revert failed due to error: ' + thrownError);
				$wait_overlay.hide();
			}
		});
	});
	// </editor-fold>
});