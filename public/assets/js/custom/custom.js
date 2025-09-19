// @ts-nocheck


$(document).ready(function () {

    /// START :: ACTIVE MENU CODE
    $(".menu a").each(function () {
        let pageUrl = window.location.href.split(/[?#]/)[0];
        if (this.href == pageUrl) {
            $(this).parent().parent().addClass("active");
            $(this).parent().addClass("active"); // add active to li of the current link
            $(this).parent().parent().prev().addClass("active"); // add active class to an anchor
            $(this).parent().parent().parent().addClass("active"); // add active class to an anchor
            $(this).parent().parent().parent().parent().addClass("active"); // add active class to an anchor
        }

        let subURL = $("a#subURL").attr("href");
        if (subURL != 'undefined') {
            if (this.href == subURL) {
                $(this).parent().addClass("active"); // add active to li of the current link
                $(this).parent().parent().addClass("active");
                $(this).parent().parent().prev().addClass("active"); // add active class to an anchor
                $(this).parent().parent().parent().addClass("active"); // add active class to an anchor
            }
        }
    });
    /// END :: ACTIVE MENU CODE
    if ($('.select2').length > 0) {
        $('.select2').select2();
    }

    $('.select2-selection__clear').hide();

    FilePond.registerPlugin(FilePondPluginImagePreview, FilePondPluginFileValidateSize,
        FilePondPluginFileValidateType);

    if ($('.filepond').length > 0) {
        $('.filepond').filepond({
            credits: null,
            allowFileSizeValidation: "true",
            maxFileSize: '25MB',
            labelMaxFileSizeExceeded: 'File is too large',
            labelMaxFileSize: 'Maximum file size is {filesize}',
            allowFileTypeValidation: true,
            acceptedFileTypes: ['image/*'],
            labelFileTypeNotAllowed: 'File of invalid type',
            fileValidateTypeLabelExpectedTypes: 'Expects {allButLastType} or {lastType}',
            storeAsFile: true,
            allowPdfPreview: true,
            pdfPreviewHeight: 320,
            pdfComponentExtraParams: 'toolbar=0&navpanes=0&scrollbar=0&view=fitH',
            allowVideoPreview: true, // default true
            allowAudioPreview: true // default true
        });
    }

    //magnific popup
    $(document).on('click', '.image-popup-no-margins', function () {
        $(this).magnificPopup({
            type: 'image',
            closeOnContentClick: true,
            closeBtnInside: false,
            fixedContentPos: true,
            image: {
                verticalFit: true
            },
            zoom: {
                enabled: true,
                duration: 300 // don't forget to change the duration also in CSS
            },
            gallery: {
                enabled: true
            },
        }).magnificPopup('open');
        return false;
    });

    $('#table_list').on('load-success.bs.table', function () {
        if ($('.gallery').length > 0) {
            $('.gallery').each(function () { // the containers for all your galleries
                $(this).magnificPopup({
                    delegate: 'a', // the selector for gallery item
                    type: 'image',
                    gallery: {
                        enabled: true
                    }
                });
            });
        }
    })

    $(document).off('focusin');
});


/// START :: TinyMCE
document.addEventListener("DOMContentLoaded", () => {
    tinymce.init({
        selector: '#tinymce_editor',
        height: 400,
        menubar: true,
        plugins: [
            'advlist autolink lists link charmap print preview anchor',
            'searchreplace visualblocks code fullscreen',
            'insertdatetime table paste code help wordcount'
        ],

        toolbar: 'insert | undo redo |  formatselect | bold italic backcolor  | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | help',
        setup: function (editor) {
            editor.on("change keyup", function () {
                //tinyMCE.triggerSave(); // updates all instances
                editor.save(); // updates this instance's textarea
                $(editor.getElement()).trigger('change'); // for garlic to detect change
            });
        }
    });
});

$('body').append('<div id="loader-container"><div class="loader"></div></div>');
$(window).on('load', function () {
    $('#loader-container').fadeOut('slow');
});

setTimeout(function () {
    $(".error-msg").fadeOut(1500)
}, 5000);

document.addEventListener('touchstart', event => {
    if (event.cancelable) {
        event.preventDefault();
    }
});

document.addEventListener('touchmove', event => {
    if (event.cancelable) {
        event.preventDefault();
    }
});

document.addEventListener('touchcancel', event => {
    if (event.cancelable) {
        event.preventDefault();
    }
});

$('.status-switch').on('change', function () {
    if ($(this).is(":checked")) {
        $(this).siblings('input[type="hidden"]').val(1);
    } else {
        $(this).siblings('input[type="hidden"]').val(0);
    }
})

$('input[type="radio"][name="duration_type"]').on('click', function () {
    if ($(this).hasClass('edit_duration_type')) {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#edit_limitation_for_duration').show();
                $('#edit_durationLimit').attr("required", "true").val("");
            } else {
                // Unlimited
                $('#edit_limitation_for_duration').hide();
                $('#edit_durationLimit').removeAttr("required").val("");
            }
        }
    } else {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#limitation_for_duration').show();
                $('#durationLimit').attr("required", "true").val("");
            } else {
                // Unlimited
                $('#limitation_for_duration').hide();
                $('#durationLimit').removeAttr("required").val("");
            }
        }
    }
});

