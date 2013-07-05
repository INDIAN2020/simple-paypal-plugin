<?php
/**
 * Simple Paypal Plugin Administration
 * Plugin URI: http://p-2.biz/plugins/paypal
 * @version: 1.0
 * @author: Peter Edwards <Peter.Edwards@p-2.biz>
 * @license: GPL2
*/

/*  Copyright 2011  Peter Edwards  (email : Peter.Edwards@p-2.biz)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as 
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**************************
 * PLUGIN ADMINISTRATION  *
 **************************/

class SimplePayPalPluginAdmin
{
	/* plugin version */
	public static $plugin_version = "1.0";

	/* paypal URLs */
	public static $paypal_url = 'https://www.paypal.com/cgi-bin/webscr';
	public static $paypal_sandbox_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';

	public static function register()
	{
		/* add the admin page and plugin options */
		if ( is_admin() ){
			add_action( 'admin_menu', array(__CLASS__, 'add_paypal_admin_menus') );
			add_action( 'admin_init', array(__CLASS__, 'register_paypal_options') );
			/* add scripts and CSS to back end */
			add_action( 'admin_enqueue_scripts', array(__CLASS__, "enqueue_scripts"));
			/* register query variables */
			add_filter( 'query_vars', array(__CLASS__, 'add_query_vars'));
			/* process PDF download request */
			add_action( 'init', array(__CLASS__, "downloadPDF"));
			/* make sure there is a directory set up to store invoices */
			$uploads_dir = wp_upload_dir();
			$invoice_dir = $uploads_dir["basedir"] . "/sppp-invoices/";
			if (!file_exists($invoice_dir)) {
				@mkdir($invoice_dir, 0777);
			}
			$invoice_htaccess = $invoice_dir . ".htaccess";
			if (!file_exists($invoice_htaccess)) {
				@file_put_contents($invoice_htaccess, "Order Deny,Allow\nDeny from all");
			}
		}
	}

	/**
	 * add the paypal menu and its submenus
	 */
	public static function add_paypal_admin_menus()
	{
        add_menu_page("Paypal", "Paypal", "edit_posts", "paypal-plugin.php", array(__CLASS__, 'paypal_options_page'), plugins_url('/img/paypal-menu-icon.png', __FILE__), 200);
		add_submenu_page("paypal-plugin.php", "Paypal", "Paypal Options", "edit_plugins", "paypal-plugin.php", array(__CLASS__, "paypal_options_page"));
		/* add payments page */
		add_submenu_page("paypal-plugin.php", "Paypal Payments", "Paypal payments", "edit_plugins", "paypal-payments", array(__CLASS__, "paypal_payments_page"));
	}

	/**
	 * adds script to front end
	 */
	public static function enqueue_scripts()
	{
		wp_enqueue_style("ppp-admin-css", plugins_url("css/admin.css", __FILE__)); 
		wp_enqueue_script("ppp-admin-js", plugins_url("js/admin.js", __FILE__), array('jquery', 'media'), self::$plugin_version, true);
	}

	/**
	 * adds query vars for payments page filters/actions
	 */
	public static function add_query_vars($query_vars)
	{
 		$query_vars[] = 'sppp-action';
 		$query_vars[] = 'sppp-id';
 		$query_vars[] = 'sppp-perpage';
 		$query_vars[] = 'sppp-page';
 		$query_vars[] = 'sppp-filter';
 		return $query_vars;
	}

	/**
	 * registers options for the plugin
	 */
	public static function register_paypal_options()
	{
		register_setting(
			'sppp_options',
			'sppp_options', 
			array(__CLASS__, "validate_options")
		);
		/* Paypal section */
		add_settings_section(
			'paypal_section',
			'Paypal Settings',
			array(__CLASS__, "section_text_fn"),
			__FILE__
		);
		add_settings_field(
			'paypal_email',
			'Paypal email address',
			array(__CLASS__, 'setting_text_fn'),
			__FILE__,
			'paypal_section',
			array(
				"field" => "paypal_email"
			)
		);
		add_settings_field(
			'paypal_ipn_email',
			'IPN monitor email address',
			array(__CLASS__, 'setting_text_fn'),
			__FILE__,
			'paypal_section',
			array(
				"field" => "paypal_ipn_email",
				"desc" => "This email address will get reports of all Instant Payment Notifications from Paypal."
			)
		);
		add_settings_field(
			'paypal_sandbox',
			'Use Paypal sandbox?',
			array(__CLASS__, 'setting_cbx_fn'),
			__FILE__,
			'paypal_section',
			array(
				"field" => "paypal_sandbox"
			)
		);
		add_settings_field(
			'paypal_sandbox_email',
			'Paypal sandbox email address',
			array(__CLASS__, 'setting_text_fn'),
			__FILE__,
			'paypal_section',
			array(
				"field" => "paypal_sandbox_email"
			)
		);
		add_settings_field(
			'paypal_currency',
			'Paypal Currency',
			array(__CLASS__, 'setting_currency_dropdown_fn'),
			__FILE__,
			'paypal_section'
		);
		/* Invoice section */
		add_settings_section(
			'invoice_section',
			'Invoice Settings',
			array(__CLASS__, "section_text_fn"),
			__FILE__
		);
		add_settings_field(
			'company_name',
			'Company Name',
			array(__CLASS__, 'setting_text_fn'),
			__FILE__,
			'invoice_section',
			array(
				"field" => "company_name",
				"desc" => "Enter the company name for invoices"
			)
		);
		add_settings_field(
			'invoice_address',
			'Invoice address',
			array(__CLASS__, 'setting_textbox_fn'),
			__FILE__,
			'invoice_section',
			array(
				"field" => "invoice_address",
				"desc" => "Enter the address for invoices"
			)
		);
		add_settings_field(
			'invoice_footer',
			'Invoice footer',
			array(__CLASS__, 'setting_textbox_fn'),
			__FILE__,
			'invoice_section',
			array(
				"field" => "invoice_footer",
				"desc" => "Enter text used in the footer of invoices"
			)
		);
		add_settings_field(
			'logo_url',
			'Invoice Logo',
			array(__CLASS__, 'setting_image_fn'),
			__FILE__,
			'invoice_section', 
			array(
				"field" => "logo_url",
				"desc" => 'In the Media Uploader (which will open when you click on the "choose image" button_), clicking on the "Insert into Post" button for an image will set the image as the Logo for your invoices'
			)
		);
		add_settings_field(
			'invoice_subject',
			'Invoice email subject',
			array(__CLASS__, 'setting_text_fn'),
			__FILE__,
			'invoice_section',
			array(
				"field" => "invoice_subject",
				"desc" => "Enter text used for the subject line of the email message sent with the invoice (the invoice number will be appended to this)"
			)
		);
		add_settings_field(
			'invoice_message',
			'Invoice email message',
			array(__CLASS__, 'setting_richtext_fn'),
			__FILE__,
			'invoice_section',
			array(
				"field" => "invoice_message",
				"desc" => "Enter the text for the email message sent with the invoice. Use <code>{{PP_ITEMS}}</code> in the message to include a table showing the products purchased."
			)
		);
		/* Interface section */
		add_settings_section(
			'cart_section',
			'Interface settings',
			array(__CLASS__, "section_text_fn"),
			__FILE__
		);
		add_settings_field(
			'supported_post_types',
			'Post types to use as products',
			array(__CLASS__, 'setting_posttype_cbx_fn'),
			__FILE__,
			'cart_section',
			array(
				"field" => "supported_post_types"
			)
		);
		add_settings_field(
			'cart_page_id',
			'Page to use as the cart',
			array(__CLASS__, 'setting_pageid_dropdown_fn'),
			__FILE__,
			'cart_section',
			array(
				"field" => "cart_page_id"
			)
		);
		/* Shipping section */
		add_settings_section(
			'shipping_section',
			'Shipping',
			array(__CLASS__, "section_text_fn"),
			__FILE__
		);
		add_settings_field(
			'shipping_method',
			'Shipping Configuration',
			array(__CLASS__, 'setting_shipping_method_fn'),
			__FILE__,
			'shipping_section'
		);
		add_settings_field(
			'shipping_settings',
			'Shipping Settings',
			array(__CLASS__, 'setting_shipping_fn'),
			__FILE__,
			'shipping_section'
		);
		add_settings_field(
			'allow_pickup',
			'Allow pick-up',
			array(__CLASS__, 'setting_cbx_fn'),
			__FILE__,
			'shipping_section',
			array(
				"field" => "allow_pickup",
				"desc" => "Checking this box will allow users to bypass shipping costs and elect to pick up items in person."
			)
		);
		add_settings_field(
			'pickup_address',
			'Pick-up address',
			array(__CLASS__, 'setting_textbox_fn'),
			__FILE__,
			'shipping_section', 
			array(
				"field" => "pickup_address",
				"desc" => "Enter the address where items will be available to pick up. Include any other information such as a link to google maps, opening hours, etc."
			)
		);
		/* VAT secion */
		add_settings_section(
			'vat_section',
			'VAT',
			array(__CLASS__, "section_text_fn"),
			__FILE__
		);
		add_settings_field(
			'vat_rate',
			'VAT rate',
			array(__CLASS__, 'setting_text_fn'),
			__FILE__,
			'vat_section',
			array(
				"field" => "vat_rate",
				"size" => 5,
				"desc" => "Please enter the percentage tax rate applied to all items."
			)
		);
		/* Communication section  */
		add_settings_section(
			'comms_section',
			'Messages',
			array(__CLASS__, "section_text_fn"),
			__FILE__
		);
		add_settings_field(
			'enquiry_msg',
			'Enquiry Autoresponder',
			array(__CLASS__, 'setting_richtext_fn'),
			__FILE__,
			'comms_section',
			array(
				"field" => "enquiry_msg", 
				"desc" => "This is the email message sent in response to product enquiries. Use <code>{PP_NAME}</code> in the message to include the product name."
			)
		);
		add_settings_field(
			'invoice_msg',
			'Invoice Email',
			array(__CLASS__, 'setting_richtext_fn'),
			__FILE__,
			'comms_section',
			array(
				"field" => "invoice_msg", 
				"desc" => "This is the email message sent to buyers with the invoice attached. The message will also include the purchase details."
			)
		);
		add_settings_field(
			'thanks_msg',
			'Thankyou message',
			array(__CLASS__, 'setting_richtext_fn'),
			__FILE__,
			'comms_section', 
			array(
				"field" => "thanks_msg", 
				"desc" => "This is the text for the page a user is returned to when they have completed a paypal payment."
			)
		);
		add_settings_field(
			'error_msg', 
			'Paypal error message', 
			array(__CLASS__, 'setting_richtext_fn'), 
			__FILE__, 
			'comms_section', 
			array(
				"field" => "error_msg", 
				"desc" => "This is the text for the page a user is returned to if there is an error with the payment process."
			)
		);
		/* JS/CSS section */
		add_settings_section(
			'enqueue_section',
			'Javascript and CSS',
			array(__CLASS__, "section_text_fn"),
			__FILE__
		);
		add_settings_field(
			'enqueue_js', 
			'Enqueue Javascript', 
			array(__CLASS__, 'setting_enqueue_fn'), 
			__FILE__, 
			'enqueue_section', 
			array(
				"field" => "enqueue_js", 
				"file" => plugins_url('/js/paypal-plugin.js', __FILE__), 
				"desc" => "Check this box if you would like the JavaScript for the plugin to be loaded in the front end."
			)
		);
		add_settings_field(
			'enqueue_css', 
			'Enqueue Javascript', 
			array(__CLASS__, 'setting_enqueue_fn'), 
			__FILE__, 
			'enqueue_section', 
			array(
				"field" => "enqueue_css", 
				"file" => plugins_url('/css/paypal-plugin.css', __FILE__), 
				"desc" => "Check this box if you would like the CSS for the plugin to be loaded in the front end."
			)
		);
	}
	
