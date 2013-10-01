<?php
/*
Plugin Name: Simple PayPal Plugin
Plugin URI: http://p-2.biz/plugins/paypal
Description: A plugin to enable PayPal purchases on a wordpress site
Version: 1.2
Author: Peter Edwards <Peter.Edwards@p-2.biz>
Author URI: http://p-2.biz
License: GPL2
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

require_once(dirname(__FILE__) . '/simple-paypal-plugin-admin.php');

class SimplePayPalPlugin
{
	/* track IDs of elements on the page */
	public static $instance_id = 0;


	/**************************
	 * PLUGIN CONFIGURATION   *
	 **************************/

	/**
	 * registers the plugin with the wordpress API
	 */
	public static function register()
	{
		/* run upgrade routine */
		self::upgrade();
		/* start session */
		add_action("plugins_loaded", array(__CLASS__, "setup_session"), 1);
		/* process any POSTed content */
		add_action("plugins_loaded", array(__CLASS__, "process_requests"), 2);
		/* registers activation hook */
		register_activation_hook(__FILE__, array(__CLASS__, 'install'));
		/* registers deactivation hook */
		register_deactivation_hook(__FILE__, array(__CLASS__, 'uninstall'));
		
		/* add meta boxes to posts */
		add_action( 'add_meta_boxes', array(__CLASS__, 'add_custom_paypal_box') );
		add_action( 'save_post', array(__CLASS__, 'save_custom_paypal_box') );

		/* SHORTCODES */
		/* shortcode for link to basket */
		add_shortcode("basket_link", array(__CLASS__, "get_basket_link"));
		/* shortcode for basket */
		add_shortcode("basket", array(__CLASS__, "get_shopping_basket"));
		/* shortcode for add to basket button */
		add_shortcode("paypal_button", array(__CLASS__, "paypal_button_shortcode"));
		/* ajax product enquiry form */
		add_action("wp_ajax_enquiryform", array(__CLASS__, "process_enquiry_form_ajax"));
		add_action("wp_ajax_nopriv_enquiryform", array(__CLASS__, "process_enquiry_form_ajax"));
		/* add scripts and CSS to front end */
		add_action("wp_enqueue_scripts", array(__CLASS__, "enqueue_scripts"));
		
	}
	
	/**
	 * activation hook
	 */
	public static function install()
	{
		global $wpdb;
		$tablename = $wpdb->prefix . "payments";
		$sql = "CREATE TABLE IF NOT EXISTS `" . $tablename . "` (`ipn_id` int(11) NOT NULL AUTO_INCREMENT, `payment_date` int(11) NOT NULL DEFAULT '0',`payment_ipn` text NOT NULL DEFAULT '', `invoice_sent` INT(11) NOT NULL DEFAULT '0', `txn_id` VARCHAR(255) NOT NULL DEFAULT '', `txn_type` VARCHAR(255)  NOT NULL DEFAULT '', `mc_gross` VARCHAR(255)  NOT NULL DEFAULT '') ENGINE=MyISAM DEFAULT CHARSET=utf8;";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		$defaults = SimplePayPalPluginAdmin::get_paypal_options();
		update_option('sppp_options', $defaults);
	}
	
	/**
	 * deactivation hook
	 */
	public static function uninstall()
	{
		global $wpdb;
		$tablename = $wpdb->prefix . "payments";
		$sql = "DROP TABLE IF EXISTS `" . $tablename . "`;";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
		delete_option('sppp_options');
		delete_option('sppp_version');
	}

	/**
	 * upgrade from previous versions
	 */
	public static function upgrade()
	{
		$currentversion = get_option('sppp_version');
		if ($currentversion != SimplePayPalPluginAdmin::$plugin_version) {
			switch ($currentversion) {
				case false:
					/* upgrade to version 1.0 */
					global $wpdb;
					$tablename = $wpdb->prefix . "payments";
					$query = "ALTER TABLE `$tablename` ADD `invoice_sent` INT(11) NOT NULL DEFAULT '0';";
					$wpdb->query($query); 
					$query = "ALTER TABLE  `$tablename` ADD  `ipn_id` INT( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST ;";
					$wpdb->query($query);
				case "1.0":
					/* upgrade routine from version 1.0 to 1.1 */
					$all_pages = get_posts(array(
						"numberposts" => -1,
						"nopaging" => true,
						"post_type" => "any",
						"post_status" => "publish"
					));
					if (count($all_pages)) {
						foreach ($all_pages as $post) {
							$linkmeta = get_post_meta($post->ID, "links", true);
				    		delete_post_meta($post->ID, "links");
				    		$media =& get_children('post_type=attachment&orderby=menu_order&order=ASC&post_parent=' . $post->ID);
						    $files = array();
						    if (is_array($media) && count($media)) {
				        		foreach ($media as $id => $att) {
				    	    		if (substr($att->post_mime_type, 0, 5) != "image") {
				    		        	$files[] = $att;
				    		        }
				    	        }
				            }
				            $links = ($linkmeta && trim($linkmeta) != '')? explode("\n", $linkmeta): array();
				            $paypal = self::get_paypal_meta($post->ID);
				   			if (count($links) || count($files)) {
				   				$paypal["description"] .= "<ul>";
				   				if (count($links)) {
					        		for ($i = 0; $i < count($links); $i++) {
						               	if (substr($links[$i], 0, 4) == "http") {
					    	           		$parts = explode(" ", $links[$i], 2);
					    		    		$url = $parts[0];
					    		            $text = (isset($parts[1]) && trim($parts[1]) != "")? trim($parts[1]): $url;
							    			$paypal["description"] .= sprintf('<li><a href="%s" title="%s">%s</a></li>', htmlentities($url), $text, $text);
					    				}
				    	    		}
						        }
					        	if (count($files)) {
				    	        	foreach ($files as $post) {
				        	        	$paypal["description"] .= sprintf('<li><a href="%s" title="%s">%s</a></li>', get_permalink($post->ID), esc_attr($post->post_title), $post->post_title);
				            	    }
				            	}
						    	$paypal["description"] .= "</ul>";
				    		}
				    	}
				    }
				case "1.1":
					/* upgrade routine from 1.1 to 1.2 */
					global $wpdb;
					$tablename = $wpdb->prefix . "payments";
					$query = "ALTER TABLE `$tablename` ADD `txn_id` VARCHAR(255) NOT NULL DEFAULT '';";
					$wpdb->query($query); 
					$query = "ALTER TABLE `$tablename` ADD  `txn_type` VARCHAR(255)  NOT NULL DEFAULT '';";
					$wpdb->query($query);
					$query = "ALTER TABLE `$tablename` ADD  `mc_gross` VARCHAR(255)  NOT NULL DEFAULT '';";
					$wpdb->query($query);

			}
			update_option('sppp_version', SimplePayPalPluginAdmin::$plugin_version);
		}
	}

	/**
	 * adds script to front end
	 */
	public static function enqueue_scripts()
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		if ($options["enqueue_js"]) {
			wp_enqueue_script("paypl-plugin-js", plugins_url("js/paypal-plugin.js", __FILE__), array("jquery"), SimplePayPalPluginAdmin::$plugin_version, true);
		}
		if ($options["enqueue_css"]) {
			wp_enqueue_style('paypal-plugin-css', plugins_url("css/paypal-plugin.css", __FILE__));
		}
	}

	/**************************
	 * REQUEST/SESSION		*
	 **************************/

	/**
	 * sets up the session
	 */
	public static function setup_session()
	{
		if ( !session_id() ) {
			session_start();
		}
	}
	
	/**
	 * process any posted content
	 */
	public static function process_requests()
	{
		if (isset($_REQUEST["payment_date"])) {
			self::processIPN();
			exit;
		} 
		if (isset($_REQUEST['pp-addcart'])) {
			self::add_to_cart();
		}
		if (isset($_REQUEST['pp-cquantity'])) {
			self::update_quantities();
		}
		if (isset($_REQUEST['pp-pickup'])) {
			self::elect_pickup();
		}
		if (isset($_REQUEST['pp-delcart'])) {
			self::remove_from_cart();
		}
		if (isset($_REQUEST["merchant_return_link"])) {
			self::reset_cart();
		}
		if (isset($_REQUEST["mc_gross"]) && $_REQUEST["mc_gross"] > 0)  {
			self::reset_cart();
		}
	}
	

	/***********************
	 * ENQUIRY FORM	  	   *
	 ***********************/

	/**
	 * returns a enquiry form for a given product
	 */
	public static function get_enquiry_form($post)
	{
		/* use for ids of HTML elements */
		self::$instance_id++;
		/* get plugin options for form action */
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		/* get product properties */
		$paypal = self::get_paypal_meta($post->ID);
		$paypal["cart_url"] = get_permalink($options["cart_page_id"]);
		$formStr = sprintf('<div class="pp-enquiry-form-wrap"><form class="pp-enquiry-form" method="post" action="%s"><input type="hidden" name="action" value="enquiryform" />', $paypal["cart_url"]);
		$paypal["product_page_id"] = $post->ID;
		foreach ($paypal as $key => $val) {
			$formStr .= sprintf('<input type="hidden" name="pp_%s" id="pp_%s_%d" value="%s" />', $key, $key, self::$instance_id, esc_attr($val));	
		}
		$formStr .= '<h3>Ask a question about this item</h3>';
		$formStr .= sprintf('<p><label for="pp_sender_name_%d">name:</label><input id="pp_sender_name_%d" type="text" name="pp_sender_name" value="" /></p>', self::$instance_id, self::$instance_id);
		$formStr .= sprintf('<p><label for="pp_sender_email_%d">email:</label><input id="pp_sender_email_%d" type="text" name="pp_sender_email" id="sender_email" value="" /></p>', self::$instance_id, self::$instance_id);
		$formStr .= sprintf('<p><label for="pp_sender_message_%d">message:</label><br /><textarea name="pp_sender_message" id="pp_sender_message_%d" cols="20" rows="5"></textarea></p>', self::$instance_id, self::$instance_id);
		$formStr .= sprintf('<p><input type="submit" class="pp-submit-enquiry" id="pp_submit_enquiry_%d" data-ppinstance="%d" name="submit" value="Send enquiry" data-ajaxurl="%s"></p>', self::$instance_id, self::$instance_id, admin_url('admin-ajax.php'));
		$formStr .= '</form></div>';
		return $formStr;
	}

	/**
	 * processes ajax and normal product enquiry forms
	 */
	public static function process_enquiry_form()
	{
		$product_page_id = $_POST["pp_product_page_id"];
		$product_name = $_POST["pp_name"];
		$product_code = $_POST["pp_code"];
		$product_price = $_POST["pp_price"];
		$product_stock = $_POST["pp_stock"];
		if (is_email($_POST["pp_sender_email"])) {
			$options = SimplePayPalPluginAdmin::get_paypal_options();
			$email_subject = "Stock enquiry: " . $product_name;
			$email_message = "Stock enquiry for " . $product_name . "\n\nProduct code: " . $product_code . "\n\nProduct price: £" . $product_price . "\n\nStock level: " . $product_stock . "\n\n";
			$email_message .= "Sender name: " . trim(strip_tags($_POST["pp_sender_name"])) . "\n";
			$email_message .= "Sender email: " . $_POST["pp_sender_email"] . "\n\n";
			if (trim($_POST["pp_sender_message"]) != "") {
				$email_message .= "Message:\n\n" . trim(strip_tags($_POST["pp_sender_message"]));
			} else {
				$email_message .= "No message sent";
			}
			$email_from = (preg_replace("/[^a-zA-Z ]*/", "", trim($_POST["pp_sender_name"])) == "")? $_POST["pp_sender_email"]: preg_replace("/[^a-zA-Z ]*/", "", trim($_POST["pp_sender_name"])) . " <" . $_POST["pp_sender_email"] . ">";
			$headers = "From: " . $email_from . "\r\nReply-to:" . $email_from . "\r\n";
			/*$ud = wp_upload_dir();
			if ($fh = fopen($ud["basedir"] . '/email.log.txt', "ab")) {
				fwrite($fh, sprintf("email sent: %s\nTo: %s\nSubject: %s\nMessage:\n%s\n======\nHeaders:\n%s\n========\n\n", date("d/m/Y H:i:s"), $options["paypal_email"], $email_subject, $email_message, $headers));
				fclose($fh);
				return true;
			} else {
				return false;
			}*/
			if (wp_mail($options["paypal_email"], $email_subject, $email_message, $headers)) {
				/* send message to person making the enquiry */
				if (trim($options["enquiry_msg"]) != "") {
					$user_message = str_replace("{PP_NAME}", $product_name, $options["enquiry_msg"]);
					$headers = "From: " . $options["paypal_email"] . "\r\nReply-to:" . $options["paypal_email"] . "\r\n";
					wp_mail($_POST["pp_sender_email"], "Thank you for your enquiry", $user_message, $headers);
				}
				return true;
			}
		}
		return false;
	}
	
	/**
	 * handles ajax processing of enquiry form
	 */
	public static function process_enquiry_form_ajax()
	{
		$result = self::process_enquiry_form();
		echo ($result)? 1: 0;
		die();
	}

	/**
	 * handles regular processing of enquiry form
	 */
	public static function process_enquiry_form_post()
	{
		$result = self::process_enquiry_form();
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		$product_name = $_POST["pp_name"];
		if ($result) {
			if (trim($options["enquiry_msg"]) != "") {
				return "<p>" . str_replace(array("{PP_NAME}", "\n"), array($product_name, "</p><p>"), $options["enquiry_msg"]) . "</p>";
			} else {
				return "<p>Thanks for your enquiry</p><p>We will try to answer your questions as soon as possible.</p>";
			}
		} else {
			return "<p>Sorry&hellip;</p><p>There was a problem sending the message - please try to <a href=\"/contact\">contact us a different way</a>.</p>";
		}
	}

	/**************************
	 * SHOPPING BASKET		*
	 **************************
	
	/**
	 * adds an item to the cart
	 */
	public static function add_to_cart()
	{
		if (isset($_REQUEST['pp-addcart'])) {
			$in_basket = false;
			if (isset($_SESSION['sppp-cart'])) {  
				$products = $_SESSION['sppp-cart'];
			} else {
				$products = array();
			}
			if (isset($_REQUEST["pp-product_page_id"])) {
				$item = self::get_paypal_meta($_REQUEST["pp-product_page_id"]);
				if (self::can_be_purchased($item)) {
					if (count($products)) {
						foreach ($products as $key => $product) {
							if ($product["product_page_id"] == $_REQUEST["pp-product_page_id"]) {
								$in_basket = true;
								$stocklevel = $item["stock"];
								if ($product['quantity'] < $stocklevel) {
									$product['quantity']++;
								} else {
									$product['quantity'] = $stocklevel;
								}
								unset($products[$key]);
								array_push($products, $product);
							}
						}
					}
					if (!$in_basket) {
						$item["quantity"] = 1;
						$item["product_page_id"] = $_REQUEST["pp-product_page_id"];
						array_push($products, $item);
					}
					sort($products);
					$_SESSION['sppp-cart'] = $products;
				}
			}
		}
	}

	/**
	 * checks stock level for an item
	 */
	public static function get_item_stock($post_id)
	{
		$paypal = self::get_paypal_meta($post_id);
		return $paypal["stock"];
	}

	/**
	 * changes quantities of items in the cart
	 */
	public static function update_quantities()
	{
		/* make sure we are responding to the correct event */
		if (isset($_REQUEST['pp-cquantity'])) {
			/* get cart contents */
			if (isset($_SESSION['sppp-cart'])) {  
				$products = $_SESSION['sppp-cart'];
			} else {
				$products = array();
			}
			/* make sure we have a product page ID */
			if (isset($_REQUEST["pp-product_page_id"])) {
				/* get the product details */
				$item = self::get_paypal_meta($_REQUEST["pp-product_page_id"]);
				/* make sure it can be purchased */
				if (self::can_be_purchased($item)) {
					/* match item against basket contents */
					if (count($products)) {
						foreach ($products as $key => $product) {
							if ($product["product_page_id"] == $_REQUEST["pp-product_page_id"]) {
								/* update the quantity of the item in the basket */
								if (isset($_REQUEST["plus"])) {
									$newQuantity = $product["quantity"] + 1;
								} else {
									$newQuantity = $product["quantity"] - 1;
								}
								/* make sure we have stock to cover it */
								if ($newQuantity > $item["stock"]) {
									$newQuantity = $item["stock"];
								}
								/* update quantity */
								$product["quantity"] = $newQuantity;
								unset($products[$key]);
								if ($newQuantity > 0) {
									array_push($products, $product);
								}
								sort($products);
								$_SESSION['sppp-cart'] = $products;
							}
						}
					}
				}
			}
		}
	}
	
	/**
	 * removes an item from the cart
	 */
	public static function remove_from_cart()
	{
		if (isset($_REQUEST['pp-delcart'])) {
			/* make sure we have a product page ID */
			if (isset($_REQUEST["pp-product_page_id"])) {
				self::remove_from_cart_by_page_id($_REQUEST["pp-product_page_id"]);
			}
		}
	}

	/**
	 * removes an item from the cart using the product page ID
	 */
	private static function remove_from_cart_by_page_id($page_id = false)
	{
		if (!$page_id) {
			return;
		}
		/* get cart contents */
		if (isset($_SESSION['sppp-cart'])) {  
			$products = $_SESSION['sppp-cart'];
		} else {
			$products = array();
		}
		if (count($products)) {
			foreach ($products as $key => $item) {
				if ($item['product_page_id'] == $page_id) {
					unset($products[$key]);
					sort($products);
					$_SESSION['sppp-cart'] = $products;
					return;
				}
			}
		}
	}
	
	/**
	 * resets the cart
	 */
	public static function reset_cart()
	{
		$products = $_SESSION['sppp-cart'];
		foreach ($products as $key => $item) {
			unset($products[$key]);
		}
		$_SESSION['sppp-cart'] = $products;
	}

	/**
	 * user has chosen to pick items up
	 */
	private static function elect_pickup()
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		if (isset($options["allow_pickup"]) && $options["allow_pickup"]) {
			$_SESSION['user_pickup'] = ($_REQUEST['pp-pickup'] == "1")? true: false;
		}
	}
	

	/**************************
	 * SHORTCODES			 *
	 **************************/
	
	/**
	 * replaces paypal_button shortcode with a buy
	 * it now button or enquiry form
	 */
	function paypal_button_shortcode()
	{
		global $post;
		return self::get_paypal_button($post);
	}
		
	/**
	 * add to basket button for paypal shortcode
	 */
	function get_paypal_button($post)
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		/* make sure this button is on the correct page */
		$pts = explode(",", $options["supported_post_types"]);
		if (!in_array($post->post_type, $pts)) {
			return "";
		}
		$item = self::get_paypal_meta($post->ID);
		$out = "";
		/* see if the relevant fields have been filled in for the item */
		if (self::can_be_purchased($item)) {
			/* we have enough information for a paypal button - check stock level */
			if (isset($item["stock"]) && preg_match("/^[0-9]+$/", $item["stock"]) && intval($item["stock"]) > 0) {
				/* in stock with ($paypal["stock"] == number of items) */
				$out .= sprintf("<form method=\"post\" action=\"%s\" class=\"pp-add-form\">", get_permalink($options["cart_page_id"]));
				/*if (isset($paypal["shipping"])) {
					$out .= sprintf('<input type="hidden" name="pp-shipping" value="%s" />', $paypal["shipping"]);
				}
				$inc_vat = (isset($paypal["includes_vat"]) && $paypal["includes_vat"])? 1 : 0;
				$out .= sprintf('<input type="hidden" name="pp-includes_vat" value="%d" />', $inc_vat)*/
				$out .= sprintf('<input type="hidden" name="pp-product_page_id" value="%s" />', $post->ID);
				/*$out .= sprintf('<input type="hidden" name="pp-name" value="%s" />', $paypal["name"]);
				$out .= sprintf('<input type="hidden" name="pp-code" value="%s" />', $paypal["code"]);
				$out .= sprintf('<input type="hidden" name="pp-price" value="%s" />', $paypal["price"]);*/
				$out .= '<input type="hidden" name="pp-addcart" value="1" />';
				$out .= '<input type="submit" class="pp-button pp-add-button" name="submit" value="Add" title="Add item to your shopping basket" />';
				$out .= "</form>\n";
				$out .= sprintf('<p class="stocklevel">We currently have <span class="num">%s</span> in stock.</p>', $item["stock"]);
			} else {
				if (trim($item["stock"]) !== "" &&  $item["stock"] != "0") {
				   /* optional stock level description */
					$out .= '<p class="stocklevel">' . $item["stock"] . '</p>';
				}
				$out .= self::get_enquiry_form($post);
			}
		} else {
			$out .= self::get_enquiry_form($post);
		}
		return $out;	
	}

	/**
	 * get_shopping_basket
	 * used to return HTML for the shopping basket page
	 */
	function get_shopping_basket()
	{
		/* make sure we have all the required paypal options */
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		if ($options["cart_url"] == false) {
			return "";
		} 
		/* see if we are returning here from paypal */
		if (isset($_GET["merchant_return_link"])) {
			return $options["thanks_msg"];
		}
		/* see if we need to provide a contact form */
		if (isset($_GET["pid"])) {
			$product = get_post($_GET["pid"]);
			return self::get_enquiry_form($product);
		}
		/* see if we are coming from a non-AJAX enquiry form */
		if (isset($_REQUEST['action']) && $_REQUEST["action"] == "enquiryform") {
			return self::process_enquiry_form_post();
		}
		/* fields for item delivery address  */
		$address_fields = array(
			'name' => array('label' => 'Name', 'required' => true),
			'address1' => array('label' => 'Address', 'required' => true),
			'address2' => array('label' => '&nbsp;', 'required' => false),
			'address3' => array('label' => '&nbsp;', 'required' => false),
			'address4' => array('label' => '&nbsp;', 'required' => false),
			'country' => array('label' => 'Country', 'required' => true),
			'postcode' => array('label' => 'Postcode/Zip', 'required' => true),
		);
		/* shipping regions */
		$regions = SimplePayPalPluginAdmin::get_shipping_regions();
		/**
		 * shipping data is set in the session, including the (non-pickup)
		 * delivery address.
		 */
		if (!isset($_SESSION["shipping_data"])) {
			$_SESSION["shipping_data"] = array("total_weight" => 0, "bands" => array(), "errors" => array(), "delivery_method" => "post", "address" => array());
		}
		/* see if we are coming from the delivery address form */
		if (isset($_REQUEST['delivery_change'])) {
			/* change of delivery method */
			if (isset($_REQUEST["change_delivery_method_pickup"])) {
				$_SESSION["shipping_data"]["delivery_method"] = "pickup"; 
			}
			if (isset($_REQUEST["change_delivery_method_post"])) {
				$_SESSION["shipping_data"]["delivery_method"] = "post"; 
			}
			/* delivery address */
			if (isset($_REQUEST["save_delivery_address"]) || isset($_REQUEST["change_delivery_address"])) {
				/* validate form values */
				foreach ($address_fields as $name => $details) {
					if ($details["required"]) {
						/* ensure required fields are non-empty */
						if (!isset($_REQUEST["delivery_" . $name]) || trim($_REQUEST["delivery_" . $name]) == "") {
							$_SESSION["shipping_data"]["errors"][$name] = "This field is required";
							continue;
						}
						/* validate country field */
						if ($name == "country") {
							$country = false;
							foreach ($regions as $region_code => $region) {
								if (in_array($_REQUEST["delivery_country"], array_keys($region["countries"]))) {
									$country = $_REQUEST["delivery_country"];
								}
							}
							if (!$country) {
								$_SESSION["shipping_data"]["errors"]["country"] = "This field is required";
							} else {
								$_SESSION["shipping_data"]["address"]["country"] = trim($_REQUEST["delivery_country"]);
							}
							continue;
						}
						if (isset($_SESSION["shipping_data"]["errors"][$name])) {
							unset($_SESSION["shipping_data"]["errors"][$name]);
						}
						$_SESSION["shipping_data"]["address"][$name] = trim($_REQUEST["delivery_" . $name]);
					}
				}
			}
		}
		/* initialise variables to store output and track totals */
		$out = "";
		$total_items = 0;
		$total_price = 0;
		$masterForm = '';
		$total_vat = 0;
		$total_ex_vat = 0;
		if (isset($_SESSION['sppp-cart']) && is_array($_SESSION['sppp-cart']) && count($_SESSION['sppp-cart'])) {   
			$out .= "  <table class=\"cart\">\n";	
			$out .= "	<tr><th width=\"5%\"></th><th width=\"60%\" style=\"text-align:left\">item</th><th width=\"15%\" style=\"text-align:right\">quantity</th><th width=\"20%\" style=\"text-align:right\">price</th></tr>\n";
			$item_price_ex_vat = array();
			$item_has_more_stock = array();
  			foreach ($_SESSION['sppp-cart'] as $item) {
				$paypal = self::get_paypal_meta($item["product_page_id"]);
				/* make sure we have stock of this item */
				if (intval($paypal["stock"]) < $item["quantity"]) {
					if (intval($paypal["stock"]) == 0) {
						self::remove_from_cart_by_page_id($item["product_page_id"]);
						continue;
					} else {
						$item["quantity"] = $paypal["stock"];
					}
				}
				$item_has_more_stock["p" . $item["product_page_id"]] = ($item["quantity"] < $paypal["stock"]);
				/* get all totals and shipping */
				$total_price += floatval($item['price']) * intval($item['quantity']);
				/* add to weight total if weight is used to determine shipping cost */
				if ($options["shipping_method"] == "weights" && isset($item["shipping_weight"]) && intval($item["shipping_weight"]) > 0) {
					$_SESSION["shipping_data"]["total_weight"] += (intval($item["shipping_weight"]) * intval($item['quantity']));
				}
				/* add item's band if shipping is done through postage bands */
				if ($options["shipping_method"] == "bands" && isset($item["shipping_band"]) && trim($item["shipping_band"]) != '') {
					if (isset($_SESSION["shipping_data"]["bands"][trim($item["shipping_band"])])) {
						$_SESSION["shipping_data"]["bands"][trim($item["shipping_band"])] += $item["quantity"];
					} else {
						$_SESSION["shipping_data"]["bands"][trim($item["shipping_band"])] = $item["quantity"];
					}
				}
				$total_items +=  $item['quantity'];
				/* calculate VAT totals */
				if ($item["includes_vat"]) {
					$amounts = self::calculate_vat($item["price"]);
					$item_price_ex_vat["p" . $item["product_page_id"]] = $amounts["price"] * $item["quantity"];
					$total_vat += $amounts["vat"] * $item["quantity"];
				} else {
					$item_price_ex_vat["p" . $item["product_page_id"]] = $item["price"] * $item["quantity"];
				}
				$total_ex_vat += $item_price_ex_vat["p" . $item["product_page_id"]];
			}
			/* if the cart has been emptied due to a decrease in stock level... */
			if (!count($_SESSION['sppp-cart'])) {
				return "  <p>Your basket is empty.</p>\n";
			}
			$count = 1;
			/* oputput the basket table and build the paypal form */
			foreach ($_SESSION['sppp-cart'] as $item) {
				$out .= "	<tr>";
				/* remove button */
				$out .= sprintf("<td style=\"text-align:center\"><form method=\"post\" action=\"%s\"><input type=\"hidden\" name=\"pp-delcart\" value=\"1\" /><input type=\"hidden\" name=\"pp-product_page_id\" value=\"%s\" /><input type=\"submit\" class=\"pp-small-button pp-remove-button\" value=\"Remove\" title=\"Remove\" /></form></td>", $options["cart_url"], $item['product_page_id']);
				/* item name linking to product page */
				$out .= sprintf("<td valign=\"middle\" style=\"text-align:left;vertical-align:middle;\"><a href=\"%s\"><strong>%s</strong></a></td>", get_permalink($item["product_page_id"]), $item["name"]);
				/* quantity indicator and change quantities button */
				$out .= sprintf("<td style=\"text-align:right\"><form method=\"post\" action=\"%s\" name=\"cquantity\" style=\"display:inline\"><input type=\"hidden\" name=\"pp-cquantity\" value=\"1\" /><input type=\"hidden\" name=\"pp-product_page_id\" value=\"%s\" /><input type=\"hidden\" name=\"pp-quantity\" value=\"%s\" />", $options["cart_url"], $item["product_page_id"], $item["quantity"]);
				if ($item_has_more_stock["p" . $item["product_page_id"]]) {
					$plusbutton = "<input type=\"submit\" class=\"pp-small-button pp-increase-button\" name=\"plus\" title=\"add one\" id=\"addbutton\" />";
				} else {
					$plusbutton = "<input type=\"button\" class=\"pp-small-button pp-increase-button-disabled\" title=\"no more stock available\" id=\"addbutton\" />";
				}
				$out .= sprintf("<input type=\"submit\" class=\"pp-small-button pp-decrease-button\" name=\"minus\" title=\"remove one\" id=\"removebutton\" /><span id=\"quantityval\">%s</span>%s</form></td>", $item["quantity"], $plusbutton);
				$out .= sprintf("<td style=\"text-align:right\"><strong>&pound;%.2f</strong></td></tr>\n", $item_price_ex_vat["p" . $item["product_page_id"]]);
				$masterForm .= sprintf("<input type=\"hidden\" name=\"item_name_%s\" value=\"%s\" /><input type=\"hidden\" name=\"item_number_%s\" value=\"%s\" /><input type=\"hidden\" name=\"amount_%s\" value=\"%s\" /><input type=\"hidden\" name=\"quantity_%s\" value=\"%s\" /><input type=\"hidden\" name=\"code_%s\" value=\"%s\" />", $count, $item["name"], $count, $item["product_page_id"], $count, $item["price"], $count, $item["quantity"], $count, $item["code"]);
				$count++;
			}
			if ($total_vat > 0) {
				$out .= sprintf('	<tr class="subtotal"><td colspan="3">VAT:</td><td colspan="2">&pound;%.2f</td></tr>', $total_vat);
			}
			$out .= sprintf('	<tr class="subtotal"><td colspan="3">Subtotal:</td><td colspan="2">&pound;%.2f</td></tr>', ($total_ex_vat + $total_vat));
			/* output a form to allow changes in shipping information */
			$out .= sprintf('<tr><td colspan="3"><h3>Shipping</h3><form method="post" action="%s" method="post"><input type="hidden" name="delivery_change" value="1" />', $options["cart_url"]);
			/* the delivery method is set once the cart page has been submitted */
			$out .= sprintf('<input type="hidden" name="delivery_method" value="%s" />', $_SESSION["shipping_data"]["delivery_method"]);
			if ($_SESSION["shipping_data"]["delivery_method"] == "pickup") {
				$out .= '<p>You are picking these items up in person <input type="submit" name="change_delivery_method_post" value="Have them posted instead" class="pp-button" /></p>';
				$out .= $options["pickup_address"];
				/* clear any errors */
				$_SESSION["shipping_data"]["errors"] = array();
			} else {
				if (count($_SESSION["shipping_data"]["address"]) && !count($_SESSION["shipping_data"]["errors"])) {
					/* address has been input, with no errors */
					$out .= '<p>These items will be delivered to:</p>';
					$address = array();
					foreach(array_keys($address_fields) as $field) {
						if (isset($_SESSION["shipping_data"][$field]) && trim($_SESSION["shipping_data"][$field]) != "") {
							if ($field == "country") {
								$address[] = self::get_country_name($_SESSION["shipping_data"]["country"]);
							} else {
								$address[] = trim($_SESSION["shipping_data"][$field]);
							}
						}
					}
					/* add paypal address input hidden fields here */
					for ($i = 1; $i <= count($address); $i++) {
						$masterForm .= sprintf('<input type="hidden" name="shipping_address_%d" value="%s" />', $i, $address[($i - 1)]);
					}
					$out .= '<p>' . implode(", ", $address) . '</p>';
					$out .= '<p><input type="submit" name="change_delivery_address" value="change this address" class="pp-button" /><input type="submit" name="change_delivery_method_pickup" value="Pick these items up in person instead" class="pp-button" /></p>';
				} else {
					/* address not input, or input with errors */
					$out .= '<p>Please input your name and delivery address:</p>';
					foreach ($address_fields as $name => $details) {
						$value = isset($_SESSION["shipping_data"][$name])? trim($_SESSION["shipping_data"][$name]): '';
						$required = ($details["required"])? ' <span class="required">*</span>': '';
						if ($name == 'country') {
							$out .= sprintf('<p class="address-input"><label for="delivery_country">Country%s</label>%s</p>', $required, self::get_countries_select('delivery_country', $value));
						} else {
							$out .= sprintf('<p class="address-input"><label for="delivery_%s">%s%s</label><input type="text" name="delivery_%s" class="pp-input" id="delivery_%s" value="%s" /></p>', $name, $details["label"], $required, $name, $name, $value);
						}
						if (isset($_SESSION["errors"]) && isset($_SESSION["errors"][$name])) {
							$out .= sprintf('<p class="error">%s</p>', $_SESSION["errors"][$name]);
						}
					}
					$out .= '<p class="address-input address-input-button"><input type="submit" name="save_delivery_address" value="Save this address" /></p>';
				}
			}
			$out .= '</form></td><td>';
			$postage_cost = false;
			if ($options["shipping_method"] == "weights") {
				$total_weight = $_SESSION["shipping_data"]["total_weight"];
				if (isset($options["weights"]) && is_array($options["weights"]) && count($options["weights"])) {
					$max_weight = 0;
					$target_band = false;
					foreach ($options["weights"] as $weight_band) {
						if ($total_weight <= $weight_band["to_weight"]) {
							$max_weight = max($max_weight, $weight_band["to_weight"]);
						}
					}
					foreach ($options["weights"] as $weight_band) {
						if ($weight_band["to_weight"] == $max_weight) {
							$target_band = $weight_band;
							break;
						}
					}
					if ($target_band && isset($_SESSION["shipping_data"]["country"])) {
						$region = self::get_region($_SESSION["shipping_data"]["country"]);
						$postage_cost = $target_band["regions"][$region];
						$out .= sprintf('<script>var region_prices = %s;</script>', json_encode($target_band["regions"]));
					}
				}
			} else {
				if (isset($options["bands"]) && is_array($options["bands"]) && count($options["bands"]) && isset($_SESSION["shipping_data"]["country"])) {
					$first_band = 0;
					$target_band = false;
					$region = self::get_region($_SESSION["shipping_data"]["country"]);
					foreach ($options["bands"] as $postage_band) {
						if (isset($_SESSION["shipping_data"]["bands"][$postage_band["name"]]) && $_SESSION["shipping_data"]["bands"][$postage_band["name"]] > 0) {
							$first_band = max($first_band, $postage_band[$region]["shipping_one"]);
						}
					}
					foreach ($options["bands"] as $postage_band) {
						if ($first_band == $postage_band[$region]["shipping_one"]) {
							$target_band = $postage_band;
							break;
						}
					}
					if ($target_band) {
						$postage_cost = 0;
						$first_item = true;
						foreach ($_SESSION["shipping_data"]["bands"] as $name => $total) {
							if ($target_band["name"] == $name) {
								if ($first_item) {
									$first_item = false;
									$postage_cost += $target_band[$region]["shipping_one"];
								} else {
									$postage_cost += $target_band[$region]["shipping_multiple"];
								}
								$_SESSION["shipping_data"]["bands"][$name]--;
							} else {
								foreach ($options["bands"] as $band) {
									if ($band["name"] == $name) {
										$postage_cost += $_SESSION["shipping_data"]["bands"][$name] * $band["shipping_multiple"];
									}
								}
							}
						}
						$out .= sprintf('<script>var region_prices = %s;</script>', json_encode($target_band));
					}
				}
			}
			$print_cost = ($postage_cost)? self::dec2($postage_cost): '';
			$out .= sprintf('<td colspan="2">&pound;<span id="postage_cost">%.02f</span></td></tr>', $print_cost);
			$masterForm .= sprintf('<input type="hidden" name="shipping_1" value="%s" /><input type="hidden" name="return" value="%s" /><input type="hidden" name="notify_url" value="%s" />', $print_cost, $options["cart_url"], $options["cart_url"]);
			if ($postage_cost) {
				$out .= sprintf('	<tr class="total"><td colspan="3">Total:</td><td colspan="2">&pound;%.2f</td></tr>', self::dec2($total_price + $postage_cost));
				$out .= sprintf('	<tr class="total"><td colspan="4"><form action="%s" id="pp-form" method="post">%s<input type="submit" class="pp-button pp-checkout-button" name="submit" title="Make payments with PayPal -  fast, free and secure!" /><input type="hidden" name="business" value="%s" /><input type="hidden" name="currency_code" value="%s" /><input type="hidden" name="cmd" value="_cart" /><input type="hidden" name="upload" value="1" /></form></td></tr></table>', $options["paypal_url"], $masterForm, $options["paypal_email"], $options["paypal_currency"]);
			} else {
				$out .= '</table><p>Please fill out your delivery preferences above to continue.</p>';
			}
		} else {
			return "  <p>Your basket is empty.</p>";
		}
		return $out;
	}

	 /**
	 * helper function used to calculate VAT
	 */
	private static function calculate_vat($price)
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		if (floatval($options["vat_rate"]) > 0 && floatval($options["vat_rate"]) < 100) {
			$ret["vat"] = self::dec2((floatval($price) / 100) * floatval($options["vat_rate"]));
			$ret["price"] = self::dec2($price) - $ret["vat"];
		} else {
			$ret["vat"] = 0;
			$ret["price"] = self::dec2($price);
		}
		return $ret;
	}

	/**
	 * helper function used to determine whether an item can be purchased
	 */
	private static function can_be_purchased($item)
	{
		return (isset($item["name"]) && trim($item["name"]) != "" && isset($item["code"]) && trim($item["code"]) != "" && isset($item["price"]) && trim($item["price"]) != "" && self::dec2($item["price"]) != 0);
	}
		
	/**
	 * returns a link to the basket
	 */
	public static function get_basket_link()
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		if ($options["cart_url"]) {
			return sprintf('<div id="basket"><a href="%s" class="pp-button pp-basket-button" title="View basket">View Basket</a></div>', $options["cart_url"]);
		}
		return '';
	}
	
	/**************************
	 * PRODUCT CONFIGURATION  *
	 **************************/
	
	/**
	 * adds meta boxes to pages to attach paypal information to them
	 */
	public static function add_custom_paypal_box()
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		$spt = (isset($options["supported_post_types"]) && trim($options["supported_post_types"]) != "")? explode(",", $options["supported_post_types"]): array();
		foreach ($spt as $pt) {
			add_meta_box( 'paypal_item', 'Paypal', array(__CLASS__, 'get_custom_paypal_box'), $pt);
		}
	}	
	
	/**
	 * saves data from meta box for pages
	 */
	public static function save_custom_paypal_box($post_id)
	{
		if (isset($_POST['paypal_meta'])) {
			/* verify this came from the our screen and with proper authorization */
			if ( !wp_verify_nonce( $_REQUEST['paypal_meta'], 'paypal_meta' )) {
				return $post_id;
			}
			/* verify if this is an auto save routine.
			 * If it is our form has not been submitted, so we dont want to do anything
			 */
			if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
				return $post_id;
			}
			/* Check permissions */
			if ( !current_user_can( 'edit_page', $post_id ) ) {
				return $post_id;
			}
			
			/* make sure we have a correct post type */
			$post_type = get_post_type($post_id);
			$options = SimplePayPalPluginAdmin::get_paypal_options();
			$pts = explode(",", $options["supported_post_types"]);
			if (!in_array($post_type, $pts)) {
				return $post_id;
			}
			
			/* save paypal details in post_meta */
			$paypal = self::get_paypal_meta($post_id);
			$paypal["name"] = trim($_REQUEST['paypal_name']);
			$paypal["code"] = preg_replace("/[^a-zA-Z0-9\-_]*/", "", $_REQUEST['paypal_code']);
			$paypal["description"] = trim($_REQUEST['paypal_description']);
			$paypal_weight = isset($_REQUEST['paypal_weight'])? self::dec2($_REQUEST['paypal_weight']): 0;
			if ($paypal_weight > 0) {
				$paypal["weight"] = $paypal_weight;
			} else {
				$paypal["weight"] = "";
			}
			$paypal_price = self::dec2($_REQUEST['paypal_price']);
			if ($paypal_price > 0) {
				$paypal["price"] = $paypal_price;
			} else {
				$paypal["price"] = "";
			}
			switch ($options["shipping_method"]) {
				case "bands":
					$paypal["shipping_band"] = isset($_REQUEST["shipping_band"])? $_REQUEST["shipping_band"]: '';
					break;
				case "weights":
					$paypal["shipping_weight"] = isset($_REQUEST["shipping_weight"])? $_REQUEST["shipping_weight"]: '';
					break;
			}
			$paypal["stock"] = trim($_REQUEST['paypal_stock']);
			$paypal["includes_vat"] = isset($_REQUEST["includes_vat"]);
			add_post_meta($post_id, 'sppp', $paypal, true) or update_post_meta($post_id, 'sppp', $paypal);
		}
	}
	
	/**
	 * gets form controls for custom paypal box
	 */
	public static function get_custom_paypal_box()
	{
		global $post;
		$paypal = self::get_paypal_meta($post->ID);
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		/* Use nonce for verification */
		$methods = SimplePayPalPluginAdmin::get_shipping_methods();
		if (isset($options["shipping_method"]) && in_array($options["shipping_method"], array_keys($methods))) {
			echo '<div class="paypal-options"><input type="hidden" name="paypal_meta" id="paypal_meta" value="' .  wp_create_nonce('paypal_meta') . '" />';
			/* left column */
			echo '<div class="left-column"><p><label for="paypal_name">Name of item: </label><input type="text" id="paypal_name" name="paypal_name" value="' . $paypal["name"] . '" size="25" /></p>';
			echo '<p><label for="paypal_code">Item code: </label><input type="text" id="paypal_code" name="paypal_code" value="' . $paypal["code"] . '" size="25" /></p>';
			echo '<p><label for="paypal_price">Price: </label><input type="text" id="paypal_price" name="paypal_price" value="' . $paypal["price"] . '" size="5" /></p>';
			$chckd = (isset($paypal["includes_vat"]) && $paypal["includes_vat"] == true)? ' checked="checked"': '';
			echo '<p><label for="includes_vat" class="cbx"><input type="checkbox" id="includes_vat" name="includes_vat" value="1"' . $chckd . ' />Check this box if the price includes VAT (otherwise a zero rate of VAT is assumed)</label></p>';
			echo '</div>';
			/* right column */
			echo '<div class="right-column">';
			if (isset($options["shipping_method"]) && $options["shipping_method"] == "weights") {
				if (!isset($options["shipping_settings"]) || !isset($options["shipping_settings"]["weights"]) || !count($options["shipping_settings"]["weights"])) {
					printf('<p><a href="%s">Please configure weight ranges on the paypal plugin options page</a></p>', admin_url('options-general.php?page=paypal_options'));
				}
				echo '<p><label for="shipping_weight">Weight: </label><input type="text" id="shipping_weight" name="shipping_weight" value="' . $paypal["weight"] . '" size="5" />g</p>';
			} else {
				if (isset($options["shipping_settings"]) && isset($options["shipping_settings"]["bands"]) && count($options["shipping_settings"]["bands"])) {
					echo '<p><label for="shipping_band">Postage band:</label><select name="shipping_band" id="shipping_band">';
					foreach ($options["shipping_settings"]["bands"] as $band) {
						$sel = (isset($paypal["shipping_band"]) && $paypal["shipping_band"] == $band["name"])? ' selected="selected"': '';
						printf('<option value="%s"%s>%s</option>', $band["name"], $sel, $band["name"]);
					}
					echo '</select></p>';

				} else {
					printf('<p><a href="%s">Please set up some postage bands on the paypal plugin options page</a>.</p>', admin_url('options-general.php?page=paypal_options'));
				}
			}
			echo '<p><label for="paypal_stock">Stock: </label><input type="text" id="paypal_stock" name="paypal_stock" value="' . $paypal["stock"] . '" size="5" /></p></div><div class="clear">&nbsp;</div>';
			/* short description - use wp-editor but capture output in a buffer */
			ob_start();
			/* options for editor */
			$options = array(
				"media_buttons" => false,
				"textarea_name" => "paypal_description",
				"textarea_rows" => 3,
				"teeny" => true
			);
			/* "echo" the editor */
			wp_editor($paypal["description"], "paypal_description", $options );
			/* get the output buffer */
			$editor = ob_get_contents();
			/* clean the output buffer */
			ob_clean();
			printf('<p>Short Item Description:</p><div>%s</div><p><em>Include information such as dimensions, special ordering instructions, estimated delivery times, etc.</em></p><div class="clear">&nbsp;</div></div>', $editor);
		} else {
			printf('<p><a href="%s">Please set up postage settings on the paypal plugin options page</a>.</p>', admin_url('admin.php?page=sppp-options'));
		}
	}
	
	/**
	 * retrieves paypal information from a custom (meta) field
	 * @param integer $page_ID
	 * @return array
	 */
	public static function get_paypal_meta($page_ID = false)
	{
		$default_meta = array(
			"name" => "",
			"code" => "",
			"description" => "",
			"weight" => "",
			"price" => "",
			"shipping_band" => "",
			"shipping_weight" => "",
			"stock" => "",
			"includes_vat" => true
		);
		$post_meta = get_post_meta($page_ID, "sppp", true);
		if ($post_meta) {
			return wp_parse_args($post_meta, $default_meta);
		} else {
			return $default_meta;
		}
	}

	/**
	 * retuns the name of a country based on country code
	 * @param string two letter country code
	 */
	public static function get_country_name($abbr)
	{
		$regions = SimplePayPalPluginAdmin::get_shipping_regions();
		foreach($regions as $region_code => $details) {
			foreach($details["countries"] as $country_code => $country_name) {
				if ($abbr == $country_code) {
					return $country_name;
				}
			}
		}
		return '';
	}

	/**
	 * returns a dropdown select list of countries
	 * @param string name of dropdown (also used as ID)
	 * @param string selected value
	 */
	public static function get_countries_dropdown($select_name = '', $selected = '')
	{
		$regions = SimplePayPalPluginAdmin::get_shipping_regions();
		$sel = ($selected == '')? ' selected="selected"': '';
		$out = sprintf('<select name="%s" id="%s"><option value=""%s>Please select a country&hellip;</option>', $select_name, $select_name, $sel);
		foreach ($regions as $region_code => $details) {
			if (count($details["countries"])) {
				$out .= sprintf('<optgroup name="%s">', $details["name"]);
				foreach ($details["countries"] as $country_code => $country_name) {
					$sel = ($selected == $country_code)? ' selected="selected"': '';
					$out .= sprintf('<option value="%s"%s>%s</option>', $country_code, $sel, $country_name);
				}
			}
			$out .= '</optgroup>';
		}
		$out .= "</select>";
		return $out;
	}

	/**
	 *  Derives a region code for a given country abbreviation
	 */
	public static function get_region($country)
	{
		$regions = SimplePayPalPluginAdmin::get_shipping_regions();
		/* first see if the country is in a defined region */
		foreach ($regions as $region_code => $region) {
			if (in_array($country, array_keys($region["countries"]))) {
				return $region_code;
			}
		}
	}


	/**************************
	 * IPN PROCESSING		 *
	 **************************/
	
	/**
	 * function to process Instant Payment Notifications from Paypal
	 */
	function processIPN()
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		$ppHost = isset($_POST['test_ipn'])? $options["paypal_sandbox_url"] : $options["paypal_url"];
		$req = 'cmd=_notify-validate';   
		$ipn_data = array();
		foreach($_POST as $key => $value) {   
			$value = urlencode(stripslashes($value));   
			$req .= "&" . $key . "=" . $value;   
			$ipn_data[$key] = urldecode($value);
		}
		/* Validate IPN with PayPal using curl */
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $ppHost);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded", "Content-Length: " . strlen($req)));
		curl_setopt($ch, CURLOPT_HEADER , false);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$curl_result = @curl_exec($ch);
		$curl_err = curl_error($ch);
		$curl_info = curl_getinfo($ch);
		$ci = "";
		foreach ($curl_info as $k => $v) {
			$ci .= $k . " : " . $v . "\n";
		}
		/* are we verified? If so, let's process the IPN */
		if (strpos($curl_result, "VERIFIED") !== false) { 
			/* now decrease stock levels of items */
			$i = 1;
			while (isset($_POST["item_number" . $i])) {
				if (isset($_POST["quantity" . $i])) {
					$paypal = self::get_paypal_meta($_POST["item_number" . $i]);
					if (isset($paypal["stock"]) && (int) $paypal["stock"] != 0) {
						$paypal["stock"] = (int) $paypal["stock"] - (int) $_POST["quantity" . $i];
						if ($paypal["stock"] < 0) {
							$paypal["stock"] = 0;
						}
						update_post_meta($_POST["item_number" . $i], 'paypal', $paypal);
					}
				}
				$i++;
			}
			/* store IPN in database */
			global $wpdb;
			$txn_id = isset($ipn_data["txn_id"])? $ipn_data["txn_id"]: '';
			$txn_type = isset($ipn_data["txn_type"])? $ipn_data["txn_type"]: '';
			$mc_gross = isset($ipn_data["mc_gross"])? $ipn_data["mc_gross"]: '';

			$tablename = $wpdb->prefix . "payments";
			$wpdb->insert($tablename, array("payment_date" => time(), "payment_ipn" => serialize($ipn_data), "txn_id" => $txn_id, "txn_type" => $txn_type, "mc_gross" => $mc_gross));

		}
		if (is_email($options["paypal_ipn_email"])) {
			wp_mail($options["paypal_ipn_email"], "IPN CURL report", "CURL result: " . $curl_result . "\n\nCURL error: " . $curl_err . "\n\nCURL info: " . $ci . "\n\nIPN:\n\n" . $req, "From: " . $options["paypal_email"] . "\r\nReply-To: " . $options["paypal_email"] . "\r\n");
		}
		curl_close($ch);
	}
	

}
SimplePayPalPlugin::register();