$('input[type="radio"][name="item_limit_type"]').on('click', function () {
    if ($(this).hasClass('edit_item_limit_type')) {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#edit_limitation_for_limit').show();
                $('#edit_ForLimit').attr("required", "true");
            } else {
                // Unlimited
                $('#edit_limitation_for_limit').hide();
                $('#edit_ForLimit').val('');
                $('#edit_ForLimit').removeAttr("required");
            }
        }
    } else {
        if ($(this).is(':checked')) {
            if ($(this).val() == 'limited') {
                $('#limitation_for_limit').show();
                $('#durationForLimit').attr("required", "true");
            } else {
                // Unlimited
                $('#limitation_for_limit').hide();
                $('#durationForLimit').removeAttr("required");
            }
        }
    }
});

$('#filter').change(function () {
    let selectedValue = $(this).val();
    // Hide all criteria elements initially
    $('#category_criteria, #price_criteria').hide();
    // Show the relevant criteria based on the selected option
    if (selectedValue === "category_criteria") {
        $('#category_criteria').show();
    } else if (selectedValue === "price_criteria") {
        $('#price_criteria').show();
    }
});

$('#edit_filter').change(function () {
    let selectedValue = $(this).val();
    $('#edit_min_price').val("");
    $('#edit_max_price').val("");
    // Hide all criteria elements initially
    $('#edit_category_criteria, #edit_price_criteria').hide();
    // Show the relevant criteria based on the selected option
    if (selectedValue === "category_criteria") {
        $('#edit_category_criteria').show();
    } else if (selectedValue === "price_criteria") {
        $('#edit_price_criteria').show();
    }
});

$("#include_image").change(function () {
    if (this.checked) {
        $('#show_image').show('fast');
        $('#file').attr('required', 'required');
    } else {
        $('#file').val('');
        $('#file').removeAttr('required');
        $('#show_image').hide('fast');
    }
});


$('#user_notification_list').on('check.bs.table  uncheck.bs.table', function () {
    let user_list = [];
    let data = $("#user_notification_list").bootstrapTable('getSelections');
    data.forEach(function (value) {
        if (value.id != "") {
            user_list.push(value.id);
        }
    })
    $('textarea#user_id').text(user_list);
});

$('#delete_multiple').on('click', function (e) {
    e.preventDefault();
    let table = $('#table_list');
    let selected = table.bootstrapTable('getSelections');
    let ids = "";

    $.each(selected, function (i, e) {
        ids += e.id + ",";
    });
    ids = ids.slice(0, -1);
    if (ids == "") {
        showErrorToast(trans('Please Select Notification First'));
    } else {
        showDeletePopupModal($(this).attr('href'), {
            data: {
                id: ids
            }, successCallBack: function () {
                $('#table_list').bootstrapTable('refresh');
            }
        })
    }
});