	/**
	 * paypal options page
	 */
	public static function paypal_options_page()
	{
		printf('<div class="wrap"><div class="icon32"><img src="%s"/></div><h2>Paypal options</h2><form method="post" action="options.php">', plugins_url('img/paypal-icon.png', __FILE__));
		settings_fields('sppp_options');
		settings_errors('sppp_options');
		do_settings_sections(__FILE__);
		print('<p class="submit"><input name="Submit" type="submit" class="button-primary" value="Save Changes" /></p></form><p>Simple Paypal Plugin for Wordpress: version ' . get_option('sppp_version') . '</p></div>');
	}

	/**
	 * section text callback function
	 */
	public static function section_text_fn() {}

	/**
	 * text input callback function for a single setting
	 */
	public static function setting_text_fn($args)
	{
		$field = $args["field"];
		$size = (isset($args["size"]))? intval($args["size"]): 30;
		$options = self::get_paypal_options();
		$value = isset($options[$field])? $options[$field]: "";
		printf('<input id="%s" name="sppp_options[%s]" size="%s" type="text" value="%s" />', $field, $field, $size, $value);
		if (isset($args["desc"])) {
			printf('<p class="field_desc">%s</p>', $args["desc"]);
		}
	}			   

	/**
	 * textbox input callback function for a single setting
	 */
	public static function setting_textbox_fn($args)
	{
		$field = $args["field"];
		$cols = (isset($args["cols"]))? intval($args["cols"]): 60;
		$rows = (isset($args["rows"]))? intval($args["rows"]): 6;
		$options = self::get_paypal_options();
		$value = isset($options[$field])? $options[$field]: "";
		printf('<textarea id="%s" name="sppp_options[%s]" cols="%s" rows="%s">%s</textarea>', $field, $field, $cols, $rows, $value);
		if (isset($args["desc"])) {
			printf('<p class="field_desc">%s</p>', $args["desc"]);
		}
	}			   

	/**
	 * textarea input callback function for a single setting
	 */
	public static function setting_richtext_fn($args)
	{
		$field = $args["field"];
		$options = self::get_paypal_options();
		$editor_id = $field;
		$value = isset($options[$field])? $options[$field]: "";
		/* use wp-editor but capture output in a buffer */
		ob_start();
		/* options for editor */
		$options = array(
			"media_buttons" => true,
			"textarea_name" => "sppp_options[$field]",
			"textarea_rows" => 3,
			"teeny" => false
		);
		/* "echo" the editor */
		wp_editor($value, $editor_id, $options );
		/* get the output buffer */
		$editor = ob_get_contents();
		/* clean the output buffer */
		ob_clean();
		printf('<div>%s</div>', $editor);
		if (isset($args["desc"])) {
			printf('<p class="field_desc">%s</p>', $args["desc"]);
		}
	}	
	
	/**
	 * checkbox input callback function for a single setting
	 */
	public static function setting_cbx_fn($args)
	{
		$field = $args["field"];
		$options = self::get_paypal_options();
		$chckd = (isset($options[$field]) && $options[$field])? ' checked="checked"': "";
		printf('<input id="%s" name="sppp_options[%s]" type="checkbox" value="1"%s />', $field, $field, $chckd);
		if (isset($args["desc"])) {
			printf('<p class="field_desc">%s</p>', $args["desc"]);
		}
	}

