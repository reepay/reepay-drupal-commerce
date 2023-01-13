(function($) {
  Drupal.behaviors.commerce_reepay_checkout = {
    attach: function(context, settings) {

      let rp;
      const key = settings.commerce_reepay_checkout.session_id;
      const checkout_type = settings.commerce_reepay_checkout.checkout_type;
      const cancel_url = settings.commerce_reepay_checkout.cancel_url;
      const return_url = settings.commerce_reepay_checkout.return_url;

      if('window' == checkout_type) {
        rp = new Reepay.WindowCheckout(key);
      }

      if('modal' == checkout_type) {
        rp = new Reepay.ModalCheckout(key);

        rp.addEventHandler(Reepay.Event.Accept, function(data) {
          const redirect_url = return_url + '?' + data.id + '&invoice=' + data.invoice + '&customer=' + data.customer;
          window.location.replace(redirect_url);
        });

        rp.addEventHandler(Reepay.Event.Cancel, function(data) {
          window.location.replace(cancel_url);
        });

        rp.addEventHandler(Reepay.Event.Error, function(data) {
          alert ('Error', data);
        });

      }
    }
  };
}(jQuery));