$(".checkbox-toggle-switch").on('change', function () {
    let inputValue = $(this).is(':checked') ? 1 : 0;
    $(this).siblings(".checkbox-toggle-switch-input").val(inputValue);
});

$('.toggle-button').on('click', function (e) {
    e.preventDefault();
    $(this).closest('.category-header').next('.subcategories').slideToggle();
});

let length = $('#sub_category_count').val();

for (let i = 1; i <= length; i++) {
    $('.child_category_list' + i).hide();
    $('#sub_category' + i).change(function () {
        $('#child_category' + i).prop("checked", $(this).is(":checked"));
    });

    $('#category_arrow' + i).on('click', function () {
        $('.child_category_list' + i).toggle();
    });
}

$('#type').on('change', function () {
    if ($.inArray($(this).val(), ['checkbox', 'radio', 'dropdown']) > -1) {
        $('#field-values-div').slideDown(500);
        $('.min-max-fields').slideUp(500);
    } else if ($.inArray($(this).val(), ['fileinput']) > -1) {
        $('.min-max-fields').slideUp(500);
    } else {
        $('#field-values-div').slideUp(500);
        $('.min-max-fields').slideDown(500);
    }
});

$('.image').on('change', function () {
    const allowedExtensions = /(\.jpg|\.jpeg|\.png|\.gif)$/i;
    const fileInput = this;
    const [file] = fileInput.files;
    if (!file) {
        return; // No file selected
    }

    if (!allowedExtensions.exec(file.name)) {
        $('.img_error').text('Invalid file type. Please choose an image file.');
        fileInput.value = '';
        return;
    }

    const maxFileSize = 2 * 1024 * 1024; // 5MB (adjust as needed)
    if (file.size > maxFileSize) {
        $('.img_error').text('File size exceeds the maximum allowed size (2MB).');
        fileInput.value = '';
    }
    if (file) {
        $(this).siblings('.preview-image').attr('src', URL.createObjectURL(file))
    }
});

$('.img_input').on('click', function () {
    $(this).siblings('.image').click();
});

$(".toggle-password").on('click', function () {
    $(this).toggleClass("bi bi-eye bi-eye-slash");
    let input = $(this).parent().siblings("input");
    if (input.attr("type") == "password") {
        input.attr("type", "text");
    } else {
        input.attr("type", "password");
    }
});

$('#price,#discount_in_percentage').on('input', function () {
    let price = $('#price').val();
    let discount = $('#discount_in_percentage').val();
    let final_price = calculateDiscountedAmount(price, discount);
    $('#final_price').val(final_price);
})

$('#final_price').on('input', function () {
    let discountedPrice = $(this).val();
    let price = $('#price').val();
    let discount = calculateDiscount(price, discountedPrice);
    $('#discount_in_percentage').val(discount);
})


$('#edit_price,#edit_discount_in_percentage').on('input', function () {
    let price = $('#edit_price').val();
    let discount = $('#edit_discount_in_percentage').val();
    let final_price = calculateDiscountedAmount(price, discount);
    $('#edit_final_price').val(final_price);
})

$('#edit_final_price').on('input', function () {
    let discountedPrice = $(this).val();
    let price = $('#edit_price').val();
    let discount = calculateDiscount(price, discountedPrice);
    $('#edit_discount_in_percentage').val(discount);
})
$('#slug').bind('keyup blur', function () {
    $(this).val($(this).val().replace(/[^A-Za-z0-9-]/g, ''))
});

function toggleRejectedReasonVisibility() {
    var status = $('#status').val();
    var rejectedReasonContainer = $('#rejected_reason_container');
    if (status === 'soft rejected' || status === 'permanent rejected') {
        rejectedReasonContainer.show();
    } else {
        rejectedReasonContainer.hide();
    }
}