	/**
	 * image path input callback
	 */
	public static function setting_image_fn($args)
	{
		$uploads_dir = wp_upload_dir();
		$options = self::get_paypal_options();
		$option_value = (isset($options[$args['field']]) && trim($options[$args['field']]) != "")? trim($options[$args['field']]): "";
		printf('<div id="%s_preview">', $args["field"]);
		if ($option_value) {
			$imgpath = str_replace($uploads_dir["baseurl"], $uploads_dir["basedir"], $option_value);
			if (false !== ($info = @getimagesize($imgpath))) {
				printf('<img src="%s" />', $option_value);
			}
		}
		printf('</div><input id="%s" type="text" name="sppp_options[%s]" value="%s" /><a class="upload_image_button button-primary" id="btn_%s">choose image</a> <a class="clear_image_button button-secondary" rel="%s">clear</a>', $args['field'], $args['field'], $option_value, $args['field'], $args['field']);
	}			

	/**
	 * dropdown of pages on the site
	 */			
	public static function setting_pageid_dropdown_fn($args)
	{
		$field = $args["field"];		
		$options = self::get_paypal_options();
		$value = isset($options[$field])? $options[$field]: "";
		$sel = $value? '': ' selected="selected"';
		printf('<select name="sppp_options[%s]" id="%s"><option value=""%s>Please select a page</option>', $field, $field, $sel);
		$pages = get_pages();
		foreach ($pages as $p) {
			$sel = ($p->ID == $value)? ' selected="selected"': '';
			printf('<option value="%s"%s>%s</option>', $p->ID, $sel, $p->post_title);
		}
		print('</select>');
		if (isset($args["desc"])) {
			printf('<p class="field_desc">%s</p>', $args["desc"]);
		}
	}
	
	/**
	 * checkbox list of post types
	 */
	public static function setting_posttype_cbx_fn($args)
	{
		$field = $args["field"];		
		$options = self::get_paypal_options();
		$value = (isset($options[$field]) && trim($options[$field]) != "")? explode(",", $options[$field]): array();
		$post_types = get_post_types( array("show_ui" => true), "object");
		foreach ($post_types as $pt) {
			$chckd = (in_array($pt->name, $value))? ' checked="checked"': '';
			printf('<p><input type="checkbox" name="sppp_options[%s][]" id="%s-%s" value="%s"%s /> <label for="%s">%s</label></p>', $field, $field, $pt->name, $pt->name, $chckd, $field, $pt->name, $pt->label);
		}
		print('<p class="field_desc">Choose the post types which can be used to sell items. Templates or content for these post types will need to include the relevant shortcodes/function calls.</p>');
	}

	/**
	 * option to include stylesheet and script in frontend
	 */
	public static function setting_enqueue_fn($args)
	{
		$field = $args["field"];		
		$options = self::get_paypal_options();
		$chckd = (isset($options[$field]) && $options[$field])? ' checked="checked"': "";
		printf('<input id="%s" name="sppp_options[%s]" type="checkbox" value="1"%s />', $field, $field, $chckd);
		if (isset($args["desc"])) {
			printf('<p class="field_desc">%s. If you would like to incorporate it into your theme, <a href="%s">you can download an example file here</a>.', $args["desc"], $args["file"]);
		}
	}

	/**
	 * currency code drop-down list
	 */
	public static function setting_currency_dropdown_fn()
	{
		$cur = self::get_supported_currencies();
		$options = self::get_paypal_options();
		$currency = (isset($options["paypal_currency"]) && in_array($options["paypal_currency"], array_keys($cur)))? $options["paypal_currency"]: false;
		$sel = ($currency)? "": ' selected="selected"';
		printf('<select name="sppp_options[paypal_currency]" id="paypal_currency"><option value=""%s>Please select a currency</option>', $sel);
		foreach ($cur as $code => $currency_name) {
			$sel = ($code == $currency)? ' selected="selected"': '';
			printf('<option value="%s"%s>%s</option>', $code, $sel, $currency_name);
		}
		print('</select>');
		print('<p class="field_desc">This contains all supported currencies in Paypal, as outlined on the <a href="https://cms.paypal.com/uk/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_currency_codes">Paypal developer site</a>.</p>');
	}

	/**
	 * shipping settings
	 */
	public static function setting_shipping_method_fn()
	{
		$options = self::get_paypal_options();
		$methods = self::get_shipping_methods();
		if (!isset($options["shipping_method"]) || !in_array($options["shipping_method"], array_keys($methods))) {
			foreach ($methods as $key => $txt) {
				printf('<p><input type="radio" name="sppp_options[shipping_method]" value="%s" /> Check this box if you want to use %s.</p>', $key, $txt);
			}
		} else {
			$current_method = $options["shipping_method"];
			$alt_method = ($current_method == "bands")? "weights": "bands";
			printf('<p>You are currently using <strong>%s</strong> to determine shipping costs. <a href="#" id="switch_method" class="button-secondary">switch to using %s</a><input type="hidden" id="shipping_method" name="sppp_options[shipping_method]" value="%s" /></p>', $methods[$current_method], $methods[$alt_method], $current_method);
		}
	}

	public static function setting_shipping_fn()
	{
		$options = self::get_paypal_options();
		$methods = self::get_shipping_methods();
		//print('<pre>');print_r($options["shipping_settings"]);print('</pre>');
		if (isset($options["shipping_method"]) && in_array($options["shipping_method"], array_keys($methods))) {
			$regions = self::get_shipping_regions();
			printf('<script>var regions = %s;</script>', json_encode($regions));
			$form_method_name = "shipping_settings_form_" . $options["shipping_method"];
			print(self::$form_method_name());
			$hidden_method_name = "shipping_settings_hidden_" . $options["shipping_method"];
			//print(self::$hidden_method_name());
		} else {
			print('<p>You need to choose a shipping calculation method above before you can configure these settings.</p>');
		}
	}

	/**
	 * shipping forms for when a series of bands are used
	 * which include a baseline cost and item cost
	 */
	public static function shipping_settings_form_bands()
	{
		$options = self::get_paypal_options();
		$regions = self::get_shipping_regions();
		if (!isset($options["shipping_settings"]) || !isset($options["shipping_settings"]["bands"]) || !count($options["shipping_settings"]["bands"])) {
			$bands = array();
			$empty_band = array('name' => '', 'default' => 1);
			foreach ($regions as $region_code => $region_data) {
				$empty_band["shipping_one_" . $region_code] = '';
				$empty_band["shipping_multiple_" . $region_code] = '';
			}
			$bands[] = $empty_band;
		} else {
			$bands = $options["shipping_settings"]["bands"];
		}
		print('<div id="shipping-settings"><div id="shipping-bands">');
		for ($i = 0; $i < count($bands); $i++) {
			printf('<fieldset class="shipping-band" data-band-id="%s" id="band_%d"><input type="hidden" name="sppp_options[shipping_settings][band_ids][]" value="%s" />', $i, $i, $i);
			printf('<p><label for="pp_band_name_%d">Name:</label><input type="text" name="sppp_options[shipping_settings][band][name_%d]" id="pp_band_name_%d" value="%s" size="20" /></p>', $i, $i, $i, $bands[$i]["name"]);
			$chckd = ($bands[$i]["default"] || count($bands) == 1)? ' checked="checked"': '';
			printf('<p><label for="pp_band_default_%s" class="wide">Check this box to make this the default postage band: <input type="radio" id="pp_band_default_%s" class="default-band" name="sppp_options[shipping_settings][default_band]" value="%s"%s></label></p>', $i, $i, $i, $chckd);
			foreach ($regions as $region_code => $region_data) {
				printf('<fieldset><legend>%s</legend>', $region_data["name"]);
				$val = self::dec2($bands[$i][$region_code]["shipping_one"]);
				printf('<p><label for="pp_shipping_one_%s_%d">First item:</label><input type="text" name="sppp_options[shipping_settings][band][shipping_one_%s_%d]" id="pp_shipping_one_%s_%d" value="%s" size="7" class="currency" /></p>', $region_code, $i, $region_code, $i, $region_code, $i, $val);
				$val = self::dec2($bands[$i][$region_code]["shipping_multiple"]);
				printf('<p><label for="pp_shipping_multiple_%s_%d">Subsequent items:</label><input type="text" name="sppp_options[shipping_settings][band][shipping_multiple_%s_%d]" id="pp_shipping_multiple_%s_%d" value="%s" size="7" class="currency" /></p>', $region_code, $i, $region_code, $i, $region_code, $i, $val);
				print('</fieldset>');
			}
			printf('<p id="delete-button-%d" class="delete-band"><a href="#" class="delete-band-button button-secondary" data-band-id="%d">delete this band</a>', $i, $i);
			print('</fieldset>');
		}
		print('</div><p><a class="button-secondary" id="add-band">Add a new postage band</a></p></div>');
	}

