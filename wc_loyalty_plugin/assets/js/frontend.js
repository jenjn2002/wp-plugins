jQuery(document).ready(function($) {
    // Apply points handler
    $('#apply-points').on('click', function(e) {
        e.preventDefault();

        var points = $('#points-to-use').val();
        if (!points || points <= 0) {
            alert(wcLoyalty.i18n.enterPoints);
            return;
        }

        $.ajax({
            url: wcLoyalty.ajaxUrl,
            type: 'POST',
            data: {
                action: 'apply_loyalty_points',
                nonce: wcLoyalty.nonce,
                points: points
            },
            success: function(response) {
                if (response.success) {
                    alert(wcLoyalty.i18n.pointsApplied);
                    location.reload();
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert(wcLoyalty.i18n.error);
            }
        });
    });

    // Update points info on quantity change
    $('form.cart').on('change', 'input.qty', function() {
        var qty = $(this).val();
        var pointsRate = wcLoyalty.pointsRate;
        var price = parseFloat($('.product-price .amount').text().replace(/[^0-9.-]+/g, ''));
        
        if (qty && price && pointsRate) {
            var points = Math.floor((price * qty) / pointsRate);
            $('.points-text').text(
                wcLoyalty.i18n.earnPoints.replace('%d', points)
            );
        }
    });
});