/**
 * shipping methods classes
 * shiping methods should eventually be handled by classes
 */

interface PayPal_Shipping_Method
{
	public static function settings_form();
	public static function validate_settings($settings);
	public static function put_in_order($a, $b);
}

class PayPal_Shipping_Method_bands implements PayPal_Shipping_Method
{
	public static function settings_form()
	{

	}
	public static function validate_settings($settings)
	{

	}
	public static function put_in_order($a, $b)
	{
	}
}

class PayPal_Shipping_Method_weights implements PayPal_Shipping_Method
{
	public static function settings_form()
	{
		$options = SimplePayPalPluginAdmin::get_paypal_options();
		$regions = SimplePayPalPluginAdmin::get_shipping_regions();
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
	public static function validate_settings($options)
	{
		if (isset($options["shipping_settings"]["weight_ids"]) && is_array($options["shipping_settings"]["weight_ids"])) {
			$defaults = SimplePayPalPlugin::get_paypal_options();
			$w = $options["shipping_settings"]["weight"];
			foreach(array_unique($options["shipping_settings"]["weight_ids"]) as $weight_id) {
				$to_val = $w["to_weight_" . $weight_id];
				if (isset($to_val) && intval($to_val) > 0) {
					$regions = SimplePayPalPluginAdmin::get_shipping_regions();
					$region_prices = array();
					foreach ($regions as $region_code => $region_data) {
						$input = "shipping_weight_" . $region_code . "_" . $weight_id;
						if (isset($w[$input]) && trim($w[$input]) != "") {
							$region_prices[$region_code] = intval($w[$input]);
						}
					}
					if (count($region_prices) == count($regions)) {
						$weights[] = array("to_weight" => $weight["to_weight_" . $weight_id], "regions" => $region_prices);
					}
				}
			}
		} else {
			$weights = $defaults["shipping_settings"]["weights"];
		}
		usort($weights, array(__CLASS__, 'put_in_order'));
		$options["shipping_settings"]["weights"] = $weights;
		unset($options["shipping_settings"]["weight_ids"]);
		unset($options["shipping_settings"]["weight"]);
		return $options;
	}
	public static function put_in_order($a, $b)
	{
		if ($a["to_weight"] == $b["to_weight"]) {
			return 0;
		}
		return ($a["to_weight"] > $b["to_weight"])? -1: 1;
	}
}