	/**
	 * validates a single shipping band
	 */
	public static function validate_shipping_band($band, $band_id, $default)
	{
		$details = array();
		if (isset($band["name_" . $band_id]) && trim($band["name_" . $band_id]) != "") {
			$regions = self::get_shipping_regions();
			foreach ($regions as $region_code => $region_data) {
				if (isset($band["shipping_one_" . $region_code . "_" . $band_id]) && 
					trim($band["shipping_one_" . $region_code . "_" . $band_id]) != "" &&
					isset($band["shipping_multiple_" . $region_code . "_" . $band_id]) &&
					trim($band["shipping_multiple_" . $region_code . "_" . $band_id]) != "") {
					$details[$region_code] = array();
					$details[$region_code]["shipping_one"] = self::dec2($band["shipping_one_" . $region_code . "_" . $band_id]);
					$details[$region_code]["shipping_multiple"] = self::dec2($band["shipping_multiple_" . $region_code . "_" . $band_id]);
				}
			}
			if (count($details)) {
				$details["name"] = trim($band["name_" . $band_id]);
				$details["default"] = ($default == $band_id);
			}
		}
		return $details;
	}

	/**
	 * shipping forms for when a series of bands are used
	 * based on item weight$options["shipping_method"]
	 */
	public static function shipping_settings_form_weights()
	{
		$options = self::get_paypal_options();
		$regions = self::get_shipping_regions();
		if (!isset($options["shipping_settings"]) || !isset($options["shipping_settings"]["weights"]) || !count($options["shipping_settings"]["weights"])) {
			$weights = array();
			$empty_weight = array('to_weight_0' => '', "default" => 0);
			foreach ($regions as $region_code => $region_data) {
				$empty_weight["shipping_weight_" . $region_code . "_0"] = '';
			}
			$weights[] = $empty_weight;
		} else {
			$weights = $options["shipping_settings"]["weights"];
		}
		print('<div id="shipping-settings"><div id="shipping-weights">');
		for ($i = 0; $i < count($weights); $i++) {
			printf('<fieldset class="shipping_weight" data-weight-id="%d" id="weight_%d"><input type="hidden" name="sppp_options[shipping_settings][weight_ids][]" value="%s" />', $i, $i, $i);
			printf('<p><label for="pp_to_weight_%d">Up to and including items weighing: </label><input type="text" name="sppp_options[shipping_settings][weight][to_weight_%d]" id="pp_to_weight_%d" value="%s" size="5" />g</p>', $i, $i, $i, $weights[$i]["to_weight"]);
			foreach ($regions as $region_code => $region_data) {
				$val = self::dec2($weights[$i]["shipping_weight_" . $region_code]);
				printf('<p><label for="pp_shipping_weight_%s_%d">%s</label><input type="text" name="sppp_options[shipping_settings][weight][shipping_weight_%s_%d]", id="pp_shipping_weight_%s_%d" value="%.02f" size="7" class="currency" /></p>', $region_code, $i, $region_data["name"], $region_code, $i, $region_code, $i, $weights[$i][$region_code]);
			}
			printf('<p class="delete-weight" id="delete-button-%d"><a href="#" class="delete-weight-button button-secondary" data-weight-id="%d">delete this setting</a></p></fieldset>', $i, $i);
		}
		print('</div><p><a class="button-secondary" id="add-weight">Add a new weight range</a></p></div>');
	}

	/**
	 * validates a single shipping weight
	 */
	public static function validate_shipping_weight($weight, $weight_id)
	{
		$details = array();
		if (isset($weight["to_weight_" . $weight_id]) && intval($weight["to_weight_" . $weight_id]) > 0) {
			$regions = self::get_shipping_regions();
			$region_prices = array();
			foreach ($regions as $region_code => $region_data) {
				if (isset($weight["shipping_weight_" . $region_code . "_" . $weight_id]) && 
					trim($weight["shipping_weight_" . $region_code . "_" . $weight_id]) != "") {
					$region_prices[$region_code] = intval($weight["shipping_weight_" . $region_code . "_" . $weight_id]);
				}
			}
			if (count($region_prices) == count($regions)) {
				return array("to_weight" => $weight["to_weight_" . $weight_id], "regions" => $region_prices);
			} else {
				return array();
			}
		}
	}

	/**
	 * gets supported shipping methods
	 */
	public static function get_shipping_methods()
	{
		return array(
			"bands" => "postage bands", 
			"weights" => "item weights"
		);
	}

	/**
	 * validates options
	 */
	public static function validate_options($options)
	{
		$defaults = self::get_paypal_options();
		if (!isset($options["paypal_sandbox"])) {
			if (!is_email($options['paypal_email'])) {
				add_settings_error('paypal_email', 'email-invalid', "The paypal email address appears to be invalid", "error");
				$options['paypal_email'] = "";
			}
			if (!is_email($options["paypal_ipn_email"])) {
				add_settings_error('paypal_ipn_email', 'ipnemail-invalid', "The IPN notification email address appears to be invalid", "error");
				$options['paypal_ipn_email'] = "";
			}
		}
		if (isset($options["paypal_sandbox"]) && !is_email($options['paypal_sandbox_email'])) {
			add_settings_error('paypal_sandbox_email', 'sandbox-email-invalid', "The paypal sandbox email address appears to be invalid", "error");
			$options['paypal_sandbox_email'] = '';
		}
		if (!in_array($options['paypal_currency'], array_keys(self::get_supported_currencies()))) {
			add_settings_error('paypal_currency', 'invalid-currency', "Please select the currency you will be using");
			$options["paypal_currency"] = $defaults["paypal_currency"];
		}
		if (get_page($options["cart_page_id"]) == null) {
			add_settings_error('cart_page_id', 'cart-page-id', "Please specify the page which you are using as the cart/shopping basket");
		}
		if (isset($options["supported_post_types"]) && count($options["supported_post_types"])) {
			$options["supported_post_types"] = implode(",",$options["supported_post_types"]);
		} else {
			add_settings_error('supported_post_types', 'supported_post_types', "Please specify which post types will be using the plugin");
		}
		if (intval($options["vat_rate"]) < 0 || intval($options["vat_rate"]) >= 100) {
			add_settings_error('vat_rate', "vat_rate", "Please specify a VAT rate between 0 and 99");
		} else {
			$options["vat_rate"] = self::dec2($options["vat_rate"]);
		}
		$methods = self::get_shipping_methods();
		if (isset($options["shipping_method"]) && in_array($options["shipping_method"], array_keys($methods))) {
			$bands = array();
			$default = isset($options["shipping_settings"]["default_band"])? $options["shipping_settings"]["default_band"]: false;
			if (isset($options["shipping_settings"]["band_ids"]) && is_array($options["shipping_settings"]["band_ids"])) {
				foreach(array_unique($options["shipping_settings"]["band_ids"]) as $band_id) {
					$band = self::validate_shipping_band($options["shipping_settings"]["band"], $band_id, $default);
					if (!empty($band)) {
						$bands[] = $band;
					}
				}
			} else {
				$bands = $defaults["shipping_settings"]["bands"];
			}
			usort($bands, array(__CLASS__, 'order_shipping_bands'));
			$options["shipping_settings"]["bands"] = $bands;
			unset($options["shipping_settings"]["band_ids"]);
			unset($options["shipping_settings"]["band"]);
			unset($options["shipping_settings"]["default_band"]);

			$weights = array();
			if (isset($options["shipping_settings"]["weight_ids"]) && is_array($options["shipping_settings"]["weight_ids"])) {
				foreach(array_unique($options["shipping_settings"]["weight_ids"]) as $weight_id) {
					$weight = self::validate_shipping_weight($options["shipping_settings"]["weight"], $weight_id);
					if (!empty($weight)) {
						$weights[] = $weight;
					}
				}
			} else {
				$weights = $defaults["shipping_settings"]["weights"];
			}
			usort($weights, array(__CLASS__, 'order_shipping_weights'));
			$options["shipping_settings"]["weights"] = $weights;
			unset($options["shipping_settings"]["weight_ids"]);
			unset($options["shipping_settings"]["weight"]);
		} else {
			add_settings_error('shipping_method', 'shipping_method', 'Please specify a shipping method');
		}

		$options["allow_pickup"] = isset($options["allow_pickup"]);
		$options["pickup_address"] = trim($options["pickup_address"]);
		if ($options["allow_pickup"] && $options["pickup_address"] == "") {
			add_settings_error('pickup_address', 'pickup_address', "Please specify a pick-up address if you are going to allow this option");
			$options["allow_pickup"] = false;
		}
		$options["enqueue_js"] = isset($options["enqueue_js"]);
		$options["enqueue_css"] = isset($options["enqueue_css"]);
		return $options;
	}

