/**
 * javascript used in admin backend
 * @version 1.0
 * @author Peter Edwards <Peter.Edwards@p-2.biz>
 * @uses jQuery
 */
;(function($){
	if ($('#paypal_sandbox').length) {
		var email_row = ($('#paypal_sandbox_email').parents('tr'));
		if ($('#paypal_sandbox').is(':checked')) {
			email_row.show();
		} else {
			email_row.hide();
		}
		$('#paypal_sandbox').on('click', function(e) {
			if ($(this).is(':checked')) {
				email_row.show();
			} else {
				email_row.hide();
			}
		})
	}
	if ($('#allow_pickup').length) {
		var address_row = ($('#pickup_address').parents('tr'));
		if ($('#allow_pickup').is(':checked')) {
			address_row.show();
		} else {
			address_row.hide();
		}
		$('#allow_pickup').on('click', function(e) {
			if ($(this).is(':checked')) {
				address_row.show();
			} else {
				address_row.hide();
			}
		})
	}
	var formfield = '';
	var imagepreview = false;
	$('.upload_image_button').click(function(e) {
        formfield = $(this).attr("id").substr(4);
        imagepreview = true;
        tb_show('', 'media-upload.php?type=image&amp;TB_iframe=true');
        e.preventDefault();
        return false;
    });
    /* callback for media upload */
    window.send_to_editor = function(html) {
        imgurl = $('img',html).attr('src');
        if (typeof(imgurl) === "undefined") {
            imgurl = $(html).attr('src');
        }
        if (typeof(imgurl) !== "undefined") {
            $('#'+formfield).val(imgurl);
            if (imagepreview) {
            	if ($('#'+formfield+'_preview img').length) {
            		$('#'+formfield+'_preview img').attr("src", imgurl);
            	} else {
            		$('#'+formfield+'_preview').append('<img src="'+imgurl+'" />');
            	}
            }
        }
        tb_remove();
    }
    $('.clear_media_button').click(function(){
        $('#'+$(this).attr("rel")).val("");
        $('#'+$(this).attr("rel")+'_preview img').remove();
    });

}(jQuery));