<?php
/*
 Plugin Name: AccessAlly™ LMS Migration from Zippy Courses®
 Plugin URI: https://accessally.com/
 Description: This AccessAlly™ LMS Migration from Zippy Courses® plugin will convert your existing Zippy Courses courses into AccessAlly courses, so you don't lose your content when you disable Zippy Courses.
 Author: AccessAlly
 Author URI: https://accessally.com/about/
 Contributors: rli,accessally
 Tags: lms, lms migration, Zippy Courses migration, accessally migration, export Zippy Courses, migrate lms, switch lms, export lms, import lms, access ally, accessally, learn dash
 Tested up to: 5.2.3
 Requires at least: 4.7.0
 Requires PHP: 5.6
 Version: 1.0.1
 Stable tag: 1.0.1
 License: Artistic License 2.0
 */

if (!class_exists('AccessAlly_ZippyCourseConversion')) {
	class AccessAlly_ZippyCourseConversion {
		/// CONSTANTS
		const VERSION = '1.0.1';
		const SETTING_KEY = '_accessally_zippy_course_conversion';
		const HELP_URL = 'https://access.ambitionally.com/accessally/';
		private static $PLUGIN_URI = '';

		public static function init() {
			self::$PLUGIN_URI = plugin_dir_url(__FILE__);
			if (is_admin()) {
				add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_administrative_resources'));
				add_action('admin_menu', array(__CLASS__, 'add_menu_pages'));
			}
			add_action('wp_ajax_accessally_zippy_course_convert', array(__CLASS__, 'convert_callback'));
			add_action('wp_ajax_accessally_zippy_course_revert', array(__CLASS__, 'revert_callback'));

			register_activation_hook(__FILE__, array(__CLASS__, 'do_activation_actions'));
			register_deactivation_hook(__FILE__, array(__CLASS__, 'do_deactivation_actions'));
		}
		public static function do_activation_actions() {
			delete_transient(self::SETTING_KEY);
			wp_cache_flush();
		}
		public static function do_deactivation_actions() {
			delete_transient(self::SETTING_KEY);
			wp_cache_flush();
		}
		public static function enqueue_administrative_resources($hook){
			if (strpos($hook, self::SETTING_KEY) !== false) {
				wp_enqueue_style('accessally-zippy-course-convert-backend', self::$PLUGIN_URI . 'backend/settings.css', false, self::VERSION);
				wp_enqueue_script('accessally-zippy-course-convert-backend', self::$PLUGIN_URI . 'backend/settings.js', array('jquery'), self::VERSION);

				// do not include the http or https protocol in the ajax url
				$admin_url = preg_replace("/^http:/i", "", admin_url('admin-ajax.php'));
				$admin_url = preg_replace("/^https:/i", "", $admin_url);

				wp_localize_script('accessally-zippy-course-convert-backend', 'accessally_zippy_course_convert_object',
					array('ajax_url' => $admin_url,
						'nonce' => wp_create_nonce('accessally-zippy-course-convert')
						));
			}
		}
		public static function add_menu_pages() {
			// Add the top-level admin menu
			$capability = 'manage_options';
			$menu_slug = self::SETTING_KEY;
			$results = add_menu_page('AccessAlly Zippy Course Conversion', 'AccessAlly Zippy Course Conversion', $capability, $menu_slug, array(__CLASS__, 'show_settings'), self::$PLUGIN_URI . 'backend/icon.png');
		}
		public static function show_settings() {
			if (!current_user_can('manage_options')) {
				wp_die('You do not have sufficient permissions to access this page.');
			}
			if (!self::is_accessally_active()) {
				wp_die('AccessAlly is not activated or outdated. Please install the latest version of AccessAlly before using the conversion tool.');
			}
			$operation_code = self::generate_setting_display();
			include (dirname(__FILE__) . '/backend/settings-display.php');
		}

		// <editor-fold defaultstate="collapsed" desc="utility function for checking AccessAlly dependencies">
		private static function is_accessally_active() {
			if (!class_exists('AccessAlly') || !class_exists('AccessAllySettingLicense') || !AccessAllySettingLicense::$accessally_enabled ||
				!class_exists('AccessAllyWizardProduct') || !method_exists('AccessAllyWizardProduct', 'merge_default_settings') ||
				!class_exists('AccessAllyWizardDrip') || !method_exists('AccessAllyWizardDrip', 'merge_default_settings')) {
				return false;
			}
			return true;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="retrieve database info">
		const ZIPPY_COURSE_SLUG = 'course';
		const ZIPPY_UNIT_SLUG = 'unit';
		const ZIPPY_LESSON_SLUG = 'lesson';
		private static $ZIPPY_CUSTOM_POST_TYPES = array(self::ZIPPY_COURSE_SLUG, self::ZIPPY_UNIT_SLUG, self::ZIPPY_LESSON_SLUG);
		private static $default_settings = array('wizard' => array());
		private static function parse_zippy_course_data($entry_value, $unit_mapping) {
			$course_entry = json_decode($entry_value);
			$course_children = array();
			foreach ($course_entry as $entry) {
				if (property_exists($entry, 'ID')) {
					$course_children []= $entry->ID;
					if (property_exists($entry, 'post_type') && self::ZIPPY_UNIT_SLUG === $entry->post_type) {
						$unit_children = array();
						if (property_exists($entry, 'entries')) {
							foreach ($entry->entries as $unit_entry) {
								if (property_exists($unit_entry, 'ID')) {
									$unit_children []= $unit_entry->ID;
								}
							}
						}
						$unit_mapping[$entry->ID] = $unit_children;
					}
				}
			}
			return array('children' => $course_children, 'unit_mapping' => $unit_mapping);
		}
		private static function get_zippy_custom_posts() {
			global $wpdb;
			$post_meta_rows = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta WHERE meta_key = 'entries'", OBJECT_K);
			$post_meta = array();
			foreach ($post_meta_rows as $row) {
				if (!isset($post_meta[$row->post_id])) {
					$post_meta[$row->post_id] = array();
				}
				$post_meta[$row->post_id][$row->meta_key] = $row->meta_value;
			}
			$posts = $wpdb->get_results("SELECT ID, post_type, post_title FROM $wpdb->posts WHERE post_type in ('" . implode("','", self::$ZIPPY_CUSTOM_POST_TYPES) . "')", OBJECT_K);
			$courses = array();
			$units = array();
			$lessons = array();
			$unit_mapping = array();
			foreach ($posts as $post) {
				if (self::ZIPPY_COURSE_SLUG === $post->post_type) {
					$course_details = array('raw' => $post, 'children' => array());
					if (isset($post_meta[$post->ID]) && isset($post_meta[$post->ID]['entries'])) {
						$course_info = self::parse_zippy_course_data($post_meta[$post->ID]['entries'], $unit_mapping);
						$course_details['children'] = $course_info['children'];
						$unit_mapping = $course_info['unit_mapping'];
					}
					$courses[$post->ID] = $course_details;
				} elseif (self::ZIPPY_UNIT_SLUG === $post->post_type) {
					$unit_details = array('raw' => $post, 'children' => array());
					if (isset($unit_mapping[$post->ID])) {
						$unit_details['children'] = $unit_mapping[$post->ID];
					}
					$units[$post->ID] = $unit_details;
				} elseif (self::ZIPPY_LESSON_SLUG === $post->post_type) {
					$lesson_details = array('raw' => $post);
					$lessons[$post->ID] = $lesson_details;
				}
			}
			return array('course' => $courses, 'unit' => $units, 'lesson' => $lessons);
		}
		public static function get_settings() {
			$setting = get_option(self::SETTING_KEY, false);
			if (!is_array($setting)) {
				$setting = self::$default_settings;
			} else {
				$setting = wp_parse_args($setting, self::$default_settings);
			}
			if (!isset($setting['wizard']) || !is_array($setting['wizard'])) {
				$setting['wizard'] = array();
			}

			return $setting;
		}
		public static function set_settings($settings) {
			$settings = wp_parse_args($settings, self::$default_settings);
			$successfully_added = add_option(self::SETTING_KEY, $settings, '', 'no');
			if (!$successfully_added) {
				update_option(self::SETTING_KEY, $settings);
			}
			return $settings;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="generate display code (used for initial display and ajax call back)">
		private static function generate_zippy_unit_display($unit_details, $zippy_custom_posts) {
			$unit_db_entry = $unit_details['raw'];
			$code = '- Unit: ' . esc_html($unit_db_entry->post_title);
			$code .= '<ul>';
			foreach ($unit_details['children'] as $child_id) {
				if (isset($zippy_custom_posts['lesson'][$child_id])) {
					$code .= '<li>';
					$code .= '- Lesson: ' . esc_html($zippy_custom_posts['lesson'][$child_id]['raw']->post_title);
					$code .= '</li>';
				}
			}
			$code .= '</ul>';
			return $code;
		}
		private static function generate_zippy_course_display($code, $course_details, $zippy_custom_posts) {
			$course_db_entry = $course_details['raw'];

			$code = str_replace('{{id}}', esc_html($course_db_entry->ID), $code);
			$code = str_replace('{{edit-link}}',esc_attr(get_edit_post_link($course_db_entry->ID)), $code);
			$code = str_replace('{{name}}', esc_html($course_db_entry->post_title), $code);

			$details = '<ul>';
			$has_unit = false;
			foreach ($course_details['children'] as $child_id) {
				$details .= '<li>';
				if (isset($zippy_custom_posts['unit'][$child_id])) {
					$has_unit = true;
					$details .= self::generate_zippy_unit_display($zippy_custom_posts['unit'][$child_id], $zippy_custom_posts);
				} elseif (isset($zippy_custom_posts['lesson'][$child_id])) {
					$details .= '- Lesson: ' . esc_html($zippy_custom_posts['lesson'][$child_id]['raw']->post_title);
				}
				$details .= '</li>';
			}
			$details .= '</ul>';

			$code = str_replace('{{details}}', $details, $code);

			if ($has_unit) {
				$code = str_replace('{{stage-release-option}}', '<option value="stage">Convert to a Stage-release course</option>', $code);
			} else {
				$code = str_replace('{{stage-release-option}}', '', $code);
			}
			return $code;
		}
		private static function generate_converted_course_display($row_code, $course_id, $wizard_course, $wizard_url_base) {
			$row_code = str_replace('{{name}}', esc_html($wizard_course['name']), $row_code);
			if (empty($wizard_course['type'])) {
				$row_code = str_replace('{{edit-link}}', '#', $row_code);
				$row_code = str_replace('{{show-edit}}', 'style="display:none"', $row_code);
			} else {
				$row_code = str_replace('{{edit-link}}', esc_attr($wizard_url_base . '&show-' . $wizard_course['type'] . '=' . $wizard_course['option-key']), $row_code);
				$row_code = str_replace('{{show-edit}}', '', $row_code);
			}
			$row_code = str_replace('{{course-id}}', esc_html($course_id), $row_code);
			return $row_code;
		}
		public static function generate_setting_display() {
			$code = file_get_contents(dirname(__FILE__) . '/backend/settings-template.php');

			$zippy_custom_posts = self::get_zippy_custom_posts();
			$zippy_course_code = '';
			$zippy_course_template = file_get_contents(dirname(__FILE__) . '/backend/convert-template.php');
			foreach ($zippy_custom_posts['course'] as $course_details) {
				$zippy_course_code .= self::generate_zippy_course_display($zippy_course_template, $course_details, $zippy_custom_posts);
			}
			$code = str_replace('{{zippy-courses}}', $zippy_course_code, $code);

			$converted_posts = self::get_settings();
			$existing_courses = '';
			if (!empty($converted_posts['wizard'])) {
				$existing_row_template = file_get_contents(dirname(__FILE__) . '/backend/existing-template.php');
				$wizard_url_base = admin_url('admin.php?page=_accessally_setting_wizard');
				foreach ($converted_posts['wizard'] as $course_id => $wizard_course) {
					$existing_courses .= self::generate_converted_course_display($existing_row_template, $course_id, $wizard_course, $wizard_url_base);
				}
			}

			$code = str_replace('{{existing-courses}}', $existing_courses, $code);

			if (!empty($existing_courses)) {
				$code = str_replace('{{show-existing}}', '', $code);
			} else {
				$code = str_replace('{{show-existing}}', 'style="display:none"', $code);
			}

			return $code;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="Create AccessAlly standalone course structure">
		private static $page_setting_template = array('type' => 'page', 'name' => '', 'is-changed' => 'no', 'page-template-select' => '0', 'checked-existing' => 'no',
			'status' => 'new', 'post-edit-link' => '#', 'post-id' => 0,
			'success-message' => '', 'error-message' => '');
		private static function create_accessally_wizard_page_from_raw_db($db_entry, $module_id = 0) {
			$result_page = self::$page_setting_template;
			$result_page['name'] = $db_entry->post_title;
			$result_page['is-changed'] = 'yes';
			$result_page['page-template-select'] = $db_entry->ID;
			$result_page['checked-existing'] = 'yes';
			$result_page['module'] = $module_id;
			return $result_page;
		}
		private static function recursive_add_custom_posts_to_array($result_array, $parent_details, $zippy_custom_posts, $add_parent_page, $module_id) {
			if ($add_parent_page) {
				$next_index = 1;
				if (!empty($result_array)) {
					$next_index = max(array_keys($result_array)) + 1;
				}
				$result_array[$next_index] = self::create_accessally_wizard_page_from_raw_db($parent_details['raw'], $module_id);
			}
			if (!empty($parent_details['children'])) {
				foreach ($parent_details['children'] as $child_id) {
					if (isset($zippy_custom_posts['unit'][$child_id])) {
						$result_array = self::recursive_add_custom_posts_to_array($result_array, $zippy_custom_posts['unit'][$child_id], $zippy_custom_posts, true, $module_id);
					} elseif (isset($zippy_custom_posts['lesson'][$child_id])) {
						$result_array = self::recursive_add_custom_posts_to_array($result_array, $zippy_custom_posts['lesson'][$child_id], $zippy_custom_posts, true, $module_id);
					}
				}
			}
			return $result_array;
		}
		private static function create_accessally_standalone_course($course_details, $zippy_custom_posts) {
			$wizard_data = AccessAllyWizardProduct::$default_product_settings;
			$wizard_data['name'] = 'Zippy Course: ' . $course_details['raw']->post_title;

			$api_settings = AccessAllySettingSetup::get_api_settings();
			$wizard_data['system'] = $api_settings['system'];

			$wizard_data['pages'][0] = self::create_accessally_wizard_page_from_raw_db($course_details['raw']);
			$wizard_data['pages'] = self::recursive_add_custom_posts_to_array($wizard_data['pages'], $course_details, $zippy_custom_posts, false, 0);

			$wizard_data = AccessAllyWizardProduct::merge_default_settings($wizard_data);

			$wizard_data = AccessAllyUtilities::set_incrementing_settings(AccessAllyWizardProduct::SETTING_KEY_WIZARD_PRODUCT,
				AccessAllyWizardProduct::SETTING_KEY_WIZARD_PRODUCT_NUMBER, $wizard_data, AccessAllyWizardProduct::$default_product_settings, true, false);
			return $wizard_data;
		}
		private static function create_accessally_stage_release_course($course_details, $zippy_custom_posts) {
			$wizard_data = AccessAllyWizardDrip::$default_drip_settings;
			$wizard_data['name'] = 'Zippy Course: ' . $course_details['raw']->post_title;

			$api_settings = AccessAllySettingSetup::get_api_settings();
			$wizard_data['system'] = $api_settings['system'];

			$wizard_data['pages'][0] = self::create_accessally_wizard_page_from_raw_db($course_details['raw'], 0);

			$base_lesson_pages = array();
			$module_count = 0;
			if (!empty($course_details['children'])) {
				foreach ($course_details['children'] as $child_id) {
					if (isset($zippy_custom_posts['unit'][$child_id])) {	// each unit is added as a module
						++$module_count;
						$module_wizard_data = AccessAllyWizardDrip::$default_module_settings;
						$module_wizard_data['name'] = $zippy_custom_posts['unit'][$child_id]['raw']->post_title;
						$wizard_data['modules'][$module_count] = $module_wizard_data;

						$wizard_data['pages'] = self::recursive_add_custom_posts_to_array($wizard_data['pages'], $zippy_custom_posts['unit'][$child_id], $zippy_custom_posts, true, $module_count);

					} elseif (isset($zippy_custom_posts['lesson'][$child_id])) {	// any base lesson is added as the initial module
						$wizard_data['pages'] []= self::create_accessally_wizard_page_from_raw_db($zippy_custom_posts['lesson'][$child_id]['raw'], 0);
					}
				}
			}

			$wizard_data = AccessAllyWizardDrip::merge_default_settings($wizard_data);
			$wizard_data = AccessAllyUtilities::set_incrementing_settings(AccessAllyWizardDrip::SETTING_KEY_WIZARD_DRIP,
				AccessAllyWizardDrip::SETTING_KEY_WIZARD_DRIP_NUMBER, $wizard_data, AccessAllyWizardDrip::$default_drip_settings, true, false);
			return $wizard_data;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="database post type update">
		private static function recursive_get_custom_post_to_convert($result_array, $root_custom_post, $zippy_custom_posts) {
			$root_post_type = $root_custom_post['raw']->post_type;
			if (!isset($result_array[$root_post_type])) {
				$result_array[$root_post_type] = array();
			}
			$result_array[$root_post_type] []= $root_custom_post['raw']->ID;

			if (!empty($root_custom_post['children'])) {
				foreach ($root_custom_post['children'] as $child_id) {
					if (isset($zippy_custom_posts['unit'][$child_id])) {
						$result_array = self::recursive_get_custom_post_to_convert($result_array, $zippy_custom_posts['unit'][$child_id], $zippy_custom_posts);
					} elseif (isset($zippy_custom_posts['lesson'][$child_id])) {
						$result_array = self::recursive_get_custom_post_to_convert($result_array, $zippy_custom_posts['lesson'][$child_id], $zippy_custom_posts);
					}
				}
			}
			return $result_array;
		}
		private static function raw_database_update($post_ids, $target_type) {
			if (empty($post_ids)) {
				return 0;
			}
			global $wpdb;

			$query = $wpdb->prepare("UPDATE {$wpdb->posts} SET post_type = %s WHERE ID in (" . implode(',', $post_ids) . ")", $target_type);
			$update_result = $wpdb->query($query);
			if (false === $update_result && $wpdb->last_error) {
				throw new Exception($wpdb->last_error);
			}
			return $update_result;
		}
		// </editor-fold>

		// <editor-fold defaultstate="collapsed" desc="Ajax callbacks: convert / revert zippy to page">
		public static function convert_callback() {
			$result = array('status' => 'error', 'message' => 'Unknown error. Please refresh the page and try again.');
			try {
				if (!self::is_accessally_active()) {
					throw new Exception('AccessAlly is not activated or outdated. Please install the latest version of AccessAlly before using the conversion tool.');
				}
				if (!isset($_REQUEST['id']) || !isset($_REQUEST['op']) || !isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'accessally-zippy-course-convert')) {
					throw new Exception('The page is outdated. Please refresh and try again.');
				}
				$course_id = $_REQUEST['id'];
				$operation = $_REQUEST['op'];
				if ('alone' !== $operation && 'stage' !== $operation && 'wp' !== $operation) {
					throw new Exception('Invalid convert operation. Please refresh and try again.');
				}
				$zippy_custom_posts = self::get_zippy_custom_posts();

				if (!isset($zippy_custom_posts['course'][$course_id])) {
					throw new Exception('The Zippy course doesn\'t exist. Please refresh and try again.');
				}
				$course_details = $zippy_custom_posts['course'][$course_id];

				$course_name = $course_details['raw']->post_title;

				$conversion_data = array('name' => $course_name);	// assign default value if the course is converted without creating a wizard course
				if ('stage' === $operation) {
					$created_course = self::create_accessally_stage_release_course($course_details, $zippy_custom_posts);
					$conversion_data = array('type' => 'stage', 'option-key' => $created_course['option-key'], 'name' => $created_course['name']);
				} elseif ('alone' === $operation) {
					$created_course = self::create_accessally_standalone_course($course_details, $zippy_custom_posts);
					$conversion_data = array('type' => 'alone', 'option-key' => $created_course['option-key'], 'name' => $created_course['name']);
				}
				$pages_to_convert = self::recursive_get_custom_post_to_convert(array(), $course_details, $zippy_custom_posts);

				foreach (self::$ZIPPY_CUSTOM_POST_TYPES as $post_type) {
					if (isset($pages_to_convert[$post_type])) {
						$post_ids = $pages_to_convert[$post_type];
						self::raw_database_update($post_ids, 'page');
					}
				}

				$conversion_data['converted'] = $pages_to_convert;

				$conversion_history = self::get_settings();
				$conversion_history['wizard'][$course_id] = $conversion_data;
				self::set_settings($conversion_history);

				$code = self::generate_setting_display();
				$result = array('status' => 'success', 'message' => 'The Zippy Course has been converted.', 'code' => $code);
			} catch (Exception $e) {
				$result['status'] = 'error';
				$result['message'] = $e->getMessage() . ' Please refresh the page and try again.';
			}
			echo json_encode($result);
			die();
		}
		public static function revert_callback() {
			$result = array('status' => 'error', 'message' => 'Unknown error. Please refresh the page and try again.');
			try {
				if (!self::is_accessally_active()) {
					throw new Exception('AccessAlly is not activated or outdated. Please install the latest version of AccessAlly before using the conversion tool.');
				}
				if (!isset($_REQUEST['id']) || !isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'accessally-zippy-course-convert')) {
					throw new Exception('The page is outdated. Please refresh and try again.');
				}
				$course_id = $_REQUEST['id'];
				$conversion_history = self::get_settings();
				if (!isset($conversion_history['wizard'][$course_id])) {
					throw new Exception('Invalid course. Please refresh and try again.');
				}
				$converted_data = $conversion_history['wizard'][$course_id];
				$converted_pages = $converted_data['converted'];
				foreach (self::$ZIPPY_CUSTOM_POST_TYPES as $post_type) {
					if (isset($converted_pages[$post_type])) {
						self::raw_database_update($converted_pages[$post_type], $post_type);
					}
				}
				unset($conversion_history['wizard'][$course_id]);
				self::set_settings($conversion_history);

				$code = self::generate_setting_display();
				$result = array('status' => 'success', 'message' => 'Reverting pages to Zippy Courses format complete.', 'code' => $code);
			} catch (Exception $e) {
				$result['status'] = 'error';
				$result['message'] = $e->getMessage() . ' Please refresh the page and try again.';
			}
			echo json_encode($result);
			die();
		}
		// </editor-fold>
	}
	AccessAlly_ZippyCourseConversion::init();
}