	/**
	 * formats a number to two decimal places
	 */
	private static function dec2($n)
	{
		$nc = floor(floatval($n) * 100);
		return sprintf('%0.2f', ($nc / 100));
	}

	/**
	 * puts shipping bands in a logical order
	 */
	public static function order_shipping_bands($a, $b)
	{
		if ($a['uk']["shipping_one"] == $b['uk']["shipping_one"]) {
			return 0;
		}
		return ($a['uk']["shipping_one"] > $b['uk']["shipping_one"])? -1: 1;
	}

	/**
	 * puts shipping weights in a logical order
	 */
	public static function order_shipping_weights($a, $b)
	{
		if ($a["to_weight"] == $b["to_weight"]) {
			return 0;
		}
		return ($a["to_weight"] > $b["to_weight"])? -1: 1;
	}

	/**
	 * gets default options
	 */
	public static function get_paypal_options()
	{
		$defaults = array(
			"paypal_email" => "",
			"paypal_url" => "https://www.paypal.com/cgi-bin/webscr",
			"paypal_sandbox_email" => "",
			"paypal_sandbox_url" => "https://www.sandbox.paypal.com/cgi-bin/webscr",
			"paypal_currency" => "GBP",
			"Company Name" => "",
			"invoice_address" => "",
			"invoice_footer" => "",
			"invoice_logo_path" => "",
			"supported_post_types" => "",
			"vat_rate" => "20",
			"allow_pickup" => false,
			"pickup_address" => "",
			"shipping_method" => "bands",
			"shipping_settings" => array()
		);
		$options = get_option("sppp_options");
		if (isset($options["cart_page_id"])) {
			$options["cart_url"] = get_permalink($options["cart_page_id"]);
		} else {
			$options["cart_url"] = false;
		}
		$options["use_sandbox"] = (isset($options["paypal_sandbox"]));
		$opts = wp_parse_args($options, $defaults);
		$shipping_methods = self::get_shipping_methods();
		foreach (array_keys($shipping_methods) as $method) {
			if (!isset($options["shipping_settings"][$method])) {
				$options["shipping_settings"][$method] = array();
			}
		}
		return $options;
	}

	public static function get_payment_details($options)
	{
		global $wpdb;
		$tablename = $wpdb->prefix . "payments";
		if (isset($options['ipn_id'])) {
			$payment_details = $wpdb->get_row("SELECT * FROM $tablename WHERE ipn_id = " . $options["ipn_id"]);
			if (is_wp_error($payment_details) || !$payment_details) {
				return false;
			} else {
				$payment_details->ipn = unserialize($payment_details->payment_ipn);
				if (!is_array($payment_details->ipn)) {
					$payment_details->ipn = array();
				}
				return $payment_details;
			}
		} else {
			$limit = isset($options["limit"])? $options["limit"]: '';
			$query = "SELECT * FROM $tablename ORDER BY `payment_date` DESC " . $limit;
			$payment_details = $wpdb->get_results($query);
			if (is_wp_error($payment_details)) {
				return false;
			} else {
				$payments = array();
				foreach($payment_details as $payment) {
					$payment->ipn = unserialize($payment->payment_ipn);
					if (!is_array($payment->ipn)) {
						$payment->ipn = array();
					}
					$payments[] = $payment;
				}
				return $payments;
			}

		}
	}

