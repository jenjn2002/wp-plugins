jQuery(document).ready(function($) {
    // Initialize program type settings visibility
    function initProgramTypeSettings() {
        var selectedType = $('#program_type').val();
        $('.program-settings').hide();
        $('#' + selectedType + '_settings').show();
    }

    // Program type change handler
    $('#program_type').on('change', initProgramTypeSettings);

    // Initialize on page load
    initProgramTypeSettings();

    // Points settings form submission
    $('#points-settings-form').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'save_loyalty_settings',
                nonce: wcLoyalty.nonce,
                points_rate: $('#points_rate').val(),
                redemption_rate: $('#redemption_rate').val()
            },
            success: function(response) {
                if (response.success) {
                    alert(wcLoyalty.i18n.saveSuccess);
                } else {
                    alert(wcLoyalty.i18n.saveFail);
                }
            },
            error: function() {
                alert(wcLoyalty.i18n.saveFail);
            }
        });
    });

    // Delete program handler
    $('.delete-program').on('click', function() {
        if (confirm(wcLoyalty.i18n.confirmDelete)) {
            var programId = $(this).data('id');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_loyalty_program',
                    nonce: wcLoyalty.nonce,
                    program_id: programId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data);
                    }
                },
                error: function() {
                    alert(wcLoyalty.i18n.deleteFail);
                }
            });
        }
    });
});