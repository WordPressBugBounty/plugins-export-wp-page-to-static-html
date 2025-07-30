var $ = jQuery;

	/**
	 * All of the code for your admin-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */


function matchCustom(params, data) {
    // If there are no search terms, return all of the data
    if ($.trim(params.term) === '') {
      return data;
    }

    // Do not display the item if there is no 'text' property
    if (typeof data.text === 'undefined') {
      return null;
    }

    // `params.term` should be the term that is used for searching
    // `data.text` is the text that is displayed for the data object
    if (data.text.indexOf(params.term) > -1) {
      var modifiedData = $.extend({}, data, true);
      modifiedData.text += ' (matched)';

      // You can return modified objects from here
      // This includes matching the `children` how you want in nested data sets
      return modifiedData;
    }

    // Return `null` if the term should not be displayed
    return null;
}

$(document).on("select2:selecting", function(e){
	if ( $(e.target).is('#export_pages') ) {
		if (!$('#posts_list').length) {
			$('#export_pages').append('<option id="posts_list" disabled="disabled">Posts</option>').change();
		}
	}	
});


$(document).on("click", ".select2-selection__choice__remove", function(){
  var data = $('#export_pages').val();

  if (data == null) {
  	$('.select_multi_pages').show();
  }
});

$(document).on("click", ".select_multi_pages", function(){
	$('.select2-selection__rendered').click();
});


$(document).on("click", ".static_html_settings .nav-item .nav-link", function(e){
	e.preventDefault();

	$('.static_html_settings .nav-item .nav-link').removeClass('active');
	$('.static_html_settings .tab-pane').removeClass('active');
	$(this).addClass('active');

	var link = $(this).attr('href');
	$(link).addClass('active');

});

$(document).on("mouseenter", ".newly-added-list", function(){
	$(this).addClass('select2-results__option--highlighted');
});

$(document).on("click", ".newly-added-list", function(){
	var page_id = $(this).attr('value');

	 $('#export_pages').val(page_id).change();
});

function rc_ajax_select2(){

	$('#export_pages').select2({
			minimumInputLength: 1,
			maximumSelectionLength: 3,
		  ajax: {
			url: rcewpp.ajax_url, // AJAX URL is predefined in WordPress admin
				dataType: 'json',
				delay: 250, // delay in ms while typing when to perform a AJAX search
				data: function (params) {
					return {
						value: params.term, // search query
						action: 'rc_search_posts', // AJAX action for admin-ajax.php
						rc_nonce: rcewpp.nonce
					};
				}
		  },
            templateResult: function (idioma) {
                var permalink = $(idioma.element).attr('permalink');
                var $span = $("<span permalink='"+idioma.permalink+"'>" + idioma.text + "</span>");
                return $span;
            }
	});	
}
$(document).on("change", "#search_posts_to_select2", function(e){
	if ($(this).is(":checked")) {
		rc_ajax_select2();
	} else {
		rc_select2_is_not_ajax();
	}
});




$(document).on("change", ".checkbox-container input", function(){
	if ($(this).is(':checked')) {
		$(this).parent().siblings('.export_html_sub_settings').slideDown();
	} else {
		$(this).parent().siblings('.export_html_sub_settings').slideUp();
	}
});

$(document).on("change", "#upload_to_ftp2", function(){
	if ($(this).is(':checked')) {
		$('.ftp_Settings_section2').slideDown();
	} else {
		$('.ftp_Settings_section2').slideUp();
	}
});

$(document).on("change", "#email_notification", function(){
	if ($(this).is(":checked")) {
		$('.email_settings_item').slideDown();
	} else {
		$('.email_settings_item').slideUp();
	}
});
function removeHtmlZipFile() {
  var txt;
  var r = confirm("Are you sure you would like to remove the file?");
  if (r == true) {
    return true;
  } else {
    return false;
  }
}

$(document).on("click", ".delete_zip_file", function(){
	var this_ = $(this);
	var file_name = this_.attr('file_name');
	if (removeHtmlZipFile()) {
		var datas = {
			'action': 'delete_exported_zip_file',
			'rc_nonce': rcewpp.nonce,
			'file_name': file_name,
		};


		$.ajax({
			url: rcewpp.ajax_url,
			data: datas,
			type: 'post',
			dataType: 'json',

			beforeSend: function(){

			},
			success: function(r){
				if(r.success == 'true'){

					this_.closest('.exported_zip_file').remove();


				} else {
					console.log('Something went wrong, please try again!');
				}

			}, error: function(){

			}
		});
	}
});

 $(document).on("click", ".support.my-2", function(e){
    e.preventDefault();

	 StopInterval();
	 console.log('Interval Stopped')
  });