	/**
	 * paypal payments page
	 */
	function paypal_payments_page()
	{
		printf('<div class="wrap"><div class="icon32"><img src="%s"/></div>', plugins_url('img/paypal-icon.png', __FILE__));
		if (isset($_REQUEST["sppp-action"]) && isset($_REQUEST["sppp-id"])) {
			print('<h2>Paypal payment details</h2>');
			$options = self::get_paypal_options();
			$payment_details = self::get_payment_details(array('ipn_id' => $_REQUEST["sppp-id"]));
			$msg = '';
			if ($payment_details) {
				//print('<pre>' . print_r($payment_details, true) . '</pre>');
				switch ($_REQUEST["sppp-action"]) {
					case "sendPDF":
						$invoice_path = self::get_invoice_path($_REQUEST["sppp-id"]);
						$success = false;
						if ($invoice_path === false) {
							if (false !== self::generatePDF($_REQUEST["sppp-id"])) {
								if (self::send_PDF_invoice($_REQUEST["sppp-id"])) {
									$success = true;
								}
							}
						} else {
							if (self::send_PDF_invoice($_REQUEST["sppp-id"])) {
								$success = true;								
							}
						}
						if (!$success) {
							$msg .= '<div class="updated"><p>Invoice could not be sent</p></div>';
						} else {
							$msg .= '<div class="updated"><p>Invoice sent</p></div>';
							$payment_details = self::get_payment_details(array('ipn_id' => $_REQUEST["sppp-id"]));
						}
						break;
				}
				print($msg);
				print('<pre>' . print_r($payment_details, true) . '</pre>');
				print self::get_payment_html($payment_details);
				print('<p>');
				printf('<a href="%s" class="button-secondary" style="margin-right:1em;">View PDF invoice</a>', admin_url('admin.php?page=paypal-payments&amp;sppp-action=downloadPDF&amp;sppp-id=' . $payment_details->ipn_id));
				$buttontxt = ($payment_details->invoice_sent)? "Re-send PDF invoice": "Send PDF invoice";
				printf('<a href="%s" class="button-primary" style="margin-right:1em;">%s</a><a href="%s" class="button-secondary">go back</a></p>', admin_url('admin.php?page=paypal-payments&amp;sppp-action=sendPDF&amp;sppp-id=' . $payment_details->ipn_id), $buttontxt, admin_url('admin.php?page=paypal-payments'));
			} else {
				print('<p>Sorry, could not find any details of this payment.</p>');
			}

		} elseif (isset($_REQUEST["sppp-action"]) && $_REQUEST["sppp-action"] == "report") {
			/* report of sold items */
			$payments = self::get_payment_details();
			$allitems = array();
			foreach ($payments as $payment) {
				$items = array();
				$item_number = 1;
				while(isset($payment->ipn["item_name" . $item_number])) {
					if (!isset($allitems[$payment->ipn["item_name" . $item_number]])) {
						$allitems[$payment->ipn["item_name" . $item_number]] = 0;
					}
					$allitems[$payment->ipn["item_name" . $item_number]]++;
					$items[] = $payment->ipn["item_name" . $item_number];
					$item_number++;
				}
			}
			print('<h2>Sales summary</h2><table summary="sales summary" class="widefat"><thead><th>item name</th><th>number sold</tr></thead><tbody>');
			foreach ($allitems as $item_name => $count) {
				printf('<tr><td>%s</td><td>%s</td></tr>', $item_name, $count);
			}
			print('</tbody></table>');
		} else {
			$per_page = isset($_REQUEST["sppp-perpage"])? intval($_REQUEST["sppp-perpage"]): 20;
			$current_page = isset($_REQUEST["sppp-page"])? intval($_REQUEST["sppp-page"]): 1;
			$filters = array("all", "sent", "unsent");
			$filter = (isset($_REQUEST["sppp-filter"]) && in_array($_REQUEST["sppp-filter"], $filters))? $_REQUEST["filter"]: false;
			$limit = " LIMIT " . (($current_page - 1) * $per_page) . ',' . $per_page;
			$payments = self::get_payment_details(array('limit' => $limit));
			$allitems = array();
			$rows = array();
			$invoices_sent = 0;
			$total = 0;
			//echo '<pre>' . print_r($payments, true) . '</pre>';
			foreach ($payments as $payment) {
				$name = $payment->ipn["first_name"]. " " . $payment->ipn["last_name"];
				$email = $payment->ipn["payer_email"];
				if (intval($payment->invoice_sent) > 0) {
					$invoice_sent = date("d/m/Y", intval($payment->invoice_sent));
					$invoices_sent++;
				} else {
					$invoice_sent = '-';
				}
				$rows[] = sprintf('<tr><td valign="top">%s</td><td valign="top">%s</td><td valign="top"><a href="mailto:%s">%s</a></td><td valign="top"><strong>&pound;%s</strong></td><td>%s</td><td><a href="%s" class="button-primary">Send Invoice</a></td><td><a href="%s" target="pdf" class="button-secondary">View invoice</a></td><td><a href="%s" class="button-secondary">view details</a></td></tr>', date("d/m/Y", $payment->payment_date), $name, $email, $email, $ipn["mc_gross"], $invoice_sent, admin_url('admin.php?page=paypal-payments&amp;sppp-action=sendPDF&amp;sppp-id=' . $payment->ipn_id), admin_url('admin.php?page=paypal-payments&amp;sppp-action=viewPDF&amp;sppp-id=' . $payment->ipn_id), admin_url('admin.php?page=paypal-payments&amp;sppp-action=viewIPN&amp;sppp-id=' . $payment->ipn_id));
				$total += floatval($payment->ipn["mc_gross"]);
			}
			$controls = '<div class="paypal-payments-controls">';

			print('<h2>Paypal payments</h2>');

			$headers = '<th>Date</th><th>Name</th><th>email</th><th>amount</th><th>invoice sent</th><th colspan="3">&nbsp;</th></tr>';
			printf('<table summary="paypal payments" class="widefat"><thead>%s</thead><tfoot>%s</tfoot><tbody>%s</tbody></table></div>', $headers, $headers, implode('', $rows));
			//print('<p>Total income: &pound' . self::dec2($total) . '</p>');
		}
	}

	public static function get_payment_html($payment_details)
	{
		print('<table class="payment-details"><tbody>');
		printf('<tr><th scope="row">Name:</th><td>%s %s</td></tr>', $payment_details->ipn["first_name"], $payment_details->ipn["last_name"]);
		printf('<tr><th scope="row">Email:</th><td><a href="mailto:%s">%s</a></td></tr>', $payment_details->ipn["payer_email"], $payment_details->ipn["payer_email"]);
		printf('<tr><th valign="top" scope="row">Address:</th><td>%s<br />%s<br />%s<br />%s<br />%s<br />%s</td></tr>', $payment_details->ipn["address_name"], $payment_details->ipn["address_street"], $payment_details->ipn["address_city"], $payment_details->ipn["address_state"], $payment_details->ipn["address_zip"], $payment_details->ipn["address_country"]);
		printf('<tr><th scope="row">Payment date:</th><td valign="top">%s</td></tr>', date("d/m/Y", $payment_details->payment_date));
		printf('<tr><th scope="row">Invoice No:</th><td valign="top">%06d</td></tr>', $payment_details->ipn_id);
		print('</tbody></table>');
		$items = array();
		$item_number = 1;
		while(isset($payment_details->ipn["item_name" . $item_number])) {
			$items[] = array(
				"name" => $payment_details->ipn["item_name" . $item_number],
				"number" => $payment_details->ipn["item_number" . $item_number],
				"url" => get_permalink($payment_details->ipn["item_number" . $item_number]),
				"price" => $payment_details->ipn["mc_gross_" . $item_number],
				"quantity" => $payment_details->ipn["quantity" . $item_number]
			);
			$item_number++;
		}
		if (count($items)) {
			print('<h3>Items in this order</h3>');
			print('<table class="widefat item-details"><thead><tr><th>Item</th><th>Quantity</th><th class="price">Price</th></tr></thead><tbody>');
			foreach ($items as $item) {
				printf('<tr><td><a href="%s">%s</a></td><td>%s</td><td class="price">&pound;%s</td></tr>', $item["url"], $item["name"], $item["quantity"], $item["price"]);
			}
			printf('<tr><th scope="row" colspan="2" class="total">Sub-total</th><td class="total">&pound;%0.2f</td></tr>', ($payment_details->ipn["mc_gross"] - $payment_details->ipn["mc_shipping"]));
			printf('<tr><th scope="row" colspan="2" class="total">Shipping:</th><td class="total">&pound;%0.2f</td></tr>', $payment_details->ipn["mc_shipping"]);
			printf('<th scope="row" colspan="2" class="total">Total:</th><td class="total">&pound;%0.2f</td></tr>', $payment_details->ipn["mc_gross"]);
			print('</tbody></table>');
		} else {
			print('<p>There are no details available for this transaction.</p>');
		}

	}

	/**
	 * gets the filesystem path for an invoice
	 */
	private static function get_invoice_path($ipn_id)
	{
		$uploads_dir = wp_upload_dir();
		$path = sprintf("%s/sppp-invoices/invoice-%06d.pdf", $uploads_dir["basedir"], $ipn_id);
		return $path;
	}

	/**
	 * gets an invoice for the sale based on IPN data
	 */
	private static function get_invoice_url($ipn_id)
	{
		if (file_exists(self::get_invoice_path($ipn_id))) {
			$uploads_dir = wp_upload_dir();
			$url = sprintf("%s/sppp-invoices/invoice-%06d.pdf", $uploads_dir["baseurl"], $ipn_id);
			return $url;
		} else {
			return false;
		}
	}


	/**
	 * generates (if neccessary) and outputs the PDF invoice
	 * triggered in the 'parse_request' action
	 */
	public static function downloadPDF()
	{
		if (isset($_REQUEST["sppp-action"]) && $_REQUEST["sppp-action"] == "downloadPDF") {

			$invoice_path = self::get_invoice_path($_REQUEST["sppp-id"]);
			if (1 || !file_exists($invoice_path)) {
				if (false === self::generatePDF($_REQUEST["sppp-id"])) {
					$invoice_path = false;
				}
			}
			if ($invoice_path !== false) {
				/* clear output buffers */
				while (ob_get_contents()) {
					ob_end_clean();
				}
				header("Content-type: application/pdf");
				header("Content-length: " . filesize($invoice_path));
				header('Content-Disposition: inline; filename="' . basename($invoice_path) . '"');
				header('Cache-Control: private, max-age=0, must-revalidate');
				header('Pragma: public');
				readfile($invoice_path);
				exit;
			}
		}
	}