$('.editdata, #status').on('click change', function () {
    toggleRejectedReasonVisibility();
});

$(document).on('change', '.update-item-status', function () {
    let url = window.baseurl + "common/change-status";
    ajaxRequest('PUT', url, {
        id: $(this).attr('id'),
        table: "items",
        column: "deleted_at",
        status: $(this).is(':checked') ? 1 : 0
    }, null, function (response) {
        showSuccessToast(response.message);
    }, function (error) {
        showErrorToast(error.message);
    })
})

$(document).on('change', '.update-user-status', function () {
    let url = window.baseurl + "common/change-status";
    ajaxRequest('PUT', url, {
        id: $(this).attr('id'),
        table: "users",
        column: "deleted_at",
        status: $(this).is(':checked') ? 1 : 0
    }, null, function (response) {
        showSuccessToast(response.message);
    }, function (error) {
        showErrorToast(error.message);
    })
})
$(document).on('change', '.update-auto-approve-status', function () {
    let url = window.baseurl + "common/change-status";
    ajaxRequest('PUT', url, {
        id: $(this).attr('id'),
        table: "users",
        column: "auto_approve_item",
        status: $(this).is(':checked') ? 1 : 0
    }, null, function (response) {
        showSuccessToast(response.message);
    }, function (error) {
        showErrorToast(error.message);
    });
});

$('#switch_banner_ad_status').on('change', function () {
    $('#banner_ad_id_android').attr('required', $(this).is(':checked'));
    $('#banner_ad_id_ios').attr('required', $(this).is(':checked'));
})
$('.package_type').on('change', function () {
    if ($(this).val() == 'item_listing') {
        $('#package_details').hide();
        $('.payment').hide();
        $('.cheque').hide();
        $('#item-listing-package-div').show();
        $('#advertisement-package-div').hide();

        $('#item-listing-package').attr('required', true);
        $('#advertisement-package').attr('required', false);
    } else if ($(this).val() == 'advertisement') {
        $('#package_details').hide();
        $('.payment').hide();
        $('.cheque').hide();
        $('#item-listing-package-div').hide();
        $('#advertisement-package-div').show();

        $('#advertisement-package').attr('required', true);
        $('#item-listing-package').attr('required', false);
    }
});

$('.package').on('change', function () {
    let package_detail = $(this).find('option:selected').data('details');
    let currency_settings = $('#currency-settings');
    let currency_symbol = currency_settings.data('symbol');
    let currency_position = currency_settings.data('position');

    if (package_detail != null) {
        $('#package_details').show();
        $('.payment').show();
    } else {
        $('#package_details').hide();
        $('.payment').hide();
        $('.cheque').hide();
    }
    let formatted_price = formatPriceWithCurrency(package_detail?.price, currency_symbol, currency_position);
    let formatted_final_price = formatPriceWithCurrency(package_detail?.final_price, currency_symbol, currency_position);
    let formatted_duration = package_detail?.duration ? `${package_detail?.duration} Days` : '';

    $("#package_name").text(package_detail?.name);
    $("#package_price").text(formatted_price);
    $("#package_final_price").text(formatted_final_price);
    $("#package_duration").text(formatted_duration);
});
function formatPriceWithCurrency(price, symbol, position) {
    if (!price) return "";
    return position === "left" ? `${symbol} ${price}` : `${price} ${symbol}`;
}

$('.payment_gateway').change(function () {
    if ($(this).val() == 'cheque') {
        $('.cheque').show();
    } else {
        $('.cheque').hide();
    }

    $('.payment').val('').trigger('change');
});

$('#switch_interstitial_ad_status').on('change', function () {
    $('#interstitial_ad_id_android').attr('required', $(this).is(':checked'));
    $('#interstitial_ad_id_ios').attr('required', $(this).is(':checked'));
})