$(document).on("click", ".btn_save_settings", function(e){
    e.preventDefault();

    var createIndexOnSinglePage = $('#createIndexOnSinglePage').is(':checked') ? true : false;
    var saveAllAssetsToSpecificDir = $('#saveAllAssetsToSpecificDir').is(':checked') ? true : false;
    var addContentsToTheHeader = $('#addContentsToTheHeader').val();
    var addContentsToTheFooter = $('#addContentsToTheFooter').val();
    var userRoles = $('[name*="user_roles"]:checked');

    var userRolesArray = [];
    $(userRoles).each(function (i, v) {
		userRolesArray.push($(v).val());
	})
     var datas = {
       'action': 'saveAdvancedSettings',
       'rc_nonce': rcewpp.nonce,
       'createIndexOnSinglePage': createIndexOnSinglePage,
       'saveAllAssetsToSpecificDir': saveAllAssetsToSpecificDir,
       'addContentsToTheHeader': addContentsToTheHeader,
       'addContentsToTheFooter': addContentsToTheFooter,
		 'userRolesArray': userRolesArray
     };

     $.ajax({
         url: rcewpp.ajax_url,
         data: datas,
         type: 'post',
         dataType: 'json',

         beforeSend: function(){
			$('.btn_save_settings .spinner_x').removeClass('hide_spin');
         },
         success: function(r){
            if(r.success){
                $('.badge_save_settings').show();
				$('.btn_save_settings .spinner_x').addClass('hide_spin');

                setTimeout(function(){
					$('.badge_save_settings').hide();
				}, 5000)
            } else {
                console.log('Something went wrong, please try again!');
				$('.btn_save_settings .spinner_x').addClass('hide_spin');
            }
         },
         error: function(){
            console.log('Something went wrong, please try again!');
			 $('.btn_save_settings .spinner_x').addClass('hide_spin');
         }
     });
});

$(document).on("click", ".btn_save_pdf_settings", function(e){
    e.preventDefault();

    var userRoles = $('[name*="user_roles_for_pdf"]:checked');

    var userRolesArray = [];
    $(userRoles).each(function (i, v) {
		userRolesArray.push($(v).val());
	})
     var datas = {
    	'action': 'savePdfSettings',
    	'rc_nonce': rcewpp.nonce,
		'userRolesArray': userRolesArray
     };

     $.ajax({
         url: rcewpp.ajax_url,
         data: datas,
         type: 'post',
         dataType: 'json',

         beforeSend: function(){
			$('.btn_save_pdf_settings .spinner_x').removeClass('hide_spin');
         },
         success: function(r){
            if(r.success){
                $('.badge_save_settings').show();
				$('.btn_save_pdf_settings .spinner_x').addClass('hide_spin');

                setTimeout(function(){
					$('.badge_save_settings').hide();
				}, 5000)
            } else {
                console.log('Something went wrong, please try again!');
				$('.btn_save_pdf_settings .spinner_x').addClass('hide_spin');
            }
         },
         error: function(){
            console.log('Something went wrong, please try again!');
			 $('.btn_save_pdf_settings .spinner_x').addClass('hide_spin');
         }
     });
});

$(document).on("click", ".cancel_rc_html_export_process", function(e){
	e.preventDefault();

	$('#cancel_ftp_process').val('true');

	var datas = {
	  'action': 'cancel_rc_html_export_process',
	  'rc_nonce': rcewpp.nonce,
	  'post2': '',
	};
	
	$.ajax({
	    url: rcewpp.ajax_url,
	    data: datas,
	    type: 'post',
	    dataType: 'json',
	
	    beforeSend: function(){
	
	    },
	    success: function(r){
	      	if(r.success == 'true'){
				rc_export_pages_failed(true)
				.then( (message) => {
					if(!$('.log.cancel_command').length){
						$('.logs_list').prepend('<div class="log main_log cancel_command" id="48"><span class="danger log_type">Export process has been canceled!</span></div>')
					}
				})
	        } else {
	          console.log('Something went wrong, please try again!');
	        }
	    	
	    }, error: function(){
	    	
	  	}
	});
});


