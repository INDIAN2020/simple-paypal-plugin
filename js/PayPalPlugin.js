/*
Plugin Name: PayPal Plugin
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

/**
 *Javascript functions used on the front-end
 * @see jQuery
 */
 jQuery(function($){
    /* ajaxify enquiry form */
    if ($(".pp-enquiry-form").length) {
        $('.pp-enquiry-form-wrap').before('<a href="/contact" class="pp-button pp-enquiry-button" title="Ask a question about this item">Ask a question about this product</a>').hide();
        $('.pp-enquiry-form').submit(function() {
            var instance_id = $('.pp-submit-enquiry', this).attr("data-ppinstance");
            var ajaxurl = $('.pp-submit-enquiry', this).attr("data-ajaxurl");
            var email = $('#pp_sender_email_'+instance_id).val();
            if ((email.indexOf(".") > 2) && (email.indexOf("@") > 0)) {
                var data = {
                    'action': 'enquiryform',
                    'pp_name': $('#pp_name_'+instance_id).val(),
                    'pp_price': $('#pp_price_'+instance_id).val(),
                    'pp_code': $('#pp_code_'+instance_id).val(),
                    'pp_stock': $('#pp_stock_'+instance_id).val(),
                    'pp_product_page_id': $('#pp_product_page_id_'+instance_id).val(),
                    'pp_sender_email': email,
                    'pp_sender_name': $('#pp_sender_name_'+instance_id).val(),
                    'pp_sender_message': $('#pp_sender_message_'+instance_id).val()
                };
                var container = $(this).parent('.pp-enquiry-form-wrap');
                $.ajax({
                    url:ajaxurl,
                    data:data,
                    type:"POST",
                    success:function(data, textstatus){
                        if (data == "0") {
                            container.html("<p>Sorry, your message could not be set. Please try again later.</p>");
                        } else {
                            container.html("<p>Thanks for your message.</p>");
                        }
                        setTimeout(function(){
                            container.fadeOut(1000);
                            container.prev().fadeOut(1000);
                        },2000);
                    }
                });
            }
            return false;
        });
        $('.pp-enquiry-button').on("click", function(e){
            $(this).next().slideDown("slow");
            $(this).addClass("active");
            e.stopPropagation();
            return false;
        });
     }
 });