$('#country').on('change', function () {
    let countryId = $(this).val();
    let url = window.baseurl + 'states/search?country_id=' + countryId;
    ajaxRequest('GET', url, null, null, function (response) {
        $('#state').html("<option value=''>" + window.trans("--Select State--") + "</option>")
        $.each(response.data, function (key, value) {
            $('#state').append($('<option>', {
                value: value.id,
                text: value.name
            }));
        });
    })
});

$('.country').on('change', function () {
    let countryId = $(this).val();
    let url = window.baseurl + 'states/search?country_id=' + countryId;
    ajaxRequest('GET', url, null, null, function (response) {
        $('#edit_state').html("<option value=''>" + window.trans("--Select State--") + "</option>")
        $.each(response.data, function (key, value) {
            $('#edit_state').append($('<option>', {
                value: value.id,
                text: value.name
            }));
        });
    })
});
$('#state').on('change', function () {
    let stateId = $(this).val();
    let url = window.baseurl + 'cities/search?state_id=' + stateId;
    ajaxRequest('GET', url, null, null, function (response) {
        $('#city').html("<option value=''>" + window.trans("--Select City--") + "</option>")
        $.each(response.data, function (key, value) {
            $('#city').append($('<option>', {
                value: value.id,
                text: value.name
            }));
        });
    })
});

$('#filter_country').on('change', function () {
    let countryId = $(this).val();
    let url = window.baseurl + 'states/search?country_id=' + countryId;
    ajaxRequest('GET', url, null, null, function (response) {
        $('#filter_state').html("<option value=''>" + window.trans("All") + "</option>")
        $.each(response.data, function (key, value) {
            $('#filter_state').append($('<option>', {
                value: value.id,
                text: value.name
            }));
        });
    })
});

$('#filter_state').on('change', function () {
    let stateId = $(this).val();
    let url = window.baseurl + 'cities/search?state_id=' + stateId;
    ajaxRequest('GET', url, null, null, function (response) {
        $('#filter_city').html("<option value=''>" + window.trans("All") + "</option>")
        $.each(response.data, function (key, value) {
            $('#filter_city').append($('<option>', {
                value: value.id,
                text: value.name
            }));
        });
    })
});

$('#filter_country_item_test').on('change', function () {
    let countryName = $(this).find('option:selected').text();
    let url = window.baseurl + 'item/cities/search?country_name=' + encodeURIComponent(countryName);
    ajaxRequest('GET', url, null, null, function (response) {
        console.log(url);
        console.log(response);
        $('#filter_city_item').html("<option value=''>" + window.trans("All") + "</option>")
        $.each(response.data, function (key, value) {
            $('#filter_city_item').append($('<option>', {
                value: value.name,
                text: value.name
            }));
        });
    });
});