$(document).on("input", "#image_quality, #custom_image_quality", function(e){
	$(this).parent().siblings('input').val($(this).val())
});


jQuery(document).ready(function($) {
  let selectedRating = 0;

  // Mouseenter: Highlight stars on hover
  $('.wpptsh-stars span').on('mouseenter', function() {
    const hoverValue = $(this).data('star');
    $('.wpptsh-stars span').each(function() {
      const starVal = $(this).data('star');
      if (starVal <= hoverValue) {
        $(this).addClass('hovered');
      } else {
        $(this).removeClass('hovered');
      }
    });
  });

  // Mouseleave: Remove hover highlight
  $('.wpptsh-stars').on('mouseleave', function() {
    $('.wpptsh-stars span').removeClass('hovered');
  });

  // Star click
  $('.wpptsh-stars span').on('click', function() {
    selectedRating = $(this).data('star');
    $('.wpptsh-stars span').removeClass('selected');
    $(this).prevAll().addBack().addClass('selected');

    if (selectedRating == 5) {
      $('#wpptsh-feedback-form').hide();
      $('#wpptsh-review-message').html("🌟 Thank you for the 5-star! Redirecting you to leave a review...");
	  
		$.ajax({
		url: rcewpp.ajax_url,
		method: 'POST',
		data: {
			action: 'wpptsh_save_review',
			rating: selectedRating,
		}
		});

      setTimeout(function() {
        window.open('https://wordpress.org/support/plugin/export-wp-page-to-static-html/reviews/?filter=5', '_blank');
      }, 2000);
    } else {
      $('#wpptsh-feedback-form').fadeIn();
    }
  });

  // Feedback submit
  $('#wpptsh-submit-review').on('click', function() {
    const comment = $('#wpptsh-review-text').val();
    if (comment.trim() === '') {
      $('#wpptsh-review-message').text('Please write your feedback.');
      return;
    }

    $.ajax({
      url: rcewpp.ajax_url,
      method: 'POST',
      data: {
        action: 'wpptsh_save_review',
        rating: selectedRating,
        comment: comment,
		'rc_nonce': rcewpp.nonce,
      },
      success: function(response) {
        $('#wpptsh-review-message').text('Thanks! Your feedback has been submitted.');
        $('#wpptsh-feedback-form').hide();
      }
    });
  });

  	$('#wpptsh-already-rated').on('click', function() {
		$('#wpptsh-review-section').fadeOut();
		    $.ajax({
			url: rcewpp.ajax_url,
			method: 'POST',
			data: {
				action: 'wpptsh_hide_review',
	  			'rc_nonce': rcewpp.nonce,
			},
			success: function(response) {
				$('#wpptsh-feedback-form').hide();
			}
		});
	});

	$('#wpptsh-close-review').on('click', function() {
	const now = Date.now(); // Current timestamp in milliseconds
	localStorage.setItem('wpptsh_review_later', now);
	console.log('Review box hidden until next week');
	// Hide the review box (optional)
	$('#wpptsh-review-section').hide();
	});

});
  function showReviewSection() {
    const reviewLater = localStorage.getItem('wpptsh_review_later');
    const oneWeek = 7 * 24 * 60 * 60 * 1000;
    const now = Date.now();

	if (reviewLater !== null) {

    console.log('Current Time:', now);
    console.log('Stored reviewLater:', reviewLater);
		const reviewTimestamp = parseInt(reviewLater, 10);

		if (!isNaN(reviewTimestamp)) {
			const timeSince = now - reviewTimestamp;
			console.log('Time since reviewLater:', timeSince, 'ms');

			if (timeSince < oneWeek) {
			console.log('Review postponed recently. Hiding box.');
			$('#wpptsh-review-section').hide();
			return;
			} else {
			console.log('More than 7 days passed. Showing box.');
			localStorage.removeItem('wpptsh_review_later');
			}
		} else {
			console.log('Invalid reviewLater timestamp in localStorage.');
			localStorage.removeItem('wpptsh_review_later');
		}
	}

    $('#wpptsh-review-section').fadeIn();
  }