	/**
	 * sends the PDF invoice as a file attachment to the buyer
	 * compiles the email using the configured options and an HTML
	 * version of the order. Updates the payments table.
	 */
	public static function send_PDF_invoice($ipn_id)
	{
		$options = self::get_paypal_options();
		$payment_details = self::get_payment_details(array('ipn_id' => $ipn_id));
		$path = self::get_invoice_path($ipn_id);
		if ($payment_details) {
			if (!file_exists($path)) {
				self::generatePDF();
			}
			if (file_exists($path)) {
				$to_addr = $payment_details->ipn["payer_email"];
				$name = $payment_details->ipn["first_name"]. " " . $payment_details->ipn["last_name"];
				if (trim($name) != "") {
					$to_addr = trim($name) . ' <' . $to_addr . '>';
				}
				$from_addr = $options["company_name"] . ' <' . $options["paypal_email"] . '>';
				$from_name = $options["company_name"];
				$subject = sprintf('%s [%04d]', $options["invoice_subject"], $payment_details->ipn_id);
				$message = str_replace('{{PP_ITEMS}}', self::get_payment_html($payment_details), $options["invoice_message"]);
				$headers = array("bcc" => $from_addr);

				$log = true;
				if ($log) {
					$uploads_dir = wp_upload_dir();
					$logfile = $uploads_dir["basedir"] . "/sppp-invoices/mail-log.txt";
					if ($fh = fopen($logfile, "wb")) {
						$log = sprintf("======\n\nSubject: %s\n\nMessage:\n%s\n\nAttachment: %s\n\n=======", $subject, $message, $path);
						fwrite($fh, $log);
						fclose($fh);
						global $wpdb;
						$tablename = $wpdb->prefix . "payments";
						$query = "UPDATE $tablename SET `invoice_sent` = UNIX_TIMESTAMP() WHERE `ipn_id` = $ipn_id";
						$wpdb->query($query);
						return true;
					}
				} else {
					self::apply_mail_filters();
					if (wp_mail($to_addr, $subject, $message, $headers, array($path))) {
						global $wpdb;
						$tablename = $wpdb->prefix . "payments";
						$query = "UPDATE $tablename SET `invoice_sent` = UNIX_TIMESTAMP() WHERE `ipn_id` = $ipn_id";
						$wpdb->query($query);
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * sets up filters for the wp_mail function
	 */
	public static function apply_mail_filters()
	{
		add_filter( 'wp_mail_from', array(__CLASS__, 'email_from_addr') );
		add_filter( 'wp_mail_from_name', array(__CLASS__, 'email_from_name') );
		add_filter( 'wp_mail_content_type', array(__CLASS__, 'email_content_type') );
	}
	
	/**
	 * used by the wp_mail_from filter
	 */
	public static function email_from_addr($addr)
	{
		$options = self::get_paypal_options();
		return $options["paypal_email"];
	}

	/**
	 * used by the wp_mail_from filter
	 */
	public static function email_from_name($name)
	{
		$options = self::get_paypal_options();
		return $options["company_name"];
	}

	/**
	 * used by the wp_mail_content_type filter
	 */
	public static function email_content_type($type)
	{
		return 'text/html';
	}
	/**
	 * generates a PDF invoice
	 */
	public static function generatePDF($ipn_id)
	{
		$path = self::get_invoice_path($ipn_id);
		if (file_exists($path)) {
			@unlink($path);
		}
		$options = self::get_paypal_options();
		$payment_details = self::get_payment_details(array('ipn_id' => $ipn_id));
		require_once(dirname(__FILE__) . '/lib/invoicePDF.php');
		$pdf = new invoicePDF($options, $path, $payment_details);
		$pdf->save();
	}

	/**
	 * gets supported paypal currencies
	 * @see https://cms.paypal.com/uk/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_currency_codes
	 */
	public static function get_supported_currencies()
	{
		$currencies_supported = array(
			"AUD" => "Australian Dollar",
			"CAD" => "Canadian Dollar",
			"CZK" => "Czech Koruna",
			"DKK" => "Danish Krone",
			"EUR" => "Euro",
			"HKD" => "Hong Kong Dollar",
			"HUF" => "Hungarian Forint",
			"ILS" => "Israeli New Sheqel",
			"JPY" => "Japanese Yen",
			"MXN" => "Mexican Peso",
			"NOK" => "Norwegian Krone",
			"NZD" => "New Zealand Dollar",
			"PHP" => "Philippine Peso",
			"PLN" => "Polish Zloty",
			"GBP" => "Pound Sterling",
			"SGD" => "Singapore Dollar",
			"SEK" => "Swedish Krona",
			"CHF" => "Swiss Franc",
			"TWD" => "Taiwan New Dollar",
			"THB" => "Thai Baht",
			"USD" => "U.S. Dollar"
		);
		return $currencies_supported;
	}	

	/**
	 * gets the correct symbol for the 	currency used
	 */
	public static function get_currency_symbol($currency_code)
	{
		switch($currency_code) {
			case "GBP":
				return "£";
				break;
			case "EUR":
				return "€";
				break;
			case "ILS":
				return "₪";
				break;
			case "JPY":
				return "¥";
				break;
			case "PHP":
				return "₱";
				break;
			case "THB":
				return "฿";
				break;
			case "PLN"://check
				return "Z";
				break;
			case "HUF"://check
				return "F";
				break;
			case "CZK"://check
			case "DKK"://check
			case "SEK"://check
				return "K";
				break;
			case "MXN"://check
				return "P";
				break;
			default:
				return "$";
				break;
		}
	}	

	/**
	 * gets supported shipping regions
	 */
	public static function get_shipping_regions()
	{
		return array(
			'uk' => array(
				'name' => 'UK',
				'countries' => array(
					"GB" => "United Kingdom"
				)
			), 
			'eu' => array(
				'name' => 'Europe (EU)', 
				'countries' => array(
					"AT" => "Austria",
					"AX" => "Azores",
					"BI" => "Balearic Islands",
					"BE" => "Belgium",
					"BG" => "Bulgaria",
					"CS" => "Corsica",
					"CY" => "Cyprus",
					"CZ" => "Czech Republic",
					"DK" => "Denmark",
					"EE" => "Estonia",
					"FI" => "Finland",
					"FR" => "France",
					"DE" => "Germany",
					"GI" => "Gibraltar",
					"GR" => "Greece",
					"HU" => "Hungary",
					"IE" => "Irish Republic",
					"IT" => "Italy",
					"LV" => "Latvia",
					"LT" => "Lithuania",
					"LU" => "Luxembourg",
					"MI" => "Madeira",
					"MT" => "Malta",
					"MC" => "Monaco",
					"NL" => "Netherlands",
					"PL" => "Poland",
					"PT" => "Portugal",
					"RO" => "Romania",
					"SK" => "Slovakia",
					"SI" => "Slovenia",
					"ES" => "Spain",
					"SE" => "Sweden"
				)
			),
			'eo' => array(
				'name' => "Europe (non-EU)",
				'countries' => array(
					"AL" => "Albania",
					"AD" => "Andorra",
					"AM" => "Armenia",
					"AZ" => "Azerbaijan",
					"BY" => "Belarus",
					"BA" => "Bosnia Herzegovina",
					"CW" => "Canary Islands",
					"HR" => "Croatia",
					"FO" => "Faroe Islands",
					"GE" => "Georgia",
					"GL" => "Greenland",
					"IS" => "Iceland",
					"KZ" => "Kazakhstan",
					"XK" => "Kosovo",
					"KG" => "Kyrgyzstan",
					"LI" => "Liechtenstein",
					"MK" => "Macedonia",
					"MD" => "Moldova",
					"ME" => "Montenegro",
					"NO" => "Norway",
					"RU" => "Russia",
					"SM" => "San Marino",
					"RS" => "Serbia",
					"CH" => "Switzerland",
					"TJ" => "Tajikistan",
					"TR" => "Turkey",
					"TM" => "Turkmenistan",
					"UA" => "Ukraine",
					"UZ" => "Uzbekistan",
					"VA" => "Vatican City State"
				)
			),
			'row1' => array(
				'name' => 'World Zone 1',
				'countries' => array(
					"AF" => "Afghanistan",
					"DZ" => "Algeria",
					"AS" => "American Samoa",
					"AO" => "Angola",
					"AI" => "Anguilla",
					"AQ" => "Antarctica",
					"AG" => "Antigua & Barbuda",
					"AR" => "Argentina",
					"AW" => "Aruba",
					"BS" => "Bahamas",
					"BH" => "Bahrain",
					"BD" => "Bangladesh",
					"BB" => "Barbados",
					"BZ" => "Belize",
					"BJ" => "Benin",
					"BM" => "Bermuda",
					"BT" => "Bhutan",
					"BO" => "Bolivia",
					"BW" => "Botswana",
					"BV" => "Bouvet Island",
					"BR" => "Brazil",
					"BN" => "Brunei Darussalam",
					"BF" => "Burkina Faso",
					"BI" => "Burundi",
					"KH" => "Cambodia",
					"CM" => "Cameroon",
					"CA" => "Canada",
					"CV" => "Cape Verde",
					"KY" => "Cayman Is",
					"CF" => "Central African Republic",
					"TD" => "Chad",
					"CL" => "Chile",
					"CN" => "China, People's Republic of",
					"CO" => "Colombia",
					"KM" => "Comoros",
					"CG" => "Congo",
					"CD" => "Congo, Democratic Republic",
					"CR" => "Costa Rica",
					"CI" => "Cote d'Ivoire",
					"CU" => "Cuba",
					"DJ" => "Djibouti",
					"DM" => "Dominica",
					"DO" => "Dominican Republic",
					"TP" => "East Timor",
					"EC" => "Ecuador",
					"EG" => "Egypt",
					"SV" => "El Salvador",
					"GQ" => "Equatorial Guinea",
					"ER" => "Eritrea",
					"ET" => "Ethiopia",
					"FK" => "Falkland Islands",
					"GF" => "French Guiana",
					"GA" => "Gabon",
					"GM" => "Gambia",
					"GH" => "Ghana",
					"GD" => "Grenada",
					"GP" => "Guadeloupe",
					"GU" => "Guam",
					"GT" => "Guatemala",
					"GN" => "Guinea",
					"GW" => "Guinea-Bissau",
					"GY" => "Guyana",
					"HT" => "Haiti",
					"HM" => "Heard Island And Mcdonald Island",
					"HN" => "Honduras",
					"HK" => "Hong Kong",
					"IN" => "India",
					"ID" => "Indonesia",
					"IR" => "Iran",
					"IQ" => "Iraq",
					"IL" => "Israel",
					"JM" => "Jamaica",
					"JP" => "Japan",
					"JT" => "Johnston Island",
					"JO" => "Jordan",
					"KE" => "Kenya",
					"KP" => "Korea, Democratic Peoples Republic",
					"KR" => "Korea, Republic of",
					"KW" => "Kuwait",
					"LB" => "Lebanon",
					"LS" => "Lesotho",
					"LR" => "Liberia",
					"LY" => "Libyan Arab Jamahiriya",
					"MG" => "Madagascar",
					"MW" => "Malawi",
					"MY" => "Malaysia",
					"MV" => "Maldives",
					"ML" => "Mali",
					"MH" => "Marshall Islands",
					"MQ" => "Martinique",
					"MR" => "Mauritania",
					"MU" => "Mauritius",
					"YT" => "Mayotte",
					"MX" => "Mexico",
					"FM" => "Micronesia",
					"MN" => "Mongolia",
					"MS" => "Montserrat",
					"MA" => "Morocco",
					"MZ" => "Mozambique",
					"MM" => "Union Of Myanmar",
					"NA" => "Namibia",
					"NP" => "Nepal",
					"AN" => "Netherlands Antilles",
					"NI" => "Nicaragua",
					"NE" => "Niger",
					"NG" => "Nigeria",
					"MP" => "Mariana Islands, Northern",
					"OM" => "Oman",
					"PK" => "Pakistan",
					"PW" => "Palau Islands",
					"PS" => "Palestine",
					"PA" => "Panama",
					"PY" => "Paraguay",
					"PE" => "Peru",
					"PH" => "Philippines",
					"PR" => "Puerto Rico",
					"QA" => "Qatar",
					"RE" => "Reunion Island",
					"RW" => "Rwanda",
					"SH" => "St Helena",
					"KN" => "St Kitts & Nevis",
					"LC" => "St Lucia",
					"PM" => "St Pierre & Miquelon",
					"VC" => "St Vincent",
					"ST" => "Sao Tome & Principe",
					"SA" => "Saudi Arabia",
					"SN" => "Senegal",
					"SC" => "Seychelles",
					"SL" => "Sierra Leone",
					"SO" => "Somalia",
					"ZA" => "South Africa",
					"GS" => "South Georgia and South Sandwich",
					"LK" => "Sri Lanka",
					"SD" => "Sudan",
					"SR" => "Suriname",
					"SJ" => "Svalbard and Jan Mayen",
					"SZ" => "Swaziland",
					"SY" => "Syrian Arab Republic",
					"TW" => "Taiwan, Republic of China",
					"TZ" => "Tanzania",
					"TH" => "Thailand",
					"TL" => "Timor Leste",
					"TG" => "Togo",
					"TT" => "Trinidad & Tobago",
					"TN" => "Tunisia",
					"TC" => "Turks And Caicos Islands",
					"UG" => "Uganda",
					"AE" => "United Arab Emirates",
					"UM" => "US Minor Outlying Islands",
					"US" => "USA",
					"HV" => "Upper Volta",
					"UY" => "Uruguay",
					"VU" => "Vanuatu",
					"VE" => "Venezuela",
					"VN" => "Vietnam",
					"VG" => "Virgin Islands (British)",
					"VI" => "Virgin Islands (US)",
					"WF" => "Wallis And Futuna Islands",
					"EH" => "Western Sahara",
					"YE" => "Yemen Arab Rep.",
					"YD" => "Yemen Democratic",
					"ZR" => "Zaire",
					"ZM" => "Zambia",
					"ZW" => "Zimbabwe"
				)
			),
			'row2' => array(
				"name" => "World Zone 2",
				"countries" => array(
					"AU" => "Australia",
					"IO" => "British Indian Ocean Territory",
					"CX" => "Christmas Island",
					"CC" => "Cocos Islands",
					"CK" => "Cook Islands",
					"FJ" => "Fiji",
					"PF" => "French Polynesia",
					"TF" => "French South Territories",
					"KI" => "Kiribati",
					"LA" => "Lao People's Democratic Republic",
					"MO" => "Macau",
					"NR" => "Nauru Island",
					"NC" => "New Caledonia",
					"NZ" => "New Zealand",
					"NU" => "Niue",
					"NF" => "Norfolk Island",
					"PG" => "Papua New Guinea",
					"PN" => "Pitcairn",
					"WS" => "Samoa",
					"SG" => "Singapore",
					"SB" => "Solomon Islands",
					"TK" => "Tokelau",
					"TO" => "Tonga",
					"TV" => "Tuvalu"
				)
			)
		);
	}
}
SimplePayPalPluginAdmin::register();