$(document).ready(function () {
    const $areaContainer = $('#areas-container');

    // Function to create new area row
    function createAreaRow(name = '', latitude = '', longitude = '') {
        return `
            <div class="row area-input-group mb-3">
                <div class="col-md-4 form-group">
                    <label for="name" class="mandatory form-label mt-2">Area Name</label>
                    <div class="d-flex">
                        <input type="text" name="name[]" class="form-control me-2" value="${name}" placeholder="Enter Area name">
                    </div>
                </div>
                <div class="form-group col-md-4 col-sm-12">
                    <label for="latitude" class="mandatory form-label mt-2">Latitude</label>
                    <div class="d-flex mb-2">
                        <input type="text" name="latitude[]" class="form-control me-2" value="${latitude}" placeholder="Enter Latitude">
                    </div>
                </div>
                <div class="form-group col-md-4 col-sm-12">
                    <label for="longitude" class="mandatory form-label mt-2">Longitude</label>
                    <div class="d-flex mb-2">
                        <input type="text" name="longitude[]" class="form-control me-2" value="${longitude}" placeholder="Enter Longitude">
                        <button type="button" class="btn btn-danger remove-area-button ms-2">-</button>
                        <button type="button" class="btn btn-secondary add-area-button ms-2">+</button>
                    </div>
                </div>
            </div>
        `;
    }

    // Handle add area button click
    $(document).on('click', '.add-area-button', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const newRow = $(createAreaRow());
        $('#areas-container').append(newRow);
    });

    // Handle remove area button click
    $(document).on('click', '.remove-area-button', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $areaRows = $('.area-input-group');
        if ($areaRows.length > 1) {
            $(this).closest('.area-input-group').remove();
        } else {
            showErrorToast('At least one area is required');
        }
    });
});
$(document).ready(function () {
    const $cityContainer = $('#city-container');

    // Function to create new city row
    function createCityRow(name = '', latitude = '', longitude = '') {
        return `
            <div class="row city-input-group mb-3">
                 <div class="form-group col-md-4 col-sm-12">
                    <label for="name" class="mandatory form-label mt-2">City Name</label><span class="text-danger">*</span>
                    <div class="d-flex mb-2">
                        <input type="text" name="name[]" class="form-control me-2" value="${name}" placeholder="Enter City name">
                    </div>
                    </div>
                    <div class="form-group col-md-4 col-sm-12">
                    <label for="latitude" class="mandatory form-label mt-2">Latitude</label>
                    <div class="d-flex mb-2">
                        <input type="text" name="latitude[]" class="form-control me-2" value="${latitude}" placeholder="Enter Latitude">
                    </div>
                    </div>
                     <div class="form-group col-md-4 col-sm-12">
                    <label for="longitude" class="mandatory form-label mt-2">Longitude</label>
                    <div class="d-flex mb-2">
                        <input type="text" name="longitude[]" class="form-control me-2" value="${longitude}" placeholder="Enter Longitude">
                        <button type="button" class="btn btn-secondary add-city-button">+</button>
                        <button type="button" class="btn btn-danger remove-city-button ms-2">-</button>
                    </div>
                    </div>
                </div>
        `;
    }

    // Handle add city button click
    $(document).on('click', '.add-city-button', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $mapRow = $('#city-container').find('#map').closest('.row');
        $(createCityRow()).insertBefore($mapRow);
    });

    // Handle remove city button click
    $(document).on('click', '.remove-city-button', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $cityRows = $('.city-input-group');
        if ($cityRows.length > 1) {
        $(this).closest('.city-input-group').remove();
        } else {
            showErrorToast('At least one city is required');
        }
    });

});


$('#switch_stripe_gateway').on('change', function () {
    let status = $(this).prop('checked');
    $('[name^="gateway[Stripe]"]').each(function () {
        $(this).prop('required', status);
    });
});

$('#switch_razorpay_gateway').on('change', function () {
    let status = $(this).prop('checked');
    $('[name^="gateway[Razorpay]"]').each(function () {
        $(this).prop('required', status);
    });
});

$('#switch_paystack_gateway').on('change', function () {
    let status = $(this).prop('checked');
    $('[name^="gateway[Paystack]"]').each(function () {
        $(this).prop('required', status);
    });
});

$('#google_map_iframe_link').on('input', function () {
    try {
        let element = $(this).val();
        let src = $(element).attr('src');
        $(this).val(src);
    } catch (err) {
        $(this).val("");
        showErrorToast("Please enter a valid map iframe")
    }

});
$('#category_name').on('input', function () {
    let slug = generateSlug($(this).val())
    $('#category_slug').val(slug);
});

$('.feature-section-name').on('input', function () {
    let slug = generateSlug($(this).val());
    $('.feature-section-slug').val(slug);
});

$('.edit-feature-section-name').on('input', function () {
    let slug = generateSlug($(this).val());
    $('.edit-feature-section-slug').val(slug);
});
$('#title').on('input', function () {
    let slug = generateSlug($(this).val())
    $('#slug').val(slug);
});
function descriptionFormatter(value, row, index) {
    if (value.length > 100) {
        return '<div class="short-description">' + value.substring(0, 50) +
            '... <a href="#" class="view-more" data-index="' + index + '">View More</a></div>' +
            '<div class="full-description" style="display:none;">' + value +
            ' <a href="#" class="view-more" data-index="' + index + '">View Less</a></div>';
    } else {
        return value;
    }
}

