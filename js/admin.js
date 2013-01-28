
;(function($){
	var log2console = function(msg) {
		if (window.console && window.console.log) {
			window.console.log(msg);
		}
	},
	formatCurrency = function(val) {
		if ($.trim(val) == '') {
			return '0.00';
		} else {
			var valueStr, value100 = Math.floor(parseFloat(val)*100);
			if (value100 < 10) {
				return '0.0'+value100;
			} else if (value100 < 100) {
				return '0.'+value100;
			} else {
				valueStr = ''+value100; 
				return valueStr.substr(0, (valueStr.length - 2))+'.'+valueStr.substr(-2);
			}
		}
	};
	/* hide the delete button on the default band */
	$('.delete-band-button').each(function(){
		var id = $(this).attr('data-band-id');
		if ($('#pp_band_default_'+id).is(':checked')) {
			log2console('hiding delete button for default band (id: '+id+')');
			$('#delete-button-'+id).hide();
		}
	});
	/* make sure one of the bands is set as the default */
	if ($('.default-band').length && !$('.default-band:checked').length) {
		log2console('setting first postage band to default');
		$('.default-band:first').attr('checked', true);
	} 
	/* make sure there is at least one weight setting form shown */
	if ($('.delete-weight-button').length == 1) {
		$('.delete-weight-button').hide();
	}
	/* hide the delete button for the default */
	$('#shipping-settings').on('click', '.default-band', function(e){
		if (this.checked) {
			var id = $(this).val();
			log2console('hiding delete button for default band (id: '+id+')');
			$('.delete-band').show();
			$('#delete-button-'+id).hide();
		}
	});
	/* click action for delete band button */
	$('#shipping-bands').on('click', '.delete-band-button', function(e){
		var id = $(this).attr('data-band-id');
		if ($('.default-band').length == 1 && $('#pp_band_default_'+id).is(':checked')) {
			alert("You cannot delete the default band - please change it first");
		} else {
			log2console('deleting band form');
			$('#band_'+id).remove();
			if (!$('.default-band:checked').length) {
				log2console('making sure a default band is selected');
				$('.default-band:first').attr('checked', 'checked');
			}
		}
		e.stopPropagation();
		return false;
	});
	/* adds a newform for an additional shipping band */
	$('#shipping-settings').on('click', '#add-band', function(e){
		/* determine next id for band */
		var nextid = -1, newid = 0, chckd = '', btn = '', html = '';
		$('.shipping-band').each(function(){
			var newid = parseInt($(this).attr('data-band-id'));
			nextid = Math.max(nextid, newid);
		});
		nextid++;
		log2console('adding a new band with id: '+nextid);
		/* if this is the first band (after a switch in method), make it default */
		chckd = (!$('.shipping-band').length)? ' checked="checked"': '';
		/* assemble form for shipping band */
		html += '<fieldset class="shipping_band" id="band_'+nextid+'"><input type="hidden" name="paypal_options[shipping_settings][band_ids][]" value="'+nextid+'" />';
		html += '<p><label for="pp_band_name_'+nextid+'">Name:</label><input type="text" name="paypal_options[shipping_settings][band][name_'+nextid+']" id="pp_band_name_'+nextid+'" value="" /></p>';
		html += '<p><label for="pp_band_default_%s" class="wide"><input type="radio" id="pp_band_default_'+nextid+'" class="default-band" name="paypal_options[shipping_settings][default_band]" value="'+nextid+'"'+chckd+'> Check this box to make this the default postage band</label></p>';
		for (r in regions) {
			html += '<fieldset><legend>'+regions[r]+'</legend>';
			html += '<p><label for="pp_shipping_one_'+r+'_'+nextid+'">First item:</label><input type="text" name="paypal_options[shipping_settings][band][shipping_one_'+r+'_'+nextid+']" id="pp_shipping_one_'+r+'_'+nextid+'" value="" class="currency" /></p>';
			html += '<p><label for="pp_shipping_multiple_'+r+'_'+nextid+'">Subsequent items:</label><input type="text" name="paypal_options[shipping_settings][band][shipping_multiple_'+r+'_'+nextid+']" id="pp_shipping_multiple_'+r+'_'+nextid+'" value="" class="currency" /></p>';
			html += '</fieldset>';
		}
		html += '<p id="delete-button-'+nextid+'" class="delete-band"><a href="#" class="delete-band-button button-secondary" data-band-id="'+nextid+'">delete this band</a></p></fieldset>';
		$('#shipping-bands').append(html);
		e.stopPropagation();
		return false;
	});
	/* click action for add weight button */
	$('#shipping-settings').on('click', '#add-weight', function(){
		/* determine next id for weight */
		var nextid = -1, newid = 0, html = '';
		$('.shipping-weight').each(function(){
			var newid = parseInt($(this).attr('data-weight-id'));
			nextid = Math.max(nextid, newid);
		});
		nextid++;
		/* assemble form for shipping weight */
		html += '<fieldset class="shipping_weight" data-weight-id="'+nextid+'" id="weight_'+nextid+'"><input type="hidden" name="weight_ids[]" value="'+nextid+'" />';
		html += '<p><label for="pp_to_weight_'+nextid+'">Up to and including items weighing: </label><input type="text" name="paypal_options[shipping_settings][weight][to_weight_'+nextid+']" id="pp_to_weight_'+nextid+'" size="5" />g</p>';
		for (r in regions) {
			html += '<p><label for="pp_shipping_weight_'+r+'_'+nextid+'">'+regions[r]+'</label><input type="text" name="paypal_options[shipping_settings][weight][shipping_weight_'+r+'_'+nextid+']", id="pp_shipping_weight_'+r+'_'+nextid+'" size="7" class="currency" /></p>';
		}
		html += '<p id="delete-button-'+nextid+'" class="delete-weight"><a href="#" class="delete-weight-button button-secondary" data-weight-id="'+nextid+'">delete this setting</a></p></fieldset>';
		$('#shipping-weights').append(html);
		$('.delete-weight-button').show();
	});
	/* click action for delete weight settings button */
	$('#shipping-settings').on('click', '.delete-weight-button', function(e){
		var id = $(this).attr('data-weight-id');
		if ($('.delete-weight-button').length == 1) {
			alert("You cannot delete all weight settings");
		} else {
			log2console("deleting weight settings form");
			$('#weight_'+id).remove();
			if ($('.delete-weight-button').length == 1) {
				$('.delete-weight-button').hide();
			}
		}
		e.stopPropagation();
		return false;
	});
	/* switch methods between bands and weights */
	$('#switch_method').on('click', function(e){
		e.preventDefault();
		var currentmethod = $('#shipping_method').val();
		$('#shipping_method').val((currentmethod == "bands"? "weights": "bands"));
		$(this).parents('form').submit();
		return false;
	});
	$('#shipping-settings').on('blur', '.currency', function(){
		$(this).val(formatCurrency($(this).val()));
	});

}(jQuery));