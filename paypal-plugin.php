<?php
/*
Plugin Name: IRA PayPal Plugin
Plugin URI: http://p-2.biz/plugins/paypal
Description: A plugin to enable PayPal purchases on a wordpress site
Version: 0.9
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


class PayPalPlugin
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
		/* start session */
		add_action("plugins_loaded", array("PayPalPlugin", "setup_session"), 1);
		/* process any POSTed content */
		add_action("after_setup_theme", array("PayPalPlugin", "process_requests"), 2);
		/* add the admin page and plugin options */
        if ( is_admin() ){
            add_action( 'admin_menu', array("PayPalPlugin", 'add_paypal_admin_menus') );
            add_action( 'admin_init', array("PayPalPlugin", 'register_paypal_options') );
        }
		/* add contextual help */
		add_filter( 'paypal_buttonadmin_head', array("PayPalPlugin", "add_contextual_help") );
		/* registers activation hook */
		register_activation_hook(__FILE__, array("PayPalPlugin", 'install'));
		/* registers deactivation hook */
		register_deactivation_hook(__FILE__, array("PayPalPlugin", 'uninstall'));
		/* add settings link to plugin page */
		add_filter('plugin_action_links', array("PayPalPlugin", 'add_settings_link'), 10, 2 );
		
		/* add meta boxes to posts */
		add_action( 'add_meta_boxes', array("PayPalPlugin", 'add_custom_paypal_box') );
		add_action( 'save_post', array("PayPalPlugin", 'save_custom_paypal_box') );
		
		/* SHORTCODES */
		/* shortcode for link to basket */
		add_shortcode("basket_link", array("PayPalPlugin", "get_basket_link"));
		/* shortcode for basket */
		add_shortcode("basket", array("PayPalPlugin", "get_shopping_basket"));
		/* shortcode for add to basket button */
		add_shortcode("paypal_button", array("PayPalPlugin", "paypal_button_shortcode"));
		/* ajax product enquiry form */
        add_action("wp_ajax_enquiryform", array("PayPalPlugin", "process_enquiry_form_ajax"));
        add_action("wp_ajax_nopriv_enquiryform", array("PayPalPlugin", "process_enquiry_form_ajax"));
		/* add scripts and CSS to front and back end */
        add_action("wp_enqueue_scripts", array("PayPalPlugin", "enqueue_scripts"));
        add_action("admin_enqueue_scripts", array("PayPalPlugin", "enqueue_scripts"));
		
	}
	
	/**
	 * activation hook
	 */
	public static function install()
	{
		global $wpdb;
		$tablename = $wpdb->prefix . "payments";
		$sql = "CREATE TABLE IF NOT EXISTS `" . $tablename . "` (`ipn_id` int(11) NOT NULL AUTO_INCREMENT, `payment_date` int(11) NOT NULL,`payment_ipn` text NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    	$defaults = self::get_paypal_options();
		update_option('paypal_options', $defaults);
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
        delete_option('paypal_options');
	}

	/**
	 * adds a link to the plugin settings page from the plugins page
	 */
	public static function add_settings_link($links, $file)
	{
        $this_plugin = plugin_basename(__FILE__);
        if ($file == $this_plugin) {
            $settings_link = '<a href="' . admin_url("options-general.php?page=paypal_options") . '">Settings</a>';
            array_unshift($links, $settings_link);
        }
        return $links;
    }
    
    /**
     * adds script to front end
     */
    public static function enqueue_scripts()
    {
        wp_enqueue_script("PayPalPlugin", plugins_url("js/PayPalPlugin.js", __FILE__), array("jquery"), "0.9", true);
        if (is_admin()){
            wp_enqueue_style("PayPalPlugin", plugins_url("css/PayPalPlugin.css", __FILE__)); 
        }
    }

    /**************************
     * REQUEST/SESSION        *
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
	

    /**************************
     * ENQUIRY FORM           *
     **************************/

    /**
     * returns a enquiry form for a given product
     */
    public static function get_enquiry_form($post)
    {
        /* use for ids of HTML elements */
        self::$instance_id++;
        /* get plugin options for form action */
        $options = self::get_paypal_options();
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
		    $options = self::get_paypal_options();
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
        $options = self::get_paypal_options();
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
     * SHOPPING BASKET        *
     **************************
    
    /**
	 * adds an item to the cart
	 */
    public static function add_to_cart()
	{
        if (isset($_REQUEST['pp-addcart'])) {
        	$in_basket = false;
            if (isset($_SESSION['simpleCart'])) {  
                $products = $_SESSION['simpleCart'];
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
                    $_SESSION['simpleCart'] = $products;
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
            if (isset($_SESSION['simpleCart'])) {  
                $products = $_SESSION['simpleCart'];
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
                                $_SESSION['simpleCart'] = $products;
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
        if (isset($_SESSION['simpleCart'])) {  
            $products = $_SESSION['simpleCart'];
        } else {
            $products = array();
        }
        if (count($products)) {
            foreach ($products as $key => $item) {
                if ($item['product_page_id'] == $page_id) {
                    unset($products[$key]);
                    sort($products);
                    $_SESSION['simpleCart'] = $products;
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
        $products = $_SESSION['simpleCart'];
        foreach ($products as $key => $item) {
            unset($products[$key]);
        }
        $_SESSION['simpleCart'] = $products;
    }

    /**
     * user has chosen to pick items up
     */
    private static function elect_pickup()
    {
        $options = self::get_paypal_options();
        if (isset($options["allow_pickup"]) && $options["allow_pickup"]) {
            $_SESSION['user_pickup'] = ($_REQUEST['pp-pickup'] == "1")? true: false;
        }
    }
	

    /**************************
     * SHORTCODES             *
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
    	$options = self::get_paypal_options();
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
        $options = self::get_paypal_options();
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
        $out = "";
        $total_items = 0;
        $total_price = 0;
        $masterForm = '';
        $item_total_shipping = 0;
        $total_vat = 0;
        $total_ex_vat = 0;
        if (isset($_SESSION['simpleCart']) && is_array($_SESSION['simpleCart']) && count($_SESSION['simpleCart'])) {   
            $out .= "  <table class=\"cart\">\n";    
            $out .= "    <tr><th width=\"5%\"></th><th width=\"60%\" style=\"text-align:left\">item</th><th width=\"15%\" style=\"text-align:right\">quantity</th><th width=\"20%\" style=\"text-align:right\">price</th></tr>\n";
            $include_base_shipping = false;
            $item_price_ex_vat = array();
            $item_has_more_stock = array();
  	        foreach ($_SESSION['simpleCart'] as $item) {
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
                $total_price += $item['price'] * $item['quantity'];
                /* if an individual item doesn't have its own shipping amount, use  global shipping preferences */
                if ((!isset($item["shipping"]) || self::dec2($item["shipping"]) == 0) && $options["use_global_shipping"]) {
            	    $item["shipping"] = $options["item_shipping"];
                    $include_base_shipping = true;
                } else {
                    $item["shipping"] = self::dec2($item["shipping"]);
                }
	            $item_total_shipping += $item['shipping'] * $item['quantity'];
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
            if (!count($_SESSION['simpleCart'])) {
                return "  <p>Your basket is empty.</p>\n";
            }
            $count = 1;
            /* oputput the basket table and build the paypal form */
	        foreach ($_SESSION['simpleCart'] as $item) {
	            $out .= "    <tr>";
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
            /* add the base shipping amount if we need to */
            if ($include_base_shipping && $options["use_global_shipping"]) {
                $postage_cost = $item_total_shipping + $options["base_shipping"];
            } else {
                $postage_cost = $item_total_shipping;
            }
            if ($total_vat > 0) {
                $out .= sprintf('    <tr class="subtotal"><td colspan="3">VAT:</td><td colspan="2">&pound;%.2f</td></tr>', $total_vat);
            }
            $out .= sprintf('    <tr class="subtotal"><td colspan="3">Subtotal:</td><td colspan="2">&pound;%.2f</td></tr>', ($total_ex_vat + $total_vat));
            if (isset($options["allow_pickup"]) && $options["allow_pickup"]) {
                $pickup_fmt = '<form method="post" action="' . $options["cart_url"] . '" style="display:inline"><input type="hidden" name="pp-pickup" value="%s" />%s <input type="submit" class="pp-pickup_button" value="%s" /></form>';
                if (isset($_SESSION["user_pickup"]) && $_SESSION["user_pickup"]) {
                    $pickup_form = sprintf($pickup_fmt, 0, "You are picking these items up in person", "cancel");
                    $postage_cost = 0;
                } else {
                    $pickup_form = sprintf($pickup_fmt, 1, "To pick your items up in person, ", "click here");
                }
                $out .= sprintf('    <tr><td colspan="2">%s</td><td class="subtotal">Shipping:</td><td colspan="2" class="subtotal">&pound;%.2f</td></tr>', $pickup_form, $postage_cost);
            } else {
                $out .= sprintf('    <tr class="subtotal"><td colspan="3">Shipping:</td><td colspan="2">&pound;%.2f</td></tr>', $postage_cost);
            }
            $masterForm .= sprintf('<input type="hidden" name="shipping_1" value="%s" /><input type="hidden" name="return" value="%s" /><input type="hidden" name="notify_url" value="%s" />', $postage_cost, $options["cart_url"], $options["cart_url"]);  
            $out .= sprintf('    <tr class="total"><td colspan="3">Total:</td><td colspan="2">&pound;%.2f</td></tr>', ($total_price + $postage_cost));
            $out .= sprintf('    <tr class="total"><td colspan="4"><form action="%s" id="pp-form" method="post">%s<input type="submit" class="pp-button pp-checkout-button" name="submit" title="Make payments with PayPal -  fast, free and secure!" /><input type="hidden" name="business" value="%s" /><input type="hidden" name="currency_code" value="%s" /><input type="hidden" name="cmd" value="_cart" /><input type="hidden" name="upload" value="1" /></form></td></tr>', $options["paypal_url"], $masterForm, $options["paypal_email"], $options["paypal_currency"]);
            $out .= "  </table>";
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
        $options = self::get_paypal_options();
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
     *elper function used to determine whether an item can be purchased
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
    	$options = self::get_paypal_options();
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
		$options = self::get_paypal_options();
        $spt = (isset($options["supported_post_types"]) && trim($options["supported_post_types"]) != "")? explode(",", $options["supported_post_types"]): array();
        foreach ($spt as $pt) {
			add_meta_box( 'paypal_item', 'Paypal', array("PayPalPlugin", 'get_custom_paypal_box'), $pt);
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
            $options = self::get_paypal_options();
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
            $paypal_shipping = self::dec2($_REQUEST['paypal_shipping']);
            if ($paypal_shipping > 0) {
        	    $paypal["shipping"] = $paypal_shipping;
            } else {
            	$paypal["shipping"] = "";
            }
            $paypal["stock"] = trim($_REQUEST['paypal_stock']);
            $paypal["includes_vat"] = isset($_REQUEST["includes_vat"]);
			add_post_meta($post_id, 'paypal', $paypal, true) or update_post_meta($post_id, 'paypal', $paypal);
        }
	}
	
	/**
	 * gets form controls for custom paypal box
	 */
	public static function get_custom_paypal_box()
	{
        global $post;
        $paypal = self::get_paypal_meta($post->ID);
        /* Use nonce for verification */
        echo '<div class="paypal-options"><input type="hidden" name="paypal_meta" id="paypal_meta" value="' .  wp_create_nonce('paypal_meta') . '" />';
        /* left column */
        echo '<div class="left-column"><p><label for="paypal_name">Name of item: </label><input type="text" id="paypal_name" name="paypal_name" value="' . $paypal["name"] . '" size="25" /></p>';
        echo '<p><label for="paypal_code">Item code: </label><input type="text" id="paypal_code" name="paypal_code" value="' . $paypal["code"] . '" size="25" /></p>';
        echo '<p><label for="paypal_price">Price: </label><input type="text" id="paypal_price" name="paypal_price" value="' . $paypal["price"] . '" size="5" /></p>';
        $chckd = (isset($paypal["includes_vat"]) && $paypal["includes_vat"] == true)? ' checked="checked"': '';
        echo '<p><input type="checkbox" id="includes_vat" name="includes_vat" value="1"' . $chckd . ' /><label for="includes_vat">Check this box if the price includes VAT (otherwise a zero rate of VAT is assumed)</label></p>';
        echo '</div>';
        /* right column */
        echo '<div class="right-column"><p><label for="paypal_weight">Weight (kg): </label><input type="text" id="paypal_weight" name="paypal_weight" value="' . $paypal["weight"] . '" size="5" /></p>';
        echo '<p><label for="paypal_shipping">Shipping: </label><input type="text" id="paypal_shipping" name="paypal_shipping" value="' . $paypal["shipping"] . '" size="5" /></p>';
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
	}
	
	/**
     * get_paypal_info
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
            "shipping" => "",
            "stock" => "",
            "includes_vat" => true
        );
	    $post_meta = get_post_meta($page_ID, "paypal", true);
        if ($post_meta) {
            return wp_parse_args($post_meta, $default_meta);
        } else {
            return $default_meta;
        }
    }

    /**************************
     * PLUGIN ADMINISTRATION  *
     **************************/

    /**
     * adds a submenu to the settings admin menu to access the paypal settings page
     */
    function add_paypal_admin_menus()
    {
        /* add optons page */
        global $paypal_plugin_options_hook;
        $paypal_plugin_options_hook = add_submenu_page("options-general.php", "Paypal Options", "Paypal options", "edit_plugins", "paypal_options", array("PayPalPlugin", "paypal_options_page"));
		/* add payments page */
		global $paypal_plugin_tools_hook;
        $paypal_plugin_tools_hook = add_submenu_page("tools.php", "Paypal Payments", "Paypal payments", "edit_plugins", "paypal_payments", array("PayPalPlugin", "paypal_payments_page"));
    }
	

    /**
	 * registers options for the plugin
	 */
    public static function register_paypal_options()
    {
        register_setting('paypal_options', 'paypal_options', array("PayPalPlugin", "validate_options"));
		/* Paypal section */
        add_settings_section('paypal_section', 'Paypal Settings', array("PayPalPlugin", "section_text_fn"), __FILE__);
	    add_settings_field('paypal_email', 'Paypal email address', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'paypal_section', array("field" => "paypal_email"));
	    add_settings_field('paypal_url', 'Paypal URL', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'paypal_section', array("field" => "paypal_url", "size" => 50));
        add_settings_field('paypal_ipn_email', 'IPN monitor email address', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'paypal_section', array("field" => "paypal_email", "desc" => "This email address will get reports of all Instant Payment Notifications from Paypal."));
	    add_settings_field('paypal_currency', 'Paypal Currency', array("PayPalPlugin", 'setting_currency_dropdown_fn'), __FILE__, 'paypal_section');
        add_settings_field('paypal_sandbox', 'Use Paypal sandbox?', array("PayPalPlugin", 'setting_cbx_fn'), __FILE__, 'paypal_section', array("field" => "paypal_sandbox"));
	    add_settings_field('paypal_sandbox_email', 'Paypal sandbox email address', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'paypal_section', array("field" => "paypal_sandbox_email"));
	    add_settings_field('paypal_sandbox_url', 'Paypal sandbox URL', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'paypal_section', array("field" => "paypal_sandbox_url", "size" => 50));
        /* Interface section */
	    add_settings_section('cart_section', 'Interface settings', array("PayPalPlugin", "section_text_fn"), __FILE__);
		add_settings_field('supported_post_types', 'Post types to use as products', array("PayPalPlugin", 'setting_posttype_cbx_fn'), __FILE__, 'cart_section', array("field" => "supported_post_types"));
	    add_settings_field('cart_page_id', 'Page to use as the cart', array("PayPalPlugin", 'setting_pageid_dropdown_fn'), __FILE__, 'cart_section', array("field" => "cart_page_id"));
        /* Shipping section */
        add_settings_section('shipping_section', 'Shipping', array("PayPalPlugin", "section_text_fn"), __FILE__);
	    add_settings_field('base_shipping', 'Base shipping cost', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'shipping_section', array("field" => "base_shipping", "size" => 5));
	    add_settings_field('item_shipping', 'Item shipping cost', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'shipping_section', array("field" => "item_shipping", "size" => 5));
        add_settings_field('allow_pickup', 'Allow pick-up', array("PayPalPlugin", 'setting_cbx_fn'), __FILE__, 'shipping_section', array("field" => "allow_pickup", "desc" => "Checking this box will allow users to bypass shipping costs and elect to pick up items in person."));
        add_settings_field('pickup_address', 'Pick-up address', array("PayPalPlugin", 'setting_textbox_fn'), __FILE__, 'shipping_section', array("field" => "pickup_address", "desc" => "Enter the address where items will be available to pick up."));
        /* VAT secion */
        add_settings_section('vat_section', 'VAT', array("PayPalPlugin", "section_text_fn"), __FILE__);
        add_settings_field('vat_rate', 'VAT rate', array("PayPalPlugin", 'setting_text_fn'), __FILE__, 'vat_section', array("field" => "vat_rate", "size" => 5, "desc" => "Please enter the percentage VAT rate applied to all items."));
        /* Communication section  */
        add_settings_section('comms_section', 'Messages', array("PayPalPlugin", "section_text_fn"), __FILE__);
        add_settings_field('enquiry_msg', 'Enquiry Autoresponder', array("PayPalPlugin", 'setting_textbox_fn'), __FILE__, 'comms_section', array("field" => "enquiry_msg", "desc" => "This is the email message sent in response to product enquiries. Use <code>{PP_NAME}</code> in the message to include the product name."));
        add_settings_field('thanks_msg', 'Thankyou message', array("PayPalPlugin", 'setting_richtext_fn'), __FILE__, 'comms_section', array("field" => "thanks_msg", "desc" => "This is the text for the page a user is returned to when they have completed a paypal payment."));
		add_settings_field('error_msg', 'Paypal error message', array("PayPalPlugin", 'setting_richtext_fn'), __FILE__, 'comms_section', array("field" => "error_msg", "desc" => "This is the text for the page a user is returned to if there is an error with the payment process."));
        /* JS/CSS section */
        add_settings_section('enqueue_section', 'Javascript and CSS', array("PayPalPlugin", "section_text_fn"), __FILE__);
        add_settings_field('enqueue_js', 'Enqueue Javascript', array("PayPalPlugin", 'setting_enqueue_fn'), __FILE__, 'enqueue_section', array("field" => "enqueue_js", "file" => plugin_dir_path('/PayPalPlugin.php') . '/js/PayPalPlugin.js', "desc" => "Check this box if you would like the JavaScript for the plugin to be loaded in the front end. If this box is not checked, you will need to include the script shown below in your theme:"));
        add_settings_field('enqueue_css', 'Enqueue Javascript', array("PayPalPlugin", 'setting_enqueue_fn'), __FILE__, 'enqueue_section', array("field" => "enqueue_css", "file" => plugin_dir_path('/PayPalPlugin.php') . '/css/PayPalPlugin.css', "desc" => "Check this box if you would like the CSS for the plugin to be loaded in the front end. If this box is not checked, please ensure you add stles to your theme for buttons and other elements. Example styles are given below:"));
        

    }
	
    /**
	 * paypal options page
	 */
	public static function paypal_options_page()
	{
		print('<div class="wrap"><div class="icon32" id="icon-options-general"><br /></div><h2>Paypal options</h2><form method="post" action="options.php">');
        settings_fields('paypal_options');
        settings_errors('paypal_options');
		do_settings_sections(__FILE__);
		print('<p class="submit"><input name="Submit" type="submit" class="button-primary" value="Save Changes" /></p></form></div>');
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
        printf('<input id="%s" name="paypal_options[%s]" size="%s" type="text" value="%s" />', $field, $field, $size, $value);
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
        printf('<textarea id="%s" name="paypal_options[%s]" cols="%s" rows="%s">%s</textarea>', $field, $field, $cols, $rows, $value);
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
            "media_buttons" => false,
            "textarea_name" => "paypal_options[$field]",
            "textarea_rows" => 3,
            "teeny" => true
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
		printf('<input id="%s" name="paypal_options[%s]" type="checkbox" value="1"%s />', $field, $field, $chckd);
        if (isset($args["desc"])) {
            printf('<p class="field_desc">%s</p>', $args["desc"]);
        }
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
		printf('<select name="paypal_options[%s]" id="%s"><option value=""%s>Please select a page</option>', $field, $field, $sel);
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
			printf('<p><input type="checkbox" name="paypal_options[%s][]" id="%s-%s" value="%s"%s /> <label for="%s">%s</label></p>', $field, $field, $pt->name, $pt->name, $chckd, $field, $pt->name, $pt->label);
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
        printf('<input id="%s" name="paypal_options[%s]" type="checkbox" value="1"%s />', $field, $field, $chckd);

        if (isset($args["desc"])) {
            printf('<p class="field_desc">%s</p>', $args["desc"]);
        }
        if (isset($args["file"]) && file_exists($args["file"])) {
            $content = file_get_contents($args["file"]);
            if ($content) {
                printf('<pre class="filecontents">%s</pre>', trim(htmlentities($content)));
            }
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
		printf('<select name="paypal_options[paypal_currency]" id="paypal_currency"><option value=""%s>Please select a currency</option>', $sel);
		foreach ($cur as $code => $currency_name) {
			$sel = ($code == $currency)? ' selected="selected"': '';
			printf('<option value="%s"%s>%s</option>', $code, $sel, $currency_name);
		}
		print('</select>');
        print('<p class="field_desc">This contains all supported currencies in Paypal, as outlined on the <a href="https://cms.paypal.com/uk/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_currency_codes">Paypal developer site</a>.</p>');
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
	    	$options['paypal_sandbox_email'] = $defaults["paypal_sandbox_email"];
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
        $options["allow_pickup"] = isset($options["allow_pickup"]);
        $options["pickup_address"] = trim($options["pickup_address"]);
        if ($options["allow_pickup"] && $options["pickup_address"] == "") {
            add_settings_error('pickup_address', 'pickup_address', "Please specify a pick-up address");
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
        return $nc / 100;
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
            "supported_post_types" => "",
            "vat_rate" => "20",
            "allow_pickup" => false,
            "pickup_address" => ""
		);
        $options = get_option("paypal_options");
        if (isset($options["cart_page_id"])) {
            $options["cart_url"] = get_permalink($options["cart_page_id"]);
        } else {
            $options["cart_url"] = false;
        }
        $options["use_sandbox"] = (isset($options["paypal_sandbox"]));
        $options["use_global_shipping"] = (isset($options["base_shipping"]) && preg_match("/^[0-9\.]+$/", $options["base_shipping"]) && isset($options["item_shipping"]) && preg_match("/^[0-9\.]+$/", $options["item_shipping"]));
    	return wp_parse_args($options, $defaults);
    }
    
    /**************************
     * IPN PROCESSING         *
     **************************/
    
    /**
	 * function to process Instant Payment Notifications from Paypal
	 */
    function processIPN()
    {
    	$options = self::get_paypal_options();
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
        	    	$paypal = get_paypal_info($_POST["item_number" . $i]);
        		    if (isset($paypal["stock"]) && (int) $paypal["stock"] != 0) {
        		        $paypal["stock"] = (int) $paypal["stock"] - (int) $_POST["quantity" . $i];
        		        if ($paypal["stock"] < 0) {
        		    	    $paypal["stock"] = 0;
        		        }
        		        update_paypal_info($_POST["item_number" . $i], $paypal);
        		    }
        	    }
        	    $i++;
            }
            /* store IPN in database */
            global $wpdb;
            $tablename = $wpdb->prefix . "payments";
            $wpdb->insert($tablename, array("payment_date" => time(), "payment_ipn" => serialize($ipn_data)));
        }
        if (is_email($options["paypal_ipn_email"])) {
            wp_mail($options["paypal_ipn_email"], "IPN CURL report", "CURL result: " . $curl_result . "\n\nCURL error: " . $curl_err . "\n\nCURL info: " . $ci . "\n\nIPN:\n\n" . $req, "From: " . $options["paypal_email"] . "\r\nReply-To: " . $options["paypal_email"] . "\r\n");
        }
        curl_close($ch);
    }
    
	/**
	 * paypal payments page
	 */
    function get_paypal_payments_page()
    {
        global $wpdb;
        $tablename = $wpdb->prefix . "payments";
        $payments = $wpdb->get_results("SELECT * FROM $tablename ORDER BY `payment_date` DESC");
        print('<h2>Paypal payments</h2><table summary="paypal payments" class="widefat"><thead><th>date</th><th>Name</th><th>email</th><th>items</th><th>amount</th></tr></thead><tbody>');
        $allitems = array();
        foreach ($payments as $payment) {
            $ipn = unserialize($payment->payment_ipn);
            $name = $ipn["first_name"] . " " . $ipn["last_name"];
            $email = $ipn["payer_email"];
            $items = array();
            $item_number = 1;
            while(isset($ipn["item_name" . $item_number])) {
                if (!isset($allitems[$ipn["item_name" . $item_number]])) {
                    $allitems[$ipn["item_name" . $item_number]] = 0;
                }
                $allitems[$ipn["item_name" . $item_number]]++;
                $items[] = $ipn["item_name" . $item_number];
                $item_number++;
            }
            printf('<tr><td valign="top">%s</td><td valign="top">%s</td><td valign="top">%s</td><td valign="top">%s</td><td valign="top"><strong>&pound;%s</strong></td></tr>', date("d/m/Y", $payment->payment_date), $name, $email, implode("<br />", $items), $ipn["mc_gross"]);
	    }
	    print('</tbody></table>');
	    print('<h2>Sales summary</h2><table summary="sales summary" class="widefat"><thead><th>item name</th><th>number sold</tr></thead><tbody>');
	    foreach ($allitems as $item_name => $count) {
	        printf('<tr><td>%s</td><td>%s</td></tr>', $item_name, $count);
	    }
	    print('</tbody></table>');
    }

    /**
     * gets an invoice for the sale based on IPN data
     */
    private static function get_invoice($ipn_id)
    {
        $id = intVal($ipn_id);
        global $wpdb;
        $tablename = $wpdb->prefix . "payments";
        if ($id) {
            $payment = $wpdb->get_row("SELECT * FROM $tablename WHERE `ipn_id` = $id");
            $ipn = unserialize($payment->payment_ipn);
            $name = $ipn["first_name"] . " " . $ipn["last_name"];
            $email = $ipn["payer_email"];
            $items = array();
            $item_number = 1;
            while(isset($ipn["item_name" . $item_number])) {
                if (!isset($allitems[$ipn["item_name" . $item_number]])) {
                    $allitems[$ipn["item_name" . $item_number]] = 0;
                }
                $allitems[$ipn["item_name" . $item_number]]++;
                $items[] = $ipn["item_name" . $item_number];
                $item_number++;
            }
            printf('<tr><td valign="top">%s</td><td valign="top">%s</td><td valign="top">%s</td><td valign="top">%s</td><td valign="top"><strong>&pound;%s</strong></td></tr>', date("d/m/Y", $payment->payment_date), $name, $email, implode("<br />", $items), $ipn["mc_gross"]);
        }
    } 

    /**
     * adds contextual help to the options, tools and editing pages
     */
    function add_contextual_help()
    {
        $help_tabs = array(
            "settings_page_paypal_options" => array(
                array(
                    "id" => "help_tab_settings",
                    "title" => "Paypal Settings",
                    "content" => "<h3>Paypal Settings</h3><p>This is where you store settings which are used for PayPal payments throughout the site.</p><h4>Options</h4><dl><dt>Paypal email address</dt><dd>The email address which is registered at Paypal and able to accept payments.</dd>    <dt>Use Paypal sandbox?</dt><dd>This box should be checked when the plugin is being tested - it will result in all transactions taking place in a &ldquo;sandbox&rdquo; rather than on the live paypal site</dd><dt>Paypal sandbox email address</dt><dd>If you are using the paypal sandbox, you need to enter your businees email address for the sandbox here.</dd><dt>Paypal Currency</dt><dd>Select your currency from the list of supported currencies.</dd><dt>Base shipping cost</dt><dd>If you fill in this option, you will activate a default postal handling system where you set a base shipping fee, and add a fixed amount for eaxh item on top of this base fee.</dd><dt>Item shipping cost</dt><dd>In conjunction with the <em>Base shipping</em> above, this amount is added to shipping for each item. If shipping amounts are set for individual items, they override this amount.</dd><dt>Allow pick-up</dt><dd>If this option is checked, users are allowed to bypass any shipping costs before checkout by agreeing to pick the items up.</dd></dl>"
                )
            ),
            "tools_page_paypal_payments" => array(
            )
        );
        $screen = get_current_screen();
        if (isset($help_tabs[$screen->id]) && count($help_tabs[$screen->id])) {
            foreach ($help_tabs[$screen->id] as $tab) {
                $screen->add_help_tab($tab);
            }
        }
    }
}
PayPalPlugin::register();