$(document).ready(function () {
    $('body').on('click', '.view-more', function (e) {
        e.preventDefault();
        var $this = $(this);
        var $row = $this.closest('tr');
        var $fullDescription = $row.find('.full-description');
        var $shortDescription = $row.find('.short-description');

        if ($fullDescription.is(':visible')) {
            $fullDescription.hide();
            $shortDescription.show();
            $this.text('View Less');
        } else {
            $fullDescription.show();
            $shortDescription.hide();
            $this.text('View More');
        }
    });
});

$(document).on('click', '.toggle-subcategories', function() {

    let categoryId = $(this).data('id');
    let categoryRow = $(this).closest('tr');
    let currentLevel = categoryRow.data('level') || 0;

    if ($(this).hasClass('expanded')) {
        $(this).removeClass('expanded').html('<i class="fa fa-plus"></i>');
        categoryRow.nextAll().filter(function() {
            return $(this).data('level') > currentLevel;
        }).remove();
    } else {
        $(this).addClass('expanded').html('<i class="fa fa-minus"></i>');
        let url = `/category/${categoryId}/subcategories`;


        ajaxRequest('GET', url, null, null, function(data) {
            if (!Array.isArray(data)) {
                console.error('Expected an array but got:', data);
                return;
            }
            let nextLevel = currentLevel + 1;
            let subcategoryRows = '';
            data.forEach(subcategory => {
                subcategoryRows += `
                    <tr class="subcategory-row" data-level="${nextLevel}">
                        <td class="text-center">${subcategory.id}</td>
                        <td>${subCategoryNameFormatter(subcategory.name , subcategory ,nextLevel)}</td>
                        <td class="text-center">${imageFormatter(subcategory.image, subcategory.name)}</td>
                        <td class="text-center">${subCategoryFormatter(subcategory.subcategories_count,subcategory)}</td>
                        <td class="text-center">${customFieldFormatter(subcategory.custom_fields_count,subcategory)}</td>
                        <td class="text-center">${subcategory.items_count}</td>
                        <td class="text-center">${statusSwitchFormatter(subcategory.status, subcategory)}</td>
                        <td>${subcategory.operate}</td>
                    </tr>
                `;
            });
            categoryRow.after(subcategoryRows);
        });

    }
});

function updateMetaLength(inputId, maxPixelWidth, tooLongPixelWidth) {
    const input = $(`#${inputId}`);
    const countElement = $(`#${inputId}_count`);

    if (input.length && countElement.length) {
        const text = input.val().trim();
        let textPixelLength = Math.round(getTextWidth(text, '19.9px Arial'));

        let iconClass = 'fa-exclamation-triangle text-danger';
        let feedbackMessage = `Your page Meta ${inputId === 'meta_title' ? 'title' : 'description'} is too short.`;
        let feedbackColor = 'text-danger';


        if (textPixelLength >= maxPixelWidth && textPixelLength <= tooLongPixelWidth) {
            iconClass = 'fa-check-circle text-success';
            feedbackMessage = `Your page Meta ${inputId === 'meta_title' ? 'title' : 'description'} is an acceptable length.`;
            feedbackColor = 'text-success';
        } else if (textPixelLength > tooLongPixelWidth) {
            feedbackMessage = `Page Meta ${inputId === 'meta_title' ? 'title' : 'description'} should be around ${tooLongPixelWidth} pixels in length`;
        }
        countElement.html(`
            <i class="fa ${iconClass}"></i>
            <span>Meta ${inputId === 'meta_title' ? 'Title' : 'Description'} is <b>${textPixelLength}</b> pixel(s) long</span>
            <span class="${feedbackColor}">--${feedbackMessage}</span>
        `);
    }
}
function getTextWidth(text, font) {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    context.font = font;
    const metrics = context.measureText(text);
    return metrics.width;
}

$('#meta_title').on('input', function() {
    updateMetaLength('meta_title', 240, 580);
});

$('#meta_description').on('input', function() {
    updateMetaLength('meta_description', 400, 920);
});

$('#file_manager').on('change',function (){
    if($(this).val()=="local"){
        $('#s3_div').hide();
    }else if($(this).val()=="s3"){
        $('#s3_div').show();
    }
})

$('#verification_status').change(function () {
    let status = $(this).val();
    if (status === 'rejected') {
        $('#rejectionReasonField').show();
    } else {
        $('#rejectionReasonField').hide();
    }
});
function customValidation() {
    let item = $("select[name=item]").val();
    let category = $("select[name=category_id]").val();
    let link = $("input[name=link]").val();
    if (item == "" && category == "" && link == "") {
        // Display an error message
        $('.invalid-form-error-message').html("Please select either Item, Category, or Add Link").addClass("text-danger");
        return false;
    }

    if ((item != "" && category != "") || (item != "" && link != "") || (category != "" && link != "")) {
        $('.invalid-form-error-message').html("Please select only one field: Item, Category, or Link").addClass("text-danger");
        return false;
    }

    $('.invalid-form-error-message').html('');

    return true;
}
$(function () {
    $(".sortable").sortable({
        revert: true,
        items: "li",
    });
    // $("#draggable").draggable({
    //     connectToSortable: "#sortable",
    //     helper: "clone",
    //     revert: "invalid"
    // });
    $("ul, li").disableSelection();
});

$("#update-team-member-rank-form").on("submit", function (e) {
    e.preventDefault();

    let userOrder = $(".sortable").sortable("toArray"); // Get the new order of items
    let formElement = $(this);
    let submitButtonElement = $(this).find(":submit");
    let url = $(this).attr("action");

    let data = new FormData(this);
    data.append("order", JSON.stringify(userOrder)); // Append order as JSON
    data.append("_method", "POST");

    function successCallback() {
        setTimeout(function () {
            window.location.reload();
        }, 1000);
    }

    formAjaxRequest(
        "POST",
        url,
        data,
        formElement,
        submitButtonElement,
        successCallback
    );
});

document.addEventListener('DOMContentLoaded', function () {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

$(document).ready(function () {
    $('#p_category').select2({
        placeholder: "{{ __('Select Category') }}",
        allowClear: true,
        width: '100%'
    });
});
$(document).ready(function () {
    function toggleTwilioSettings() {
        let otpServicesProviderValue = $("#otp-services-provider").val();
        if (otpServicesProviderValue === 'twilio') {
            $("#twilio-sms-settings-div").show();
            $(".twilio-account-settings").attr('required', true);
        } else {
            $(".twilio-account-settings").removeAttr('required');
            $("#twilio-sms-settings-div").hide();
        }
    }

    toggleTwilioSettings();
    $("#otp-services-provider").on('change', function () {
        toggleTwilioSettings();
    });
});

document.addEventListener('DOMContentLoaded', function () {
    let answerInput = document.getElementById('answer');
    if (answerInput) {
        answerInput.addEventListener('input', function () {
            let words = this.value.trim().split(/\s+/).filter(Boolean).length;
            let maxWords = 500;
            if (words > maxWords) {
                this.value = this.value.trim().split(/\s+/).slice(0, maxWords).join(' ');
                alert("Maximum 500 words allowed.");
            }
        });
    }
});
function toggleRejectedReasonVisibility() {
    var status = $('#report_status').val();
    var rejectedReasonContainer = $('#report_rejected_reason_container');
    if (status == 'rejected') {
        rejectedReasonContainer.show();
    } else {
        rejectedReasonContainer.hide();
    }
}

$('#report_status').on('change', function () {
    toggleRejectedReasonVisibility();
});
